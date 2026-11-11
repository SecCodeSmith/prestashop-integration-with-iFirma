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
 * Spec receipt-documents-list, "Bulk mark as sent": the bulk runner records the selected documents as sent without a
 * request to iFirma, so neither the request limit nor the authentication pause stops it.
 */
class BulkRunnerMarkSentTest extends TestCase
{
    use IfirmaWiringFixture;

    const EMPLOYEE = 9;

    /** @var int */
    public $now = 1800000000;

    protected function setUp(): void
    {
        $this->wiringSetUp();
    }

    private function runner()
    {
        $self = $this;
        $client = IfirmaApiClient::fromConfiguration();
        $delivery = new IfirmaDeliveryService(
            $client,
            new IfirmaReceiptService($client, $this->module),
            $this->module,
            function () use ($self) {
                return $self->now;
            },
            function () {
            }
        );

        return (new IfirmaBulkRunner($this->module, function () use ($self) {
            return $self->now;
        }))->setDeliveryService($delivery);
    }

    private function failedDelivery($idOrder)
    {
        return (int) $this->makeReceipt(array(
            'id_order' => $idOrder,
            'delivery_status' => 'failed',
            'delivery_channel' => 'shop_mail',
            'delivery_error' => 'smtp down',
        ))->id;
    }

    private function deliveryOf($id)
    {
        $r = new IfirmaReceipt((int) $id);

        return array($r->delivery_status, $r->delivery_channel);
    }

    public function testTheKindIsListedWithItsBatchSize()
    {
        $this->assertContains('mark_sent', IfirmaBulkRunner::kinds());
        $this->assertSame('mark_sent', IfirmaBulkRunner::KIND_MARK_SENT);
        $this->assertSame(25, IfirmaBulkRunner::batchSize('mark_sent'));
    }

    public function testAMixedSelectionMarksTheFailedOnesAndSkipsTheRestWithTheirReasons()
    {
        $failedA = $this->failedDelivery(31);
        $failedB = $this->failedDelivery(32);
        $alreadySent = (int) $this->makeReceipt(array('id_order' => 33, 'delivery_status' => 'sent'))->id;
        $notIssued = (int) $this->makeReceipt(array('id_order' => 34, 'status' => 'error', 'ifirma_id' => ''))->id;

        $data = $this->runner()->run('mark_sent', array($failedA, $failedB, $alreadySent, $notIssued), self::EMPLOYEE);

        $this->assertSame(array('sent', 'sent', 'skipped', 'skipped'), array_column($data['results'], 'outcome'));
        $this->assertSame('This document is already recorded as sent.', $data['results'][2]['message']);
        $this->assertSame('The document has not been issued yet.', $data['results'][3]['message']);
        $this->assertSame(array('sent', 'manual'), $this->deliveryOf($failedA));
        $this->assertSame(array('sent', 'manual'), $this->deliveryOf($failedB));
        $this->assertSame('', $data['stopped']);
        $this->assertSame(array(), $data['remaining']);
    }

    public function testADocumentMissingInIFirmaIsSkippedWithItsReason()
    {
        $missing = (int) $this->makeReceipt(array('id_order' => 35, 'remote_missing' => 1))->id;

        $data = $this->runner()->run('mark_sent', array($missing), self::EMPLOYEE);

        $this->assertSame(array('skipped'), array_column($data['results'], 'outcome'));
        $this->assertSame('The document is missing in iFirma.', $data['results'][0]['message']);
    }

    public function testADocumentThatDoesNotLoadIsSkippedAndDoesNotPauseTheRunOnRejectedCredentials()
    {
        $ok = $this->failedDelivery(36);

        $runner = $this->runner()->setAuthProbe(function () {
            return true;
        });
        $data = $runner->run('mark_sent', array(555, $ok), self::EMPLOYEE);

        $this->assertSame(array('skipped', 'sent'), array_column($data['results'], 'outcome'));
        $this->assertSame('', $data['stopped']);
    }

    public function testAReachedRequestLimitDoesNotStopTheRunAndNoRequestIsSent()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':80');
        $ids = array($this->failedDelivery(41), $this->failedDelivery(42), $this->failedDelivery(43));

        $data = $this->runner()->run('mark_sent', $ids, self::EMPLOYEE);

        $this->assertSame(array('sent', 'sent', 'sent'), array_column($data['results'], 'outcome'));
        $this->assertSame('', $data['stopped']);
        $this->assertCount(0, $this->t->calls, 'no request to iFirma');
        $this->assertSame(array(), Mail::$sent, 'no e-mail');
    }

    public function testAnAuthenticationPauseDoesNotStopTheRunAndNoRequestIsSent()
    {
        $ids = array($this->failedDelivery(44), $this->failedDelivery(45), $this->failedDelivery(46));

        $runner = $this->runner()->setAuthProbe(function () {
            return true;
        });
        $data = $runner->run('mark_sent', $ids, self::EMPLOYEE);

        $this->assertSame(array('sent', 'sent', 'sent'), array_column($data['results'], 'outcome'));
        $this->assertSame('', $data['stopped']);
        $this->assertCount(0, $this->t->calls, 'no request to iFirma');
    }

    public function testTheAuditEntryCarriesTheEmployeeAndTheCountsAndNoCustomerData()
    {
        $marked = $this->failedDelivery(51);
        $skipped = (int) $this->makeReceipt(array('id_order' => 52, 'delivery_status' => 'sent'))->id;
        PrestaShopLogger::$logs = array();

        $this->runner()->run('mark_sent', array($marked, $skipped), self::EMPLOYEE, array('final' => true));

        $messages = array_column(PrestaShopLogger::$logs, 'message');
        $bulk = array_values(array_filter($messages, function ($m) {
            return strpos($m, 'iFirma bulk ') === 0;
        }));
        $this->assertSame(array('iFirma bulk mark_sent by employee 9: sent=1, skipped=1'), $bulk);
    }
}
