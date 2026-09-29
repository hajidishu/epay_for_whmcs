<?php

use WHMCS\Database\Capsule;

if (!function_exists('epay_secure_html')) {
    function epay_secure_html($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('epay_secure_normalize_money')) {
    function epay_secure_normalize_money($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException('Invalid payment amount.');
        }

        $parts = explode('.', $value, 2);
        $integer = ltrim($parts[0], '0');
        if ($integer === '') {
            $integer = '0';
        }
        $fraction = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';

        return $integer . '.' . substr($fraction, 0, 2);
    }
}

if (!function_exists('epay_secure_money_to_cents')) {
    function epay_secure_money_to_cents($value)
    {
        $normalized = epay_secure_normalize_money($value);
        list($whole, $fraction) = explode('.', $normalized, 2);
        return ((int) $whole * 100) + (int) $fraction;
    }
}

if (!function_exists('epay_secure_cents_to_money')) {
    function epay_secure_cents_to_money($cents)
    {
        $cents = (int) $cents;
        if ($cents < 0) {
            throw new RuntimeException('Negative money values are not supported.');
        }
        return floor($cents / 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('epay_secure_calculate_invoice_fee')) {
    function epay_secure_calculate_invoice_fee($invoiceAmount, $feeRate)
    {
        $amountCents = epay_secure_money_to_cents($invoiceAmount);
        $feeRate = (float) $feeRate;
        if ($feeRate <= 0) {
            return '0.00';
        }
        if ($feeRate > 100) {
            throw new RuntimeException('Handling fee rate is invalid.');
        }
        $feeCents = (int) round(($amountCents * $feeRate) / 100, 0, PHP_ROUND_HALF_UP);
        return epay_secure_cents_to_money($feeCents);
    }
}

if (!function_exists('epay_secure_effective_rate')) {
    function epay_secure_effective_rate($invoiceAmount, $processingAmount)
    {
        $invoiceCents = epay_secure_money_to_cents($invoiceAmount);
        $processingCents = epay_secure_money_to_cents($processingAmount);
        if ($invoiceCents <= 0) {
            return null;
        }
        return number_format($processingCents / $invoiceCents, 12, '.', '');
    }
}

if (!function_exists('epay_secure_sign')) {
    function epay_secure_sign(array $params, $key)
    {
        ksort($params, SORT_STRING);
        $parts = array();

        foreach ($params as $name => $value) {
            if ($name === 'sign' || $name === 'sign_type') {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $value = (string) $value;
            if ($value === '') {
                continue;
            }
            $parts[] = $name . '=' . $value;
        }

        return md5(implode('&', $parts) . (string) $key);
    }
}

if (!function_exists('epay_secure_launch_token')) {
    function epay_secure_launch_token(
        $invoiceId,
        $invoiceAmount,
        $invoiceCurrency,
        $processingAmount,
        $processingCurrency,
        $timestamp,
        $returnUrl,
        $description,
        $key
    ) {
        $payload = implode("\n", array(
            (string) (int) $invoiceId,
            epay_secure_normalize_money($invoiceAmount),
            strtoupper(trim((string) $invoiceCurrency)),
            epay_secure_normalize_money($processingAmount),
            strtoupper(trim((string) $processingCurrency)),
            (string) (int) $timestamp,
            (string) $returnUrl,
            (string) $description,
        ));

        return hash_hmac('sha256', $payload, (string) $key);
    }
}

if (!function_exists('epay_secure_verify_launch_token')) {
    function epay_secure_verify_launch_token(
        $invoiceId,
        $invoiceAmount,
        $invoiceCurrency,
        $processingAmount,
        $processingCurrency,
        $timestamp,
        $returnUrl,
        $description,
        $key,
        $ttlSeconds,
        $receivedToken
    ) {
        $timestamp = (int) $timestamp;
        $now = time();
        if ($timestamp <= 0 || $timestamp > ($now + 300) || ($now - $timestamp) > (int) $ttlSeconds) {
            return false;
        }

        $expected = epay_secure_launch_token(
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

        $receivedToken = (string) $receivedToken;
        return $receivedToken !== '' && hash_equals($expected, $receivedToken);
    }
}

if (!function_exists('epay_secure_gateway_base_url')) {
    function epay_secure_gateway_base_url($url, $allowHttp)
    {
        $url = trim((string) $url);
        if ($url === '') {
            throw new RuntimeException('Payment gateway URL is not configured.');
        }

        $url = preg_replace('~/(?:submit|mapi|api)\.php/?$~i', '', rtrim($url, '/'));
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Payment gateway URL is invalid.');
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Payment gateway URL must be a clean base URL without credentials, query strings, or fragments.');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
            throw new RuntimeException('Payment gateway URL must use HTTPS.');
        }

        return rtrim($url, '/');
    }
}

if (!function_exists('epay_secure_payment_type')) {
    function epay_secure_payment_type($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^[A-Za-z0-9._-]{1,32}$/', $value)) {
            throw new RuntimeException('Invalid EPay payment type.');
        }
        return $value;
    }
}

if (!function_exists('epay_secure_currency_code')) {
    function epay_secure_currency_code($value)
    {
        $value = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z0-9]{2,16}$/', $value)) {
            throw new RuntimeException('Invalid currency code.');
        }
        return $value;
    }
}

