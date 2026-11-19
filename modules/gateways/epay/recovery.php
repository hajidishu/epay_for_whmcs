<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib.php';

function epay_secure_invoice_lock($invoiceId)
{
    return epay_secure_acquire_lock('invoice:' . (int) $invoiceId, 25);
}

function epay_secure_legacy_invoice($invoiceId)
{
    $record = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
    $cutover = strtotime(epay_secure_get_meta('legacy_cutover_at', ''));
    if (!$record || $cutover === false) {
        return false;
    }

    $issued = !empty($record->created_at) ? strtotime((string) $record->created_at) : false;
    $firstUnixDate = strtotime('1970-01-01');
    if ($issued === false || $issued < $firstUnixDate) {
        $issued = strtotime((string) $record->date);
    }
    return $issued !== false && $issued <= $cutover;
}

function epay_secure_legacy_clear_key($invoiceId)
{
    return 'legacy_closed:' . (int) $invoiceId;
}

function epay_secure_set_legacy_clear($invoiceId, $reason)
{
    $key = epay_secure_legacy_clear_key($invoiceId);
    epay_secure_set_meta_if_missing($key, $reason);
    if (epay_secure_get_meta($key, '') === '') {
        throw new RuntimeException('未能保存旧订单的核对结果。');
    }
}

function epay_secure_legacy_resolution_key($invoiceId, $transactionId)
{
    return 'legacy_resolved:' . (int) $invoiceId . ':' . substr(hash('sha256', $transactionId), 0, 32);
}

function epay_secure_legacy_paid_key($invoiceId)
{
    return 'legacy_paid_notice:' . (int) $invoiceId;
}

/**
 * V1 status=0 only means unpaid. A local deadline or an unrecognized
 * provider response cannot prove that an order can no longer be paid.
 */
function epay_secure_provider_order_state(array $order, $outTradeNo, $pid)
{
    $code = (string) ($order['code'] ?? '');
    $message = trim((string) ($order['msg'] ?? ''));
    if (!in_array($code, array('1', '-1', '0'), true)) {
        return 'unknown';
    }
    if ($code !== '1') {
        if (in_array(strtolower($message), array('订单不存在', '该订单不存在', 'order not found', 'trade_not_exist'), true)) {
            return 'absent';
        }
        return 'unknown';
    }

    if ((string) ($order['out_trade_no'] ?? '') !== (string) $outTradeNo
        || (isset($order['pid']) && (string) $order['pid'] !== (string) $pid)) {
        return 'mismatch';
    }

    $status = strtoupper(trim((string) ($order['status'] ?? '')));
    $tradeStatus = strtoupper(trim((string) ($order['trade_status'] ?? '')));
    $paid = $status === '1' || in_array($tradeStatus, array('TRADE_SUCCESS', 'TRADE_FINISHED'), true);
    $terminal = array('TRADE_CLOSED', 'TRADE_EXPIRED', 'CLOSED', 'EXPIRED', 'CANCELLED', 'CANCELED');
    $closed = in_array($status, $terminal, true) || in_array($tradeStatus, $terminal, true);
    if ($paid && $closed) {
        return 'mismatch';
    }
    if ($paid) {
        return 'paid';
    }
    if ($closed) {
        return 'closed';
    }
    if ($status === '0' || $tradeStatus === 'WAIT_BUYER_PAY') {
        return 'unpaid';
    }
    return 'unknown';
}

function epay_secure_probe_provider_order(array $gateway, $outTradeNo)
{
    $base = epay_secure_gateway_base_url($gateway['gatewayUrl'], !empty($gateway['allowHttp']));
    if (strtolower((string) parse_url($base, PHP_URL_SCHEME)) !== 'https' || !empty($gateway['disableTlsVerify'])) {
        return array('state' => 'unknown', 'order' => array());
    }

    try {
        if (epay_secure_is_mhpay($gateway)) {
            return epay_secure_query_mhpay_order($gateway, $outTradeNo);
        }
        $order = epay_secure_query_order_v1($gateway, $outTradeNo);
    } catch (RuntimeException $e) {
        return array('state' => 'unknown', 'order' => array());
    }
    return array(
        'state' => epay_secure_provider_order_state($order, $outTradeNo, $gateway['pid']),
        'order' => $order,
    );
}

