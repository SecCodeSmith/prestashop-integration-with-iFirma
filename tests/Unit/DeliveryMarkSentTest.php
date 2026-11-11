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
 * Spec receipt-delivery, "An employee can mark a document as sent": the state change of
 * IfirmaDeliveryService::markSent() sends no e-mail and no request, ends a retry or a KSeF wait, and is refused for a
 * document that is not issued, is missing in iFirma or is already sent.
 */
class DeliveryMarkSentTest extends TestCase
{
    const NOW = 1800000000;
    const EMPLOYEE = 7;

    /** @var int */
    public $now;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaDeliveryService */
    private $svc;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = self::NOW;
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $module = new Module();
        $self = $this;
        $this->svc = new IfirmaDeliveryService(
            $client,
            new IfirmaReceiptService($client, $module),
            $module,
            function () use ($self) {
                return $self->now;
            },
            function () {
            }
        );
    }

    /** A saved receipt that carries $fields; the seeding writes are cleared. */
    private function seeded(array $fields)
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->document_type = 'fakturaparagon';
        $r->ifirma_id = '77';
        $r->ifirma_number = '7/2026';
        $r->status = 'created';
        $r->source = 'module';
        $r->issue_date = '2026-11-01';
        foreach ($fields as $name => $value) {
            $r->$name = $value;
        }
        $r->save();
        IfirmaTestState::clearWrites();

        return $r;
    }

    private function row()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return reset($rows);
    }

    private function assertNothingWasSent()
    {
        $this->assertCount(0, $this->t->calls, 'no request to iFirma');
        $this->assertSame(array(), Mail::$sent, 'no e-mail');
    }

    public function testAFailedDeliveryBecomesSentThroughTheManualChannelAtTheClockTime()
    {
        $r = $this->seeded(array('delivery_status' => 'failed', 'delivery_channel' => 'shop_mail', 'delivery_error' => 'smtp down'));

        $res = $this->svc->markSent($r, self::EMPLOYEE);

        $this->assertTrue($res->isSent());
        $this->assertSame('sent', $res->getStatus());
        $row = $this->row();
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame('manual', $row['delivery_channel']);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW), $row['delivered_at']);
        $this->assertSame('', $row['delivery_error']);
        $this->assertNothingWasSent();
    }

    public function testASkippedDeliveryIsRecordedAsSentThroughTheManualChannel()
    {
        $r = $this->seeded(array('delivery_status' => 'skipped', 'delivery_channel' => 'none'));

        $this->svc->markSent($r, self::EMPLOYEE);

        $row = $this->row();
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame('manual', $row['delivery_channel']);
    }

    public function testThePendingAutomaticRetryIsCancelled()
    {
        $r = $this->seeded(array(
            'delivery_status' => 'failed',
            'delivery_attempts' => 1,
            'delivery_retry_at' => date('Y-m-d H:i:s', self::NOW - 60),
        ));

        $this->svc->markSent($r, self::EMPLOYEE);

        $row = $this->row();
        $this->assertNull($row['delivery_retry_at']);
        $this->assertFalse(IfirmaReceipt::loadFresh((int) $r->id)->hasDeliveryRetryScheduled());
        $this->assertNothingWasSent();
    }

    public function testADocumentAwaitingKsefLeavesTheWaitingState()
    {
        $r = $this->seeded(array('delivery_status' => 'awaiting_ksef', 'ksef_status' => 'submitted', 'ksef_number' => ''));

        $res = $this->svc->markSent($r, self::EMPLOYEE);

        $this->assertTrue($res->isSent());
        $fresh = IfirmaReceipt::loadFresh((int) $r->id);
        $this->assertFalse(IfirmaKsefDeliveryGate::isWaitingState($fresh->delivery_status, $fresh->ksef_status, $fresh->ksef_number));
    }

    public function testAnAlreadySentDocumentIsRefusedAndKeepsItsTimeAndChannel()
    {
        $r = $this->seeded(array(
            'delivery_status' => 'sent',
            'delivery_channel' => 'shop_mail',
            'delivered_at' => '2026-11-02 10:00:00',
        ));

        $res = $this->svc->markSent($r, self::EMPLOYEE);

        $this->assertFalse($res->isSent());
        $this->assertSame('This document is already recorded as sent.', $res->getMessage());
        $row = $this->row();
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame('2026-11-02 10:00:00', $row['delivered_at']);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertSame(array(), PrestaShopLogger::$logs);
    }

    public function testADocumentThatIsNotIssuedIsRefusedAndNothingIsRecorded()
    {
        $r = $this->seeded(array('ifirma_id' => '', 'delivery_status' => 'none'));

        $res = $this->svc->markSent($r, self::EMPLOYEE);

        $this->assertFalse($res->isSent());
        $this->assertSame('The document has not been issued yet.', $res->getMessage());
        $this->assertSame('none', $this->row()['delivery_status']);
        $this->assertSame(array(), PrestaShopLogger::$logs);
    }

    public function testADocumentMissingInIfirmaIsRefusedAndNothingIsRecorded()
    {
        $r = $this->seeded(array('remote_missing' => 1, 'delivery_status' => 'failed'));

        $res = $this->svc->markSent($r, self::EMPLOYEE);

        $this->assertFalse($res->isSent());
        $this->assertSame('The document is missing in iFirma.', $res->getMessage());
        $this->assertSame('failed', $this->row()['delivery_status']);
    }

    public function testAFlagThatChangedWhileTheCallerWaitedForTheLockIsHonouredAndNotWrittenOver()
    {
        $stale = $this->seeded(array('delivery_status' => 'failed'));
        $other = IfirmaReceipt::loadFresh((int) $stale->id);
        $other->remote_missing = 1;
        $other->save();

        $res = $this->svc->markSent($stale, self::EMPLOYEE);

        $this->assertFalse($res->isSent());
        $this->assertSame('The document is missing in iFirma.', $res->getMessage());
        $this->assertSame('failed', $this->row()['delivery_status']);
        $this->assertEquals(1, $this->row()['remote_missing']);
    }

    public function testASaveTheDatabaseRefusesLeavesTheFailedDeliveryAsItWasAndLogsNothingAboutMarking()
    {
        $r = $this->seeded(array(
            'delivery_status' => 'failed',
            'delivery_channel' => 'shop_mail',
            'delivery_error' => 'smtp down',
            'delivered_at' => '2026-11-02 10:00:00',
        ));
        $table = IfirmaReceipt::$definition['table'];
        $refusing = new class(ObjectModel::$registry[$table]) extends ArrayObject {
            public function offsetSet($key, $value): void
            {
                throw new RuntimeException('the database refused the write');
            }
        };
        ObjectModel::$registry[$table] = $refusing;

        try {
            $res = $this->svc->markSent($r, self::EMPLOYEE);
        } finally {
            ObjectModel::$registry[$table] = $refusing->getArrayCopy();
        }

        $this->assertFalse($res->isSent());
        $this->assertSame('The delivery state could not be saved.', $res->getMessage());
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame('2026-11-02 10:00:00', $row['delivered_at']);
        $this->assertCount(1, PrestaShopLogger::$logs, 'only the failed save is logged');
        $this->assertStringNotContainsString('marked as sent', PrestaShopLogger::$logs[0]['message']);
        $this->assertNothingWasSent();
    }

    public function testOneLogEntryNamesTheRecordAndTheEmployeeAndNoCustomerData()
    {
        $r = $this->seeded(array('delivery_status' => 'failed', 'delivery_error' => 'jan@example.test refused'));

        $this->svc->markSent($r, self::EMPLOYEE);

        $this->assertCount(1, PrestaShopLogger::$logs);
        $message = PrestaShopLogger::$logs[0]['message'];
        $this->assertStringContainsString('record ' . (int) $r->id, $message);
        $this->assertStringContainsString('employee ' . self::EMPLOYEE, $message);
        $this->assertStringNotContainsString('jan@example.test', $message);
        $this->assertStringNotContainsString('7/2026', $message);
    }

    public function testTheDeliveryTextOfAManuallyMarkedDocumentSaysSoAndNamesTheTime()
    {
        $r = $this->seeded(array('delivery_status' => 'failed'));
        $this->svc->markSent($r, self::EMPLOYEE);

        $text = $this->svc->describeDelivery(IfirmaReceipt::loadFresh((int) $r->id));

        $this->assertSame('Marked as sent on ' . date('Y-m-d H:i:s', self::NOW) . ' by an employee', $text);
    }

    public function testTheManualChannelIsNotAChoiceOfTheSendChannelSetting()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'manual');

        $this->assertSame('shop_mail', $this->svc->getChannel());
    }
}
