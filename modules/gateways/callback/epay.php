<?php

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/../epay/lib.php';

use WHMCS\Database\Capsule;

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('Module Not Activated');
}

$data = epay_secure_get_request_data();
$required = array('pid', 'trade_no', 'out_trade_no', 'type', 'money', 'trade_status', 'sign', 'sign_type');
foreach ($required as $field) {
    if (!isset($data[$field]) || $data[$field] === '') {
        logTransaction($gatewayParams['name'], $data, '回调缺少字段: ' . $field);
        http_response_code(400);
        exit('fail');
    }
}

$attempt = null;
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
        logTransaction($gatewayParams['name'], $data, '交易未成功: ' . (string) $data['trade_status']);
        exit('fail');
    }

    epay_secure_ensure_schema();
    $attempt = Capsule::table('mod_epay_attempts')
        ->where('out_trade_no', (string) $data['out_trade_no'])
        ->first();
    if (!$attempt) {
        throw new RuntimeException('Unknown out_trade_no.');
    }

    if ((string) $attempt->payment_type !== (string) $data['type']) {
        throw new RuntimeException('Payment type mismatch.');
    }
    if (!empty($attempt->provider_trade_no) && !hash_equals((string) $attempt->provider_trade_no, (string) $data['trade_no'])) {
        throw new RuntimeException('Provider trade_no mismatch.');
    }

    $processingAmount = epay_secure_attempt_processing_amount($attempt);
    $processingCurrency = epay_secure_attempt_processing_currency($attempt);
    if (epay_secure_money_to_cents($processingAmount) !== epay_secure_money_to_cents($data['money'])) {
        throw new RuntimeException('Processing amount mismatch.');
    }

    $invoiceId = checkCbInvoiceID((int) $attempt->invoice_id, $gatewayParams['name']);
    $transactionId = (string) $data['trade_no'];
    $lockName = epay_secure_acquire_lock('trade:' . $transactionId, 10);

    // Provider retries are normal, but an existing transid is idempotent ONLY when it
    // already belongs to this exact invoice and this exact gateway. A global transid
    // collision must never cause a different Attempt to be marked paid.
    $existingTransaction = Capsule::table('tblaccounts')->where('transid', $transactionId)->first();
    if ($existingTransaction) {
        if ((int) $existingTransaction->invoiceid !== $invoiceId
            || !hash_equals((string) $gatewayModuleName, (string) $existingTransaction->gateway)) {
            throw new RuntimeException('Transaction ID already belongs to another invoice or gateway.');
        }

        if (isset($attempt->invoice_amount) && $attempt->invoice_amount !== null && $attempt->invoice_amount !== ''
            && isset($existingTransaction->amountin) && $existingTransaction->amountin !== null && $existingTransaction->amountin !== ''
            && epay_secure_money_to_cents($attempt->invoice_amount) !== epay_secure_money_to_cents($existingTransaction->amountin)) {
            throw new RuntimeException('Existing WHMCS transaction amount does not match this Payment Attempt.');
        }

        Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
            'status' => 'paid',
            'provider_trade_no' => $transactionId,
            'callback_payload' => epay_secure_json_payload($data),
            'paid_at' => $attempt->paid_at ?: date('Y-m-d H:i:s'),
            'last_seen_at' => date('Y-m-d H:i:s'),
            'last_error' => null,
        ));
        logTransaction($gatewayParams['name'], $data, '重复通知已确认（invoice/gateway/amount 绑定一致）');
        epay_secure_release_lock($lockName);
        exit('success');
    }

    epay_secure_verify_provider_order(
        $gatewayParams,
        (string) $data['out_trade_no'],
        $transactionId,
        (string) $data['type'],
        $processingAmount
    );

    checkCbTransID($transactionId);

    // If another provider-confirmed payment for this invoice is awaiting review, or
    // this Attempt was quarantined because of such a payment, never auto-post a second
    // provider charge into WHMCS. Record this payment for manual reconciliation too.
    if ((string) $attempt->status === 'blocked_review'
        || (string) $attempt->status === 'paid_review'
        || epay_secure_invoice_has_unresolved_review($invoiceId)) {
        $message = 'Payment was confirmed while this invoice already had an unresolved/blocked payment review. Manual reconciliation required to prevent duplicate accounting.';
        epay_secure_mark_attempt_review($attempt->id, $transactionId, $data, $message);
        logTransaction($gatewayParams['name'], $data, '已收款但该发票存在待核账/隔离支付，当前交易也进入人工核账。');
        epay_secure_release_lock($lockName);
        exit('success');
    }

    $invoiceSnapshot = epay_secure_get_invoice_snapshot($invoiceId);

    $invoiceAmount = isset($attempt->invoice_amount) && $attempt->invoice_amount !== null && $attempt->invoice_amount !== ''
        ? epay_secure_normalize_money($attempt->invoice_amount)
        : null;
    $invoiceCurrency = isset($attempt->invoice_currency) && $attempt->invoice_currency !== null && $attempt->invoice_currency !== ''
        ? epay_secure_currency_code($attempt->invoice_currency)
        : null;
    $invoiceFee = isset($attempt->invoice_fee) && $attempt->invoice_fee !== null && $attempt->invoice_fee !== ''
        ? epay_secure_normalize_money($attempt->invoice_fee)
        : null;

    // Existing v2.0 attempts only stored the gateway-side amount/currency. They are safe to auto-hydrate
    // only when the invoice currency equals the processing currency (there was no cross-currency ambiguity).
    if ($invoiceAmount === null || $invoiceCurrency === null) {
        if ($invoiceSnapshot['currency'] !== $processingCurrency) {
            $message = 'v2.0 migrated Attempt used a different processing currency; original invoice amount cannot be reconstructed safely. Manual accounting review required.';
            epay_secure_mark_attempt_review($attempt->id, $transactionId, $data, $message);
            logTransaction($gatewayParams['name'], $data, '已收款但需要人工入账：' . $message);
            epay_secure_release_lock($lockName);
            exit('success');
        }

        $invoiceAmount = $processingAmount;
        $invoiceCurrency = $processingCurrency;
        $invoiceFee = '0.00';
        Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
            'invoice_amount' => $invoiceAmount,
            'invoice_currency' => $invoiceCurrency,
            'conversion_rate' => '1.000000000000',
            'invoice_fee' => $invoiceFee,
            'source' => 'v20',
            'last_error' => 'Migrated v2.0 same-currency Attempt; historical fee snapshot unavailable, recorded as 0.00.',
        ));
    }

    // WHMCS accounting uses the invoice currency. If that currency somehow changed after Attempt creation,
    // do not guess: the provider payment is real, but automatic ledger application is no longer safe.
    if ($invoiceSnapshot['currency'] !== $invoiceCurrency) {
        $message = 'Invoice currency changed after Payment Attempt creation. Manual accounting review required.';
        epay_secure_mark_attempt_review($attempt->id, $transactionId, $data, $message);
        logTransaction($gatewayParams['name'], $data, '已收款但需要人工入账：' . $message);
        epay_secure_release_lock($lockName);
        exit('success');
    }

    if ($invoiceFee === null) {
        $invoiceFee = '0.00';
    }

    // IMPORTANT: callback money is the EPay processing-currency amount and is used only for provider validation.
    // WHMCS receives the frozen invoice-currency amount from the Payment Attempt snapshot.
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $invoiceAmount,
        $invoiceFee,
        $gatewayModuleName
    );

    Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
        'status' => 'paid',
        'provider_trade_no' => $transactionId,
        'callback_payload' => epay_secure_json_payload($data),
        'paid_at' => date('Y-m-d H:i:s'),
        'last_seen_at' => date('Y-m-d H:i:s'),
        'last_error' => null,
    ));

    logTransaction($gatewayParams['name'], $data, '成功');
    epay_secure_release_lock($lockName);
    exit('success');
} catch (Throwable $e) {
    epay_secure_release_lock($lockName);
    try {
        if ($attempt) {
            Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
                'last_error' => substr($e->getMessage(), 0, 255),
                'last_seen_at' => date('Y-m-d H:i:s'),
                'callback_payload' => epay_secure_json_payload($data),
            ));
        }
    } catch (Throwable $ignored) {
    }

    logTransaction($gatewayParams['name'], $data, '回调失败: ' . $e->getMessage());
    http_response_code(400);
    exit('fail');
}