if (!function_exists('epay_secure_truncate')) {
    function epay_secure_truncate($value, $bytes)
    {
        $value = trim((string) $value);
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, (int) $bytes, 'UTF-8');
        }
        return substr($value, 0, (int) $bytes);
    }
}

if (!function_exists('epay_secure_get_invoice_snapshot')) {
    function epay_secure_get_invoice_snapshot($invoiceId)
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            throw new RuntimeException('Invalid invoice ID.');
        }

        $invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
        if (!is_array($invoice) || !isset($invoice['result']) || $invoice['result'] !== 'success') {
            throw new RuntimeException('WHMCS invoice was not found.');
        }

        $userId = isset($invoice['userid']) ? (int) $invoice['userid'] : 0;
        if ($userId <= 0) {
            throw new RuntimeException('Invoice owner is invalid.');
        }

        $currencyId = Capsule::table('tblclients')->where('id', $userId)->value('currency');
        $currencyCode = $currencyId ? Capsule::table('tblcurrencies')->where('id', (int) $currencyId)->value('code') : null;
        if (!$currencyCode) {
            throw new RuntimeException('Unable to resolve invoice currency.');
        }

        return array(
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'status' => isset($invoice['status']) ? (string) $invoice['status'] : '',
            'paymentmethod' => isset($invoice['paymentmethod']) ? (string) $invoice['paymentmethod'] : '',
            'balance' => epay_secure_normalize_money(isset($invoice['balance']) ? $invoice['balance'] : ''),
            'currency' => epay_secure_currency_code($currencyCode),
        );
    }
}

if (!function_exists('epay_secure_schema_add_column')) {
    function epay_secure_schema_add_column($tableName, $columnName, $callback)
    {
        $schema = Capsule::schema();
        if (!$schema->hasColumn($tableName, $columnName)) {
            $schema->table($tableName, $callback);
        }
    }
}

