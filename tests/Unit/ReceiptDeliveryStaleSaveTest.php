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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDeliveryRetryJob.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaStatusSyncService.php';

/**
 * Spec receipt-delivery "A retry never e-mails a customer twice": a save writes only the delivery state its object
 * changed. A writer that loaded the receipt before a delivery ended, and saves a sync field after it, must not put
 * the old failed state and its due time back (and must not erase a fresh claim).
 */
class ReceiptDeliveryStaleSaveTest extends TestCase
{
    const DELIVERY_COLUMNS = array('delivery_status', 'delivery_channel', 'delivered_at', 'delivery_error', 'delivery_attempts', 'delivery_retry_at');
    const PDF = "%PDF-1.4\nfake body\n%%EOF";

    /** @var int */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 1, 2026);
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
    }

    private function due()
    {
        return date('Y-m-d H:i:s', $this->now - 60);
    }

    /** A document whose delivery failed once and is due for its retry. */
    private function failedAndDue()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 10;
        $r->id_customer = 5;
        $r->id_shop = 1;
        $r->document_type = 'fakturaparagon';
        $r->ifirma_id = '710';
        $r->ifirma_number = '10/2026';
        $r->status = 'created';
        $r->source = 'module';
        $r->security_token = 'tok10';
        $r->issue_date = '2026-11-01';
        $r->delivery_status = 'failed';
        $r->delivery_channel = 'shop_mail';
        $r->delivery_error = 'earlier failure';
        $r->delivery_attempts = 1;
        $r->delivery_retry_at = $this->due();
        $r->save(false, false);
        IfirmaTestState::clearWrites();

        return (int) $r->id;
    }

    private function row($id)
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return $rows[$id];
    }

    /** PrestaShop fills the objects of a collection from rows with hydrate(), not through the constructor. */
    public function testAnObjectFilledFromARowRemembersItsDeliveryStateToo()
    {
        $id = $this->failedAndDue();
        $stale = new IfirmaReceipt();
        $stale->hydrate(array('id_ifirma_receipt' => $id) + $this->row($id));
        $this->deliveredByTheCron($id);

        $stale->sync_error = 'sync note';
        $stale->save();

        $row = $this->row($id);
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertNull($row['delivery_retry_at']);
        $this->assertSame(array(), array_intersect(self::DELIVERY_COLUMNS, $this->lastWrittenColumns()));
    }

    /** What the cron's retry does to the row: delivered, attempt counted, nothing due. */
    private function deliveredByTheCron($id)
    {
        $cron = IfirmaReceipt::loadFresh($id);
        $cron->recordDelivery(IfirmaReceipt::DELIVERY_SENT, 'shop_mail', '', 2, null);
        $cron->delivered_at = date('Y-m-d H:i:s', $this->now);
        $this->assertTrue($cron->save());
    }

    private function lastWrittenColumns()
    {
        $log = IfirmaTestState::writes('ifirma_receipt');

        return $log[count($log) - 1]['columns'];
    }

    public function testAStaleSaveOfASyncFieldDoesNotPutTheFailedStateBackAfterTheDeliveryEnded()
    {
        $id = $this->failedAndDue();
        $stale = new IfirmaReceipt($id);
        $this->deliveredByTheCron($id);

        $stale->last_sync_at = date('Y-m-d H:i:s', $this->now);
        $stale->sync_error = '';
        $stale->save();

        $row = $this->row($id);
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame(2, (int) $row['delivery_attempts']);
        $this->assertNull($row['delivery_retry_at']);
        $this->assertSame('', $row['delivery_error']);
        $this->assertNotEmpty($row['delivered_at']);
        $this->assertSame(array(), array_intersect(self::DELIVERY_COLUMNS, $this->lastWrittenColumns()));
        $this->assertContains('last_sync_at', $this->lastWrittenColumns());
    }

    /**
     * @return array[] the non-delivery field a stale writer changes and the value
     */
    public static function staleWriters()
    {
        return array(
            'sync time' => array('last_sync_at', '2026-11-01 10:15:20'),
            'sync error' => array('sync_error', 'timeout'),
            'KSeF state' => array('ksef_status', 'PRZYJETA'),
            'document status' => array('status', 'created'),
            'paid amount' => array('paid_amount', 12.5),
        );
    }

    /**
     * @dataProvider staleWriters
     */
    public function testNoWriterOfAnotherFieldRestoresTheDueDelivery($field, $value)
    {
        $id = $this->failedAndDue();
        $stale = new IfirmaReceipt($id);
        $this->deliveredByTheCron($id);

        $stale->$field = $value;
        $stale->save();

        $this->assertSame('sent', $this->row($id)['delivery_status']);
        $this->assertNull($this->row($id)['delivery_retry_at']);
    }

    public function testAStaleSaveDoesNotEraseAScheduleTheCronJustWrote()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 11;
        $r->ifirma_id = '711';
        $r->status = 'created';
        $r->delivery_status = 'sent';
        $r->delivery_channel = 'shop_mail';
        $r->save(false, false);
        $stale = new IfirmaReceipt((int) $r->id);
        $failing = IfirmaReceipt::loadFresh((int) $r->id);
        $failing->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'smtp down', 1, $this->now + 900);
        $failing->save();

        $stale->last_sync_at = date('Y-m-d H:i:s', $this->now);
        $stale->save();

        $row = $this->row((int) $r->id);
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame(1, (int) $row['delivery_attempts']);
        $this->assertSame(date('Y-m-d H:i:s', $this->now + 900), $row['delivery_retry_at']);
        $this->assertSame('smtp down', $row['delivery_error']);
    }

    public function testAStaleSaveDoesNotEraseAFreshClaimMarker()
    {
        $id = $this->failedAndDue();
        $stale = new IfirmaReceipt($id);
        $claim = IfirmaReceipt::loadFresh($id);
        $claim->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'attempt started', 2, null);
        $claim->save();

        $stale->sync_error = 'x';
        $stale->save();

        $row = $this->row($id);
        $this->assertSame('attempt started', $row['delivery_error']);
        $this->assertSame(2, (int) $row['delivery_attempts']);
        $this->assertNull($row['delivery_retry_at']);
    }

    public function testASaveWritesExactlyTheDeliveryColumnsItChanged()
    {
        $id = $this->failedAndDue();
        $receipt = new IfirmaReceipt($id);

        $receipt->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'attempt started', 2, null);
        $receipt->save();

        $written = array_values(array_intersect(self::DELIVERY_COLUMNS, $this->lastWrittenColumns()));
        $this->assertSame(array('delivery_error', 'delivery_attempts', 'delivery_retry_at'), $written);
        $this->assertSame('attempt started', $this->row($id)['delivery_error']);
        $this->assertNull($this->row($id)['delivery_retry_at']);
    }

    public function testADirectAssignmentOfTheDeliveryStatusIsWritten()
    {
        $id = $this->failedAndDue();
        $receipt = new IfirmaReceipt($id);

        $receipt->delivery_status = IfirmaReceipt::DELIVERY_SKIPPED;
        $receipt->save();

        $this->assertSame(array('delivery_status'), array_values(array_intersect(self::DELIVERY_COLUMNS, $this->lastWrittenColumns())));
        $this->assertSame('skipped', $this->row($id)['delivery_status']);
        $this->assertSame(1, (int) $this->row($id)['delivery_attempts']);
    }

    public function testANewReceiptWritesAllSixDeliveryColumns()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 12;
        $r->save(false, false);

        $log = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame('add', $log[count($log) - 1]['op']);
        $this->assertSame(self::DELIVERY_COLUMNS, array_values(array_intersect(self::DELIVERY_COLUMNS, $log[count($log) - 1]['columns'])));
    }

    public function testASecondSaveOfTheSameObjectWritesNoDeliveryColumnUnlessItChangedAgain()
    {
        $id = $this->failedAndDue();
        $receipt = new IfirmaReceipt($id);
        $receipt->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'attempt started', 2, null);
        $receipt->save();
        $this->deliveredByTheCron($id);

        $receipt->sync_error = 'later';
        $receipt->save();

        $this->assertSame(array(), array_intersect(self::DELIVERY_COLUMNS, $this->lastWrittenColumns()));
        $this->assertSame('sent', $this->row($id)['delivery_status']);
    }

    public function testAnInsertedObjectKeepsItsDeliveryStateOutOfLaterSaves()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 13;
        $r->delivery_status = 'failed';
        $r->save(false, false);

        $r->sync_error = 'x';
        $r->save();

        $this->assertSame(array(), array_intersect(self::DELIVERY_COLUMNS, $this->lastWrittenColumns()));
    }

    public function testAnObjectWithoutAKnownLoadedStateWritesEverythingAsBefore()
    {
        $id = $this->failedAndDue();
        $receipt = new IfirmaReceipt();
        $receipt->id = $id;
        $receipt->id_order = 10;
        $receipt->delivery_status = 'sent';

        $receipt->save();

        $this->assertSame(self::DELIVERY_COLUMNS, array_values(array_intersect(self::DELIVERY_COLUMNS, $this->lastWrittenColumns())));
    }

    public function testAPartialUpdateRequestedByTheCallerStaysPartialAndLosesUnchangedDeliveryColumns()
    {
        $id = $this->failedAndDue();
        $receipt = new IfirmaReceipt($id);
        $receipt->setFieldsToUpdate(array('sync_error' => true, 'delivery_status' => true));
        $receipt->sync_error = 'x';

        $receipt->save();

        $columns = $this->lastWrittenColumns();
        $this->assertContains('sync_error', $columns);
        $this->assertNotContains('delivery_status', $columns, 'unchanged since the load');
        $this->assertNotContains('status', $columns);
        $this->assertSame(array('sync_error' => true, 'delivery_status' => true), $receipt->getFieldsToUpdate(), 'the caller\'s setting is put back');
    }

    public function testASyncThatLoadedTheReceiptBeforeTheRetryDeliveredDoesNotMakeTheDocumentDueAgain()
    {
        Db::getInstance()->whenExecuteS("/FROM `ps_ifirma_receipt` WHERE delivery_status = 'failed' AND ifirma_id != '' AND delivery_retry_at IS NOT NULL/", function () {
            $out = array();
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                $due = (string) $row['delivery_retry_at'];
                if ($row['delivery_status'] === 'failed' && $due > '2000-01-01 00:00:00' && $due <= date('Y-m-d H:i:s', $this->now)) {
                    $out[] = array('id_ifirma_receipt' => (string) $id);
                }
            }

            return $out;
        });
        Db::getInstance()->whenGetValue("/FROM `ps_ifirma_receipt` WHERE delivery_status = 'failed' AND ifirma_id != '' AND delivery_retry_at IS NOT NULL/", 0);
        $id = $this->failedAndDue();
        $this->assertCount(1, IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now));
        $loadedBeforeTheDelivery = new IfirmaReceipt($id);

        $t = new ScriptedTransport();
        $client = new IfirmaApiClient('u', array('faktura' => 'abcd'), 'http://ifirma-mock:8001/iapi/', 30, $t);
        $client->setClock(function () {
            return $this->now;
        });
        $sync = new IfirmaStatusSyncService($client, function () {
            return $this->now;
        });
        $t->queue('GET', '#faktury\.json#', function () use ($id) {
            $this->deliveredByTheCron($id);

            return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array(array(
                'FakturaId' => 710, 'PelnyNumer' => '10/2026', 'Brutto' => 100.0, 'Zaplacono' => 0.0,
                'CzyWyslano' => false, 'CzySzkic' => false, 'KsefStatus' => '', 'KsefNumer' => '',
                'NumerPowiazanegoPrzychodu' => null,
            ))))));
        });

        $sync->syncReceipt($loadedBeforeTheDelivery);

        $this->assertSame('sent', $this->row($id)['delivery_status']);
        $this->assertSame(array(), IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now));
        $module = new Module();
        $delivery = new IfirmaDeliveryService($client, new IfirmaReceiptService($client, $module), $module, function () {
            return $this->now;
        }, function () {
        });
        $report = (new IfirmaDeliveryRetryJob($delivery))->run(new IfirmaCronContext(10, function () {
            return $this->now;
        }));
        $this->assertSame(0, $report->block()['handled']);
        $this->assertCount(0, Mail::$sent);
    }
}