function epay_secure_assert_attempt_identity($attempt, array $data)
{
    $tradeNo = trim((string) ($data['trade_no'] ?? ''));
    if ($tradeNo === '' || strlen($tradeNo) > 128) {
        throw new RuntimeException('支付平台未返回有效的交易号。');
    }
    if ((string) $attempt->out_trade_no !== (string) ($data['out_trade_no'] ?? '')
        || (!empty($attempt->provider_trade_no) && !hash_equals((string) $attempt->provider_trade_no, $tradeNo))) {
        throw new RuntimeException('支付平台的订单号与本地记录不一致，请人工核账。');
    }
    if ((string) $attempt->payment_type !== (string) ($data['type'] ?? '')
        || epay_secure_money_to_cents(epay_secure_attempt_processing_amount($attempt)) !== epay_secure_money_to_cents($data['money'] ?? '')
        || epay_secure_money_to_cents($data['money']) <= 0) {
        throw new RuntimeException('支付平台的支付方式或金额与本地记录不一致，请人工核账。');
    }
}

function epay_secure_invoice_payment_conflict(array $invoice, $currency, $amount)
{
    if (!in_array($invoice['status'], array('Unpaid', 'Payment Pending'), true)) {
        return '发票已付款或已关闭。';
    }
    if ($invoice['paymentmethod'] !== '' && $invoice['paymentmethod'] !== 'epay') {
        return '发票已改用其他支付方式。';
    }
    if ($invoice['currency'] !== $currency) {
        return '发票币种已变更。';
    }
    if (epay_secure_money_to_cents($amount) <= 0
        || epay_secure_money_to_cents($invoice['balance']) !== epay_secure_money_to_cents($amount)) {
        return '发票当前应付余额与原支付订单金额不同。';
    }
    return null;
}

function epay_secure_recorded_transaction($invoiceId, $transactionId, $amount = null)
{
    $existing = Capsule::table('tblaccounts')->where('transid', $transactionId)->first();
    if (!$existing) {
        return false;
    }
    if ((int) $existing->invoiceid !== (int) $invoiceId || (string) $existing->gateway !== 'epay'
        || (float) $existing->amountin <= 0
        || ($amount !== null && epay_secure_money_to_cents($existing->amountin) !== epay_secure_money_to_cents($amount))) {
        throw new RuntimeException('同一交易号的入账记录与本次付款不匹配，请人工核账。');
    }
    return true;
}

// The caller holds the invoice lock. The transaction lock also protects a
// provider transaction ID from being posted to two different invoices.
function epay_secure_record_attempt_payment($attempt, array $data)
{
    epay_secure_assert_attempt_identity($attempt, $data);
    $invoiceId = (int) $attempt->invoice_id;
    $transactionId = (string) $data['trade_no'];
    $lock = epay_secure_acquire_lock('trade:' . strtolower($transactionId), 10);
    try {
        return Capsule::connection()->transaction(function () use ($attempt, $data, $invoiceId, $transactionId) {
            $row = Capsule::table('tblinvoices')->where('id', $invoiceId)->lockForUpdate()->first();
            $amount = isset($attempt->invoice_amount) && $attempt->invoice_amount !== ''
                ? epay_secure_normalize_money($attempt->invoice_amount) : null;
            if ((string) $attempt->status === 'reconciled' && (string) $attempt->provider_trade_no === $transactionId) {
                return '已核账交易的重复通知';
            }
            if (epay_secure_recorded_transaction($invoiceId, $transactionId, $amount)) {
                epay_secure_mark_attempt_paid($attempt, $data);
                return '重复通知已确认';
            }

            $note = null;
            if (!$row) {
                $note = '找不到原发票。';
            } elseif ((string) $attempt->status === 'blocked_review' || epay_secure_invoice_has_unresolved_review($invoiceId)) {
                $note = '发票存在待核账或隔离付款。';
            } elseif (!in_array($row->status, array('Unpaid', 'Payment Pending'), true)) {
                $note = '发票已付款或已关闭。';
            } else {
                $invoice = epay_secure_get_invoice_snapshot($invoiceId);
                $currency = !empty($attempt->invoice_currency) ? epay_secure_currency_code($attempt->invoice_currency) : null;
                $fee = !empty($attempt->invoice_fee) ? epay_secure_normalize_money($attempt->invoice_fee) : '0.00';
                if ($amount === null || $currency === null) {
                    $oldCurrency = epay_secure_attempt_processing_currency($attempt);
                    if ($invoice['currency'] === $oldCurrency) {
                        $amount = epay_secure_attempt_processing_amount($attempt);
                        $currency = $oldCurrency;
                        $fee = '0.00';
                    } else {
                        $note = '旧订单缺少原始发票币种或金额。';
                    }
                }
                $note = $note ?? epay_secure_invoice_payment_conflict($invoice, $currency, $amount);
            }

            if ($note !== null) {
                epay_secure_mark_attempt_review($attempt->id, $transactionId, $data, $note);
                return '已收款待人工核账：' . $note;
            }

            addInvoicePayment($invoiceId, $transactionId, $amount, $fee, 'epay');
            if (!epay_secure_recorded_transaction($invoiceId, $transactionId, $amount)) {
                throw new RuntimeException('WHMCS 尚未记录这笔付款，请重试回调。');
            }
            epay_secure_mark_attempt_paid($attempt, $data);
            return '成功';
        });
    } finally {
        epay_secure_release_lock($lock);
    }
}

