<?php
/**
 * Migration-only callback for provider orders created by the OLD plugin.
 *
 * Old orders used WHMCS invoice ID directly as out_trade_no and did not persist
 * a trustworthy historical invoice/processing currency snapshot. v2.2 therefore
 * NEVER auto-posts a newly received legacy success to WHMCS. Every such payment is
 * recorded as paid_review for manual accounting, which also blocks a second payment.
 *
 * The endpoint remains available only during the bounded migration window.
 */

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/lib.php';

use WHMCS\Database\Capsule;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$gatewayModuleName = 'epay';
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('Module Not Activated');
}

$data = epay_secure_get_request_data();
$required = array('pid', 'trade_no', 'out_trade_no', 'type', 'money', 'trade_status', 'sign', 'sign_type');
foreach ($required as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        logTransaction($gatewayParams['name'], $data, 'Legacy 回调缺少字段: ' . $field);
        http_response_code(400);
        exit('fail');
    }
}

$lockName = null;

try {
    if (strcasecmp((string) $data['sign_type'], 'MD5') !== 0) {
        throw new RuntimeException('Unsupported sign_type.');
    }
    if (!hash_equals((string) $gatewayParams['pid'], (string) $data['pid'])) {
        throw new RuntimeException('PID mismatch.');
    }

    $expectedSign = epay_secure_sign($data, $gatewayParams['key']);
    if (!hash_equals(strtolower($expectedSign), strtolower((string) $data['sign']))) {
        throw new RuntimeException('Signature verification failed.');
    }
    if ((string) $data['trade_status'] !== 'TRADE_SUCCESS') {
        logTransaction($gatewayParams['name'], $data, 'Legacy 交易未成功: ' . (string) $data['trade_status']);
        exit('fail');
    }
    if (!ctype_digit((string) $data['out_trade_no']) || (int) $data['out_trade_no'] <= 0) {
        throw new RuntimeException('Legacy out_trade_no is not a WHMCS invoice ID.');
    }

    epay_secure_initialize_cutover($gatewayParams);
    if (!epay_secure_legacy_is_open()) {
        $until = epay_secure_legacy_accept_until();
        logTransaction($gatewayParams['name'], $data, 'Legacy 回调迁移窗口已关闭（截止 ' . $until . '）');
        http_response_code(410);
        exit('fail');
    }

    $invoiceId = checkCbInvoiceID((int) $data['out_trade_no'], $gatewayParams['name']);
    $transactionId = (string) $data['trade_no'];
    $paymentAmount = epay_secure_normalize_money($data['money']);
    $lockName = epay_secure_acquire_lock('legacy-trade:' . $transactionId, 10);

    // A retry can be acknowledged only when an existing WHMCS transaction belongs to
    // this exact legacy invoice and this exact gateway. A transid collision elsewhere
    // is a hard error and must not be treated as idempotent success.
    $existingTransaction = Capsule::table('tblaccounts')->where('transid', $transactionId)->first();
    if ($existingTransaction) {
        if ((int) $existingTransaction->invoiceid !== $invoiceId
            || !hash_equals((string) $gatewayModuleName, (string) $existingTransaction->gateway)) {
            throw new RuntimeException('Transaction ID already belongs to another invoice or gateway.');
        }

        logTransaction($gatewayParams['name'], $data, 'Legacy 重复通知已确认（该交易已正确记入同一发票/网关）');
        epay_secure_release_lock($lockName);
        exit('success');
    }

    $existingReview = Capsule::table('mod_epay_legacy_events')
        ->where('provider_trade_no', $transactionId)
        ->first();
    if ($existingReview) {
        if ((int) $existingReview->invoice_id !== $invoiceId
            || !hash_equals((string) $existingReview->out_trade_no, (string) $data['out_trade_no'])) {
            throw new RuntimeException('Legacy review transaction is already bound to another invoice/order.');
        }

        logTransaction($gatewayParams['name'], $data, 'Legacy 人工复核交易重复通知已确认');
        epay_secure_release_lock($lockName);
        exit('success');
    }

    epay_secure_verify_provider_order(
        $gatewayParams,
        (string) $data['out_trade_no'],
        $transactionId,
        (string) $data['type'],
        $paymentAmount
    );

    checkCbTransID($transactionId);

    // IMPORTANT: Old numeric orders never persisted their historical invoice-side
    // amount/currency or Convert To For Processing state. The current gateway config
    // is not evidence of what was used 15 days ago. Therefore every newly received
    // legacy success is manual-review only; no automatic WHMCS payment-posting helper is called here.
    $message = 'Legacy order payment confirmed by EPay. Historical invoice/processing currency snapshot is unavailable, so automatic WHMCS accounting is intentionally disabled. Manual review required.';
    epay_secure_record_legacy_review($invoiceId, $data, null, $message);

    logTransaction($gatewayParams['name'], $data, 'Legacy 已收款并进入人工核账；为防止错账和重复扣款，该发票将禁止再次创建 EPay 订单。');
    epay_secure_release_lock($lockName);
    exit('success');
} catch (Throwable $e) {
    epay_secure_release_lock($lockName);
    logTransaction($gatewayParams['name'], $data, 'Legacy 回调失败: ' . $e->getMessage());
    http_response_code(400);
    exit('fail');
}
