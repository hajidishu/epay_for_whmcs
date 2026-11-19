<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!defined('WHMCS')) {
    require_once __DIR__ . '/../../../init.php';
}
require_once __DIR__ . '/recovery.php';

use WHMCS\Database\Capsule;

function epay_admin_show_orders($invoiceId)
{
    return array(
        'invoice' => epay_secure_get_invoice_snapshot($invoiceId),
        'legacy_cleared' => epay_secure_get_meta(epay_secure_legacy_clear_key($invoiceId), ''),
        'legacy_paid_notice' => epay_secure_get_meta(epay_secure_legacy_paid_key($invoiceId), ''),
        'attempts' => Capsule::table('mod_epay_attempts')->where('invoice_id', $invoiceId)->orderBy('id')
            ->get(array('out_trade_no', 'provider_trade_no', 'invoice_amount', 'invoice_currency',
                'processing_amount', 'processing_currency', 'status', 'expires_at', 'last_error')),
        'legacy_payments' => Capsule::table('mod_epay_legacy_events')->where('invoice_id', $invoiceId)->orderBy('id')
            ->get(array('out_trade_no', 'provider_trade_no', 'processing_amount', 'processing_currency', 'status', 'note')),
        'payment_reviews' => Capsule::table('mod_epay_payment_reviews')->where('invoice_id', $invoiceId)->orderBy('id')
            ->get(array('out_trade_no', 'provider_trade_no', 'processing_amount', 'status', 'note')),
    );
}

function epay_admin_confirm_closed($invoiceId, $order, $reference)
{
    if ($order === 'legacy') {
        $notice = epay_secure_get_meta(epay_secure_legacy_paid_key($invoiceId), '');
        if (!epay_secure_legacy_invoice($invoiceId) || ($notice !== '' && $notice !== 'provider')
            || Capsule::table('mod_epay_legacy_events')->where('invoice_id', $invoiceId)
            ->where('status', 'paid_review')->exists()) {
            throw new RuntimeException('无可关闭的迁移旧单，或仍有一笔旧付款待核账。');
        }
        epay_secure_set_legacy_clear($invoiceId, 'admin closed: ' . $reference);
        return;
    }
    $changed = Capsule::table('mod_epay_attempts')->where('invoice_id', $invoiceId)->where('out_trade_no', $order)
        ->whereIn('status', array('pending', 'expired', 'blocked_review'))->update(array(
            'status' => 'closed',
            'last_seen_at' => date('Y-m-d H:i:s'),
            'last_error' => epay_secure_truncate('管理员确认已关闭：' . $reference, 255),
        ));
    if (!$changed) {
        throw new RuntimeException('没有找到该发票下可关闭的未付款订单。');
    }
}