if (!function_exists('epay_secure_ensure_schema')) {
    function epay_secure_ensure_schema()
    {
        $schema = Capsule::schema();
        $attemptsExisted = $schema->hasTable('mod_epay_attempts');

        if (!$attemptsExisted) {
            $schema->create('mod_epay_attempts', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('invoice_id');
                $table->string('out_trade_no', 64)->unique();
                $table->string('provider_trade_no', 128)->nullable()->index();
                $table->decimal('invoice_amount', 18, 2)->nullable();
                $table->string('invoice_currency', 16)->nullable();
                $table->decimal('processing_amount', 18, 2)->nullable();
                $table->string('processing_currency', 16)->nullable();
                $table->decimal('conversion_rate', 28, 12)->nullable();
                $table->decimal('invoice_fee', 18, 2)->nullable();
                $table->string('payment_type', 32);
                $table->string('source', 16)->default('v22');
                $table->string('status', 32)->default('pending');
                $table->unsignedInteger('launch_count')->default(1);
                $table->string('client_ip', 45)->nullable();
                $table->text('provider_response')->nullable();
                $table->dateTime('provider_response_at')->nullable();
                $table->text('callback_payload')->nullable();
                $table->string('last_error', 255)->nullable();
                $table->dateTime('created_at');
                $table->dateTime('expires_at');
                $table->dateTime('last_seen_at')->nullable();
                $table->dateTime('paid_at')->nullable();

                $table->index(array('invoice_id', 'status'), 'epay_invoice_status_idx');
                $table->index('expires_at', 'epay_expires_idx');
            });
        } else {
            // v2.0/v2.1 -> v2.2 in-place schema migration. Old amount/currency columns are kept for rollback safety.
            epay_secure_schema_add_column('mod_epay_attempts', 'invoice_amount', function ($table) {
                $table->decimal('invoice_amount', 18, 2)->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'invoice_currency', function ($table) {
                $table->string('invoice_currency', 16)->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'processing_amount', function ($table) {
                $table->decimal('processing_amount', 18, 2)->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'processing_currency', function ($table) {
                $table->string('processing_currency', 16)->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'conversion_rate', function ($table) {
                $table->decimal('conversion_rate', 28, 12)->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'invoice_fee', function ($table) {
                $table->decimal('invoice_fee', 18, 2)->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'source', function ($table) {
                $table->string('source', 16)->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'callback_payload', function ($table) {
                $table->text('callback_payload')->nullable();
            });
            epay_secure_schema_add_column('mod_epay_attempts', 'provider_response_at', function ($table) {
                $table->dateTime('provider_response_at')->nullable();
            });

            if ($schema->hasColumn('mod_epay_attempts', 'amount')) {
                Capsule::statement('UPDATE `mod_epay_attempts` SET `processing_amount` = `amount` WHERE `processing_amount` IS NULL');
            }
            if ($schema->hasColumn('mod_epay_attempts', 'currency')) {
                Capsule::statement('UPDATE `mod_epay_attempts` SET `processing_currency` = `currency` WHERE `processing_currency` IS NULL');
            }
            Capsule::table('mod_epay_attempts')->whereNull('source')->update(array('source' => 'v20'));
        }

        if (!$schema->hasTable('mod_epay_meta')) {
            $schema->create('mod_epay_meta', function ($table) {
                $table->string('meta_key', 64)->primary();
                $table->text('meta_value')->nullable();
                $table->dateTime('updated_at');
            });
        }

        if (!$schema->hasTable('mod_epay_legacy_events')) {
            $schema->create('mod_epay_legacy_events', function ($table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('invoice_id');
                $table->string('out_trade_no', 64);
                $table->string('provider_trade_no', 128)->unique();
                $table->string('payment_type', 32)->nullable();
                $table->decimal('processing_amount', 18, 2);
                $table->string('processing_currency', 16)->nullable();
                $table->string('status', 32)->default('paid_review');
                $table->text('callback_payload')->nullable();
                $table->string('note', 255)->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->index('invoice_id', 'epay_legacy_invoice_idx');
            });
        }

        epay_secure_set_meta_if_missing('schema_version', '2.2');
        Capsule::table('mod_epay_meta')->where('meta_key', 'schema_version')->update(array(
            'meta_value' => '2.2',
            'updated_at' => date('Y-m-d H:i:s'),
        ));

        $cutoverAt = epay_secure_get_meta('legacy_cutover_at', '');
        if ($cutoverAt === '') {
            $cutoverAt = date('Y-m-d H:i:s');
            epay_secure_set_meta_if_missing('legacy_cutover_at', $cutoverAt);
        }
        $cutoverTimestamp = strtotime($cutoverAt);
        if ($cutoverTimestamp === false) {
            $cutoverTimestamp = time();
        }
        epay_secure_set_meta_if_missing('legacy_accept_until', date('Y-m-d H:i:s', $cutoverTimestamp + (17 * 86400)));
    }
}

if (!function_exists('epay_secure_set_meta_if_missing')) {
    function epay_secure_set_meta_if_missing($key, $value)
    {
        if (!Capsule::schema()->hasTable('mod_epay_meta')) {
            return;
        }
        if (!Capsule::table('mod_epay_meta')->where('meta_key', (string) $key)->exists()) {
            try {
                Capsule::table('mod_epay_meta')->insert(array(
                    'meta_key' => (string) $key,
                    'meta_value' => (string) $value,
                    'updated_at' => date('Y-m-d H:i:s'),
                ));
            } catch (Throwable $e) {
                // Another request may have initialized it concurrently.
            }
        }
    }
}

if (!function_exists('epay_secure_get_meta')) {
    function epay_secure_get_meta($key, $defaultValue)
    {
        if (!Capsule::schema()->hasTable('mod_epay_meta')) {
            return $defaultValue;
        }
        $value = Capsule::table('mod_epay_meta')->where('meta_key', (string) $key)->value('meta_value');
        return $value === null ? $defaultValue : (string) $value;
    }
}

if (!function_exists('epay_secure_legacy_accept_until')) {
    function epay_secure_legacy_accept_until()
    {
        epay_secure_ensure_schema();
        return epay_secure_get_meta('legacy_accept_until', '');
    }
}

if (!function_exists('epay_secure_legacy_is_open')) {
    function epay_secure_legacy_is_open()
    {
        $until = epay_secure_legacy_accept_until();
        $timestamp = $until !== '' ? strtotime($until) : false;
        return $timestamp !== false && time() <= $timestamp;
    }
}

if (!function_exists('epay_secure_resolve_convert_to_id')) {
    function epay_secure_resolve_convert_to_id(array $gatewayParams)
    {
        $convertTo = isset($gatewayParams['convertto']) ? (int) $gatewayParams['convertto'] : 0;
        if ($convertTo > 0) {
            return $convertTo;
        }

        // Do not rely exclusively on the runtime params for this standard WHMCS setting.
        // Some callback contexts can expose a narrower parameter set than _link().
        $gatewayName = isset($gatewayParams['paymentmethod']) && $gatewayParams['paymentmethod'] !== ''
            ? (string) $gatewayParams['paymentmethod']
            : 'epay';
        try {
            $stored = Capsule::table('tblpaymentgateways')
                ->where('gateway', $gatewayName)
                ->where('setting', 'convertto')
                ->value('value');
            if ($stored !== null && (int) $stored > 0) {
                return (int) $stored;
            }
        } catch (Throwable $e) {
            // The caller can safely treat this as conversion disabled/unresolved.
        }

        return 0;
    }
}

if (!function_exists('epay_secure_initialize_cutover')) {
    function epay_secure_initialize_cutover(array $gatewayParams)
    {
        // v2.2 deliberately does NOT infer historical legacy processing currency from
        // the gateway's current Convert To For Processing setting. Old numeric-order
        // callbacks lack a trustworthy historical amount/currency snapshot, so every
        // newly received legacy success is routed to paid_review instead of auto-posted.
        epay_secure_ensure_schema();
    }
}

if (!function_exists('epay_secure_invoice_has_unresolved_review')) {
    function epay_secure_invoice_has_unresolved_review($invoiceId)
    {
        epay_secure_ensure_schema();
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_epay_attempts')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'blocked_review')
            ->where('expires_at', '<=', $now)
            ->update(array(
                'status' => 'expired',
                'last_seen_at' => $now,
            ));

        if (Capsule::table('mod_epay_attempts')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'paid_review')
            ->exists()) {
            return true;
        }

        if (Capsule::table('mod_epay_attempts')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'blocked_review')
            ->where('expires_at', '>', $now)
            ->exists()) {
            return true;
        }

        return Capsule::table('mod_epay_legacy_events')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'paid_review')
            ->exists();
    }
}

if (!function_exists('epay_secure_assert_invoice_not_under_review')) {
    function epay_secure_assert_invoice_not_under_review($invoiceId)
    {
        if (epay_secure_invoice_has_unresolved_review($invoiceId)) {
            throw new RuntimeException('此发票存在已确认待人工核账的支付，或仍在隔离期内且可能被支付的旧订单。为防止重复扣款，暂时禁止再次付款，请联系管理员。');
        }
    }
}

if (!function_exists('epay_secure_provider_artifact_is_fresh')) {
    function epay_secure_provider_artifact_is_fresh($attempt, $ttlMinutes, $nowTimestamp = null)
    {
        if (!$attempt || empty($attempt->provider_response) || empty($attempt->provider_response_at)) {
            return false;
        }

        $response = json_decode((string) $attempt->provider_response, true);
        if (!is_array($response) || (int) (isset($response['code']) ? $response['code'] : 0) !== 1) {
            return false;
        }

        $createdAt = strtotime((string) $attempt->provider_response_at);
        if ($createdAt === false) {
            return false;
        }

        $ttlMinutes = max(1, min(1440, (int) $ttlMinutes));
        $nowTimestamp = $nowTimestamp === null ? time() : (int) $nowTimestamp;
        return $nowTimestamp <= ($createdAt + ($ttlMinutes * 60));
    }
}

if (!function_exists('epay_secure_client_ip')) {
    function epay_secure_client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '127.0.0.1';
    }
}

if (!function_exists('epay_secure_generate_trade_no')) {
    function epay_secure_generate_trade_no($invoiceId)
    {
        try {
            $random = strtoupper(bin2hex(random_bytes(5)));
        } catch (Throwable $e) {
            $random = strtoupper(substr(sha1(uniqid((string) mt_rand(), true)), 0, 10));
        }

        return 'WH' . (int) $invoiceId . date('YmdHis') . $random;
    }
}

if (!function_exists('epay_secure_get_or_create_attempt')) {
    function epay_secure_get_or_create_attempt(
        $invoiceId,
        $invoiceAmount,
        $invoiceCurrency,
        $processingAmount,
        $processingCurrency,
        $paymentType,
        $lifetimeHours,
        $clientIp,
        $feeRate
    ) {
        epay_secure_ensure_schema();

        $invoiceId = (int) $invoiceId;
        $invoiceAmount = epay_secure_normalize_money($invoiceAmount);
        $invoiceCurrency = epay_secure_currency_code($invoiceCurrency);
        $processingAmount = epay_secure_normalize_money($processingAmount);
        $processingCurrency = epay_secure_currency_code($processingCurrency);
        $paymentType = epay_secure_payment_type($paymentType);
        $hours = max(1, min(720, (int) $lifetimeHours));
        $invoiceFee = epay_secure_calculate_invoice_fee($invoiceAmount, $feeRate);
        $conversionRate = epay_secure_effective_rate($invoiceAmount, $processingAmount);
        $now = date('Y-m-d H:i:s');

        return Capsule::connection()->transaction(function () use (
            $invoiceId,
            $invoiceAmount,
            $invoiceCurrency,
            $processingAmount,
            $processingCurrency,
            $paymentType,
            $hours,
            $clientIp,
            $invoiceFee,
            $conversionRate,
            $now
        ) {
            $lockedInvoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if (!$lockedInvoice) {
                throw new RuntimeException('Invoice no longer exists.');
            }

            // Database-level duplicate-payment guard. A provider-confirmed payment that
            // still needs manual accounting review must block every new Payment Attempt.
            Capsule::table('mod_epay_attempts')
                ->where('invoice_id', $invoiceId)
                ->where('status', 'blocked_review')
                ->where('expires_at', '<=', $now)
                ->update(array(
                    'status' => 'expired',
                    'last_seen_at' => $now,
                ));

            if (Capsule::table('mod_epay_attempts')
                ->where('invoice_id', $invoiceId)
                ->where('status', 'paid_review')
                ->exists()
                || Capsule::table('mod_epay_attempts')
                    ->where('invoice_id', $invoiceId)
                    ->where('status', 'blocked_review')
                    ->where('expires_at', '>', $now)
                    ->exists()
                || Capsule::table('mod_epay_legacy_events')
                    ->where('invoice_id', $invoiceId)
                    ->where('status', 'paid_review')
                    ->exists()) {
                throw new RuntimeException('此发票已有已确认待核账支付，或仍可能被支付的隔离订单。为防止重复扣款，禁止创建新的支付订单。');
            }

            Capsule::table('mod_epay_attempts')
                ->where('invoice_id', $invoiceId)
                ->where('status', 'pending')
                ->where('expires_at', '<=', $now)
                ->update(array(
                    'status' => 'expired',
                    'last_seen_at' => $now,
                ));

            $active = Capsule::table('mod_epay_attempts')
                ->where('invoice_id', $invoiceId)
                ->where('status', 'pending')
                ->where('expires_at', '>', $now)
                ->orderBy('id', 'desc')
                ->first();

            if ($active) {
                $activeInvoiceAmount = isset($active->invoice_amount) && $active->invoice_amount !== null ? $active->invoice_amount : null;
                $activeInvoiceCurrency = isset($active->invoice_currency) ? strtoupper((string) $active->invoice_currency) : '';
                $activeProcessingAmount = isset($active->processing_amount) && $active->processing_amount !== null
                    ? $active->processing_amount
                    : (isset($active->amount) ? $active->amount : null);
                $activeProcessingCurrency = isset($active->processing_currency) && $active->processing_currency !== null
                    ? strtoupper((string) $active->processing_currency)
                    : (isset($active->currency) ? strtoupper((string) $active->currency) : '');

                // A v2.0 attempt did not store the invoice-side snapshot. If there was no
                // currency conversion, the processing amount is also the original invoice
                // amount and can be hydrated safely. Cross-currency v2.0 attempts remain
                // intentionally blocked from reuse because their historical source amount
                // cannot be reconstructed without guessing.
                $activeSource = isset($active->source) ? (string) $active->source : '';
                if ($activeInvoiceAmount === null && $activeSource === 'v20'
                    && $activeProcessingAmount !== null
                    && $activeProcessingCurrency === $invoiceCurrency
                    && $processingCurrency === $invoiceCurrency
                    && epay_secure_money_to_cents($activeProcessingAmount) === epay_secure_money_to_cents($invoiceAmount)
                    && epay_secure_money_to_cents($activeProcessingAmount) === epay_secure_money_to_cents($processingAmount)
                ) {
                    Capsule::table('mod_epay_attempts')->where('id', $active->id)->update(array(
                        'invoice_amount' => $invoiceAmount,
                        'invoice_currency' => $invoiceCurrency,
                        'processing_amount' => $processingAmount,
                        'processing_currency' => $processingCurrency,
                        'conversion_rate' => '1.000000000000',
                        // Historical v2.0 fee semantics were ambiguous; never invent one.
                        'invoice_fee' => '0.00',
                        'last_error' => 'Migrated v2.0 same-currency attempt; historical fee set to 0.00.',
                        'last_seen_at' => $now,
                    ));
                    $active = Capsule::table('mod_epay_attempts')->where('id', $active->id)->first();
                    $activeInvoiceAmount = $active->invoice_amount;
                    $activeInvoiceCurrency = strtoupper((string) $active->invoice_currency);
                    $activeProcessingAmount = $active->processing_amount;
                    $activeProcessingCurrency = strtoupper((string) $active->processing_currency);
                }

                $sameInvoiceAmount = $activeInvoiceAmount !== null
                    && epay_secure_money_to_cents($activeInvoiceAmount) === epay_secure_money_to_cents($invoiceAmount);
                $sameInvoiceCurrency = $activeInvoiceCurrency === $invoiceCurrency;
                $sameProcessingAmount = $activeProcessingAmount !== null
                    && epay_secure_money_to_cents($activeProcessingAmount) === epay_secure_money_to_cents($processingAmount);
                $sameProcessingCurrency = $activeProcessingCurrency === $processingCurrency;
                $sameType = (string) $active->payment_type === $paymentType;

                if (!$sameInvoiceAmount || !$sameInvoiceCurrency || !$sameProcessingAmount || !$sameProcessingCurrency || !$sameType) {
                    throw new RuntimeException(
                        'This invoice already has an active EPay order with different payment details. '
                        . 'Do not create a second provider order while the first can still be paid. '
                        . 'Wait for the existing provider order to expire or reconcile/cancel it before retrying.'
                    );
                }

                Capsule::table('mod_epay_attempts')->where('id', $active->id)->update(array(
                    'launch_count' => (int) $active->launch_count + 1,
                    'last_seen_at' => $now,
                    'client_ip' => $clientIp,
                ));

                return Capsule::table('mod_epay_attempts')->where('id', $active->id)->first();
            }

            $outTradeNo = epay_secure_generate_trade_no($invoiceId);
            while (Capsule::table('mod_epay_attempts')->where('out_trade_no', $outTradeNo)->exists()) {
                $outTradeNo = epay_secure_generate_trade_no($invoiceId);
            }

            $expiresAt = date('Y-m-d H:i:s', time() + ($hours * 3600));
            $id = Capsule::table('mod_epay_attempts')->insertGetId(array(
                'invoice_id' => $invoiceId,
                'out_trade_no' => $outTradeNo,
                'provider_trade_no' => null,
                'invoice_amount' => $invoiceAmount,
                'invoice_currency' => $invoiceCurrency,
                'processing_amount' => $processingAmount,
                'processing_currency' => $processingCurrency,
                'conversion_rate' => $conversionRate,
                'invoice_fee' => $invoiceFee,
                'payment_type' => $paymentType,
                'source' => 'v22',
                'status' => 'pending',
                'launch_count' => 1,
                'client_ip' => $clientIp,
                'provider_response' => null,
                'provider_response_at' => null,
                'callback_payload' => null,
                'last_error' => null,
                'created_at' => $now,
                'expires_at' => $expiresAt,
                'last_seen_at' => $now,
                'paid_at' => null,
            ));

            return Capsule::table('mod_epay_attempts')->where('id', $id)->first();
        });
    }
}

if (!function_exists('epay_secure_build_order_params')) {
    function epay_secure_build_order_params(array $gatewayParams, $attempt, $notifyUrl, $returnUrl, $description, $includeClientIp)
    {
        $params = array(
            'pid' => trim((string) $gatewayParams['pid']),
            'type' => epay_secure_payment_type($attempt->payment_type),
            'out_trade_no' => (string) $attempt->out_trade_no,
            'notify_url' => (string) $notifyUrl,
            'return_url' => (string) $returnUrl,
            'name' => epay_secure_truncate($description, 120),
            'money' => epay_secure_normalize_money($attempt->processing_amount),
            'param' => 'whmcs:' . (int) $attempt->invoice_id . ':' . (int) $attempt->id,
            'sign_type' => 'MD5',
        );

        if ($includeClientIp) {
            $params['clientip'] = epay_secure_client_ip();
        }

        $params['sign'] = epay_secure_sign($params, $gatewayParams['key']);
        return $params;
    }
}

if (!function_exists('epay_secure_post_form')) {
    function epay_secure_post_form($url, array $params, $verifyTls)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for mapi mode.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => (bool) $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ),
            CURLOPT_USERAGENT => 'WHMCS-EPay/2.2',
        ));

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('EPay request failed: ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('EPay returned HTTP ' . $httpCode . '.');
        }

        return (string) $body;
    }
}