function epay_secure_mark_attempt_paid($attempt, array $data)
{
    $now = date('Y-m-d H:i:s');
    Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
        'status' => 'paid',
        'provider_trade_no' => (string) $data['trade_no'],
        'callback_payload' => epay_secure_json_payload($data),
        'paid_at' => $attempt->paid_at ?: $now,
        'last_seen_at' => $now,
        'last_error' => null,
    ));
}

function epay_secure_record_legacy_payment($invoiceId, array $data)
{
    $transactionId = trim((string) ($data['trade_no'] ?? ''));
    if ($transactionId === '' || strlen($transactionId) > 128 || epay_secure_money_to_cents($data['money'] ?? '') <= 0) {
        throw new RuntimeException('旧订单缺少有效的交易号或付款金额。');
    }
    $lock = epay_secure_acquire_lock('trade:' . strtolower($transactionId), 10);
    try {
        if (epay_secure_get_meta(epay_secure_legacy_resolution_key($invoiceId, $transactionId), '') !== '') {
            return '已核账旧交易的重复通知';
        }
        if (epay_secure_recorded_transaction($invoiceId, $transactionId)) {
            return '旧交易已入账';
        }
        $review = Capsule::table('mod_epay_legacy_events')->where('provider_trade_no', $transactionId)->first();
        if ($review) {
            if ((int) $review->invoice_id !== (int) $invoiceId || (string) $review->out_trade_no !== (string) $data['out_trade_no']) {
                throw new RuntimeException('旧交易号已关联其他发票或订单。');
            }
            return '旧交易重复通知已确认';
        }
        Capsule::connection()->transaction(function () use ($invoiceId, $data) {
            epay_secure_record_legacy_review($invoiceId, $data, null, '旧版支付已确认，请按平台记录核对原发票金额和币种。');
        });
        return '旧交易已收款待人工核账';
    } finally {
        epay_secure_release_lock($lock);
    }
}

function epay_secure_assert_legacy_clear($invoiceId, array $gateway)
{
    if (($gateway['legacyOrderMode'] ?? '') === 'fresh' || !epay_secure_legacy_invoice($invoiceId)
        || epay_secure_get_meta(epay_secure_legacy_clear_key($invoiceId), '') !== '') {
        return;
    }
    if (epay_secure_get_meta(epay_secure_legacy_paid_key($invoiceId), '') !== '') {
        throw new RuntimeException('支付平台显示旧订单已付款，请等待支付通知或联系管理员核账。');
    }
    $evidence = epay_secure_probe_provider_order($gateway, (string) $invoiceId);
    if ($evidence['state'] === 'closed' || $evidence['state'] === 'absent') {
        epay_secure_set_legacy_clear($invoiceId, 'provider:' . $evidence['state']);
        return;
    }
    if ($evidence['state'] === 'paid') {
        $note = epay_secure_record_legacy_payment($invoiceId, $evidence['order']);
        logTransaction($gateway['name'], array('invoice_id' => $invoiceId), $note);
        throw new RuntimeException('旧订单已付款，请刷新发票或联系管理员核账。');
    }
    if ($evidence['state'] === 'paid_notice') {
        epay_secure_set_meta_if_missing(epay_secure_legacy_paid_key($invoiceId), $evidence['order']['trade_no'] ?? 'provider');
        throw new RuntimeException('支付平台显示旧订单已付款，请等待支付通知或联系管理员核账。');
    }
    throw new RuntimeException('旧版支付订单尚未确认关闭，请联系管理员核对。');
}

