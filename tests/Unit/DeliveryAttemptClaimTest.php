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
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAwaitingDeliveryJob.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaIssueFeed.php';

/**
 * An automatic delivery attempt is claimed in the database before anything is sent (receipt-delivery spec, "A retry
 * never e-mails a customer twice"): a process that dies, or a save that fails, after the e-mail left must leave a record
 * the cron never sends again and that needs attention.
 */
class DeliveryAttemptClaimTest extends TestCase
{
    const PDF = "%PDF-1.4\nfake body\n%%EOF";
    const MARKER = 'A delivery attempt started and its outcome was not recorded. Check whether the customer received the document before sending it again.';

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var int */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 1, 2026);
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->client->setClock(function () {
            return $this->now;
        });
        $c = new Customer();
        $c->id = 5;
        $c->email = 'jan@example.test';
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        Customer::register($c);

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
        Db::getInstance()->whenExecuteS("/FROM `ps_ifirma_receipt` WHERE delivery_status = 'awaiting_ksef' AND \(ksef_number != /", function () {
            $out = array();
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ($row['delivery_status'] === 'awaiting_ksef' && trim((string) $row['ksef_number']) !== '') {
                    $out[] = array('id_ifirma_receipt' => (string) $id);
                }
            }

            return $out;
        });
        Db::getInstance()->whenGetValue("/FROM `ps_ifirma_receipt` WHERE delivery_status = 'awaiting_ksef' AND \(ksef_number != /", 0);
    }

    /** @param callable|null $clock the clock of the delivery service */
    private function service($clock = null)
    {
        $module = new Module();

        return new IfirmaDeliveryService(
            $this->client,
            new IfirmaReceiptService($this->client, $module),
            $module,
            $clock !== null ? $clock : function () {
                return $this->now;
            },
            function () {
            }
        );
    }

    /** A clock that dies, as a killed process does, once the e-mail has left. */
    private function clockThatDiesAfterTheMailLeft()
    {
        return function () {
            if (Mail::$sent !== array()) {
                throw new Error('process killed after the mail left');
            }

            return $this->now;
        };
    }

    private function context()
    {
        return new IfirmaCronContext(10, function () {
            return $this->now;
        });
    }

    private function order($idOrder)
    {
        $o = new Order();
        $o->id = $idOrder;
        $o->id_customer = 5;
        $o->id_lang = 1;
        $o->reference = 'REF' . $idOrder;
        Order::register($o);
    }

    private function seed($idOrder, array $over)
    {
        $this->order($idOrder);
        $r = new IfirmaReceipt();
        $r->id_order = $idOrder;
        $r->id_customer = 5;
        $r->id_shop = 1;
        $r->document_type = 'fakturaparagon';
        $r->ifirma_id = (string) (700 + $idOrder);
        $r->ifirma_number = $idOrder . '/2026';
        $r->status = 'created';
        $r->source = 'module';
        $r->security_token = 'tok' . $idOrder;
        $r->issue_date = '2026-11-01';
        foreach ($over as $field => $value) {
            $r->$field = $value;
        }
        $r->date_add = '2026-11-01 09:00:00';
        $r->save(false, false);
        IfirmaTestState::clearWrites();

        return (int) $r->id;
    }

    private function failedDue($idOrder, $attempts)
    {
        return $this->seed($idOrder, array(
            'delivery_status' => 'failed',
            'delivery_channel' => 'shop_mail',
            'delivery_error' => 'earlier failure',
            'delivery_attempts' => $attempts,
            'delivery_retry_at' => date('Y-m-d H:i:s', $this->now - 60),
        ));
    }

    private function acceptedAwaiting($idOrder)
    {
        return $this->seed($idOrder, array(
            'document_type' => 'fakturakraj',
            'delivery_status' => 'awaiting_ksef',
            'ksef_number' => '5260001122-20261101-ABC-01',
        ));
    }

    private function row($id)
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return $rows[$id];
    }

    private function pdfOk($times = 1)
    {
        for ($i = 0; $i < $times; $i++) {
            $this->t->queue('GET', '#\.pdf#', array('body' => self::PDF));
        }
    }

    private function runRetryJob(IfirmaDeliveryService $service)
    {
        return (new IfirmaDeliveryRetryJob($service))->run($this->context());
    }

    private function assertClaimedAndNeverRetried($id, $attempts)
    {
        $row = $this->row($id);
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame($attempts, (int) $row['delivery_attempts']);
        $this->assertNull($row['delivery_retry_at']);
        $this->assertSame(self::MARKER, $row['delivery_error']);
        $this->assertSame(IfirmaIssueFeed::KIND_DELIVERY_FAILED, IfirmaIssueFeed::classify($row));

        $this->now += 86400;
        $again = $this->runRetryJob($this->service());
        $this->assertSame(0, $again->block()['handled']);
        $this->assertCount(1, Mail::$sent, 'the cron never sends the document again');
    }

    public function testTheAttemptIsCountedAndRecordedBeforeThePdfIsEvenRequested()
    {
        $id = $this->failedDue(10, 1);
        $seen = array();
        $this->t->queue('GET', '#\.pdf#', function () use ($id, &$seen) {
            $seen = $this->row($id);

            return array('body' => self::PDF);
        });

        $this->runRetryJob($this->service());

        $this->assertSame('failed', $seen['delivery_status']);
        $this->assertSame(2, (int) $seen['delivery_attempts']);
        $this->assertNull($seen['delivery_retry_at']);
        $this->assertSame(self::MARKER, $seen['delivery_error']);
        $this->assertSame('sent', $this->row($id)['delivery_status']);
    }

    public function testAProcessThatDiesAfterTheMailLeftLeavesTheDocumentNeverRetried()
    {
        $id = $this->failedDue(11, 1);
        $this->pdfOk();

        try {
            $this->runRetryJob($this->service($this->clockThatDiesAfterTheMailLeft()));
            $this->fail('the killed process must not return');
        } catch (Error $e) {
            $this->assertSame('process killed after the mail left', $e->getMessage());
        }

        $this->assertCount(1, Mail::$sent);
        $this->assertClaimedAndNeverRetried($id, 2);
    }

    public function testAnOutcomeThatCannotBeSavedAfterASuccessfulSendLeavesTheDocumentNeverRetried()
    {
        $id = $this->failedDue(12, 1);
        $this->pdfOk();
        $record = new class($id) extends IfirmaReceipt {
            public $saves = 0;

            public function save($nullValues = false, $autoDate = true)
            {
                return ++$this->saves === 1 ? parent::save($nullValues, $autoDate) : false;
            }
        };

        $result = $this->service()->deliver($record, null, array(IfirmaDeliveryService::OPTION_CLAIM_ATTEMPT => true));

        $this->assertTrue($result->isSent(), 'the mail did leave');
        $this->assertSame(2, $record->saves);
        $this->assertClaimedAndNeverRetried($id, 2);
    }

    public function testAnOutcomeSaveThatThrowsAfterASuccessfulSendLeavesTheDocumentNeverRetried()
    {
        $id = $this->failedDue(13, 1);
        $this->pdfOk();
        $record = new class($id) extends IfirmaReceipt {
            public $saves = 0;

            public function save($nullValues = false, $autoDate = true)
            {
                if (++$this->saves === 1) {
                    return parent::save($nullValues, $autoDate);
                }
                throw new RuntimeException('db gone away');
            }
        };

        $this->service()->deliver($record, null, array(IfirmaDeliveryService::OPTION_CLAIM_ATTEMPT => true));

        $this->assertClaimedAndNeverRetried($id, 2);
    }

    public function testNothingIsSentWhenTheClaimCannotBeSaved()
    {
        $id = $this->failedDue(14, 1);
        $record = new class($id) extends IfirmaReceipt {
            public function save($nullValues = false, $autoDate = true)
            {
                return false;
            }
        };

        $result = $this->service()->deliver($record, null, array(IfirmaDeliveryService::OPTION_CLAIM_ATTEMPT => true));

        $this->assertFalse($result->isSent());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(), $this->t->calls);
        $row = $this->row($id);
        $this->assertSame(1, (int) $row['delivery_attempts']);
        $this->assertSame('earlier failure', $row['delivery_error']);
        $this->assertSame(1, (int) $record->delivery_attempts, 'the in-memory record is put back as it was');
    }

    public function testNothingIsSentWhenTheClaimSaveThrows()
    {
        $id = $this->failedDue(15, 0);
        $record = new class($id) extends IfirmaReceipt {
            public function save($nullValues = false, $autoDate = true)
            {
                throw new RuntimeException('db gone away');
            }
        };

        $result = $this->service()->deliver($record, null, array(IfirmaDeliveryService::OPTION_CLAIM_ATTEMPT => true));

        $this->assertFalse($result->isSent());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testATemporaryFailureAfterTheClaimCountsTheAttemptOnceAndSchedulesTheNextOne()
    {
        $id = $this->failedDue(16, 1);
        $this->pdfOk();
        Mail::$result = false;

        $this->runRetryJob($this->service());

        $row = $this->row($id);
        $this->assertSame(2, (int) $row['delivery_attempts']);
        $this->assertSame(date('Y-m-d H:i:s', $this->now + 7200), $row['delivery_retry_at']);
        $this->assertNotSame(self::MARKER, $row['delivery_error']);
    }

    public function testARefusalAfterTheClaimGivesTheAttemptBackAndLeavesTheDeliveryDue()
    {
        $id = $this->failedDue(17, 1);
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|HTTP 401 returned by iFirma.');

        $this->runRetryJob($this->service());

        $row = $this->row($id);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(1, (int) $row['delivery_attempts']);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $row['delivery_retry_at']);
        $this->assertNotSame(self::MARKER, $row['delivery_error']);
    }

    public function testAnAwaitingKsefFirstDeliveryThatDiesAfterTheMailLeftIsNeverPickedAgain()
    {
        $id = $this->acceptedAwaiting(20);
        $this->pdfOk();

        try {
            (new IfirmaAwaitingDeliveryJob($this->service($this->clockThatDiesAfterTheMailLeft())))->run($this->context());
            $this->fail('the killed process must not return');
        } catch (Error $e) {
            $this->assertSame('process killed after the mail left', $e->getMessage());
        }

        $this->assertClaimedAndNeverRetried($id, 1);
        $again = (new IfirmaAwaitingDeliveryJob($this->service()))->run($this->context());
        $this->assertSame(0, $again->block()['handled'], 'the awaiting job does not pick it up either');
        $this->assertCount(1, Mail::$sent);
    }

    public function testAnAwaitingKsefDeliveryRefusedAfterTheClaimIsDueAtOnceWithNoAttemptCounted()
    {
        $id = $this->acceptedAwaiting(21);
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|HTTP 401 returned by iFirma.');

        (new IfirmaAwaitingDeliveryJob($this->service()))->run($this->context());

        $row = $this->row($id);
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame(0, (int) $row['delivery_attempts']);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $row['delivery_retry_at']);
        $this->assertCount(0, Mail::$sent);
    }

    public function testAManualResendDoesNotClaimAnAttempt()
    {
        $id = $this->failedDue(22, 1);
        $seen = array();
        $this->t->queue('GET', '#\.pdf#', function () use ($id, &$seen) {
            $seen = $this->row($id);

            return array('body' => self::PDF);
        });

        $this->service()->resend(new IfirmaReceipt($id));

        $this->assertSame('earlier failure', $seen['delivery_error']);
        $this->assertSame(1, (int) $seen['delivery_attempts']);
    }
}