if (!function_exists('epay_secure_query_order_v1')) {
    function epay_secure_query_order_v1(array $gatewayParams, $outTradeNo)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for provider order verification.');
        }

        $baseUrl = epay_secure_gateway_base_url($gatewayParams['gatewayUrl'], !empty($gatewayParams['allowHttp']));
        $params = array(
            'act' => 'order',
            'pid' => trim((string) $gatewayParams['pid']),
            'key' => (string) $gatewayParams['key'],
            'out_trade_no' => (string) $outTradeNo,
        );
        $url = $baseUrl . '/api.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $verifyTls = empty($gatewayParams['disableTlsVerify']);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => (bool) $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
            CURLOPT_USERAGENT => 'WHMCS-EPay/2.2',
        ));
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('EPay order query failed: ' . $error);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('EPay order query returned HTTP ' . $httpCode . '.');
        }

        $result = json_decode((string) $body, true);
        if (!is_array($result)) {
            throw new RuntimeException('EPay order query returned invalid JSON.');
        }
        return $result;
    }
}

if (!function_exists('epay_secure_verify_provider_order')) {
    function epay_secure_verify_provider_order(array $gatewayParams, $outTradeNo, $transactionId, $paymentType, $processingAmount)
    {
        if (empty($gatewayParams['verifyOrderQuery'])) {
            return;
        }

        $order = epay_secure_query_order_v1($gatewayParams, $outTradeNo);
        if ((int) (isset($order['code']) ? $order['code'] : 0) !== 1) {
            throw new RuntimeException('Provider order query did not return success.');
        }
        if (!isset($order['status']) || (int) $order['status'] !== 1) {
            throw new RuntimeException('Provider order query does not show a paid order.');
        }
        if (!isset($order['out_trade_no']) || !hash_equals((string) $outTradeNo, (string) $order['out_trade_no'])) {
            throw new RuntimeException('Provider order query out_trade_no mismatch.');
        }
        if (!isset($order['trade_no']) || !hash_equals((string) $transactionId, (string) $order['trade_no'])) {
            throw new RuntimeException('Provider order query trade_no mismatch.');
        }
        if (!isset($order['money']) || epay_secure_money_to_cents($order['money']) !== epay_secure_money_to_cents($processingAmount)) {
            throw new RuntimeException('Provider order query amount mismatch.');
        }
        if (isset($order['type']) && $order['type'] !== '' && (string) $order['type'] !== (string) $paymentType) {
            throw new RuntimeException('Provider order query payment type mismatch.');
        }
        if (isset($order['pid']) && $order['pid'] !== '' && (string) $order['pid'] !== (string) $gatewayParams['pid']) {
            throw new RuntimeException('Provider order query PID mismatch.');
        }
    }
}

