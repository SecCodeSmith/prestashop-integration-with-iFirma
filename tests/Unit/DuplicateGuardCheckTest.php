<?php
/**
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 */

use PHPUnit\Framework\TestCase;

/**
 * Pins what IfirmaDuplicateGuard::check() resolves from its options and the configuration, asks
 * iFirma for, and hands back, beyond what DuplicateGuardTest asserts: the settings (an option wins,
 * then the configuration, then the default), the search window, the order the candidates are read
 * in, and every field of the result on every outcome.
 *
 * Written against check() before it was split into resolveSettings() / scanRemote() / classify()
 * (refactor-srp-splits 4.1), so that the split cannot change any of it unnoticed.
 */
class DuplicateGuardCheckTest extends TestCase
{
    use IfirmaContractorMapDb;

    /** Stands for a configuration key that is not set at all (as opposed to one set to null). */
    const ABSENT = '<absent>';

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaDuplicateGuard */
    private $guard;

    /** @var IfirmaApiClient */
    private $client;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->guard = new IfirmaDuplicateGuard($this->client);
        $this->scriptContractorMapDb();

        $cur = new Currency();
        $cur->id = 1;
        $cur->iso_code = 'PLN';
        Currency::register($cur);
        $a = new Address();
        $a->id = 7;
        $a->id_country = 14;
        Address::register($a);

