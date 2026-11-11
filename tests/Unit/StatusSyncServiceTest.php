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

class StatusSyncServiceTest extends TestCase
{
    use IfirmaThrowableProviders;
    use IfirmaSentQueries;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaStatusSyncService */
    private $svc;

    /** @var int */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(12, 0, 0, 11, 1, 2026);
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u', array('faktura' => 'abcd'), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $self = $this;
        $clock = function () use ($self) {
            return $self->now;
        };
        $this->client->setClock($clock);
        $this->svc = new IfirmaStatusSyncService($this->client, $clock);
        Configuration::updateValue('IFIRMA_CRON_TOKEN', 'secret-token');
    }

    private function receipt($ifirmaId, $date = '2026-10-30', array $extra = array())
    {
        $r = new IfirmaReceipt();
        $r->id_order = (int) $ifirmaId;
        $r->id_customer = 5;
        $r->ifirma_id = (string) $ifirmaId;
        $r->ifirma_number = $ifirmaId . '/2026';
        $r->status = IfirmaReceipt::STATUS_CREATED;
        $r->issue_date = $date;
        $r->delivery_status = 'sent';
        foreach ($extra as $k => $v) {
            $r->$k = $v;
        }
        $r->save();

        return new IfirmaReceipt((int) $r->id);
    }

    private function listBody(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows))));
    }

    private function row($id, $brutto = 100.0, $paid = 0.0, array $extra = array())
    {
        return array_merge(array(
            'FakturaId' => $id, 'PelnyNumer' => $id . '/2026', 'Brutto' => $brutto, 'Zaplacono' => $paid,
            'CzyWyslano' => false, 'CzySzkic' => false, 'KsefStatus' => '', 'KsefNumer' => '',
            'NumerPowiazanegoPrzychodu' => null,
        ), $extra);
    }

    private function listCalls()
    {
        $out = array();
        foreach ($this->t->calls as $c) {
            if (strpos($c['url'], 'faktury.json') !== false) {
                parse_str((string) parse_url($c['url'], PHP_URL_QUERY), $q);
                $out[] = $q;
            }
        }

        return $out;
    }

    public function testRowFoundStoresRemoteFieldsWithoutDetailCall()
    {
        $r = $this->receipt(11);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(11, 100, 40, array(
            'CzyWyslano' => true, 'CzySzkic' => true, 'KsefStatus' => 'ACCEPTED', 'KsefNumer' => 'KS-1',
        )))));
        $summary = $this->svc->syncReceipt($r);
        $r = new IfirmaReceipt((int) $r->id);

        $this->assertEquals(40.0, $r->remote_paid_amount);
        $this->assertTrue((bool) $r->remote_sent);
        $this->assertTrue((bool) $r->remote_draft);
        $this->assertSame('ACCEPTED', $r->ksef_status);
        $this->assertSame('KS-1', $r->ksef_number);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $r->last_sync_at);
        $this->assertSame('', $r->sync_error);
        $this->assertSame(1, $summary['checked']);
        foreach ($this->t->calls as $c) {
            $this->assertStringNotContainsString('/fakturakraj/', $c['url']);
            $this->assertStringContainsString('faktury.json', $c['url']);
        }
    }

    public function testLinkedCorrectionStored()
    {
        $r = $this->receipt(12);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(12, 100, 0, array(
            'NumerPowiazanegoPrzychodu' => 'KOR 1/2026',
        )))));
        $this->svc->syncReceipt($r);
        $this->assertSame('KOR 1/2026', (new IfirmaReceipt((int) $r->id))->linked_document);
    }

    public function testFullyPaidBecomesPaid()
    {
        $r = $this->receipt(13);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(13, 123.45, 123.45))));
        $summary = $this->svc->syncReceipt($r);
        $this->assertSame(IfirmaReceipt::STATUS_PAID, (new IfirmaReceipt((int) $r->id))->status);
        $this->assertSame(1, $summary['paid']);
    }

    public function testWithinToleranceBecomesPaid()
    {
        $r = $this->receipt(14);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(14, 100, 99.99))));
        $this->svc->syncReceipt($r);
        $this->assertSame(IfirmaReceipt::STATUS_PAID, (new IfirmaReceipt((int) $r->id))->status);
    }

    public function testPartiallyPaidKeepsStatusAndShowsAmount()
    {
        $r = $this->receipt(15);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(15, 100, 50))));
        $this->svc->syncReceipt($r);
        $r = new IfirmaReceipt((int) $r->id);
        $this->assertSame(IfirmaReceipt::STATUS_CREATED, $r->status);
        $this->assertEquals(50.0, $r->remote_paid_amount);
    }

    public function testEmptyKsefStatusKeepsTheModuleMarker()
    {
        $r = $this->receipt(21, '2026-10-30', array('ksef_status' => 'WYSLANA', 'ksef_sent_at' => '2026-11-01 10:00:00'));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(21))));
        $this->svc->syncReceipt($r);
        $r = new IfirmaReceipt((int) $r->id);

        $this->assertSame('WYSLANA', $r->ksef_status);
        $this->assertSame('', $r->ksef_number);
    }

    public function testReportedKsefStatusAndNumberOverwriteTheMarker()
    {
        $r = $this->receipt(22, '2026-10-30', array('ksef_status' => 'WYSLANA'));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(22, 100, 0, array(
            'KsefStatus' => 'PRZYJETA', 'KsefNumer' => '1111112228-20260702-6505FF400000-26',
        )))));
        $this->svc->syncReceipt($r);
        $r = new IfirmaReceipt((int) $r->id);

        $this->assertSame('PRZYJETA', $r->ksef_status);
        $this->assertSame('1111112228-20260702-6505FF400000-26', $r->ksef_number);
    }

    public function testPaidIsNeverDowngraded()
    {
        $r = $this->receipt(16, '2026-10-30', array('status' => IfirmaReceipt::STATUS_PAID));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(16, 100, 0))));
        $this->svc->syncReceipt($r);
        $this->assertSame(IfirmaReceipt::STATUS_PAID, (new IfirmaReceipt((int) $r->id))->status);
    }

    // ---- applyRow(): the columns each path stores and the number of writes ------------------

    /**
     * Values in the columns applyRow() never writes, so that a stray assignment shows up.
     *
     * @return array column => value, for receipt()
     */
    private function columnsTheSyncLeavesAlone()
    {
        return array(
            'paid_amount' => 99.9,
            'last_error' => 'earlier error',
            'request_payload' => '{"request":1}',
            'response_payload' => '{"response":"earlier"}',
            'source' => IfirmaReceipt::SOURCE_EXTERNAL,
            'document_type' => 'fakturakraj',
            'order_reference' => 'PS-ORDER-1-ABCDEFGHI',
            'security_token' => 'tok-abc',
            'delivery_channel' => 'shop_mail',
            'delivered_at' => '2026-10-31 09:00:00',
            'delivery_error' => 'earlier delivery error',
            'ksef_sent_at' => '2026-10-31 10:00:00',
            'ksef_error' => 'earlier ksef error',
            'duplicate_candidates' => '[{"id":1}]',
            'id_data_request' => 9,
        );
    }

    /**
     * Applies a list row to a stored receipt and checks the write that makes.
     *
     * @param IfirmaReceipt $receipt
     * @param array         $row     list row
     * @param array         $set     column => the value the sync had to store (the rest must stay)
     * @return string the outcome applyRow() reported
     */
    private function assertApplyRowStores(IfirmaReceipt $receipt, array $row, array $set)
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $before = $rows[(int) $receipt->id];
        IfirmaTestState::clearWrites();

        $outcome = $this->svc->applyRow($receipt, $row);

        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('update'), array_column($writes, 'op'), 'exactly one update and nothing else');
        $this->assertSame((int) $receipt->id, $writes[0]['id']);
        $after = $writes[0]['row'];
        // The ObjectModel stub stamps date_upd on every update.
        unset($before['date_upd'], $after['date_upd']);
        $this->assertSame(array_keys($before), array_keys($after), 'the same columns are stored');
        foreach ($before as $column => $value) {
            $this->assertSame(array_key_exists($column, $set) ? $set[$column] : $value, $after[$column], $column);
        }

        return $outcome;
    }

    public function testAFullyPaidRowStoresThePaidStatusAndTheRemoteFieldsInOneWrite()
    {
        $r = $this->receipt(50, '2026-10-30', $this->columnsTheSyncLeavesAlone());

        $outcome = $this->assertApplyRowStores($r, $this->row(50, 123.45, 123.45, array('CzyWyslano' => true)), array(
            'status' => 'paid',
            'remote_missing' => 0,
            'remote_paid_amount' => 123.45,
            'remote_sent' => true,
            'remote_draft' => false,
            'linked_document' => '',
            'sync_error' => '',
            'last_sync_at' => date('Y-m-d H:i:s', $this->now),
        ));

        $this->assertSame('paid', $outcome);
    }

    public function testAPartiallyPaidRowStoresTheRemoteFieldsInOneWriteAndNotThePaidStatus()
    {
        $r = $this->receipt(51, '2026-10-30', $this->columnsTheSyncLeavesAlone());

        $outcome = $this->assertApplyRowStores($r, $this->row(51, 100, 50), array(
            'remote_missing' => 0,
            'remote_paid_amount' => 50.0,
            'remote_sent' => false,
            'remote_draft' => false,
            'linked_document' => '',
            'sync_error' => '',
            'last_sync_at' => date('Y-m-d H:i:s', $this->now),
        ));

        $this->assertSame('updated', $outcome);
    }

    public function testARowJustBelowTheToleranceIsNotPaid()
    {
        $r = $this->receipt(52, '2026-10-30', $this->columnsTheSyncLeavesAlone());

        $outcome = $this->assertApplyRowStores($r, $this->row(52, 100, 99.98), array(
            'remote_missing' => 0,
            'remote_paid_amount' => 99.98,
            'remote_sent' => false,
            'remote_draft' => false,
            'linked_document' => '',
            'sync_error' => '',
            'last_sync_at' => date('Y-m-d H:i:s', $this->now),
        ));

        $this->assertSame('updated', $outcome);
    }

    public function testAReceiptThatIsAlreadyPaidIsRefreshedAndNotReportedAsNewlyPaid()
    {
        $extra = array_merge($this->columnsTheSyncLeavesAlone(), array('status' => IfirmaReceipt::STATUS_PAID));
        $r = $this->receipt(53, '2026-10-30', $extra);

        $outcome = $this->assertApplyRowStores($r, $this->row(53, 123.45, 123.45), array(
            'remote_missing' => 0,
            'remote_paid_amount' => 123.45,
            'remote_sent' => false,
            'remote_draft' => false,
            'linked_document' => '',
            'sync_error' => '',
            'last_sync_at' => date('Y-m-d H:i:s', $this->now),
        ));

        $this->assertSame('updated', $outcome);
    }

    public function testAReappearingPaidDocumentIsReportedAsPaid()
    {
        $extra = array_merge($this->columnsTheSyncLeavesAlone(), array('remote_missing' => 1));
        $r = $this->receipt(54, '2026-10-30', $extra);

        $outcome = $this->assertApplyRowStores($r, $this->row(54, 100, 100), array(
            'status' => 'paid',
            'remote_missing' => 0,
            'remote_paid_amount' => 100.0,
            'remote_sent' => false,
            'remote_draft' => false,
            'linked_document' => '',
            'sync_error' => '',
            'last_sync_at' => date('Y-m-d H:i:s', $this->now),
        ));

        $this->assertSame('paid', $outcome);
    }

    public static function documentNumbers()
    {
        return array(
            'an empty number is taken from the row' => array('', '55/2026', '55/2026'),
            'a stored number is kept' => array('55/2026', '55/2026-B', '55/2026'),
            'an empty number stays empty when the row has none' => array('', '', ''),
        );
    }

    /**
     * @dataProvider documentNumbers
     */
    public function testTheDocumentNumberIsTakenFromTheRowOnlyWhenTheReceiptHasNone($stored, $listed, $expected)
    {
        $r = $this->receipt(55, '2026-10-30', array('ifirma_number' => $stored));

        $this->svc->applyRow($r, $this->row(55, 100, 0, array('PelnyNumer' => $listed)));

        $this->assertSame($expected, (new IfirmaReceipt((int) $r->id))->ifirma_number);
    }

    public function testDeletedDocumentFlaggedMissingAndNumberKept()
    {
        $r = $this->receipt(17);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(999))));
        $summary = $this->svc->syncReceipt($r);
        $r = new IfirmaReceipt((int) $r->id);
        $this->assertEquals(1, (int) $r->remote_missing);
        $this->assertSame('17/2026', $r->ifirma_number);
        $this->assertSame('17', $r->ifirma_id);
        $this->assertSame(1, $summary['missing']);
    }

    public function testReappearingDocumentClearsMissingFlag()
    {
        $r = $this->receipt(18, '2026-10-30', array('remote_missing' => 1));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(18))));
        $this->svc->syncReceipt($r);
        $this->assertEquals(0, (int) (new IfirmaReceipt((int) $r->id))->remote_missing);
        $this->assertSame('reappeared', $this->svc->applyRow(
            $this->receipt(19, '2026-10-30', array('remote_missing' => 1)),
            $this->row(19)
        ));
    }

    public function testSyncReceiptMakesExactlyOneListCallForIssueDate()
    {
        $r = $this->receipt(20, '2026-10-28');
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(20))));
        $this->svc->syncReceipt($r);
        $calls = $this->listCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('2026-10-28', $calls[0]['dataOd']);
        $this->assertSame('2026-10-28', $calls[0]['dataDo']);
        $this->assertSame('100', $calls[0]['iloscNaStronie']);
    }

    public function testLookupDateFallsBackToDateAdd()
    {
        $r = $this->receipt(21, '', array('date_add' => '2026-09-15 08:00:00'));
        $r->issue_date = null;
        $this->assertSame('2026-09-15', $this->svc->lookupDate($r));
    }

    public function testPagingContinuesUntilShortPage()
    {
        $page1 = array();
        for ($i = 1; $i <= 100; $i++) {
            $page1[] = $this->row(1000 + $i);
        }
        $this->t->queue('GET', '#faktury\.json#', $this->listBody($page1));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(2000))));
        $res = $this->svc->fetchRowsForDate('2026-10-30');
        $this->assertCount(101, $res['rows']);
        $this->assertSame(2, $res['calls']);
        $calls = $this->listCalls();
        $this->assertSame('1', $calls[0]['strona']);
        $this->assertSame('2', $calls[1]['strona']);
    }

    public function testOpportunisticStaleSyncsOnceWithShortTimeout()
    {
        $r = $this->receipt(30, '2026-10-30', array('last_sync_at' => date('Y-m-d H:i:s', $this->now - 20 * 60)));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(30, 100, 100))));
        $this->assertTrue($this->svc->opportunisticSync($r));
        $this->assertCount(1, $this->listCalls());
        $this->assertSame(5, $this->t->calls[0]['timeout']);
        $this->assertSame(IfirmaReceipt::STATUS_PAID, (new IfirmaReceipt((int) $r->id))->status);
    }

    public function testOpportunisticSyncRefusedByTheLimitReturnsFalseMakesNoCallAndWritesNothing()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now) . ':14000');
        $r = $this->receipt(36, '2026-10-30', array('last_sync_at' => date('Y-m-d H:i:s', $this->now - 20 * 60), 'sync_error' => ''));
        IfirmaTestState::clearWrites();

        $this->assertFalse($this->svc->opportunisticSync($r));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $stored = new IfirmaReceipt((int) $r->id);
        $this->assertSame(date('Y-m-d H:i:s', $this->now - 20 * 60), $stored->last_sync_at);
        $this->assertSame('', (string) $stored->sync_error);
    }

    public function testOpportunisticSyncRefusedByTheBackoffReturnsFalse()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) ($this->now + 40));
        $r = $this->receipt(37, '2026-10-30', array('last_sync_at' => date('Y-m-d H:i:s', $this->now - 20 * 60)));

        $this->assertFalse($this->svc->opportunisticSync($r));
        $this->assertSame(array(), $this->t->calls);
    }

    public function testOpportunisticSyncThatIfirmaRefusesWithItsLimitAnswerReturnsFalseAndWritesNothing()
    {
        $r = $this->receipt(38, '2026-10-30', array('last_sync_at' => date('Y-m-d H:i:s', $this->now - 20 * 60), 'sync_error' => ''));
        IfirmaTestState::clearWrites();
        $this->t->queue('GET', '#faktury\.json#', array(
            'status' => 429,
            'body' => '{"response":{"Kod":100,"Informacja":"Przekroczono limit zapytan"}}',
            'headers' => array('Retry-After' => '20'),
        ));

        $this->assertFalse($this->svc->opportunisticSync($r));
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    public function testOpportunisticRecentMakesNoCall()
    {
        $r = $this->receipt(31, '2026-10-30', array('last_sync_at' => date('Y-m-d H:i:s', $this->now - 5 * 60)));
        $this->assertFalse($this->svc->opportunisticSync($r));
        $this->assertSame(array(), $this->t->calls);
    }

    public function testOpportunisticTransportErrorIsSwallowedAndStateKept()
    {
        $r = $this->receipt(32, '2026-10-30', array(
            'status' => IfirmaReceipt::STATUS_PAID, 'remote_sent' => 1, 'remote_paid_amount' => 100,
            'last_sync_at' => date('Y-m-d H:i:s', $this->now - 3600),
        ));
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->assertTrue($this->svc->opportunisticSync($r));
        $r = new IfirmaReceipt((int) $r->id);
        $this->assertNotSame('', (string) $r->sync_error);
        $this->assertSame(IfirmaReceipt::STATUS_PAID, $r->status);
        $this->assertEquals(100.0, $r->remote_paid_amount);
        $this->assertEquals(1, (int) $r->remote_sent);
        $this->assertSame('sent', $r->delivery_status);
    }

    /** @dataProvider throwables */
    public function testOpportunisticSyncSwallowsAThrowableFromTheListCall(Throwable $failure)
    {
        $r = $this->receipt(35, '2026-10-30', array('last_sync_at' => date('Y-m-d H:i:s', $this->now - 20 * 60)));
        IfirmaTestState::clearWrites();
        $this->t->queue('GET', '#faktury\.json#', function () use ($failure) {
            throw $failure;
        });

        $this->assertFalse($this->svc->opportunisticSync($r));

        // the receipt was stale, so the list call was made; nothing of it was recorded
        $this->assertCount(1, $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $after = new IfirmaReceipt((int) $r->id);
        $this->assertSame('', (string) $after->sync_error);
        $this->assertSame($r->last_sync_at, $after->last_sync_at);
    }

    public function testShouldSyncRespectsConfiguredInterval()
    {
        Configuration::updateValue('IFIRMA_SYNC_INTERVAL_MIN', '60');
        $r = $this->receipt(33, '2026-10-30', array('last_sync_at' => date('Y-m-d H:i:s', $this->now - 20 * 60)));
        $this->assertFalse($this->svc->shouldSyncOpportunistically($r));
        $r2 = $this->receipt(34);
        $this->assertTrue($this->svc->shouldSyncOpportunistically($r2));
    }

    public function testCronGroupsReceiptsByDate()
    {
        $rows = array();
        $dbRows = array();
        $dates = array('2026-10-28', '2026-10-29', '2026-10-30');
        for ($i = 1; $i <= 30; $i++) {
            $r = $this->receipt(100 + $i, $dates[$i % 3]);
            $dbRows[] = array('id_ifirma_receipt' => $r->id);
            $rows[] = $this->row(100 + $i);
        }
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', $dbRows);
        Db::getInstance()->whenGetValue('/COUNT\(DISTINCT id_order\)/', 4);
        $this->t->queue('GET', '#faktury\.json#', function () use ($rows) {
            return array('status' => 200, 'body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows))));
        });

        $s = $this->svc->runCron();
        $this->assertCount(3, $this->listCalls());
        $this->assertSame(3, $s['calls']);
        $this->assertSame(30, $s['receipts_checked']);
        $this->assertSame(3, $s['dates_total']);
        $this->assertSame(3, $s['dates_processed']);
        $this->assertSame(4, $s['held_orders']);
        foreach (array('receipts_checked', 'updated', 'marked_paid', 'marked_missing', 'errors', 'rate_limited',
            'calls', 'dates_total', 'dates_processed', 'held_orders', 'started_at', 'finished_at') as $key) {
            $this->assertArrayHasKey($key, $s);
        }
    }

    public function testCronBudgetStopsAndLeavesRemainingDateUntouched()
    {
        $ids = array();
        $dbRows = array();
        foreach (array('2026-10-28', '2026-10-29', '2026-10-30') as $i => $d) {
            $r = $this->receipt(200 + $i, $d);
            $ids[] = $r->id;
            $dbRows[] = array('id_ifirma_receipt' => $r->id);
        }
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', $dbRows);
        $this->t->queue('GET', '#faktury\.json#', function () {
            return array('status' => 200, 'body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array()))));
        });

        $s = $this->svc->runCron(2);
        $this->assertCount(2, $this->listCalls());
        $this->assertSame(2, $s['dates_processed']);
        $this->assertSame(3, $s['dates_total']);
        $this->assertEmpty((new IfirmaReceipt((int) $ids[2]))->last_sync_at);
        $this->assertNotEmpty((new IfirmaReceipt((int) $ids[0]))->last_sync_at);
    }

    public function testCronMaxCallsFromConfiguration()
    {
        Configuration::updateValue('IFIRMA_CRON_MAX_CALLS', '1');
        $dbRows = array();
        foreach (array('2026-10-28', '2026-10-29') as $i => $d) {
            $dbRows[] = array('id_ifirma_receipt' => $this->receipt(300 + $i, $d)->id);
        }
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', $dbRows);
        $this->t->queue('GET', '#faktury\.json#', function () {
            return array('status' => 200, 'body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array()))));
        });
        $s = $this->svc->runCron();
        $this->assertSame(1, $s['calls']);
    }

    public function testListErrorRecordsSyncErrorWithoutTouchingStatusOrDelivery()
    {
        $r = $this->receipt(40, '2026-10-30', array('remote_sent' => 1, 'remote_paid_amount' => 10));
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Bad key"}}'));
        $summary = $this->svc->syncReceipt($r);
        $r = new IfirmaReceipt((int) $r->id);
        $this->assertNotSame('', (string) $r->sync_error);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $r->last_sync_at);
        $this->assertSame(IfirmaReceipt::STATUS_CREATED, $r->status);
        $this->assertSame('sent', $r->delivery_status);
        $this->assertEquals(1, (int) $r->remote_sent);
        $this->assertEquals(10.0, $r->remote_paid_amount);
        $this->assertEquals(0, (int) $r->remote_missing);
        $this->assertSame(1, $summary['errors']);
    }

    public function testRateLimitedWritesNoErrorAndIsCounted()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':80');
        $r = $this->receipt(41);
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', array(array('id_ifirma_receipt' => $r->id)));
        $s = $this->svc->runCron();
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(1, $s['rate_limited']);
        $this->assertSame(0, $s['errors']);
        $r = new IfirmaReceipt((int) $r->id);
        $this->assertEmpty($r->sync_error);
        $this->assertEmpty($r->last_sync_at);
    }

    public function testRateLimitStopsCronImmediately()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':80');
        $dbRows = array();
        foreach (array('2026-10-28', '2026-10-29') as $i => $d) {
            $dbRows[] = array('id_ifirma_receipt' => $this->receipt(400 + $i, $d)->id);
        }
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', $dbRows);
        $s = $this->svc->runCron();
        $this->assertSame(1, $s['dates_processed']);
        $this->assertSame(1, $s['rate_limited']);
    }

    public function testCronTokenValidation()
    {
        $this->assertTrue($this->svc->isValidCronToken('secret-token'));
        $this->assertFalse($this->svc->isValidCronToken('wrong'));
        $this->assertFalse($this->svc->isValidCronToken(false));
        $this->assertFalse($this->svc->isValidCronToken(null));
        $this->assertFalse($this->svc->isValidCronToken(''));
        Configuration::updateValue('IFIRMA_CRON_TOKEN', '');
        $this->assertFalse($this->svc->isValidCronToken(''));
    }

    public function testSelectForCronSqlConditions()
    {
        $this->svc->selectForCron(50);
        $sql = '';
        foreach (Db::getInstance()->queries as $q) {
            $sql = is_array($q) ? (string) reset($q) : (string) $q;
        }
        $this->assertStringContainsString("status != 'paid'", $sql);
        $this->assertStringContainsString('remote_sent = 0', $sql);
        $this->assertStringContainsString('last_sync_at IS NULL', $sql);
        $this->assertStringContainsString('last_sync_at <', $sql);
        $this->assertStringContainsString("ifirma_id != ''", $sql);
        $this->assertStringContainsString("status != 'error'", $sql);
        $this->assertStringContainsString('LIMIT 50', $sql);
    }

    // ---- the two cron queries: selectForCron() and countHeldOrders() ------------------------

    /**
     * The receipts query selectForCron() sends, line by line, with the clock of setUp(): the stale
     * cut-off is 24 hours before it. The indentation inside the string is part of the text; the line
     * endings are not (see sentQueries()).
     *
     * @param int $limit
     * @return string
     */
    private function dueReceiptsSql($limit)
    {
        return implode("\n", array(
            'SELECT id_ifirma_receipt FROM `ps_ifirma_receipt`',
            "             WHERE ifirma_id != '' AND status != 'error'",
            "             AND (status != 'paid' OR remote_sent = 0 OR last_sync_at IS NULL",
            // deliberate change (task 5.3): a document that waits for KSeF is always due
            "                  OR last_sync_at < '" . date('Y-m-d H:i:s', $this->now - 24 * 3600) . "' OR delivery_status = 'awaiting_ksef')",
            '             ORDER BY last_sync_at ASC, id_ifirma_receipt ASC',
            '             LIMIT ' . $limit,
        ));
    }

    /**
     * @return string the SQL countHeldOrders() sends
     */
    private function heldOrdersSql()
    {
        return implode("\n", array(
            'SELECT COUNT(DISTINCT id_order) FROM `ps_ifirma_data_request`',
            "             WHERE status = 'pending'",
        ));
    }

    /**
     * @param IfirmaReceipt[] $receipts
     * @return int[] their ids, under the same keys
     */
    private function receiptIds(array $receipts)
    {
        return array_map(function ($receipt) {
            return $receipt->id;
        }, $receipts);
    }

    public function testSelectForCronSendsOneQueryWithTheCutOffOfTheServiceClock()
    {
        $this->svc->selectForCron(50);

        $this->assertSame(array($this->dueReceiptsSql(50)), $this->sentQueries());
    }

    public function testSelectForCronReadsAtMostFiveHundredReceiptsByDefault()
    {
        $this->svc->selectForCron();

        $this->assertSame(array($this->dueReceiptsSql(500)), $this->sentQueries());
    }

    public static function dueReceiptsLimits()
    {
        return array(
            'a limit of 1 is sent as it is' => array(1, 1),
            'a limit of 0 is raised to 1' => array(0, 1),
            'a negative limit is raised to 1' => array(-5, 1),
            'a numeric string is sent as a whole number' => array('25', 25),
            'a fraction is cut down to a whole number' => array(7.9, 7),
        );
    }

    /**
     * @dataProvider dueReceiptsLimits
     */
    public function testSelectForCronSendsAWholeLimitOfAtLeastOne($given, $sent)
    {
        $this->svc->selectForCron($given);

        $this->assertSame(array($this->dueReceiptsSql($sent)), $this->sentQueries());
    }

    public function testSelectForCronReturnsTheReceiptsInTheOrderOfTheRows()
    {
        $first = $this->receipt(71);
        $second = $this->receipt(72);
        $third = $this->receipt(73);
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', array(
            array('id_ifirma_receipt' => (string) $third->id),
            array('id_ifirma_receipt' => (string) $first->id),
            array('id_ifirma_receipt' => (string) $second->id),
        ));

        $receipts = $this->svc->selectForCron();

        // the keys are 0, 1, 2 and the order is the database's, not the id order
        $this->assertSame(array((int) $third->id, (int) $first->id, (int) $second->id), $this->receiptIds($receipts));
        $this->assertContainsOnlyInstancesOf('IfirmaReceipt', $receipts);
        $this->assertSame(array('73', '71', '72'), array_map(function ($receipt) {
            return $receipt->ifirma_id;
        }, $receipts));
    }

    public function testSelectForCronSkipsARowWhoseReceiptDoesNotLoad()
    {
        $first = $this->receipt(74);
        $second = $this->receipt(75);
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', array(
            array('id_ifirma_receipt' => (string) $first->id),
            array('id_ifirma_receipt' => '9999'),
            array('id_ifirma_receipt' => '0'),
            array('id_ifirma_receipt' => (string) $second->id),
        ));

        $receipts = $this->svc->selectForCron();

        $this->assertSame(array((int) $first->id, (int) $second->id), $this->receiptIds($receipts));
    }

    public function testSelectForCronReturnsAnEmptyListWhenNoRowIsDue()
    {
        $this->assertSame(array(), $this->svc->selectForCron());
    }

    public function testCountHeldOrdersCountsTheOrdersOfThePendingDataRequestsInOneQuery()
    {
        Db::getInstance()->whenGetValue('/COUNT\(DISTINCT id_order\)/', '4');

        $this->assertSame(4, $this->svc->countHeldOrders());
        $this->assertSame(array($this->heldOrdersSql()), $this->sentQueries());
    }

    public function testCountHeldOrdersIsZeroWhenTheDatabaseAnswersNothing()
    {
        $this->assertSame(0, $this->svc->countHeldOrders());
    }

    public function testRunCronSendsTheDueReceiptsQueryFirstAndTheHeldOrdersCountLast()
    {
        Db::getInstance()->whenGetValue('/COUNT\(DISTINCT id_order\)/', 3);

        $summary = $this->svc->runCron();

        $this->assertSame(array($this->dueReceiptsSql(500), $this->heldOrdersSql()), $this->sentQueries());
        $this->assertSame(3, $summary['held_orders']);
        $this->assertSame(0, $summary['receipts_checked']);
    }

    public function testFrontControllerClassLoads()
    {
        $this->assertInstanceOf('ModuleFrontController', IfirmaTestModuleLoader::loadFrontController('cron'));
    }

    public function testCronDuringTheCoolDownTouchesNothing()
    {
        $this->receipt(201, '2026-10-30');
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', array(array('id_ifirma_receipt' => 1)));
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($this->now - 300) . '|Bad hash');
        $queriesBefore = count(Db::getInstance()->queries);

        $s = $this->svc->runCron();

        $this->assertTrue($s['auth_blocked']);
        $this->assertStringContainsString('calls are paused until', $s['message']);
        $this->assertStringContainsString('Bad hash', $s['message']);
        foreach (array('receipts_checked', 'updated', 'marked_paid', 'marked_missing', 'errors', 'rate_limited',
            'calls', 'dates_total', 'dates_processed', 'held_orders') as $key) {
            $this->assertSame(0, $s[$key], $key);
        }
        $this->assertSame(array(), $this->t->calls);
        $this->assertCount($queriesBefore, Db::getInstance()->queries, 'no receipt selected');
    }

    public function testCronAfterTheCoolDownRunsAndReportsNotBlocked()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($this->now - 900) . '|Bad hash');

        $s = $this->svc->runCron();

        $this->assertFalse($s['auth_blocked']);
        $this->assertSame('', $s['message']);
    }

    public function testCronWithoutAMarkReportsNotBlocked()
    {
        $s = $this->svc->runCron();

        $this->assertFalse($s['auth_blocked']);
    }
}