function epay_admin_resolve_review($invoiceId, $trade, $outcome, $reference, $order)
{
    if ($trade === '' || strlen($trade) > 128 || !in_array($outcome, array('recorded', 'refunded'), true)) {
        throw new RuntimeException('请提供交易号和核账结果 recorded 或 refunded。');
    }
    if ($outcome === 'recorded' && !epay_secure_recorded_transaction($invoiceId, $trade)) {
        throw new RuntimeException('WHMCS 尚无归属此发票的 EPay 入账记录。');
    }
    $note = epay_secure_truncate('管理员核账 ' . $outcome . '：' . $reference, 255);
    $now = date('Y-m-d H:i:s');

    Capsule::connection()->transaction(function () use ($invoiceId, $trade, $order, $note, $now, $outcome) {
        $reviews = Capsule::table('mod_epay_payment_reviews')->where('invoice_id', $invoiceId)
            ->where('provider_trade_no', $trade)->where('status', 'paid_review');
        if ($order !== '') {
            $reviews->where('out_trade_no', $order === 'legacy' ? (string) $invoiceId : $order);
        }
        $events = $reviews->limit(2)->get();
        if (count($events) > 1) {
            throw new RuntimeException('该交易号有多条待核账记录，请指定原商户订单号。');
        }
        $event = $events->first();
        if ($event) {
            Capsule::table('mod_epay_payment_reviews')->where('id', $event->id)->update(array(
                'status' => 'reconciled', 'note' => $note, 'updated_at' => $now,
            ));
            $attempt = Capsule::table('mod_epay_attempts')->where('invoice_id', $invoiceId)
                ->where('out_trade_no', $event->out_trade_no)->first();
            if ($attempt && (string) $attempt->gateway_fingerprint === (string) $event->gateway_fingerprint
                && (empty($attempt->provider_trade_no) || (string) $attempt->provider_trade_no === $trade)
                && in_array($attempt->status, array('pending', 'expired', 'blocked_review', 'awaiting_callback', 'paid_review'), true)) {
                Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
                    'status' => 'reconciled', 'provider_trade_no' => $trade, 'last_error' => $note, 'last_seen_at' => $now,
                ));
            } elseif ((string) $event->out_trade_no === (string) $invoiceId && epay_secure_legacy_invoice($invoiceId)) {
                epay_secure_set_legacy_clear($invoiceId, $note);
            }
            return;
        }
        $attempt = Capsule::table('mod_epay_attempts')->where('invoice_id', $invoiceId)
            ->where(function ($query) use ($trade, $order) {
                $query->where('provider_trade_no', $trade);
                if ($order !== '' && $order !== 'legacy') {
                    $query->orWhere(function ($pending) use ($order) {
                        $pending->where('out_trade_no', $order)->where('status', 'awaiting_callback')->whereNull('provider_trade_no');
                    });
                }
            })->whereIn('status', array('paid_review', 'awaiting_callback'))->first();
        $legacy = Capsule::table('mod_epay_legacy_events')->where('invoice_id', $invoiceId)
            ->where('provider_trade_no', $trade)->first();
        if ($attempt) {
            if ($outcome === 'recorded' && !empty($attempt->invoice_amount)) {
                epay_secure_recorded_transaction($invoiceId, $trade, $attempt->invoice_amount);
            }
            Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->update(array(
                'status' => 'reconciled',
                'provider_trade_no' => $trade,
                'last_error' => $note,
                'last_seen_at' => $now,
            ));
        } elseif ($legacy && (string) $legacy->status === 'paid_review') {
            Capsule::table('mod_epay_legacy_events')->where('id', $legacy->id)->update(array(
                'status' => 'reconciled', 'note' => $note, 'updated_at' => $now,
            ));
            epay_secure_set_legacy_clear($invoiceId, $note);
        } elseif ($order === 'legacy' && !$legacy && epay_secure_legacy_invoice($invoiceId)) {
            // Older query APIs may report a paid order without an amount
            // and without forwarding the original signed notification.
            $key = epay_secure_legacy_resolution_key($invoiceId, $trade);
            epay_secure_set_meta_if_missing($key, $note);
            if (epay_secure_get_meta($key, '') === '') {
                throw new RuntimeException('未能保存旧交易的核账结果。');
            }
            epay_secure_set_legacy_clear($invoiceId, $note);
        } else {
            throw new RuntimeException('没有找到该发票下对应的待核账交易。');
        }
    });
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$operation = $argv[1] ?? '';
$invoiceId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
if (!$invoiceId || !in_array($operation, array('status', 'confirm-closed', 'resolve-review'), true)) {
    fwrite(STDERR, "用法\n  php reconcile.php status <invoice_id>\n"
        . "  php reconcile.php confirm-closed <invoice_id> <out_trade_no|legacy> <平台核对凭据>\n"
        . "  php reconcile.php resolve-review <invoice_id> <trade_no> <recorded|refunded> <核账凭据> [out_trade_no|legacy]\n");
    exit(1);
}

$lock = null;
$exitCode = 0;
try {
    epay_secure_ensure_schema();
    $lock = epay_secure_invoice_lock($invoiceId);
    if ($operation === 'status') {
        echo json_encode(epay_admin_show_orders($invoiceId), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    } else {
        $reference = trim((string) ($argv[$operation === 'confirm-closed' ? 4 : 5] ?? ''));
        if ($reference === '' || !Capsule::table('tblinvoices')->where('id', $invoiceId)->exists()) {
            throw new RuntimeException('请输入核对凭据，并确认发票 ID 正确。');
        }
        if ($operation === 'confirm-closed') {
            epay_admin_confirm_closed($invoiceId, (string) ($argv[3] ?? ''), $reference);
        } else {
            epay_admin_resolve_review($invoiceId, (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), $reference, (string) ($argv[6] ?? ''));
        }
        echo "核账记录已保存。\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    epay_secure_release_lock($lock);
}
exit($exitCode);