        // IfirmaReceipt::getByOrder() runs raw SQL; answer it from the in-memory registry.
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function ($sql, $m) {
            $found = 0;
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $id > $found) {
                    $found = $id;
                }
            }

            return $found ?: false;
        });
    }

    /** The fixed moment of the tests that need to know the day: 2026-07-01 12:00:00. */
    private static function noon()
    {
        return strtotime('2026-07-01 12:00:00');
    }

    private function guardAt($timestamp)
    {
        return new IfirmaDuplicateGuard($this->client, function () use ($timestamp) {
            return $timestamp;
        });
    }

    private function order($daysAgo = 1, $now = null)
    {
        $now = $now === null ? time() : $now;
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_shop = 1;
        $o->id_currency = 1;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = date('Y-m-d H:i:s', $now - $daysAgo * 86400);
        $o->total_paid_tax_incl = 123.00;
        $o->details = array(array(
            'product_name' => 'Produkt A',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 123.00,
            'unit_price_tax_excl' => 100.00,
            'tax_rate' => 23,
        ));

        return $o;
    }

    /** Sale date of order(): the order date. */
    private function saleDate($daysAgo = 1, $now = null)
    {
        $now = $now === null ? time() : $now;

        return date('Y-m-d', $now - $daysAgo * 86400);
    }

    /** Detail of a document of the buyer without the order reference, matching sale date and lines. */
    private function detailNoRef(array $over = array(), $saleDate = null)
    {
        $node = array_merge(array(
            'Uwagi' => 'other',
            'DataSprzedazy' => $saleDate === null ? $this->saleDate() : $saleDate,
            'FormatDatySprzedazy' => 'DZN',
            'Pozycje' => array(array('NazwaPelna' => 'Produkt A', 'Ilosc' => 1, 'CenaJednostkowa' => 123.0)),
        ), $over);

        return json_encode(array('response' => $node));
    }

    private function detailWithRef()
    {
        return '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}}';
    }

    private function receiptRow()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return reset($rows);
    }

    private function recorded()
    {
        return json_decode($this->receiptRow()['duplicate_candidates'], true);
    }

    private function nip()
    {
        $a = new Address();
        $a->id = 7;
        $a->vat_number = 'PL 123-456-78-90';
        Address::register($a);
    }

    private function row(array $over = array())
    {
        return array_merge(array(
            'FakturaId' => 77,
            'IdentyfikatorKontrahenta' => 'PS-C5',
            'PelnyNumer' => '7/2026',
            'DataWystawienia' => date('Y-m-d'),
            'Brutto' => 123.00,
            'Zaplacono' => 0,
            'KontrahentNazwa' => 'Jan K',
            'Rodzaj' => 'prz_faktura_paragon',
            'Waluta' => 'PLN',
            'CzySzkic' => false,
            'CzyWyslano' => false,
        ), $over);
    }

    /**
     * A listed row as the guard sees it after the answer of iFirma went through JSON (a whole float
     * comes back as an integer), plus the `_matched_by` that filterByBuyer() adds for the buyer's
     * PS-C5 identifier.
     */
    private function matched(array $row)
    {
        return array_merge(json_decode(json_encode($row), true), array('_matched_by' => 'identifier'));
    }

    private function queueList(array $rows)
    {
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows)))));
    }

    private function queueDetail($type, $id, $body)
    {
        $this->t->queue('GET', '#' . $type . '/' . $id . '\.json#', array('body' => $body));
    }

    /** A first page of non-candidates that is full, so that a second page is asked for. */
    private function fullPage()
    {
        $rows = array();
        for ($i = 1; $i <= IfirmaDuplicateGuard::PAGE_SIZE; ++$i) {
            $rows[] = $this->row(array('FakturaId' => 1000 + $i, 'Brutto' => 1));
        }

        return $rows;
    }

    private function urls()
    {
        $u = array();
        foreach ($this->t->calls as $c) {
            $u[] = urldecode($c['url']);
        }

        return $u;
    }

    /** The document ids whose detail was asked for, in the order they were asked. */
    private function detailIds()
    {
        $ids = array();
        foreach ($this->urls() as $url) {
            if (preg_match('#/(?:fakturaparagon|fakturakraj)/(\d+)\.json#', $url, $m)) {
                $ids[] = (int) $m[1];
            }
        }

        return $ids;
    }

    private function configure($key, $value)
    {
        if ($value === self::ABSENT) {
            Configuration::deleteByName($key);
        } else {
            Configuration::updateValue($key, $value);
        }
    }

    private function receiptWrites()
    {
        return IfirmaTestState::writes('ifirma_receipt');
    }

    // ---- the switch and the bypass: option, then configuration, then on ----------------------

    public function testEnabledOptionTrueRunsTheLookupAlthoughTheConfigurationSwitchesTheGuardOff()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', '0');
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order(), array('enabled' => true));

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount(2, $this->t->calls);
    }

    public function testEnabledOptionFalseSwitchesTheGuardOffAlthoughTheConfigurationSwitchesItOn()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', '1');

        $res = $this->guard->check($this->order(), array('enabled' => false));

        $this->assertSame(IfirmaGuardResult::OUTCOME_DISABLED, $res->outcome);
        $this->assertCount(0, $this->t->calls);
    }

    public function testEnabledOptionNullCountsAsOffAndDoesNotFallBackToTheConfiguration()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', '1');

        $res = $this->guard->check($this->order(), array('enabled' => null));

        $this->assertSame(IfirmaGuardResult::OUTCOME_DISABLED, $res->outcome);
        $this->assertCount(0, $this->t->calls);
    }

    public static function configuredSwitches()
    {
        return array(
            'not configured: on' => array(self::ABSENT, true),
            'null: on' => array(null, true),
            'empty text: on' => array('', true),
            'false: on' => array(false, true),
            'integer one: on' => array(1, true),
            'text one: on' => array('1', true),
            'text one with a space: on' => array(' 1', true),
            'text two: on' => array('2', true),
            'integer zero: off' => array(0, false),
            'text zero: off' => array('0', false),
            'text without a number: off' => array('off', false),
        );
    }

    /**
     * @dataProvider configuredSwitches
     */
    public function testTheConfiguredSwitchDecidesWhenNoOptionSaysOtherwise($configured, $enabled)
    {
        $this->configure('IFIRMA_DUPLICATE_CHECK', $configured);
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order());

        $this->assertSame($enabled ? IfirmaGuardResult::OUTCOME_PROCEED : IfirmaGuardResult::OUTCOME_DISABLED, $res->outcome);
        $this->assertCount($enabled ? 2 : 0, $this->t->calls);
    }

    public static function bypassOptions()
    {
        return array(
            'true' => array(true, true),
            'integer one' => array(1, true),
            'text one' => array('1', true),
            'text' => array('yes', true),
            'false' => array(false, false),
            'integer zero' => array(0, false),
            'text zero' => array('0', false),
            'empty text' => array('', false),
            'null' => array(null, false),
        );
    }

    /**
     * @dataProvider bypassOptions
     */
    public function testTheBypassOptionSkipsTheLookupOnlyWhenItIsNotEmpty($bypass, $bypassed)
    {
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order(), array('bypass' => $bypass));

        $this->assertSame($bypassed ? IfirmaGuardResult::OUTCOME_BYPASSED : IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount($bypassed ? 0 : 2, $this->t->calls);
    }

    public function testASwitchedOffGuardAnswersDisabledWhateverTheBypassSays()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', '0');

        $res = $this->guard->check($this->order(), array('bypass' => true));

        $this->assertSame(IfirmaGuardResult::OUTCOME_DISABLED, $res->outcome);
    }

    public function testALocalIssuedRecordIsReportedWhateverTheSwitchAndTheBypassSay()
    {
        $local = new IfirmaReceipt();
        $local->id_order = 15;
        $local->ifirma_id = '99';
        $local->status = IfirmaReceipt::STATUS_CREATED;
        $local->save();

        $res = $this->guard->check($this->order(), array('enabled' => false, 'bypass' => true));

        $this->assertSame(IfirmaGuardResult::OUTCOME_LOCAL_ISSUED, $res->outcome);
        $this->assertInstanceOf(IfirmaReceipt::class, $res->receipt);
        $this->assertSame((int) $local->id, (int) $res->receipt->id);
        $this->assertSame('99', (string) $res->receipt->ifirma_id);
        $this->assertCount(0, $this->t->calls);
    }

    public function testTheAnswersWithoutALookupCarryNothingButTheOutcome()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', '0');
        $disabled = $this->guard->check($this->order());
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', '1');
        $bypassed = $this->guard->check($this->order(), array('bypass' => true));

        foreach (array($disabled, $bypassed) as $res) {
            $this->assertSame(0, $res->queries);
            $this->assertSame('', $res->error);
            $this->assertFalse($res->rateLimited);
            $this->assertNull($res->candidate);
            $this->assertSame(array(), $res->candidates);
            $this->assertNull($res->receipt);
        }
        $this->assertSame(IfirmaGuardResult::OUTCOME_DISABLED, $disabled->outcome);
        $this->assertSame(IfirmaGuardResult::OUTCOME_BYPASSED, $bypassed->outcome);
    }

    // ---- the amount tolerance: option, then configuration, then one grosz --------------------

    public static function tolerances()
    {
        // configured value, options, gross of the listed document (the order's is 123.00), is it a candidate
        return array(
            'default: a grosz over' => array(self::ABSENT, array(), 123.01, true),
            'default: a grosz under' => array(self::ABSENT, array(), 122.99, true),
            'default: two groszy over' => array(self::ABSENT, array(), 123.02, false),
            'default: two groszy under' => array(self::ABSENT, array(), 122.98, false),
            'an empty configuration is the default' => array('', array(), 123.02, false),
            'a null configuration is the default' => array(null, array(), 123.02, false),
            'configured 5: on the edge' => array('5', array(), 128.00, true),
            'configured 5: past the edge' => array('5', array(), 128.01, false),
            'configured 5: on the lower edge' => array('5', array(), 118.00, true),
            'configured 0: an exact amount' => array('0', array(), 123.00, true),
            'configured 0: a grosz over' => array('0', array(), 123.01, false),
            'the option wins: inside it' => array('5', array('tolerance' => 0.5), 123.50, true),
            'the option wins: outside it' => array('5', array('tolerance' => 0.5), 128.00, false),
            'the option may be text' => array(self::ABSENT, array('tolerance' => '0.5'), 123.50, true),
            'the option 0 is a value, not unset' => array('5', array('tolerance' => 0), 128.00, false),
            'a null option falls back to the configuration' => array('5', array('tolerance' => null), 128.00, true),
            'a null option falls back to the default' => array(self::ABSENT, array('tolerance' => null), 123.02, false),
        );
    }

    /**
     * @dataProvider tolerances
     */
    public function testToleranceOptionThenConfigurationThenOneGroszDecidesWhichDocumentsAreCandidates($configured, array $options, $gross, $candidate)
    {
        $this->configure('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', $configured);
        $this->queueList(array($this->row(array('Brutto' => $gross))));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order(), $options);

        $this->assertSame($candidate ? IfirmaGuardResult::OUTCOME_SUSPECTED : IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount($candidate ? 2 : 1, $this->t->calls);
    }

    public static function orderTotals()
    {
        return array(
            'a fraction of a grosz under is rounded away' => array(123.004, true),
            'a fraction of a grosz over is rounded away' => array(122.996, true),
            'text is read as a number' => array('123.004', true),
            'rounded up to the next grosz it differs' => array(123.006, false),
        );
    }

    /**
     * @dataProvider orderTotals
     */
    public function testTheOrderTotalIsRoundedToGroszyBeforeItIsCompared($total, $candidate)
    {
        $order = $this->order();
        $order->total_paid_tax_incl = $total;
        $this->queueList(array($this->row(array('Brutto' => 123.00))));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($order, array('tolerance' => 0));

        $this->assertSame($candidate ? IfirmaGuardResult::OUTCOME_SUSPECTED : IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
    }

    public static function currencies()
    {
        // currency of the order, currency of the listed document, is it a candidate
        return array(
            'an unknown currency is PLN: a PLN document' => array(99, 'PLN', true),
            'an unknown currency is PLN: a EUR document' => array(99, 'EUR', false),
            'a currency without an ISO code is PLN' => array(3, 'PLN', true),
            'a EUR order: a EUR document' => array(2, 'EUR', true),
            'a EUR order: the code in lower case' => array(2, 'eur', true),
            'a EUR order: a PLN document' => array(2, 'PLN', false),
            'a PLN order: a EUR document' => array(1, 'EUR', false),
        );
    }

    /**
     * @dataProvider currencies
     */
    public function testOnlyDocumentsInTheCurrencyOfTheOrderAreCandidates($idCurrency, $documentCurrency, $candidate)
    {
        $eur = new Currency();
        $eur->id = 2;
        $eur->iso_code = 'EUR';
        Currency::register($eur);
        $blank = new Currency();
        $blank->id = 3;
        $blank->iso_code = '';
        Currency::register($blank);
        $order = $this->order();
        $order->id_currency = $idCurrency;
        $this->queueList(array($this->row(array('Waluta' => $documentCurrency))));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($order);

        $this->assertSame($candidate ? IfirmaGuardResult::OUTCOME_SUSPECTED : IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
    }

    // ---- the search window: option, then configuration, then 60 days --------------------------

    public static function windows()
    {
        // configured value, options, age of the order in days, is the unfiltered query run
        // (it is when the window from the day before the order to today spans at most window_days)
        return array(
            'default: 59 days old' => array(self::ABSENT, array(), 59, true),
            'default: 60 days old' => array(self::ABSENT, array(), 60, false),
            'an empty configuration is the default: 59 days old' => array('', array(), 59, true),
            'an empty configuration is the default: 60 days old' => array('', array(), 60, false),
            'a null configuration is the default: 60 days old' => array(null, array(), 60, false),
            'configured 30: 29 days old' => array(30, array(), 29, true),
            'configured 30: 30 days old' => array(30, array(), 30, false),
            'configured as text: 29 days old' => array('30', array(), 29, true),
            'configured as text: 30 days old' => array('30', array(), 30, false),
            'configured with decimals they are cut: 44 days old' => array('45.9', array(), 44, true),
            'configured with decimals they are cut: 45 days old' => array('45.9', array(), 45, false),
            'configured 0 never lists unfiltered' => array('0', array(), 0, false),
            'the option wins: 9 days old' => array(30, array('window_days' => 10), 9, true),
            'the option wins: 10 days old' => array(30, array('window_days' => 10), 10, false),
            'the option may be text: 9 days old' => array(self::ABSENT, array('window_days' => '10'), 9, true),
            'the option may be text: 10 days old' => array(self::ABSENT, array('window_days' => '10'), 10, false),
            'the option 0 is a value, not unset' => array(30, array('window_days' => 0), 0, false),
            'a null option falls back to the configuration' => array(30, array('window_days' => null), 29, true),
            'a null option falls back to the default' => array(self::ABSENT, array('window_days' => null), 59, true),
        );
    }

    /**
     * @dataProvider windows
     */
    public function testWindowDaysOptionThenConfigurationThenSixtyDecidesWhetherTheUnfilteredQueryRuns($configured, array $options, $ageInDays, $unfiltered)
    {
        $this->configure('IFIRMA_DUPLICATE_WINDOW_DAYS', $configured);
        $this->queueList(array());
        $this->queueList(array());
        $now = self::noon();

        $res = $this->guardAt($now)->check($this->order($ageInDays, $now), $options);

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount($unfiltered ? 2 : 1, $this->t->calls);
        $this->assertSame($unfiltered ? 2 : 1, $res->queries);
        $this->assertStringContainsString('kontrahent=PS-C5', $this->urls()[0]);
        if ($unfiltered) {
            $this->assertStringNotContainsString('kontrahent=', $this->urls()[1]);
        }
    }

    public function testTheSearchWindowRunsFromTheDayBeforeTheOrderToTheDayOfTheClock()
    {
        $now = self::noon();
        $this->queueList(array());
        $this->queueList(array());
        $order = $this->order(11, $now);
        $order->date_add = '2026-06-20 10:00:00';

        $this->guardAt($now)->check($order);

        $this->assertSame(
            'http://ifirma-mock:8001/iapi/faktury.json?dataOd=2026-06-19&dataDo=2026-07-01&iloscNaStronie=100&kontrahent=PS-C5',
            $this->urls()[0]
        );
        $this->assertSame(
            'http://ifirma-mock:8001/iapi/faktury.json?dataOd=2026-06-19&dataDo=2026-07-01&iloscNaStronie=100',
            $this->urls()[1]
        );
    }

    public static function undatedOrders()
    {
        return array(
            'an empty date' => array(''),
            'no date' => array(null),
            'text that is no date' => array('not a date'),
        );
    }

    /**
     * @dataProvider undatedOrders
     */
    public function testAnOrderWithoutADateStartsTheWindowTheDayBeforeTheClockDay($dateAdd)
    {
        $now = self::noon();
        $this->queueList(array());
        $this->queueList(array());
        $order = $this->order(1, $now);
        $order->date_add = $dateAdd;

        $this->guardAt($now)->check($order);

        $this->assertCount(2, $this->t->calls);
        $this->assertStringContainsString('dataOd=2026-06-30&dataDo=2026-07-01&', $this->urls()[0]);
    }

    public static function clockChanges()
    {
        // time zone, clock of the guard, date_add of the order, window_days, is the unfiltered query run (the window
        // spans 4 calendar days; a clock change between its two midnights makes the span an hour short of, or an
        // hour over, 4 whole days)
        return array(
            'clocks went forward inside the window: 4 calendar days count as 3' => array('Europe/Warsaw', '2026-03-30 12:00:00', '2026-03-27 11:00:00', 3, true),
            'clocks went forward inside the window: 4 days do not fit in 2' => array('Europe/Warsaw', '2026-03-30 12:00:00', '2026-03-27 11:00:00', 2, false),
            'clocks went back inside the window: 4 calendar days still count as 4' => array('Europe/Warsaw', '2026-10-27 12:00:00', '2026-10-24 11:00:00', 4, true),
            'clocks went back inside the window: 4 days do not fit in 3' => array('Europe/Warsaw', '2026-10-27 12:00:00', '2026-10-24 11:00:00', 3, false),
            'no clock change: 4 calendar days fit in 4' => array('Europe/Warsaw', '2026-07-01 12:00:00', '2026-06-28 11:00:00', 4, true),
            'no clock change: 4 calendar days do not fit in 3' => array('Europe/Warsaw', '2026-07-01 12:00:00', '2026-06-28 11:00:00', 3, false),
        );
    }

    /**
     * @dataProvider clockChanges
     */
    public function testTheDaysOfTheWindowAreWholeDaysBetweenTheTwoMidnightsRoundedDown($zone, $clock, $dateAdd, $windowDays, $unfiltered)
    {
        $before = date_default_timezone_get();
        date_default_timezone_set($zone);
        try {
            $now = strtotime($clock);
            $order = $this->order(1, $now);
            $order->date_add = $dateAdd;
            $this->queueList(array());
            $this->queueList(array());

            $res = $this->guardAt($now)->check($order, array('window_days' => $windowDays));

            $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
            $this->assertCount($unfiltered ? 2 : 1, $this->t->calls);
        } finally {
            date_default_timezone_set($before);
        }
    }

    // ---- the timeout option: every call to iFirma carries it ----------------------------------

    public static function timeouts()
    {
        return array(
            'no option: the client timeout' => array(array(), 30),
            'seconds' => array(array('timeout' => 7), 7),
            'text' => array(array('timeout' => '9'), 9),
            'zero is a value, not unset' => array(array('timeout' => 0), 0),
            'null falls back to the client timeout' => array(array('timeout' => null), 30),
        );
    }

    /**
     * @dataProvider timeouts
     */
    public function testEveryPageAndEveryDetailCallCarriesTheTimeout(array $options, $seconds)
    {
        $this->queueList($this->fullPage());
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order(), $options);

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertCount(3, $this->t->calls);
        $this->assertSame(array($seconds, $seconds, $seconds), array_column($this->t->calls, 'timeout'));
    }

    // ---- the detail cap: option, then ten -----------------------------------------------------

    /** Candidates 1..$count of the buyer, the lower the id the newer the document, all matching the order. */
    private function queueCandidates($count)
    {
        $rows = array();
        for ($i = 1; $i <= $count; ++$i) {
            $rows[] = $this->row(array('FakturaId' => $i, 'DataWystawienia' => date('Y-m-d', time() - $i * 86400)));
            $this->queueDetail('fakturaparagon', $i, $this->detailNoRef());
        }
        $this->queueList($rows);
    }

    public static function detailCaps()
    {
        // options, candidates listed, details read
        return array(
            'three of five' => array(array('max_detail_calls' => 3), 5, 3),
            'one of two' => array(array('max_detail_calls' => 1), 2, 1),
            'text' => array(array('max_detail_calls' => '2'), 4, 2),
            'zero reads nothing' => array(array('max_detail_calls' => 0), 3, 0),
            'a negative cap reads nothing' => array(array('max_detail_calls' => -2), 3, 0),
            'no option: ten' => array(array(), 12, 10),
            'a null option: ten' => array(array('max_detail_calls' => null), 12, 10),
            'ten are read when there are only ten' => array(array(), 10, 10),
        );
    }

    /**
     * @dataProvider detailCaps
     */
    public function testTheDetailOfAtMostTheCapOfCandidatesIsRead(array $options, $listed, $read)
    {
        $this->queueCandidates($listed);

        $res = $this->guard->check($this->order(), $options);

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame($read ? range(1, $read) : array(), $this->detailIds(), 'the newest candidates are read');
        $this->assertCount(1 + $read, $this->t->calls);
        $this->assertCount($listed, $res->candidates);
        foreach ($res->candidates as $index => $row) {
            $this->assertSame($index + 1, $row['FakturaId']);
            $this->assertSame($index < $read ? $this->saleDate() : '', $row['_sale_date'], 'candidate ' . ($index + 1));
        }
    }

    public function testACandidateBeyondTheCapIsNeverReadEvenWhenItWouldBeConfirmed()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 70, 'DataWystawienia' => '2026-01-03')),
            $this->row(array('FakturaId' => 60, 'DataWystawienia' => '2026-01-01')),
        ));
        $this->queueDetail('fakturaparagon', 70, $this->detailNoRef());
        $this->queueDetail('fakturaparagon', 60, $this->detailWithRef());

        $res = $this->guard->check($this->order(), array('max_detail_calls' => 1));

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame(array(70), $this->detailIds());
        $this->assertSame(array(70, 60), array_column($res->candidates, 'FakturaId'));
        $this->assertSame(array($this->saleDate(), ''), array_column($res->candidates, '_sale_date'));
    }

    // ---- the order the candidates are read and reported in ------------------------------------

    public function testCandidatesAreReadNewestIssueDateFirst()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 70, 'DataWystawienia' => '2026-01-01')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-03')),
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-02')),
        ));
        foreach (array(70, 80, 90) as $id) {
            $this->queueDetail('fakturaparagon', $id, $this->detailNoRef());
        }

        $res = $this->guard->check($this->order());

        $this->assertSame(array(80, 90, 70), $this->detailIds());
        $this->assertSame(array(80, 90, 70), array_column($res->candidates, 'FakturaId'));
    }

    public function testCandidatesOfTheSameDayAreReadHighestIdFirst()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 70, 'DataWystawienia' => '2026-01-03')),
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-03')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-03')),
        ));
        foreach (array(70, 80, 90) as $id) {
            $this->queueDetail('fakturaparagon', $id, $this->detailNoRef());
        }

        $res = $this->guard->check($this->order());

        $this->assertSame(array(90, 80, 70), $this->detailIds());
        $this->assertSame(array(90, 80, 70), array_column($res->candidates, 'FakturaId'));
    }

    public function testACandidateWithoutAnIssueDateIsReadLast()
    {
        $undated = $this->row(array('FakturaId' => 5));
        unset($undated['DataWystawienia']);
        $this->queueList(array($undated, $this->row(array('FakturaId' => 1, 'DataWystawienia' => '2026-01-01'))));
        $this->queueDetail('fakturaparagon', 1, $this->detailNoRef());
        $this->queueDetail('fakturaparagon', 5, $this->detailNoRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(array(1, 5), $this->detailIds());
        $this->assertSame(array(1, 5), array_column($res->candidates, 'FakturaId'));
    }

    public function testACandidateWithoutAnIdCountsAsIdZeroAmongTheSameDay()
    {
        $anonymous = $this->row(array('DataWystawienia' => '2026-01-03'));
        unset($anonymous['FakturaId']);
        $this->queueList(array($anonymous, $this->row(array('FakturaId' => 3, 'DataWystawienia' => '2026-01-03'))));

        $res = $this->guard->check($this->order(), array('max_detail_calls' => 0));

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertCount(1, $this->t->calls);
        $this->assertSame(array(3, null), array_map(function ($row) {
            return isset($row['FakturaId']) ? $row['FakturaId'] : null;
        }, $res->candidates));
    }

    // ---- the result on every outcome ---------------------------------------------------------

    public function testProceedWithNothingFoundCarriesTheListCallsAndNothingElse()
    {
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame(2, $res->queries);
        $this->assertSame('', $res->error);
        $this->assertFalse($res->rateLimited);
        $this->assertNull($res->candidate);
        $this->assertSame(array(), $res->candidates);
        $this->assertNull($res->receipt);
        $this->assertSame(array(), $this->receiptWrites());
    }

    public function testEveryPageIsOneListCall()
    {
        $this->queueList($this->fullPage());
        $this->queueList(array($this->row(array('Brutto' => 1))));

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount(2, $this->t->calls);
        $this->assertSame(2, $res->queries);
        $this->assertSame(array(), $this->receiptWrites());
    }

    public function testAFailingSecondPageIsInconclusiveAndCountsBothCalls()
    {
        $this->queueList($this->fullPage());
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'timeout'));

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_INCONCLUSIVE, $res->outcome);
        $this->assertSame(2, $res->queries);
        $this->assertSame('cURL error: timeout', $res->error);
        $this->assertFalse($res->rateLimited);
        $this->assertNull($res->candidate);
        $this->assertSame(array(), $res->candidates);
        $this->assertNull($res->receipt);
        $this->assertSame(array(), $this->receiptWrites());
    }

    public function testAFailingFirstQueryStopsTheScanBeforeTheNextOne()
    {
        $this->nip();
        $this->t->queue('GET', '#faktury\.json#', array('status' => 500, 'body' => ''));

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_INCONCLUSIVE, $res->outcome);
        $this->assertSame('HTTP 500 returned by iFirma.', $res->error);
        $this->assertSame(1, $res->queries);
        $this->assertCount(1, $this->t->calls);
    }

    public function testASkippedUnknownContractorQueryStillCountsAsAListCall()
    {
        $this->nip();
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta o zadanym identyfikatorze"}}'));
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame(3, $res->queries);
        $this->assertSame('', $res->error);
        $this->assertFalse($res->rateLimited);
    }

    public function testAnUnknownContractorFilterWithNoLaterQueryProceedsWithoutAnError()
    {
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta o zadanym identyfikatorze"}}'));

        $res = $this->guard->check($this->order(90));

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame(1, $res->queries);
        $this->assertSame('', $res->error);
        $this->assertFalse($res->rateLimited);
        $this->assertCount(1, $this->t->calls);
        $this->assertSame(array(), $this->receiptWrites());
    }

    public function testANipQueryWithRowsEndsTheScanBeforeTheUnfilteredQuery()
    {
        $this->nip();
        $this->queueList(array());
        $this->queueList(array($this->row(array('IdentyfikatorKontrahenta' => 'EC_KOWALSKI', 'NIPKontrahenta' => '123-456-78-90'))));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame(2, $res->queries);
        $this->assertCount(3, $this->t->calls);
        $this->assertStringContainsString('nipKontrahenta=1234567890', $this->urls()[1]);
        $this->assertStringContainsString('fakturaparagon/77.json', $this->urls()[2]);
    }

    public function testAdoptReportsTheConfirmedRowAsListedPlusItsBuyerMatchAndNothingMore()
    {
        $row = $this->row(array('Zaplacono' => 123.00, 'CzyWyslano' => true));
        $this->queueList(array($row));
        $this->queueDetail('fakturaparagon', 77, $this->detailWithRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame($this->matched($row), $res->candidate);
        $this->assertSame(array(), $res->candidates);
        $this->assertSame('', $res->error);
        $this->assertSame(1, $res->queries);
        $this->assertFalse($res->rateLimited);
        $this->assertInstanceOf(IfirmaReceipt::class, $res->receipt);
        $this->assertSame('77', (string) $res->receipt->ifirma_id);
        $this->assertSame(array('add'), array_column($this->receiptWrites(), 'op'));
        $summary = $this->recorded()['candidates'][0];
        $this->assertSame('identifier', $summary['matched_by']);
        $this->assertSame('', $summary['sale_date']);
    }

    public function testAdoptionThroughTheConfiguredPrefixStamp()
    {
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'scmedical');
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, '{"response":{"Uwagi":"scmedical-15-ABCDEFGHI"}}');

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame('scmedical-15-ABCDEFGHI', $res->receipt->order_reference);
    }

    public function testAdoptionThroughTheLegacyStampAfterAPrefixChange()
    {
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'scmedical');
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailWithRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame('77', (string) $res->receipt->ifirma_id);
        $this->assertSame('scmedical-15-ABCDEFGHI', $res->receipt->order_reference);
    }

    public function testNoAdoptionThroughAnotherShopsStamp()
    {
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'scmedical');
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, '{"response":{"Uwagi":"hialumed-15-ABCDEFGHI"}}');

        $res = $this->guard->check($this->order());

        $this->assertNotSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
    }

    public function testAdoptForgetsAnEarlierUnreadableDetailAndTheCandidatesKeptBeforeIt()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-03')),
            $this->row(array('FakturaId' => 85, 'DataWystawienia' => '2026-01-02')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-01')),
        ));
        $this->t->queue('GET', '#fakturaparagon/90\.json#', array('status' => 500, 'body' => ''));
        $this->queueDetail('fakturaparagon', 85, $this->detailNoRef());
        $this->queueDetail('fakturaparagon', 80, $this->detailWithRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame(80, $res->candidate['FakturaId']);
        $this->assertSame(array(), $res->candidates);
        $this->assertSame('', $res->error);
        $this->assertSame(array(90, 85, 80), $this->detailIds());
        $this->assertSame(1 + 3, count($this->t->calls));
        $this->assertSame('80', (string) $res->receipt->ifirma_id);
        $this->assertSame(array('add'), array_column($this->receiptWrites(), 'op'));
    }

    public function testAdoptStopsReadingAtTheFirstConfirmedCandidate()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-03')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-02')),
            $this->row(array('FakturaId' => 70, 'DataWystawienia' => '2026-01-01')),
        ));
        $this->queueDetail('fakturaparagon', 90, $this->detailWithRef());
        $this->queueDetail('fakturaparagon', 80, $this->detailWithRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame(90, $res->candidate['FakturaId']);
        $this->assertSame(array(90), $this->detailIds());
    }

    public function testSuspectedReportsTheKeptRowsWithTheirBuyerMatchAndSaleDate()
    {
        $row = $this->row();
        $this->queueList(array($row));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertNull($res->candidate);
        $this->assertSame(array(array_merge($this->matched($row), array('_sale_date' => $this->saleDate()))), $res->candidates);
        $this->assertSame('', $res->error);
        $this->assertSame(1, $res->queries);
        $this->assertFalse($res->rateLimited);
        $this->assertInstanceOf(IfirmaReceipt::class, $res->receipt);
        $this->assertSame('duplicate_suspected', $res->receipt->status);
        $this->assertSame(array('add'), array_column($this->receiptWrites(), 'op'));
    }

    public function testAnUnreadableDetailKeepsTheCandidateUnverifiedAndReportsTheError()
    {
        $this->queueList(array($this->row()));
        $this->t->queue('GET', '#fakturaparagon/77\.json#', array('status' => 500, 'body' => ''));

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame('HTTP 500 returned by iFirma.', $res->error);
        $this->assertFalse($res->rateLimited);
        $this->assertSame(array(77), array_column($res->candidates, 'FakturaId'));
        $this->assertSame(array(''), array_column($res->candidates, '_sale_date'));
        $this->assertSame(array('identifier'), array_column($res->candidates, '_matched_by'));
    }

    public static function unreadableDetails()
    {
        $serverError = array('status' => 500, 'body' => '');
        $timeout = array('status' => 0, 'error' => 'timeout');
        $readable = array('readable' => true);

        // the detail of the newer document (read first), the detail of the older one, the error
        // reported, the sale date each of the two kept candidates carries
        return array(
            'the second failure wins over the first' => array($serverError, $timeout, 'cURL error: timeout', array('', '')),
            'the second failure wins, the other way round' => array($timeout, $serverError, 'HTTP 500 returned by iFirma.', array('', '')),
            'a later readable detail does not clear the error' => array($serverError, $readable, 'HTTP 500 returned by iFirma.', array('', 'sale date')),
            'an earlier readable detail does not hide a later failure' => array($readable, $serverError, 'HTTP 500 returned by iFirma.', array('sale date', '')),
        );
    }

    /**
     * @dataProvider unreadableDetails
     */
    public function testTheErrorOfTheLastUnreadableDetailIsReported(array $newer, array $older, $error, array $saleDates)
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-02')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-01')),
        ));
        foreach (array(90 => $newer, 80 => $older) as $id => $response) {
            if (!empty($response['readable'])) {
                $response = array('body' => $this->detailNoRef());
            }
            $this->t->queue('GET', '#fakturaparagon/' . $id . '\.json#', $response);
        }

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame($error, $res->error);
        $expected = array();
        foreach ($saleDates as $saleDate) {
            $expected[] = $saleDate === 'sale date' ? $this->saleDate() : $saleDate;
        }
        $this->assertSame($expected, array_column($res->candidates, '_sale_date'));
        $this->assertSame(array(90, 80), array_column($res->candidates, 'FakturaId'));
    }

    public function testADetailRefusedByTheRateGuardIsAnUnreadableDetailNotAnInconclusiveRun()
    {
        $fixed = 1700000000;
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 1);
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $fixed) . ':0');
        $this->client->setClock(function () use ($fixed) {
            return $fixed;
        });
        $this->queueList(array($this->row()));

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame('iFirma rate limit guard: 1 requests in the current minute (cap 1). Retry shortly.', $res->error);
        $this->assertFalse($res->rateLimited);
        $this->assertCount(1, $this->t->calls);
        $this->assertSame(array(''), array_column($res->candidates, '_sale_date'));
    }

    public function testCandidatesThatAreAnotherSaleAreDroppedAndNothingIsStored()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-02')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-01')),
        ));
        $this->queueDetail('fakturaparagon', 90, $this->detailNoRef(array('DataSprzedazy' => '2020-01-01')));
        $this->queueDetail('fakturaparagon', 80, $this->detailNoRef(array('Pozycje' => array(array('NazwaPelna' => 'Produkt B', 'Ilosc' => 1)))));

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame('', $res->error);
        $this->assertNull($res->candidate);
        $this->assertSame(array(), $res->candidates);
        $this->assertNull($res->receipt);
        $this->assertSame(1, $res->queries);
        $this->assertSame(array(90, 80), $this->detailIds());
        $this->assertSame(array(), $this->receiptWrites());
    }

    public function testDroppedKeptAndUnverifiedCandidatesAreReportedInReadOrder()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 70, 'DataWystawienia' => '2026-01-01')),
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-03')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-02')),
            $this->row(array('FakturaId' => 60, 'DataWystawienia' => '2025-12-31')),
        ));
        $this->queueDetail('fakturaparagon', 90, $this->detailNoRef(array('DataSprzedazy' => '2020-01-01')));
        $this->queueDetail('fakturaparagon', 80, $this->detailNoRef());

        $res = $this->guard->check($this->order(), array('max_detail_calls' => 2));

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame(array(90, 80), $this->detailIds());
        $this->assertSame(array(80, 70, 60), array_column($res->candidates, 'FakturaId'));
        $this->assertSame(array($this->saleDate(), '', ''), array_column($res->candidates, '_sale_date'));
        $this->assertSame('', $res->error);
        $this->assertStringContainsString('3 matching document', $res->receipt->last_error);
    }

    public function testADetailOfOtherTypeIsReadFromItsOwnEndpoint()
    {
        $this->queueList(array($this->row(array('Rodzaj' => 'prz_faktura_kraj'))));
        $this->queueDetail('fakturakraj', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order());

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertStringContainsString('/fakturakraj/77.json', $this->urls()[1]);
    }

    // ---- what is read, and when ---------------------------------------------------------------

    /** An order that counts how often its product lines are read. */
    private function countingOrder()
    {
        $order = new class extends Order {
            public $lineReads = 0;

            public function getOrderDetailList()
            {
                ++$this->lineReads;

                return parent::getOrderDetailList();
            }
        };
        foreach (get_object_vars($this->order()) as $field => $value) {
            if ($field !== 'lineReads') {
                $order->{$field} = $value;
            }
        }

        return $order;
    }

    public function testTheProductLinesOfTheOrderAreNotReadWhenNothingIsACandidate()
    {
        $this->queueList(array($this->row(array('Brutto' => 5))));
        $order = $this->countingOrder();

        $res = $this->guard->check($order);

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame(0, $order->lineReads);
    }

    public function testTheProductLinesOfTheOrderAreReadOnceWhenThereAreCandidates()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-01-02')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-01')),
        ));
        $this->queueDetail('fakturaparagon', 90, $this->detailNoRef());
        $this->queueDetail('fakturaparagon', 80, $this->detailNoRef());
        $order = $this->countingOrder();

        $res = $this->guard->check($order);

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame(1, $order->lineReads);
    }

    public function testTheClockIsReadOnceToFindTheWindowAndOnceMoreToStampAnAdoption()
    {
        $reads = 0;
        $guard = new IfirmaDuplicateGuard($this->client, function () use (&$reads) {
            ++$reads;

            return time();
        });
        $this->queueList(array());
        $this->queueList(array());

        $guard->check($this->order());
        $this->assertSame(1, $reads, 'a check that finds nothing');

        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailWithRef());
        $reads = 0;
        $guard->check($this->order());
        $this->assertSame(2, $reads, 'a check that adopts a document');
    }

    // ---- the whole conversation with iFirma, call by call -----------------------------------

    public function testTheCallsToIFirmaOfAFullRunAreExactlyTheseInThisOrder()
    {
        $now = self::noon();
        $this->nip();
        $this->queueList(array());
        $this->queueList(array());
        $this->queueList($this->fullPage());
        $this->queueList(array(
            $this->row(array('FakturaId' => 90, 'DataWystawienia' => '2026-06-30')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-06-29')),
        ));
        $sale = $this->saleDate(2, $now);
        $this->queueDetail('fakturaparagon', 90, $this->detailNoRef(array(), $sale));
        $this->queueDetail('fakturaparagon', 80, $this->detailWithRef());
        $order = $this->order(2, $now);

        $res = $this->guardAt($now)->check($order, array('timeout' => 12));

        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame(4, $res->queries);
        $base = 'http://ifirma-mock:8001/iapi/';
        $window = 'dataOd=2026-06-28&dataDo=2026-07-01&iloscNaStronie=100';
        $this->assertSame(array(
            array('GET', $base . 'faktury.json?' . $window . '&kontrahent=PS-C5', 12),
            array('GET', $base . 'faktury.json?' . $window . '&nipKontrahenta=1234567890', 12),
            array('GET', $base . 'faktury.json?' . $window, 12),
            array('GET', $base . 'faktury.json?' . $window . '&strona=2', 12),
            array('GET', $base . 'fakturaparagon/90.json', 12),
            array('GET', $base . 'fakturaparagon/80.json', 12),
        ), array_map(function ($call) {
            return array($call['method'], urldecode($call['url']), $call['timeout']);
        }, $this->t->calls));
        $this->assertSame(80, $res->candidate['FakturaId']);
    }
}
