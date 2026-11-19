<?php

namespace WHMCS\Database {
    final class TestRows extends \ArrayObject
    {
        public function first() { return $this[0] ?? null; }
    }

    final class TestTable
    {
        private $name;
        private $filters = array();
        private $alternatives = array();
        private $order;
        private $limit;

        public function __construct($name) { $this->name = $name; }
        public function where($key, $value = null)
        {
            if (is_callable($key)) {
                $nested = new self($this->name);
                $key($nested);
                $this->filters[] = function ($row) use ($nested) { return $nested->matches($row); };
            } else {
                $this->filters[] = function ($row) use ($key, $value) { return ($row[$key] ?? null) == $value; };
            }
            return $this;
        }
        public function orWhere($callback)
        {
            $nested = new self($this->name);
            $callback($nested);
            $this->alternatives[] = function ($row) use ($nested) { return $nested->matches($row); };
            return $this;
        }
        public function whereIn($key, $values)
        {
            $this->filters[] = function ($row) use ($key, $values) { return in_array($row[$key] ?? null, $values, true); };
            return $this;
        }
        public function whereNull($key) { return $this->where($key, null); }
        public function orderBy($key) { $this->order = $key; return $this; }
        public function limit($count) { $this->limit = $count; return $this; }
        public function lockForUpdate() { return $this; }
        private function matches($row)
        {
            foreach ($this->filters as $predicate) {
                if (!$predicate($row)) {
                    foreach ($this->alternatives as $other) {
                        if ($other($row)) { return true; }
                    }
                    return false;
                }
            }
            return true;
        }
        public function get()
        {
            $rows = array_values(array_filter(Capsule::$data[$this->name] ?? array(), array($this, 'matches')));
            if ($this->order !== null) {
                usort($rows, function ($a, $b) { return $a[$this->order] <=> $b[$this->order]; });
            }
            return new TestRows(array_map(function ($row) { return (object) $row; }, array_slice($rows, 0, $this->limit)));
        }
        public function first() { return $this->get()->first(); }
        public function value($key) { return $this->first()->$key ?? null; }
        public function exists() { return $this->first() !== null; }
        public function insert($row) { $this->insertGetId($row); }
        public function insertGetId($row)
        {
            $row['id'] = $row['id'] ?? count(Capsule::$data[$this->name] ?? array()) + 1;
            Capsule::$data[$this->name][] = $row;
            return $row['id'];
        }
        public function update($patch)
        {
            $changed = 0;
            foreach (Capsule::$data[$this->name] ?? array() as $index => $row) {
                if ($this->matches($row)) {
                    Capsule::$data[$this->name][$index] = array_replace($row, $patch);
                    $changed++;
                }
            }
            return $changed;
        }
    }

    final class Capsule
    {
        public static $data = array();
        public static $locks = array();
        public static function table($name) { return new TestTable($name); }
        public static function connection() { return new self(); }
        public static function schema() { return new self(); }
        public function hasTable($name) { return true; }
        public function hasColumn($table, $name) { return false; }
        public function transaction($callback)
        {
            $before = self::$data;
            try { return $callback(); } catch (\Throwable $e) {
                self::$data = $before;
                throw $e;
            }
        }
        public static function select($sql, $args)
        {
            $lock = $args[0];
            if (strpos($sql, 'GET_LOCK') !== false) {
                $acquired = !isset(self::$locks[$lock]);
                self::$locks[$lock] = true;
                return array((object) array('acquired' => (int) $acquired));
            }
            unset(self::$locks[$lock]);
            return array();
        }
    }
}

namespace {
    use WHMCS\Database\Capsule;

    if (PHP_SAPI !== 'cli') { exit; }
    define('WHMCS', true);

