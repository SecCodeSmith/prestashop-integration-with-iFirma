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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaKsefRetryJob.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAwaitingDeliveryJob.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaStatusSyncJob.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCronRunner.php';

/**
 * The two cron jobs of KSeF-confirmed delivery: the deferred KSeF submissions and the deliveries after acceptance.
 */
class KsefCronJobsTest extends TestCase
{
    const NUMBER = '5260001122-20261101-ABC-01';

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaKsefService */
    private $ksef;

    /** @var IfirmaDeliveryService */
    private $delivery;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'b2b');
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $module = new Module();
        $this->ksef = new IfirmaKsefService($this->client, $module);
        $service = new IfirmaReceiptService($this->client, $module);
        $this->delivery = new IfirmaDeliveryService($this->client, $service, $module, function () {
            return mktime(10, 15, 20, 11, 1, 2026);
        }, function () {
        });

        $c = new Customer();
        $c->id = 5;
        $c->email = 'jan@example.test';
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        Customer::register($c);
        $a = new Address();
        $a->id = 7;
        $a->id_customer = 5;
        $a->id_country = 14;
        Address::register($a);

        $this->scriptSelection('/FROM `ps_ifirma_receipt`\s+WHERE delivery_status = \'awaiting_ksef\' AND ifirma_id != \'\' AND \(ksef_status IS NULL/', function ($r) {
            return $r['delivery_status'] === 'awaiting_ksef' && trim((string) $r['ksef_status']) === '' && trim((string) $r['ksef_number']) === '';
        });
        $this->scriptSelection('/FROM `ps_ifirma_receipt`\s+WHERE delivery_status = \'awaiting_ksef\' AND \(ksef_number != /', function ($r) {
            return $r['delivery_status'] === 'awaiting_ksef' && (trim((string) $r['ksef_number']) !== '' || $r['ksef_status'] === 'PRZYJETA');
        });
    }

    /**
     * Scripts one selection query and its count against the stored rows, oldest first (date_add, then id).
     */
    private function scriptSelection($regex, $keep)
    {
        $rows = function ($limit = 500) use ($keep) {
            $out = array();
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ($keep($row)) {
                    $out[] = array('id_ifirma_receipt' => (string) $id, 'date_add' => $row['date_add'], 'id' => $id);
                }
            }
            usort($out, function ($a, $b) {
                return strcmp($a['date_add'], $b['date_add']) ?: $a['id'] - $b['id'];
            });

            return array_slice($out, 0, $limit);
        };
        Db::getInstance()->whenExecuteS($regex, function ($sql) use ($rows) {
            return $rows(preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : 500);
        });
        Db::getInstance()->whenGetValue($regex, function () use ($rows) {
            return count($rows(100000));
        });
    }

    private function order($id)
    {
        $o = new Order();
        $o->id = $id;
        $o->id_customer = 5;
        $o->id_address_invoice = 7;
        $o->id_lang = 1;
        $o->reference = 'REF' . $id;
        Order::register($o);
    }

    /** An invoice to a buyer with a NIP, issued, delivery waiting for KSeF. */
    private function invoice($idOrder, $age, array $over = array())
    {
        $this->order($idOrder);
        $r = new IfirmaReceipt();
        $r->id_order = $idOrder;
        $r->id_customer = 5;
        $r->id_shop = 1;
        $r->document_type = 'fakturakraj';
        $r->ifirma_id = (string) (700 + $idOrder);
        $r->ifirma_number = $idOrder . '/2026';
        $r->status = 'created';
        $r->source = 'module';
        $r->security_token = 'tok' . $idOrder;
        $r->delivery_status = 'awaiting_ksef';
        $r->ksef_status = '';
        $r->ksef_number = '';
        $r->request_payload = json_encode(array('Kontrahent' => array('Nazwa' => 'ACME', 'NIP' => '5260001122')));
        foreach ($over as $field => $value) {
            $r->$field = $value;
        }
        $r->date_add = date('Y-m-d H:i:s', mktime(9, 0, 0, 11, 1, 2026) + $age);
        $r->save(false, false);
        IfirmaTestState::clearWrites();

        return $r;
    }

    private function context($max = 10)
    {
        return new IfirmaCronContext($max, function () {
            return mktime(10, 15, 20, 11, 1, 2026);
        });
    }

    private function row($idOrder)
    {
        foreach (IfirmaTestState::objects('ifirma_receipt') as $row) {
            if ((int) $row['id_order'] === $idOrder) {
                return $row;
            }
        }

        return null;
    }

    private function posts()
    {
        $posts = array();
        foreach ($this->t->calls as $call) {
            if ($call['method'] === 'POST') {
                $posts[] = $call;
            }
        }

        return $posts;
    }

    private function remote($status, $number = '')
    {
        $this->t->queue('GET', '#fakturakraj/\d+\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'KsefStatus' => $status, 'KsefNumer' => $number,
        )))));
    }

    private function ksefJob()
    {
        return new IfirmaKsefRetryJob($this->ksef);
    }

    private function deliveryJob()
    {
        return new IfirmaAwaitingDeliveryJob($this->delivery);
    }

    // ---- the deferred submissions ------------------------------------------------------------------------

    public function testADeferredSubmissionIsReadAndSubmittedOnce()
    {
        $this->invoice(10, 1);
        $this->remote('');
        $this->t->queue('POST', '#ksef/send/710\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok"}}'));

        $report = $this->ksefJob()->run($this->context());

        $this->assertCount(1, $this->posts());
        $this->assertSame('WYSLANA', $this->row(10)['ksef_status']);
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 0), $report->block());
    }

    public function testNothingIsSubmittedWhenIfirmaSentTheInvoiceInTheMeantime()
    {
        $this->invoice(11, 1);
        $this->remote('WYSLANA');

        $report = $this->ksefJob()->run($this->context());

        $this->assertSame(array(), $this->posts());
        $this->assertSame('WYSLANA', $this->row(11)['ksef_status'], 'the reported state is stored');
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 0), $report->block());
    }

    public function testTheSubmitterIfirmaMakesTheJobDoNothing()
    {
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');
        $this->invoice(12, 1);

        $report = $this->ksefJob()->run($this->context());

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(0, $report->block()['handled']);
    }

    public function testTheOldestInvoicesComeFirstAndTheItemCapApplies()
    {
        $this->invoice(21, 30);
        $this->invoice(20, 10);
        $this->invoice(22, 20);
        foreach (array(20, 22) as $order) {
            $this->remote('');
            $this->t->queue('POST', '#ksef/send/' . (700 + $order) . '\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok"}}'));
        }

        $report = $this->ksefJob()->run($this->context(2));

        $urls = array_column($this->posts(), 'url');
        $this->assertStringContainsString('/720.json', $urls[0]);
        $this->assertStringContainsString('/722.json', $urls[1]);
        $this->assertSame(array('handled' => 2, 'succeeded' => 2, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testARateLimitedStateReadEndsTheJobAndLeavesTheInvoiceDeferred()
    {
        $fixed = mktime(10, 15, 20, 11, 1, 2026);
        $this->client->setClock(function () use ($fixed) {
            return $fixed;
        });
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $fixed) . ':80');
        $this->invoice(30, 1);
        $this->invoice(31, 2);

        $report = $this->ksefJob()->run($this->context());

        $this->assertTrue($report->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $report->getRateWindow());
        $this->assertSame(array(), $this->t->calls, 'no request after the refusal');
        $this->assertSame('', $this->row(30)['ksef_status']);
        $this->assertSame(array('handled' => 1, 'succeeded' => 0, 'failed' => 0, 'left' => 2), $report->block());
    }

    public function testAnInvoiceWithAKsefStateIsNotSelectedForSubmission()
    {
        $this->invoice(40, 1, array('ksef_status' => 'WYSLANA'));
        $this->invoice(41, 2, array('ksef_number' => self::NUMBER));

        $report = $this->ksefJob()->run($this->context());

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(0, $report->block()['handled']);
    }

    // ---- the deliveries after acceptance ----------------------------------------------------------------------

    public function testAnAcceptedDocumentIsDeliveredOnceByTheCron()
    {
        $this->invoice(50, 1, array('ksef_number' => self::NUMBER, 'ksef_status' => 'PRZYJETA'));
        $this->t->queue('GET', '#fakturakraj/750\.pdf#', array('body' => "%PDF-1.4\nbody\n%%EOF"));

        $report = $this->deliveryJob()->run($this->context());

        $this->assertSame('sent', $this->row(50)['delivery_status']);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 0), $report->block());

        $again = $this->deliveryJob()->run($this->context());
        $this->assertCount(1, Mail::$sent, 'no second e-mail');
        $this->assertSame(0, $again->block()['handled']);
    }

    public function testADocumentThatIsNotAcceptedYetIsNotDelivered()
    {
        $this->invoice(51, 1, array('ksef_status' => 'WYSLANA'));

        $report = $this->deliveryJob()->run($this->context());

        $this->assertSame(array(), $this->t->calls);
        $this->assertCount(0, Mail::$sent);
        $this->assertSame('awaiting_ksef', $this->row(51)['delivery_status']);
        $this->assertSame(0, $report->block()['handled']);
    }

    /**
     * @return array[]
     */
    public function rejectedStatuses()
    {
        return array('rejected' => array('ODRZUCONA'), 'error' => array('BLAD'));
    }

    /**
     * @dataProvider rejectedStatuses
     */
    public function testARejectedDocumentIsNotDeliveredAndKeepsWaiting($status)
    {
        $this->invoice(52, 1, array('ksef_status' => $status));

        $this->deliveryJob()->run($this->context());

        $this->assertCount(0, Mail::$sent);
        $this->assertSame('awaiting_ksef', $this->row(52)['delivery_status']);
    }

    /**
     * Scenario "Delivery failure after acceptance" (receipt-delivery spec): it replaces "recorded as failed and not
     * retried". The awaiting job still tries a document once; the retry job takes it from there.
     */
    public function testADeliveryThatFailsAfterAcceptanceIsRecordedAsFailedWithOneAttemptUsedAndRetriedUnderTheAutomaticRetryRules()
    {
        $this->invoice(53, 1, array('ksef_number' => self::NUMBER));
        $this->t->queue('GET', '#fakturakraj/753\.pdf#', array('status' => 0, 'error' => 'timeout'));

        $report = $this->deliveryJob()->run($this->context());

        $row = $this->row(53);
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame(1, $row['delivery_attempts']);
        $this->assertSame(date('Y-m-d H:i:s', mktime(10, 15, 20, 11, 1, 2026) + 900), $row['delivery_retry_at']);
        $this->assertSame(array('handled' => 1, 'succeeded' => 0, 'failed' => 1, 'left' => 0), $report->block());
        $this->assertSame(0, $this->deliveryJob()->run($this->context())->block()['handled'], 'the awaiting job does not try a failed delivery again');
    }

    public function testTheDeliveryJobTakesTheOrderLockWithoutWaitingAndReleasesIt()
    {
        $this->invoice(58, 1, array('ksef_number' => self::NUMBER));
        $this->t->queue('GET', '#fakturakraj/758\.pdf#', array('body' => "%PDF-1.4\nbody\n%%EOF"));

        $this->deliveryJob()->run($this->context());

        $locks = array_values(array_filter(Db::getInstance()->queries, function ($q) {
            return strpos($q, '_LOCK(') !== false;
        }));
        $this->assertSame(array("SELECT GET_LOCK('ifirma_order_58', 0)", "SELECT RELEASE_LOCK('ifirma_order_58')"), $locks);
    }

    public function testADocumentWhoseOrderLockIsBusyIsNotDeliveredByTheDeliveryJob()
    {
        $this->invoice(59, 1, array('ksef_number' => self::NUMBER));
        Db::getInstance()->whenGetValue("/^SELECT GET_LOCK\('ifirma_order_59'/", 0);

        $report = $this->deliveryJob()->run($this->context());

        $this->assertCount(0, Mail::$sent);
        $this->assertSame('awaiting_ksef', $this->row(59)['delivery_status']);
        $this->assertSame(0, $report->block()['handled']);
    }

    public function testAnEmployeeResendThatDeliveredTheDocumentBeforeTheLockIsNotRepeatedByTheDeliveryJob()
    {
        $invoice = $this->invoice(61, 1, array('ksef_number' => self::NUMBER));
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\(/', function () use ($invoice) {
            $employee = new IfirmaReceipt((int) $invoice->id);
            $employee->recordDelivery(IfirmaReceipt::DELIVERY_SENT, 'shop_mail', '');
            $employee->save();

            return 1;
        });

        $report = $this->deliveryJob()->run($this->context());

        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(0, $report->block()['handled']);
    }

    public function testTheDeliveryJobStopsWhenTheRateGuardBlocks()
    {
        $this->invoice(54, 1, array('ksef_number' => self::NUMBER));
        $this->invoice(55, 2, array('ksef_number' => self::NUMBER));
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', mktime(10, 15, 20, 11, 1, 2026)) . ':80');

        $report = $this->deliveryJob()->run($this->context());

        $this->assertTrue($report->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 2), $report->block());
    }

    public function testASkippedJobStillReportsWhatIsLeft()
    {
        $this->invoice(56, 1);
        $this->invoice(57, 2, array('ksef_number' => self::NUMBER));

        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $this->ksefJob()->skip($this->context())->block());
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $this->deliveryJob()->skip($this->context())->block());
    }

    public function testAcceptanceAndDeliveryHappenInOneRun()
    {
        $this->invoice(60, 1, array('ksef_status' => 'WYSLANA'));
        $this->t->queue('GET', '#fakturakraj/760\.pdf#', array('body' => "%PDF-1.4\nbody\n%%EOF"));
        // the status sync reads the acceptance from iFirma and stores the KSeF number
        $service = $this->getMockBuilder(IfirmaStatusSyncService::class)->disableOriginalConstructor()->onlyMethods(array('runCron'))->getMock();
        $service->method('runCron')->willReturnCallback(function () {
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                $receipt = new IfirmaReceipt((int) $id);
                $receipt->ksef_status = 'PRZYJETA';
                $receipt->ksef_number = KsefCronJobsTest::NUMBER;
                $receipt->save();
            }

            return IfirmaStatusSyncService::emptySummary(0);
        });
        $runner = new IfirmaCronRunner(array($this->ksefJob(), new IfirmaStatusSyncJob($service), $this->deliveryJob()), function () {
            return mktime(10, 15, 20, 11, 1, 2026);
        });

        $summary = $runner->run();

        $this->assertSame('sent', $this->row(60)['delivery_status']);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame(1, $summary['delivery']['handled']);
        $this->assertSame(0, $summary['ksef']['handled'], 'an invoice that is already sent is not submitted again');
    }
    public function testASubmissionThatIsRefusedWithoutCallingIfirmaCountsAsFailed()
    {
        $this->invoice(70, 1);
        $this->remote('ODRZUCONA');

        $report = $this->ksefJob()->run($this->context());

        $this->assertSame(array(), $this->posts());
        $this->assertSame(array('handled' => 1, 'succeeded' => 0, 'failed' => 1, 'left' => 0), $report->block());
    }
}