if (!function_exists('epay_secure_is_safe_web_url')) {
    function epay_secure_is_safe_web_url($url, $allowHttp)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return $scheme === 'https' || ($scheme === 'http' && $allowHttp);
    }
}

if (!function_exists('epay_secure_is_safe_app_scheme')) {
    function epay_secure_is_safe_app_scheme($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, array('alipay', 'alipays', 'weixin'), true);
    }
}

if (!function_exists('epay_secure_qr_payload')) {
    function epay_secure_qr_payload($value)
    {
        $value = trim((string) $value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('Invalid QR code payload.');
        }
        return $value;
    }
}

if (!function_exists('epay_secure_validate_return_url')) {
    function epay_secure_validate_return_url($returnUrl, $systemUrl, $invoiceId)
    {
        $fallback = rtrim($systemUrl, '/') . '/viewinvoice.php?id=' . (int) $invoiceId;
        $returnParts = parse_url((string) $returnUrl);
        $systemParts = parse_url((string) $systemUrl);

        if (!$returnParts || !$systemParts || empty($returnParts['host']) || empty($systemParts['host'])) {
            return $fallback;
        }

        $returnScheme = strtolower(isset($returnParts['scheme']) ? (string) $returnParts['scheme'] : '');
        $systemScheme = strtolower(isset($systemParts['scheme']) ? (string) $systemParts['scheme'] : '');
        $returnPort = isset($returnParts['port']) ? (int) $returnParts['port'] : ($returnScheme === 'https' ? 443 : ($returnScheme === 'http' ? 80 : null));
        $systemPort = isset($systemParts['port']) ? (int) $systemParts['port'] : ($systemScheme === 'https' ? 443 : ($systemScheme === 'http' ? 80 : null));

        if ($returnScheme !== $systemScheme || strcasecmp($returnParts['host'], $systemParts['host']) !== 0 || $returnPort !== $systemPort) {
            return $fallback;
        }

        return (string) $returnUrl;
    }
}