    function epay_secure_ensure_schema() {}
    function epay_secure_query_order_v1(array $gateway, $number) { return $GLOBALS['classic'][$number] ?? array('code' => 0); }
    function epay_secure_post_form($url, array $params, $tls)
    {
        if ($url === 'https://pay.hhdgdh.top/api/v2/queryorder') {
            check($tls === true && hash_equals(epay_secure_sign($params, 'fixture-key'), $params['sign']), 'MHPay V2 signs merchant lookup');
            return json_encode($GLOBALS['mhpay'][$params['orderNo']] ?? array('code' => 0));
        }
        throw new LogicException('unexpected provider endpoint in test: ' . $url);
    }
    function localAPI($action, array $params)
    {
        $row = Capsule::table('tblinvoices')->where('id', $params['invoiceid'])->first();
        return $row ? array('result' => 'success') + (array) $row : array('result' => 'error');
    }
    function addInvoicePayment($invoiceId, $trade, $amount, $fee, $gateway)
    {
        Capsule::table('tblaccounts')->insert(array(
            'invoiceid' => $invoiceId, 'transid' => $trade, 'amountin' => $amount, 'gateway' => $gateway,
        ));
        Capsule::table('tblinvoices')->where('id', $invoiceId)->update(array('balance' => '0.00', 'status' => 'Paid'));
    }
    function logTransaction($name, $data, $note) {}

    require_once __DIR__ . '/../modules/gateways/epay/recovery.php';
    require_once __DIR__ . '/../modules/gateways/epay/reconcile.php';
    require_once __DIR__ . '/../modules/gateways/epay/qr.php';

    function check($ok, $label)
    {
        if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
        echo 'PASS: ' . $label . PHP_EOL;
    }

    function refused($action)
    {
        try { $action(); } catch (RuntimeException $e) { return true; }
        return false;
    }

    function fixture_invoice($id, $issued = '2026-11-20 12:00:00')
    {
        Capsule::table('tblinvoices')->insert(array('id' => $id, 'userid' => 1, 'status' => 'Unpaid',
            'balance' => '12.00', 'paymentmethod' => 'epay', 'created_at' => $issued, 'date' => substr($issued, 0, 10)));
    }

    function fixture_attempt($invoiceId, array $gateway, $amount = '85.00', $currency = 'CNY')
    {
        return epay_secure_get_or_create_attempt($invoiceId, '12.00', 'USD', $amount, $currency, 'alipay', 360, '127.0.0.1', 1,
            epay_secure_gateway_fingerprint($gateway));
    }

    function receipt($attempt, $trade)
    {
        return array('trade_no' => $trade, 'out_trade_no' => $attempt->out_trade_no, 'money' => '85.00', 'type' => 'alipay');
    }

    Capsule::$data = array(
        'tblclients' => array(array('id' => 1, 'currency' => 1)),
        'tblcurrencies' => array(array('id' => 1, 'code' => 'USD')),
        'mod_epay_meta' => array(array('meta_key' => 'legacy_cutover_at', 'meta_value' => '2026-11-19 12:00:00')),
    );
    $classic = array('gatewayUrl' => 'https://example.test', 'pid' => 'fixture', 'key' => 'fixture-key', 'name' => 'EPay fixture');
    $mh = array_replace($classic, array('gatewayUrl' => 'https://pay.hhdgdh.top'));
    $classicFingerprint = epay_secure_gateway_fingerprint($classic);
    $mhFingerprint = epay_secure_gateway_fingerprint($mh);
    $lookup = array('code' => 1, 'out_trade_no' => 'example', 'pid' => 'fixture', 'status' => '0');
    check(epay_secure_provider_order_state($lookup, 'example', 'fixture') === 'unpaid', 'V1 unpaid does not prove closure');
    check(epay_secure_provider_order_state($lookup, 'different', 'fixture') === 'mismatch', 'query identity is mandatory');

