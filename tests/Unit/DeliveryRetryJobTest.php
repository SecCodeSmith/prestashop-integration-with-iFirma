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
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCronRunner.php';

/**
 * The cron job that retries failed deliveries (receipt-delivery and receipt-status-sync specs): when a retry is
 * due, what it skips, what stops it and that it never e-mails a customer twice.
 */
class DeliveryRetryJobTest extends TestCase
{
    const PDF = "%PDF-1.4\nfake body\n%%EOF";

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaDeliveryService */
    private $delivery;

    /** @var int the clock of the cron run, of the client and of the delivery service */
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
        $module = new Module();
        $this->delivery = new IfirmaDeliveryService(
            $this->client,
            new IfirmaReceiptService($this->client, $module),
            $module,
            function () {
                return $this->now;
            },
            function () {
            }
        );

        $c = new Customer();
        $c->id = 5;
        $c->email = 'jan@example.test';
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        Customer::register($c);

        Db::getInstance()->whenExecuteS("/FROM `ps_ifirma_receipt` WHERE delivery_status = 'failed' AND ifirma_id != '' AND delivery_retry_at IS NOT NULL/", function ($sql) {
            return $this->due($sql, preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : 500);
        });
        Db::getInstance()->whenGetValue("/FROM `ps_ifirma_receipt` WHERE delivery_status = 'failed' AND ifirma_id != '' AND delivery_retry_at IS NOT NULL/", function ($sql) {
            return count($this->due($sql, 100000));
        });
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

    /** Evaluates the finder's SQL against the stored rows, the timestamp of the query deciding what is due. */
    private function due($sql, $limit)
    {
        preg_match("/delivery_retry_at <= '([^']+)'/", $sql, $m);
        $now = isset($m[1]) ? $m[1] : '';
        $out = array();
        foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
            $due = (string) $row['delivery_retry_at'];
            if ($row['delivery_status'] === 'failed' && $due !== '' && $due > '2000-01-01 00:00:00' && $due <= $now) {
                $out[] = array('id_ifirma_receipt' => (string) $id, 'due' => $due, 'id' => $id);
            }
        }
        usort($out, function ($a, $b) {
            return strcmp($a['due'], $b['due']) ?: $a['id'] - $b['id'];
        });