if (!function_exists('epay_secure_get_request_data')) {
    function epay_secure_get_request_data()
    {
        $data = array_merge($_GET, $_POST);
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                unset($data[$key]);
            } else {
                $data[$key] = trim((string) $value);
            }
        }
        return $data;
    }
}

if (!function_exists('epay_secure_json_payload')) {
    function epay_secure_json_payload(array $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return null;
        }
        return substr($json, 0, 65535);
    }
}

if (!function_exists('epay_secure_attempt_processing_amount')) {
    function epay_secure_attempt_processing_amount($attempt)
    {
        if (isset($attempt->processing_amount) && $attempt->processing_amount !== null) {
            return epay_secure_normalize_money($attempt->processing_amount);
        }
        if (isset($attempt->amount) && $attempt->amount !== null) {
            return epay_secure_normalize_money($attempt->amount);
        }
        throw new RuntimeException('Attempt processing amount is missing.');
    }
}

if (!function_exists('epay_secure_attempt_processing_currency')) {
    function epay_secure_attempt_processing_currency($attempt)
    {
        if (isset($attempt->processing_currency) && $attempt->processing_currency !== null && $attempt->processing_currency !== '') {
            return epay_secure_currency_code($attempt->processing_currency);
        }
        if (isset($attempt->currency) && $attempt->currency !== null && $attempt->currency !== '') {
            return epay_secure_currency_code($attempt->currency);
        }
        throw new RuntimeException('Attempt processing currency is missing.');
    }
}