    fixture_invoice(100);
    $old = fixture_attempt(100, $classic);
    Capsule::table('mod_epay_attempts')->where('id', $old->id)
        ->update(array('expires_at' => '2020-01-01 00:00:00', 'status' => 'expired'));
    $GLOBALS['classic'][$old->out_trade_no] = array_replace($lookup, array('out_trade_no' => $old->out_trade_no, 'trade_no' => 'old'));
    epay_secure_recover_open_orders(100, $classic, $classicFingerprint);
    check(fixture_attempt(100, $classic)->out_trade_no === $old->out_trade_no, 'local expiry retains the existing merchant order');
    $GLOBALS['classic'][$old->out_trade_no]['trade_status'] = 'TRADE_CLOSED';
    epay_secure_recover_open_orders(100, $classic, $classicFingerprint);
    $new = fixture_attempt(100, $classic);
    check($new->out_trade_no !== $old->out_trade_no && Capsule::table('mod_epay_attempts')->where('id', $old->id)->value('status') === 'closed',
        'confirmed closure lets the same invoice open a new order');

    fixture_invoice(101, '2026-11-18 12:00:00');
    $GLOBALS['classic']['101'] = array_replace($lookup, array('out_trade_no' => '101'));
    check(refused(function () use ($classic) { epay_secure_assert_legacy_clear(101, $classic); }), 'payable legacy order blocks a replacement');
    epay_admin_confirm_closed(101, 'legacy', 'confirmed in merchant dashboard');
    epay_secure_assert_legacy_clear(101, $classic);
    check(fixture_attempt(101, $classic)->invoice_id === 101, 'operator can reopen payment after confirming legacy closure');

    fixture_invoice(102);
    $attempt = fixture_attempt(102, $mh);
    $GLOBALS['mhpay'][$attempt->out_trade_no] = array('code' => 1, 'data' => array(
        'out_order_no' => $attempt->out_trade_no, 'order_no' => 'MH-102', 'order_status' => 'paid', 'refund_amount' => 0,
    ));
    check(refused(function () use ($mh, $mhFingerprint) { epay_secure_recover_open_orders(102, $mh, $mhFingerprint); })
        && Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->value('status') === 'awaiting_callback'
        && !Capsule::table('tblaccounts')->where('invoiceid', 102)->exists(), 'MHPay status pauses checkout without guessing an amount');
    $attempt = Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->first();
    epay_secure_accept_attempt_receipt($attempt, receipt($attempt, 'MH-102'), $mh, false);
    epay_secure_accept_attempt_receipt($attempt, receipt($attempt, 'MH-102'), $mh, false);
    check(count(Capsule::table('tblaccounts')->where('invoiceid', 102)->get()) === 1
        && Capsule::table('tblaccounts')->where('invoiceid', 102)->value('amountin') === '12.00', 'callback posts the invoice-currency amount once');

    fixture_invoice(103);
    $attempt = fixture_attempt(103, $classic);
    Capsule::table('tblinvoices')->where('id', 103)->update(array('balance' => '9.00'));
    epay_secure_record_attempt_payment($attempt, receipt($attempt, 'review-103'));
    check(epay_secure_invoice_has_unresolved_review(103) && !Capsule::table('tblaccounts')->where('invoiceid', 103)->exists(),
        'changed invoice balance sends receipt to review');
    check(refused(function () { epay_admin_resolve_review(103, 'review-103', 'recorded', 'invoice checked', ''); }),
        'recorded reconciliation requires a WHMCS posting');
    epay_admin_resolve_review(103, 'review-103', 'refunded', 'merchant refund reference', '');
    epay_secure_record_attempt_payment(Capsule::table('mod_epay_attempts')->where('id', $attempt->id)->first(), receipt($attempt, 'review-103'));
    check(!epay_secure_invoice_has_unresolved_review(103), 'resolved refund remains resolved on callback retry');

    epay_secure_record_attempt_payment($new, receipt($new, 'new-100'));
    epay_secure_record_attempt_payment($old, receipt($old, 'late-100'));
    check(count(Capsule::table('tblaccounts')->where('invoiceid', 100)->get()) === 1
        && epay_secure_invoice_has_unresolved_review(100), 'second receipt for a settled invoice goes to review');

    $legacy = array('out_trade_no' => '101', 'trade_no' => 'legacy-101', 'money' => '85.00', 'type' => 'alipay');
    epay_secure_record_legacy_payment(101, $legacy);
    check(epay_secure_invoice_has_unresolved_review(101), 'old receipt is recorded for manual reconciliation');
    epay_admin_resolve_review(101, 'legacy-101', 'refunded', 'legacy refund reference', '');
    epay_secure_record_legacy_payment(101, $legacy);
    check(!epay_secure_invoice_has_unresolved_review(101), 'legacy resolution allows balance payment and survives retries');

