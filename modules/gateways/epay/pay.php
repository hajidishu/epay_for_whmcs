<?php

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/qr.php';

use WHMCS\Config\Setting;
use WHMCS\Database\Capsule;

function epay_pay_error($message, $invoiceId, $systemUrl, $httpCode)
{
    http_response_code((int) $httpCode);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    $back = rtrim($systemUrl, '/') . '/viewinvoice.php?id=' . (int) $invoiceId;
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>支付请求失败</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:720px;margin:60px auto;padding:0 20px;line-height:1.65}.box{border:1px solid #ddd;border-radius:12px;padding:24px}a{display:inline-block;margin-top:16px}</style></head><body><div class="box">'
        . '<h2>无法发起支付</h2><p>' . epay_secure_html($message) . '</p>'
        . '<a href="' . epay_secure_html($back) . '">返回发票并刷新后重试</a>'
        . '</div></body></html>';
    exit;
}

function epay_pay_render_mapi_result(array $response, $safeReturnUrl, $allowHttp)
{
    foreach (array('payurl', 'url') as $field) {
        if (!empty($response[$field]) && epay_secure_is_safe_web_url($response[$field], $allowHttp)) {
            header('Location: ' . (string) $response[$field], true, 303);
            exit;
        }
    }

    $qrPayload = null;
    if (!empty($response['qrcode'])) {
        $qrPayload = epay_secure_qr_payload($response['qrcode']);
    }

    $urlScheme = null;
    if (!empty($response['urlscheme']) && epay_secure_is_safe_app_scheme($response['urlscheme'])) {
        $urlScheme = (string) $response['urlscheme'];
    }

    if ($qrPayload === null && $urlScheme === null) {
        throw new RuntimeException('支付平台下单成功，但没有返回可用的 payurl、qrcode 或 urlscheme。建议切换为 submit.php 模式。');
    }

    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>继续支付</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#111}.card{max-width:560px;margin:40px auto;padding:28px;background:#fff;border:1px solid #e4e7eb;border-radius:14px;text-align:center}.qr{display:inline-block;padding:14px;background:#fff;border:1px solid #ddd;border-radius:10px}.btn{display:inline-block;margin:16px 6px 0;padding:11px 18px;border-radius:8px;text-decoration:none;background:#111;color:#fff}.back{display:inline-block;margin-top:20px;color:#333}p{line-height:1.65}</style></head><body><div class="card">';

    if ($qrPayload !== null) {
        echo '<h2>请扫码完成支付</h2><p>此二维码由本插件在本机根据支付平台返回的 qrcode 内容生成，不调用第三方二维码服务。</p>'
            . '<div class="qr">' . epay_qr_svg($qrPayload, 320) . '</div>';
    } else {
        echo '<h2>请在支付客户端中继续</h2>';
    }

    if ($urlScheme !== null) {
        echo '<p><a class="btn" rel="noreferrer" href="' . epay_secure_html($urlScheme) . '">打开支付客户端</a></p>';
    }

    echo '<p><a class="back" href="' . epay_secure_html($safeReturnUrl) . '">返回发票</a></p>'
        . '</div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

$gatewayModuleName = 'epay';
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('Module Not Activated');
}

$systemUrl = rtrim((string) Setting::getValue('SystemURL'), '/');
$invoiceId = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
$mapiLockName = null;