function epay_secure_recover_open_orders($invoiceId, array $gateway)
{
    foreach (epay_secure_open_attempts($invoiceId)->orderBy('id')->get() as $attempt) {
        $evidence = epay_secure_probe_provider_order($gateway, $attempt->out_trade_no);
        $order = $evidence['order'];
        if ($evidence['state'] === 'mismatch') {
            throw new RuntimeException('支付平台返回的旧订单身份不匹配，请联系管理员核对。');
        }
        if ($evidence['state'] === 'paid') {
            $order['type'] = $order['type'] ?? $attempt->payment_type;
            $note = epay_secure_record_attempt_payment($attempt, $order);
            logTransaction($gateway['name'], array('invoice_id' => $invoiceId, 'out_trade_no' => $attempt->out_trade_no), $note);
            throw new RuntimeException('支付平台已确认付款，请刷新发票查看状态。');
        }
        if ($evidence['state'] === 'paid_notice' || $attempt->status === 'awaiting_callback') {
            $tradeNo = (string) ($order['trade_no'] ?? '');
            if ($tradeNo !== '' && !empty($attempt->provider_trade_no) && (string) $attempt->provider_trade_no !== $tradeNo) {
                throw new RuntimeException('平台返回的付款交易号与原记录不符，请联系管理员核对。');
            }
            Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
                'status' => 'awaiting_callback',
                'provider_trade_no' => $tradeNo !== '' && strlen($tradeNo) <= 128 ? $tradeNo : $attempt->provider_trade_no,
                'last_error' => '支付平台显示已付款，等待签名通知或人工核账。',
                'last_seen_at' => date('Y-m-d H:i:s'),
            ));
            throw new RuntimeException('支付平台显示已付款，请等待支付通知或联系管理员核账。');
        }
        if ($evidence['state'] === 'closed') {
            if (!empty($attempt->provider_trade_no) && (string) ($order['trade_no'] ?? '') !== (string) $attempt->provider_trade_no) {
                throw new RuntimeException('平台的关单记录与原交易号不符，请联系管理员核对。');
            }
            Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
                'status' => 'closed',
                'last_error' => null,
                'last_seen_at' => date('Y-m-d H:i:s'),
            ));
        }
    }
}

function epay_secure_serve_callback($legacy)
{
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    $gateway = getGatewayVariables('epay');
    if (empty($gateway['type']) || empty($gateway['key'])) {
        http_response_code(503);
        echo 'fail';
        return;
    }

    $data = epay_secure_get_request_data();
    $lock = null;
    try {
        foreach (array('pid', 'trade_no', 'out_trade_no', 'type', 'money', 'trade_status', 'sign', 'sign_type') as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new RuntimeException('回调缺少字段：' . $field);
            }
        }
        if (strcasecmp($data['sign_type'], 'MD5') !== 0 || !hash_equals((string) $gateway['pid'], $data['pid'])
            || !hash_equals(epay_secure_sign($data, $gateway['key']), strtolower($data['sign']))) {
            throw new RuntimeException('回调签名或商户不匹配。');
        }
        if ($data['trade_status'] !== 'TRADE_SUCCESS' || epay_secure_money_to_cents($data['money']) <= 0) {
            throw new RuntimeException('回调未确认有效付款。');
        }

        epay_secure_ensure_schema();
        $orderNo = $data['out_trade_no'];
        if ($legacy) {
            $invoiceId = ctype_digit($orderNo) ? (int) $orderNo : 0;
            if (!$invoiceId || !epay_secure_legacy_invoice($invoiceId)) {
                throw new RuntimeException('该订单与迁移前的发票不匹配。');
            }
        } else {
            $attempt = Capsule::table('mod_epay_attempts')->where('out_trade_no', $orderNo)->first();
            if (!$attempt) {
                throw new RuntimeException('未找到对应的支付订单。');
            }
            $invoiceId = (int) $attempt->invoice_id;
        }

        $lock = epay_secure_invoice_lock($invoiceId);
        $known = epay_secure_recorded_transaction($invoiceId, $data['trade_no']);
        if ($legacy) {
            $known = $known || Capsule::table('mod_epay_legacy_events')->where('invoice_id', $invoiceId)
                ->where('provider_trade_no', $data['trade_no'])->where('out_trade_no', $orderNo)->exists()
                || epay_secure_get_meta(epay_secure_legacy_resolution_key($invoiceId, $data['trade_no']), '') !== '';
            if (!$known) {
                epay_secure_verify_provider_order($gateway, $orderNo, $data['trade_no'], $data['type'], $data['money']);
            }
            $note = epay_secure_record_legacy_payment($invoiceId, $data);
        } else {
            $attempt = Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->first();
            if (!$attempt) {
                throw new RuntimeException('支付订单在处理前已被移除。');
            }
            $known = $known || ((string) $attempt->provider_trade_no === $data['trade_no']
                && in_array($attempt->status, array('paid_review', 'reconciled'), true));
            if (!$known) {
                epay_secure_verify_provider_order($gateway, $orderNo, $data['trade_no'], $data['type'], $data['money']);
            }
            $note = epay_secure_record_attempt_payment($attempt, $data);
        }

        logTransaction($gateway['name'], $data, $note);
        echo 'success';
    } catch (Throwable $e) {
        logTransaction($gateway['name'], $data, '回调失败：' . $e->getMessage());
        http_response_code(400);
        echo 'fail';
    } finally {
        epay_secure_release_lock($lock);
    }
}