    fixture_invoice(104);
    $refunded = fixture_attempt(104, $mh);
    $GLOBALS['mhpay'][$refunded->out_trade_no] = array('code' => 1, 'data' => array(
        'out_order_no' => $refunded->out_trade_no, 'order_no' => 'MH-104', 'order_status' => 'paid',
        'refund_amount' => '85.00', 'payment_time' => 1795161600,
    ));
    epay_secure_accept_attempt_receipt($refunded, receipt($refunded, 'MH-104'), $mh, true);
    check(Capsule::table('mod_epay_payment_reviews')->where('invoice_id', 104)->value('status') === 'paid_review'
        && !Capsule::table('tblaccounts')->where('invoiceid', 104)->exists(), 'refunded provider order cannot be confirmed for posting');
    epay_admin_resolve_review(104, 'MH-104', 'refunded', 'verified refund reference', '');
    epay_secure_accept_attempt_receipt($refunded, receipt($refunded, 'MH-104'), $mh, true);
    check(!epay_secure_invoice_has_unresolved_review(104) && !Capsule::table('tblaccounts')->where('invoiceid', 104)->exists(),
        'reviewed refund stays closed on later receipt notifications');

    fixture_invoice(105);
    $differentAmount = fixture_attempt(105, $classic);
    $incoming = array_replace(receipt($differentAmount, 'amount-105'), array('money' => '84.00'));
    epay_secure_accept_attempt_receipt($differentAmount, $incoming, $classic, false);
    epay_secure_accept_attempt_receipt($differentAmount, $incoming, $classic, false);
    check(epay_secure_invoice_has_unresolved_review(105)
        && count(Capsule::table('mod_epay_payment_reviews')->where('invoice_id', 105)->get()) === 1
        && Capsule::table('mod_epay_payment_reviews')->where('invoice_id', 105)->value('processing_amount') === '84.00'
        && !Capsule::table('tblaccounts')->where('invoiceid', 105)->exists(), 'authenticated amount discrepancy is recorded once for review');

    fixture_invoice(106);
    $differentTrade = fixture_attempt(106, $classic);
    Capsule::table('mod_epay_attempts')->where('id', $differentTrade->id)->update(array('provider_trade_no' => 'original-106'));
    $differentTrade = Capsule::table('mod_epay_attempts')->where('id', $differentTrade->id)->first();
    epay_secure_accept_attempt_receipt($differentTrade, receipt($differentTrade, 'reported-106'), $classic, false);
    epay_admin_resolve_review(106, 'reported-106', 'refunded', 'verified refund reference', $differentTrade->out_trade_no);
    check(Capsule::table('mod_epay_payment_reviews')->where('invoice_id', 106)->value('status') === 'reconciled'
        && Capsule::table('mod_epay_attempts')->where('invoice_id', 106)->value('provider_trade_no') === 'original-106'
        && Capsule::table('mod_epay_attempts')->where('invoice_id', 106)->value('status') === 'pending',
        'resolving a mismatched receipt preserves the original order mapping');

    fixture_invoice(107);
    $frozen = fixture_attempt(107, $classic);
    $changed = array_replace($classic, array('pid' => 'changed-merchant'));
    $changedFingerprint = epay_secure_gateway_fingerprint($changed);
    check(refused(function () use ($changed, $changedFingerprint) { epay_secure_recover_open_orders(107, $changed, $changedFingerprint); })
        && refused(function () use ($changed) { fixture_attempt(107, $changed); }), 'switching merchants cannot expose or reuse an old order');
    $stamp = time();
    $token = epay_secure_launch_token(107, '12.00', 'USD', '85.00', 'CNY', $stamp, 'https://whmcs.test', 'fixture', 'fixture-key', $classicFingerprint);
    check(!epay_secure_verify_launch_token(107, '12.00', 'USD', '85.00', 'CNY', $stamp, 'https://whmcs.test', 'fixture', 'fixture-key', 3600, $token, $changedFingerprint),
        'old launch token does not survive a merchant change');
    $reused = fixture_attempt(107, $classic, '87.20');
    check($reused->out_trade_no === $frozen->out_trade_no
        && epay_secure_build_order_params($classic, $reused, 'https://whmcs.test/callback', 'https://whmcs.test', 'fixture', false)['money'] === '85.00',
        'exchange rate update preserves the processing amount of an eligible old order');
    check(refused(function () use ($classic) { fixture_attempt(107, $classic, '12.00', 'USD'); }),
        'change of processing currency still requires reconciliation');