if (!function_exists('epay_secure_gateway_processing_currency')) {
    function epay_secure_gateway_processing_currency(array $gatewayParams, $invoiceCurrency)
    {
        $invoiceCurrency = epay_secure_currency_code($invoiceCurrency);
        $convertTo = epay_secure_resolve_convert_to_id($gatewayParams);
        if ($convertTo <= 0) {
            return $invoiceCurrency;
        }

        $code = Capsule::table('tblcurrencies')->where('id', $convertTo)->value('code');
        if (!$code) {
            throw new RuntimeException('Unable to resolve Convert To For Processing currency.');
        }
        return epay_secure_currency_code($code);
    }
}

if (!function_exists('epay_secure_record_legacy_review')) {
    function epay_secure_record_legacy_review($invoiceId, array $data, $processingCurrency, $note)
    {
        epay_secure_ensure_schema();
        $transactionId = (string) $data['trade_no'];
        $now = date('Y-m-d H:i:s');
        $existing = Capsule::table('mod_epay_legacy_events')->where('provider_trade_no', $transactionId)->first();
        $record = array(
            'invoice_id' => (int) $invoiceId,
            'out_trade_no' => (string) $data['out_trade_no'],
            'provider_trade_no' => $transactionId,
            'payment_type' => isset($data['type']) ? (string) $data['type'] : null,
            'processing_amount' => epay_secure_normalize_money($data['money']),
            'processing_currency' => $processingCurrency !== null ? epay_secure_currency_code($processingCurrency) : null,
            'status' => 'paid_review',
            'callback_payload' => epay_secure_json_payload($data),
            'note' => substr((string) $note, 0, 255),
            'updated_at' => $now,
        );
        if ($existing) {
            Capsule::table('mod_epay_legacy_events')->where('id', $existing->id)->update($record);
        } else {
            $record['created_at'] = $now;
            Capsule::table('mod_epay_legacy_events')->insert($record);
        }

        // A provider-confirmed legacy payment is now awaiting accounting review.
        // Quarantine any still-pending v2.x Attempt for the same invoice so a nearly
        // concurrent browser launch cannot keep reusing it after the review state exists.
        Capsule::table('mod_epay_attempts')
            ->where('invoice_id', (int) $invoiceId)
            ->where('status', 'pending')
            ->update(array(
                'status' => 'blocked_review',
                'last_error' => 'Blocked because another provider-confirmed payment for this invoice is awaiting manual review.',
                'last_seen_at' => $now,
            ));
    }
}

