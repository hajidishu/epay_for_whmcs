<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/epay/lib.php';

function epay_MetaData()
{
    return array(
        'DisplayName' => 'EPay Secure',
        'APIVersion' => '1.1',
    );
}

function epay_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'EPay Secure',
        ),
        'pid' => array(
            'Type' => 'text',
            'FriendlyName' => '商户 ID',
            'Description' => 'EPay 商户 ID / PID',
        ),
        'key' => array(
            'Type' => 'password',
            'FriendlyName' => '商户密钥',
            'Description' => 'EPay MD5 签名密钥',
        ),
        'gatewayUrl' => array(
            'Type' => 'text',
            'FriendlyName' => '支付网关地址',
            'Default' => 'https://pay.example.com',
            'Description' => '填写站点根地址，不要附加 submit.php 或 mapi.php',
        ),
        'payType' => array(
            'Type' => 'text',
            'FriendlyName' => '支付方式代码',
            'Default' => 'alipay',
            'Description' => '例如 alipay、wxpay；以你的 EPay 文档为准',
        ),
        'requestMode' => array(
            'Type' => 'dropdown',
            'FriendlyName' => '下单方式',
            'Options' => array(
                'submit' => 'submit.php 浏览器跳转（推荐生产）',
                'mapi' => 'mapi.php 服务端下单（支持 payurl/qrcode/urlscheme）',
            ),
            'Default' => 'submit',
            'Description' => '推荐 submit.php；mapi.php 仅在客户点击后创建远端订单，并对返回字段分别处理',
        ),
        'mapiArtifactTtlMinutes' => array(
            'Type' => 'text',
            'FriendlyName' => 'MAPI 支付入口缓存时间（分钟）',
            'Default' => '5',
            'Description' => '仅 mapi 模式。payurl/qrcode/urlscheme 超过此时间后会在同一 out_trade_no 下尝试刷新；平台不支持刷新时会安全报错而不会创建新订单号。',
        ),
        'orderLifetimeHours' => array(
            'Type' => 'text',
            'FriendlyName' => '支付订单有效期（小时）',
            'Default' => '360',
            'Description' => '支付商为 15 天时填写 360。过期后插件才会生成新的 out_trade_no',
        ),
        'handlingFee' => array(
            'Type' => 'text',
            'FriendlyName' => 'WHMCS 账务手续费率 (%)',
            'Default' => '0',
            'Description' => '按发票原币种金额计算并在创建 Payment Attempt 时冻结；不会加到客户应付金额',
        ),
        'verifyOrderQuery' => array(
            'Type' => 'yesno',
            'FriendlyName' => '回调后二次查询订单',
            'Description' => '可选增强：收到成功回调后再调用经典 V1 /api.php?act=order 核对订单。仅在你的 EPay 明确兼容该查询接口时开启。',
        ),
        'allowHttp' => array(
            'Type' => 'yesno',
            'FriendlyName' => '允许 HTTP 网关',
            'Description' => '仅测试环境使用。生产环境请保持关闭并使用 HTTPS',
        ),
        'disableTlsVerify' => array(
            'Type' => 'yesno',
            'FriendlyName' => '关闭 TLS 证书校验',
            'Description' => '仅 mapi 调试使用；生产环境不要开启',
        ),
    );
}

function epay_link($params)
{
    try {
        $invoiceId = (int) $params['invoiceid'];
        $processingAmount = epay_secure_normalize_money($params['amount']);
        if (epay_secure_money_to_cents($processingAmount) <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }
        $processingCurrency = epay_secure_currency_code($params['currency']);
        $description = (string) $params['description'];
        $returnUrl = (string) $params['returnurl'];
        $timestamp = time();
        $key = (string) $params['key'];

        if ($invoiceId <= 0 || $key === '') {
            throw new RuntimeException('EPay gateway is not fully configured.');
        }

        // Validate gateway configuration, but never contact EPay from epay_link().
        epay_secure_gateway_base_url($params['gatewayUrl'], !empty($params['allowHttp']));
        epay_secure_payment_type($params['payType']);
        epay_secure_initialize_cutover($params);

        // Front-end guard: provider-confirmed payments awaiting manual accounting review
        // must not present another Pay button. pay.php/get_or_create_attempt repeats the
        // same check server-side because an older signed form can remain valid for 1 hour.
        if (epay_secure_invoice_has_unresolved_review($invoiceId)) {
            return '<div class="alert alert-warning">此发票存在已确认待人工核账的支付，或仍在隔离期内且可能被支付的旧订单。为防止重复扣款，暂时禁止再次付款，请联系管理员。</div>';
        }

        // WHMCS may have converted $params['amount']/currency for gateway processing.
        // Snapshot the invoice's own current balance/currency separately so the two ledgers never mix.
        $invoice = epay_secure_get_invoice_snapshot($invoiceId);
        if ($invoice['status'] !== 'Unpaid') {
            throw new RuntimeException('Invoice is not Unpaid.');
        }
        if ($invoice['paymentmethod'] !== '' && $invoice['paymentmethod'] !== 'epay') {
            throw new RuntimeException('Invoice payment method is no longer EPay.');
        }
        $invoiceAmount = $invoice['balance'];
        $invoiceCurrency = $invoice['currency'];
        if (epay_secure_money_to_cents($invoiceAmount) <= 0) {
            throw new RuntimeException('Invoice balance must be greater than zero.');
        }

        $token = epay_secure_launch_token(
            $invoiceId,
            $invoiceAmount,
            $invoiceCurrency,
            $processingAmount,
            $processingCurrency,
            $timestamp,
            $returnUrl,
            $description,
            $key
        );

        $action = rtrim((string) $params['systemurl'], '/') . '/modules/gateways/epay/pay.php';
        $button = !empty($params['langpaynow']) ? (string) $params['langpaynow'] : '立即支付';

        return '<form method="post" action="' . epay_secure_html($action) . '">'
            . '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">'
            . '<input type="hidden" name="invoice_amount" value="' . epay_secure_html($invoiceAmount) . '">'
            . '<input type="hidden" name="invoice_currency" value="' . epay_secure_html($invoiceCurrency) . '">'
            . '<input type="hidden" name="processing_amount" value="' . epay_secure_html($processingAmount) . '">'
            . '<input type="hidden" name="processing_currency" value="' . epay_secure_html($processingCurrency) . '">'
            . '<input type="hidden" name="ts" value="' . $timestamp . '">'
            . '<input type="hidden" name="return_url" value="' . epay_secure_html($returnUrl) . '">'
            . '<input type="hidden" name="description" value="' . epay_secure_html($description) . '">'
            . '<input type="hidden" name="token" value="' . epay_secure_html($token) . '">'
            . '<button type="submit" class="btn btn-primary">' . epay_secure_html($button) . '</button>'
            . '</form>';
    } catch (Throwable $e) {
        return '<div class="alert alert-danger">EPay 配置错误：' . epay_secure_html($e->getMessage()) . '</div>';
    }
}