try {
    $invoiceAmount = epay_secure_normalize_money(isset($_POST['invoice_amount']) ? $_POST['invoice_amount'] : '');
    $invoiceCurrency = epay_secure_currency_code(isset($_POST['invoice_currency']) ? $_POST['invoice_currency'] : '');
    $processingAmount = epay_secure_normalize_money(isset($_POST['processing_amount']) ? $_POST['processing_amount'] : '');
    $processingCurrency = epay_secure_currency_code(isset($_POST['processing_currency']) ? $_POST['processing_currency'] : '');
    $timestamp = isset($_POST['ts']) ? (int) $_POST['ts'] : 0;
    $returnUrl = isset($_POST['return_url']) ? (string) $_POST['return_url'] : '';
    $description = isset($_POST['description']) ? (string) $_POST['description'] : '';
    $receivedToken = isset($_POST['token']) ? (string) $_POST['token'] : '';

    if ($invoiceId <= 0 || epay_secure_money_to_cents($invoiceAmount) <= 0 || epay_secure_money_to_cents($processingAmount) <= 0) {
        throw new RuntimeException('支付请求参数不完整。');
    }

    if (!epay_secure_verify_launch_token(
        $invoiceId,
        $invoiceAmount,
        $invoiceCurrency,
        $processingAmount,
        $processingCurrency,
        $timestamp,
        $returnUrl,
        $description,
        $gatewayParams['key'],
        3600,
        $receivedToken
    )) {
        throw new RuntimeException('支付链接已过期或校验失败，请刷新发票页面后重新点击支付。');
    }

    $invoice = epay_secure_get_invoice_snapshot($invoiceId);
    if ($invoice['status'] !== 'Unpaid') {
        throw new RuntimeException('该发票当前状态不是 Unpaid，无需或无法继续支付。');
    }
    if ($invoice['paymentmethod'] !== '' && $invoice['paymentmethod'] !== $gatewayModuleName) {
        throw new RuntimeException('该发票当前已选择其他支付方式，请刷新发票页面。');
    }

    $sessionUserId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
    if ($sessionUserId > 0 && $invoice['user_id'] !== $sessionUserId) {
        throw new RuntimeException('当前登录账户无权支付此发票。');
    }

    // The invoice-side snapshot is authoritative for WHMCS accounting and must still be current.
    if (epay_secure_money_to_cents($invoice['balance']) !== epay_secure_money_to_cents($invoiceAmount)
        || $invoice['currency'] !== $invoiceCurrency) {
        throw new RuntimeException('发票余额或原币种已经发生变化，请刷新发票页面后重新支付。');
    }

    // A signed form can remain valid for up to one hour, so never rely only on the
    // hidden/disabled button in epay_link(). Re-check paid_review on every launch.
    epay_secure_assert_invoice_not_under_review($invoiceId);

    $baseUrl = epay_secure_gateway_base_url($gatewayParams['gatewayUrl'], !empty($gatewayParams['allowHttp']));
    $paymentType = epay_secure_payment_type($gatewayParams['payType']);
    $lifetimeHours = isset($gatewayParams['orderLifetimeHours']) ? (int) $gatewayParams['orderLifetimeHours'] : 360;
    if ($lifetimeHours <= 0) {
        $lifetimeHours = 360;
    }
    $feeRate = isset($gatewayParams['handlingFee']) ? (float) $gatewayParams['handlingFee'] : 0.0;

    $attempt = epay_secure_get_or_create_attempt(
        $invoiceId,
        $invoiceAmount,
        $invoiceCurrency,
        $processingAmount,
        $processingCurrency,
        $paymentType,
        $lifetimeHours,
        epay_secure_client_ip(),
        $feeRate
    );

    // Narrow the race between Attempt creation/reuse and a concurrent legacy/new
    // callback entering paid_review. This protects submit mode too, before the browser
    // receives a form that could create another provider-side payment flow.
    epay_secure_assert_invoice_not_under_review($invoiceId);

    $notifyUrl = $systemUrl . '/modules/gateways/callback/epay.php';
    $safeReturnUrl = epay_secure_validate_return_url($returnUrl, $systemUrl, $invoiceId);
    $requestMode = isset($gatewayParams['requestMode']) ? (string) $gatewayParams['requestMode'] : 'submit';

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    if ($requestMode === 'mapi') {
        $artifactTtlMinutes = isset($gatewayParams['mapiArtifactTtlMinutes'])
            ? (int) $gatewayParams['mapiArtifactTtlMinutes']
            : 5;
        $artifactTtlMinutes = max(1, min(1440, $artifactTtlMinutes));

        // Serialize the whole remote-order/artifact refresh section for this out_trade_no.
        // After acquiring the advisory lock, re-read the Attempt because another request
        // may already have created/refreshed the provider order while we were waiting.
        $mapiLockName = epay_secure_acquire_lock('mapi-create:' . (string) $attempt->out_trade_no, 25);
        $attempt = Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->first();
        if (!$attempt) {
            throw new RuntimeException('Payment Attempt no longer exists.');
        }

        // A paid_review may have arrived from an older payment while this browser request
        // was waiting for the MAPI lock. Check again immediately before any remote call.
        epay_secure_assert_invoice_not_under_review($invoiceId);

        $storedResponse = null;
        $hadSuccessfulStoredResponse = false;
        if (!empty($attempt->provider_response)) {
            $storedResponse = json_decode((string) $attempt->provider_response, true);
            $hadSuccessfulStoredResponse = is_array($storedResponse)
                && (int) (isset($storedResponse['code']) ? $storedResponse['code'] : 0) === 1;
        }

        // Cache only the payment artifact (payurl/qrcode/urlscheme) for its own short TTL.
        // The provider order itself may live for 15 days, but its entry token may not.
        if ($hadSuccessfulStoredResponse
            && epay_secure_provider_artifact_is_fresh($attempt, $artifactTtlMinutes)) {
            epay_secure_release_lock($mapiLockName);
            $mapiLockName = null;
            epay_pay_render_mapi_result($storedResponse, $safeReturnUrl, !empty($gatewayParams['allowHttp']));
        }

        $orderParams = epay_secure_build_order_params(
            $gatewayParams,
            $attempt,
            $notifyUrl,
            $safeReturnUrl,
            $description,
            true
        );

        $responseBody = epay_secure_post_form(
            $baseUrl . '/mapi.php',
            $orderParams,
            empty($gatewayParams['disableTlsVerify'])
        );
        $response = json_decode($responseBody, true);
        if (!is_array($response)) {
            Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
                'last_error' => 'Invalid JSON response from mapi.php while creating/refreshing payment artifact.',
                'last_seen_at' => date('Y-m-d H:i:s'),
            ));
            throw new RuntimeException('支付平台返回了无法识别的数据，请稍后重试。');
        }

        $providerAccepted = (int) (isset($response['code']) ? $response['code'] : 0) === 1;
        $now = date('Y-m-d H:i:s');

        if (!$providerAccepted) {
            // Never overwrite a previously successful payment artifact with a later
            // duplicate-order/error response. If the old artifact has expired and this
            // provider cannot refresh it using the same out_trade_no, fail safely rather
            // than showing a stale token or creating a second merchant order number.
            $providerMessage = (string) (isset($response['msg']) ? $response['msg'] : 'Unknown EPay error');
            Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
                'last_error' => substr($providerMessage, 0, 255),
                'last_seen_at' => $now,
            ));

            if ($hadSuccessfulStoredResponse) {
                throw new RuntimeException(
                    '原支付入口已超过缓存有效期，但支付平台不支持在同一订单号下刷新入口。'
                    . '为避免展示失效二维码或重复创建订单，请联系管理员，建议改用 submit.php 模式。'
                );
            }

            throw new RuntimeException('支付平台拒绝下单：' . $providerMessage);
        }

        $newProviderTradeNo = !empty($response['trade_no']) ? (string) $response['trade_no'] : '';
        if (!empty($attempt->provider_trade_no) && $newProviderTradeNo !== ''
            && !hash_equals((string) $attempt->provider_trade_no, $newProviderTradeNo)) {
            throw new RuntimeException('支付平台在刷新同一订单时返回了不同的 trade_no，已拒绝覆盖原订单映射。');
        }

        $attemptUpdate = array(
            'provider_trade_no' => $newProviderTradeNo !== '' ? $newProviderTradeNo : $attempt->provider_trade_no,
            'provider_response' => substr($responseBody, 0, 65535),
            'provider_response_at' => $now,
            'last_error' => null,
            'last_seen_at' => $now,
        );
        if (!$hadSuccessfulStoredResponse) {
            // Only the FIRST provider acceptance anchors the provider-order lifetime.
            // Refreshing a short-lived QR/payurl token must never extend a 15-day order.
            $attemptUpdate['expires_at'] = date('Y-m-d H:i:s', time() + ($lifetimeHours * 3600));
        }
        Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update($attemptUpdate);

        epay_secure_release_lock($mapiLockName);
        $mapiLockName = null;
        epay_pay_render_mapi_result($response, $safeReturnUrl, !empty($gatewayParams['allowHttp']));
    }

    if ($requestMode !== 'submit') {
        throw new RuntimeException('未知的 EPay 下单方式，请在网关设置中选择 submit 或 mapi。');
    }

    $orderParams = epay_secure_build_order_params(
        $gatewayParams,
        $attempt,
        $notifyUrl,
        $safeReturnUrl,
        $description,
        false
    );
    $submitUrl = $baseUrl . '/submit.php';

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>正在跳转到支付平台</title><style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;text-align:center;padding:60px 20px}button{font-size:16px;padding:10px 22px}</style></head><body>'
        . '<p>正在跳转到支付平台，请勿关闭此页面。</p>'
        . '<form id="epay-submit" method="post" action="' . epay_secure_html($submitUrl) . '">';
    foreach ($orderParams as $name => $value) {
        echo '<input type="hidden" name="' . epay_secure_html($name) . '" value="' . epay_secure_html($value) . '">';
    }
    echo '<button type="submit">继续支付</button></form>'
        . '<script>document.getElementById("epay-submit").submit();</script>'
        . '</body></html>';
    exit;
} catch (Throwable $e) {
    epay_secure_release_lock($mapiLockName);
    try {
        if ($invoiceId > 0) {
            logTransaction('EPay Secure', array('invoice_id' => $invoiceId), '发起支付失败: ' . $e->getMessage());
        }
    } catch (Throwable $ignored) {
    }
    epay_pay_error($e->getMessage(), $invoiceId, $systemUrl, 400);
}