if (!function_exists('epay_secure_mark_attempt_review')) {
    function epay_secure_mark_attempt_review($attemptId, $transactionId, array $data, $message)
    {
        $attemptId = (int) $attemptId;
        $attempt = Capsule::table('mod_epay_attempts')->where('id', $attemptId)->first();
        if (!$attempt) {
            throw new RuntimeException('Payment Attempt no longer exists while entering review state.');
        }

        $now = date('Y-m-d H:i:s');
        Capsule::table('mod_epay_attempts')->where('id', $attemptId)->update(array(
            'status' => 'paid_review',
            'provider_trade_no' => (string) $transactionId,
            'callback_payload' => epay_secure_json_payload($data),
            'paid_at' => $now,
            'last_seen_at' => $now,
            'last_error' => substr((string) $message, 0, 255),
        ));

        Capsule::table('mod_epay_attempts')
            ->where('invoice_id', (int) $attempt->invoice_id)
            ->where('status', 'pending')
            ->where('id', '<>', $attemptId)
            ->update(array(
                'status' => 'blocked_review',
                'last_error' => 'Blocked because another provider-confirmed payment for this invoice is awaiting manual review.',
                'last_seen_at' => $now,
            ));
    }
}

if (!function_exists('epay_secure_acquire_lock')) {
    function epay_secure_acquire_lock($scope, $timeoutSeconds)
    {
        $name = 'whmcs_epay_' . substr(sha1((string) $scope), 0, 40);
        $rows = Capsule::select('SELECT GET_LOCK(?, ?) AS acquired', array($name, (int) $timeoutSeconds));
        if (!$rows || (int) $rows[0]->acquired !== 1) {
            throw new RuntimeException('Could not acquire payment processing lock.');
        }
        return $name;
    }
}

if (!function_exists('epay_secure_release_lock')) {
    function epay_secure_release_lock($name)
    {
        if ($name === null || $name === '') {
            return;
        }
        try {
            Capsule::select('SELECT RELEASE_LOCK(?) AS released', array((string) $name));
        } catch (Throwable $e) {
            // Lock is automatically released when the database connection closes.
        }
    }
}