    fixture_invoice(108);
    $noEvidence = fixture_attempt(108, $mh);
    epay_secure_recover_open_orders(108, $mh, $mhFingerprint);
    check(Capsule::table('mod_epay_attempts')->where('id', $noEvidence->id)->value('status') === 'pending',
        'unavailable MHPay V2 lookup cannot block checkout through an unbound V1 hint');

    fixture_invoice(109);
    $oldUnbound = fixture_attempt(109, $classic);
    Capsule::table('mod_epay_attempts')->where('id', $oldUnbound->id)->update(array('gateway_fingerprint' => null));
    check(refused(function () use ($classic, $classicFingerprint) { epay_secure_recover_open_orders(109, $classic, $classicFingerprint); }),
        'historical order with no merchant proof cannot be reopened');
    $GLOBALS['classic'][$oldUnbound->out_trade_no] = array_replace($lookup, array('out_trade_no' => $oldUnbound->out_trade_no, 'trade_no' => 'classic-109'));
    epay_secure_recover_open_orders(109, $classic, $classicFingerprint);
    check(Capsule::table('mod_epay_attempts')->where('id', $oldUnbound->id)->value('gateway_fingerprint') === $classicFingerprint,
        'verified merchant query binds a historical order');

    fixture_invoice(110);
    $waiting = fixture_attempt(110, $mh);
    check(refused(function () use ($waiting, $mh) { epay_secure_accept_attempt_receipt($waiting, receipt($waiting, 'MH-110'), $mh, true); })
        && Capsule::table('mod_epay_attempts')->where('id', $waiting->id)->value('status') === 'awaiting_callback'
        && !Capsule::table('tblaccounts')->where('invoiceid', 110)->exists(), 'signed MHPay receipt is retained while authorized lookup is unavailable');
    $GLOBALS['mhpay'][$waiting->out_trade_no] = array('code' => 1, 'data' => array(
        'out_order_no' => $waiting->out_trade_no, 'order_no' => 'MH-110', 'order_status' => 'paid', 'refund_amount' => 0,
    ));
    $waiting = Capsule::table('mod_epay_attempts')->where('id', $waiting->id)->first();
    epay_secure_accept_attempt_receipt($waiting, receipt($waiting, 'MH-110'), $mh, true);
    check(Capsule::table('tblaccounts')->where('invoiceid', 110)->value('amountin') === '12.00', 'retry posts once the authorized lookup confirms payment');

    check(epay_secure_cashier_url(array('cashurl' => 'https://pay.hhdgdh.top/cashier/example'), $mh) !== null
        && epay_secure_cashier_url(array('cashurl' => 'https://other.test/cashier/example'), $mh) === null,
        'MHPay cashier reopening stays on the configured origin');
    $payload = str_pad('weixin://test?', 2048, 'A');
    $version = epay_qr_choose_version(strlen($payload));
    $matrix = epay_qr_make_matrix(epay_qr_make_codewords(array_values(unpack('C*', $payload)), $version), $version, 0);
    $bits = implode('', array_map(function ($row) { return implode('', array_map('intval', $row)); }, $matrix));
    check(hash('sha256', $bits) === '20b66a431b4e0d69a38241857995dce07c96597aef393531b29d6c4132498ea6',
        '2048-byte byte-mode QR matrix matches the independent reference');
    check(Capsule::$locks === array(), 'processing releases all simulated locks');
}