        return array_slice($out, 0, $limit);
    }

    private function dueIn($seconds)
    {
        return date('Y-m-d H:i:s', $this->now + $seconds);
    }

    private function order($id)
    {
        $o = new Order();
        $o->id = $id;
        $o->id_customer = 5;
        $o->id_lang = 1;
        $o->reference = 'REF' . $id;
        Order::register($o);
    }

    /** A document whose delivery failed and waits for its automatic retry. */
    private function failed($idOrder, $attempts, $due, array $over = array())
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
        $r->delivery_status = 'failed';
        $r->delivery_channel = 'shop_mail';
        $r->delivery_error = 'earlier failure';
        $r->delivery_attempts = $attempts;
        $r->delivery_retry_at = $due;
        foreach ($over as $field => $value) {
            $r->$field = $value;
        }
        $r->date_add = '2026-11-01 09:00:00';
        $r->save(false, false);
        IfirmaTestState::clearWrites();

        return (int) $r->id;
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

    private function job($authBlocked = null)
    {
        return new IfirmaDeliveryRetryJob($this->delivery, $authBlocked);
    }

    private function context($max = 10)
    {
        return new IfirmaCronContext($max, function () {
            return $this->now;
        });
    }

    private function lockQueries()
    {
        return array_values(array_filter(Db::getInstance()->queries, function ($q) {
            return strpos($q, '_LOCK(') !== false;
        }));
    }

    public function testTheJobIsNamedAndImplementsTheCronJobContract()
    {
        $job = $this->job();

        $this->assertInstanceOf(IfirmaCronJob::class, $job);
        $this->assertSame('delivery_retry', $job->name());
    }

    public function testADueRetryDeliversOnceAndEndsTheSchedule()
    {
        $id = $this->failed(10, 1, $this->dueIn(-60));
        $this->pdfOk();

        $report = $this->job()->run($this->context());

        $row = $this->row($id);
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertNull($row['delivery_retry_at']);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('jan@example.test', Mail::$sent[0]['to']);
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 0), $report->block());
    }

    public function testADeliveryThatIsNotDueYetIsLeftAlone()
    {
        $id = $this->failed(11, 1, $this->dueIn(600));

        $report = $this->job()->run($this->context());

        $this->assertCount(0, Mail::$sent);
        $this->assertSame($this->dueIn(600), $this->row($id)['delivery_retry_at']);
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 0), $report->block());
    }

    public function testAnOldFailureWithoutADueTimeIsNeverRetried()
    {
        $this->failed(12, 0, null);

        $report = $this->job()->run($this->context());

        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(0, $report->block()['handled']);
    }

    public function testASecondFailureSchedulesTheThirdAttemptTwoHoursLater()
    {
        $id = $this->failed(13, 1, $this->dueIn(-60));
        $this->pdfOk();
        Mail::$result = false;

        $report = $this->job()->run($this->context());

        $row = $this->row($id);
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame(2, $row['delivery_attempts']);
        $this->assertSame($this->dueIn(7200), $row['delivery_retry_at']);
        $this->assertSame(array('handled' => 1, 'succeeded' => 0, 'failed' => 1, 'left' => 0), $report->block());
    }

    public function testTheThirdFailureEndsTheRetriesForGood()
    {
        $id = $this->failed(14, 2, $this->dueIn(-60));
        $this->pdfOk();
        Mail::$result = false;
        $this->job()->run($this->context());
        $this->assertNull($this->row($id)['delivery_retry_at']);
        $this->assertSame(3, $this->row($id)['delivery_attempts']);

        $this->now += 86400;
        $again = $this->job()->run($this->context());

        $this->assertSame(0, $again->block()['handled']);
        $this->assertCount(1, Mail::$sent, 'only the third attempt sent anything, the cron never tries that document again');
    }

    public function testAPermanentFailureIsNeverSelectedAgain()
    {
        $id = $this->failed(15, 0, $this->dueIn(-60));
        $this->t->queue('GET', '#\.pdf#', array('body' => json_encode(array('response' => array('Kod' => 403, 'Informacja' => 'Brak uprawnien')))));
        $this->job()->run($this->context());
        $this->assertNull($this->row($id)['delivery_retry_at']);

        $this->now += 86400;
        $again = $this->job()->run($this->context());

        $this->assertSame(0, $again->block()['handled']);
        $this->assertCount(1, $this->t->calls);
    }

    public function testAnUnknownOutcomeIsNeverSelectedAgain()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        $id = $this->failed(16, 0, $this->dueIn(-60), array('document_type' => 'fakturakraj'));
        $this->t->queue('POST', '#send/716\.json#', array('status' => 0, 'error' => 'Operation timed out'));
        $this->job()->run($this->context());
        $this->assertNull($this->row($id)['delivery_retry_at']);

        $this->now += 86400;
        $again = $this->job()->run($this->context());

        $this->assertSame(0, $again->block()['handled']);
        $this->assertCount(1, $this->t->calls, 'the send request went out once and is never repeated');
    }

    public function testACoolDownRefusalKeepsTheAttemptCountAndTheDeliveryDueAtOnce()
    {
        $id = $this->failed(17, 1, $this->dueIn(-60));
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|HTTP 401 returned by iFirma.');

        $this->job()->run($this->context());

        $row = $this->row($id);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(1, $row['delivery_attempts']);
        $this->assertLessThanOrEqual($this->dueIn(0), $row['delivery_retry_at']);
        $this->assertNotNull($row['delivery_retry_at']);
    }

    public function testTheJobStopsAtTheFirstLimitRefusalAndTheOthersKeepTheirAttemptCounts()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '1');
        $a = $this->failed(20, 1, $this->dueIn(-300));
        $b = $this->failed(21, 1, $this->dueIn(-200));
        $c = $this->failed(22, 1, $this->dueIn(-100));
        $this->pdfOk(3);

        $report = $this->job()->run($this->context());

        $this->assertCount(1, Mail::$sent, 'one delivery is attempted');
        $this->assertSame('sent', $this->row($a)['delivery_status']);
        foreach (array($b, $c) as $other) {
            $this->assertSame(1, $this->row($other)['delivery_attempts']);
            $this->assertNotNull($this->row($other)['delivery_retry_at']);
            $this->assertSame('failed', $this->row($other)['delivery_status']);
        }
        $this->assertTrue($report->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $report->getRateWindow());
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 2), $report->block());
    }

    public function testTheOldestDueTimeComesFirstWithinTheRunCap()
    {
        $late = $this->failed(30, 1, $this->dueIn(-60));
        $early = $this->failed(31, 1, $this->dueIn(-600));
        $middle = $this->failed(32, 1, $this->dueIn(-300));
        $this->pdfOk(3);

        $report = $this->job()->run($this->context(2));

        $this->assertSame('sent', $this->row($early)['delivery_status']);
        $this->assertSame('sent', $this->row($middle)['delivery_status']);
        $this->assertSame('failed', $this->row($late)['delivery_status']);
        $this->assertSame(array('handled' => 2, 'succeeded' => 2, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testAnEmployeeResendThatSucceededBetweenSelectionAndLockSendsNothingMore()
    {
        $id = $this->failed(40, 1, $this->dueIn(-60));
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\(/', function () use ($id) {
            $employee = new IfirmaReceipt($id);
            $employee->recordDelivery(IfirmaReceipt::DELIVERY_SENT, 'shop_mail', '');
            $employee->delivered_at = $this->dueIn(-1);
            $employee->save();

            return 1;
        });

        $report = $this->job()->run($this->context());

        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame('sent', $this->row($id)['delivery_status']);
        $this->assertSame(0, $report->block()['handled']);
    }

    public function testADeliveryWhoseDueTimeMovedBetweenSelectionAndLockIsNotAttempted()
    {
        $id = $this->failed(41, 1, $this->dueIn(-60));
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\(/', function () use ($id) {
            $other = new IfirmaReceipt($id);
            $other->delivery_retry_at = $this->dueIn(7200);
            $other->save();

            return 1;
        });

        $report = $this->job()->run($this->context());

        $this->assertCount(0, Mail::$sent);
        $this->assertSame(0, $report->block()['handled']);
    }

    public function testADocumentWhoseOrderLockIsBusyIsSkippedAndTheOthersAreDelivered()
    {
        $busy = $this->failed(50, 1, $this->dueIn(-300));
        $free = $this->failed(51, 1, $this->dueIn(-200));
        Db::getInstance()->whenGetValue("/^SELECT GET_LOCK\('ifirma_order_50'/", 0);
        $this->pdfOk();

        $report = $this->job()->run($this->context());

        $this->assertSame('failed', $this->row($busy)['delivery_status']);
        $this->assertSame(1, $this->row($busy)['delivery_attempts']);
        $this->assertSame('sent', $this->row($free)['delivery_status']);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testTheOrderLockIsTakenWithoutWaitingAndReleasedAfterTheDelivery()
    {
        $this->failed(60, 1, $this->dueIn(-60));
        $this->pdfOk();

        $this->job()->run($this->context());

        $this->assertSame(array(
            "SELECT GET_LOCK('ifirma_order_60', 0)",
            "SELECT RELEASE_LOCK('ifirma_order_60')",
        ), $this->lockQueries());
    }

    public function testTheOrderLockIsReleasedWhenTheDeliveryThrows()
    {
        $this->failed(61, 1, $this->dueIn(-60));
        $throwing = $this->getMockBuilder(IfirmaDeliveryService::class)->disableOriginalConstructor()->onlyMethods(array('deliver'))->getMock();
        $throwing->method('deliver')->willThrowException(new RuntimeException('boom'));

        try {
            (new IfirmaDeliveryRetryJob($throwing))->run($this->context());
            $this->fail('the exception of the delivery must reach the runner');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertContains("SELECT RELEASE_LOCK('ifirma_order_61')", $this->lockQueries());
    }

    public function testAChannelSwitchedOffBeforeTheRetryRecordsSkippedAndClearsTheSchedule()
    {
        $id = $this->failed(70, 1, $this->dueIn(-60));
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');

        $report = $this->job()->run($this->context());

        $row = $this->row($id);
        $this->assertSame('skipped', $row['delivery_status']);
        $this->assertNull($row['delivery_retry_at']);
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(1, $report->block()['handled']);
    }

    public function testPausedCredentialsStopTheJobBeforeAnyDelivery()
    {
        $id = $this->failed(80, 1, $this->dueIn(-60));

        $report = $this->job(function () {
            return true;
        })->run($this->context());

        $this->assertSame(array(), $this->t->calls);
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(1, $this->row($id)['delivery_attempts']);
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testTheReportCountsWhatSucceededAndWhatFailedAndIgnoresWhatIsNotDue()
    {
        $this->failed(90, 1, $this->dueIn(-300));
        $this->failed(91, 1, $this->dueIn(-200));
        $this->failed(92, 1, $this->dueIn(3600));
        $this->pdfOk();
        for ($i = 0; $i < 3; $i++) {
            $this->t->queue('GET', '#\.pdf#', array('status' => 0, 'error' => 'timeout'));
        }

        $report = $this->job()->run($this->context());

        $this->assertSame(array('handled' => 2, 'succeeded' => 1, 'failed' => 1, 'left' => 0), $report->block());
        $this->assertCount(1, Mail::$sent);
    }

    public function testASkippedJobReportsWhatIsDueAndDoesNothing()
    {
        $this->failed(100, 1, $this->dueIn(-60));
        $this->failed(101, 1, $this->dueIn(3600));

        $report = $this->job()->skip($this->context());

        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $report->block());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheRunnerSkipsTheJobWhenAnEarlierJobWasLimited()
    {
        $this->failed(110, 1, $this->dueIn(-60));
        $limited = $this->createMock(IfirmaCronJob::class);
        $limited->method('name')->willReturn('earlier');
        $limited->method('run')->willReturn(IfirmaCronJobReport::counts('earlier', 1, 0, 0, 3)->limited(IfirmaRateDecision::WINDOW_MINUTE));
        $runner = new IfirmaCronRunner(array($limited, $this->job()), function () {
            return $this->now;
        });

        $summary = $runner->run();

        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $summary['delivery_retry']);
        $this->assertSame(1, $summary['rate_limited']);
    }

    public function testADeliveryTheAwaitingJobFailedInTheSameRunIsNotRetriedInThatRun()
    {
        $this->order(120);
        $r = new IfirmaReceipt();
        $r->id_order = 120;
        $r->id_customer = 5;
        $r->document_type = 'fakturakraj';
        $r->ifirma_id = '820';
        $r->ifirma_number = '120/2026';
        $r->status = 'created';
        $r->source = 'module';
        $r->security_token = 'tok120';
        $r->delivery_status = 'awaiting_ksef';
        $r->ksef_number = '5260001122-20261101-ABC-01';
        $r->date_add = '2026-11-01 09:00:00';
        $r->save(false, false);
        IfirmaTestState::clearWrites();
        $this->pdfOk(2);
        Mail::$result = false;
        $runner = new IfirmaCronRunner(array(new IfirmaAwaitingDeliveryJob($this->delivery), $this->job()), function () {
            return $this->now;
        });

        $summary = $runner->run();

        $row = $this->row((int) $r->id);
        $this->assertCount(1, Mail::$sent, 'only the awaiting job tried');
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame(1, $row['delivery_attempts']);
        $this->assertSame($this->dueIn(900), $row['delivery_retry_at']);
        $this->assertSame(1, $summary['delivery']['failed']);
        $this->assertSame(0, $summary['delivery_retry']['handled']);
    }

    public function testThatDeliveryIsRetriedByTheRunAfterItsDueTime()
    {
        $id = $this->failed(121, 1, $this->dueIn(900));
        $this->pdfOk();
        $this->now += 901;

        $report = $this->job()->run($this->context());

        $this->assertSame('sent', $this->row($id)['delivery_status']);
        $this->assertSame(1, $report->block()['succeeded']);
    }

    private function steppingContext($budget)
    {
        return new IfirmaCronContext(10, function () {
            return $this->now;
        }, $budget);
    }

    /** The PDF download of the first delivery takes the given seconds of the run. */
    private function pdfThatTakes($seconds, $times)
    {
        for ($i = 0; $i < $times; $i++) {
            $this->t->queue('GET', '#\.pdf#', function () use ($seconds) {
                $this->now += $seconds;

                return array('body' => self::PDF);
            });
        }
    }

    public function testTheRetryJobStartsNoRecordOnceTheTimeBudgetIsUsedUpAndReportsTheRestAsLeft()
    {
        $first = $this->failed(130, 1, $this->dueIn(-300));
        $second = $this->failed(131, 1, $this->dueIn(-200));
        $third = $this->failed(132, 1, $this->dueIn(-100));
        $this->pdfThatTakes(40, 3);
        $context = $this->steppingContext(60);

        $report = $this->job()->run($context);

        $this->assertSame('sent', $this->row($first)['delivery_status']);
        $this->assertSame('sent', $this->row($second)['delivery_status'], 'a record that started inside the budget is finished');
        $this->assertSame('failed', $this->row($third)['delivery_status']);
        $this->assertSame(1, $this->row($third)['delivery_attempts']);
        $this->assertTrue($context->timeBudgetWasUsedUp());
        $this->assertSame(array('handled' => 2, 'succeeded' => 2, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testTheAwaitingDeliveryJobStartsNoRecordOnceTheTimeBudgetIsUsedUp()
    {
        foreach (array(140, 141) as $idOrder) {
            $this->order($idOrder);
            $r = new IfirmaReceipt();
            $r->id_order = $idOrder;
            $r->id_customer = 5;
            $r->document_type = 'fakturakraj';
            $r->ifirma_id = (string) (800 + $idOrder);
            $r->ifirma_number = $idOrder . '/2026';
            $r->status = 'created';
            $r->source = 'module';
            $r->security_token = 'tok' . $idOrder;
            $r->delivery_status = 'awaiting_ksef';
            $r->ksef_number = '5260001122-20261101-ABC-01';
            $r->date_add = '2026-11-01 09:00:00';
            $r->save(false, false);
        }
        IfirmaTestState::clearWrites();
        Db::getInstance()->whenGetValue("/FROM `ps_ifirma_receipt` WHERE delivery_status = 'awaiting_ksef' AND \(ksef_number != /", 1);
        $this->pdfThatTakes(50, 2);

        $report = (new IfirmaAwaitingDeliveryJob($this->delivery))->run($this->steppingContext(45));

        $this->assertCount(1, Mail::$sent);
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testAJobInsideItsTimeBudgetHandlesEveryRecord()
    {
        $this->failed(150, 1, $this->dueIn(-200));
        $this->failed(151, 1, $this->dueIn(-100));
        $this->pdfThatTakes(5, 2);

        $report = $this->job()->run($this->steppingContext(45));

        $this->assertSame(array('handled' => 2, 'succeeded' => 2, 'failed' => 0, 'left' => 0), $report->block());
    }

    public function testTheRetryJobDownloadsThePdfOnceAndNeverSleepsBetweenAttempts()
    {
        $waits = array();
        $module = new Module();
        $delivery = new IfirmaDeliveryService(
            $this->client,
            new IfirmaReceiptService($this->client, $module),
            $module,
            function () {
                return $this->now;
            },
            function ($seconds) use (&$waits) {
                $waits[] = $seconds;
            }
        );
        $id = $this->failed(160, 1, $this->dueIn(-60));
        $this->t->queue('GET', '#\.pdf#', array('status' => 404, 'body' => ''));
        $this->t->queue('GET', '#\.pdf#', array('body' => self::PDF));

        $report = (new IfirmaDeliveryRetryJob($delivery))->run($this->context());

        $this->assertSame(array(), $waits);
        $this->assertCount(1, $this->t->calls, 'one download per retry; the next retry is the second chance');
        $this->assertCount(0, Mail::$sent);
        $row = $this->row($id);
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame(2, $row['delivery_attempts']);
        $this->assertSame($this->dueIn(7200), $row['delivery_retry_at']);
        $this->assertSame(1, $report->block()['failed']);
    }
}
