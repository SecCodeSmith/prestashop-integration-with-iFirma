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

/** Stand-in pipeline: a callable decides the result of each run. */
class BulkRunnerFakePipeline
{
    /** @var callable */
    public $decide;

    /** @var array[] order id and options of every run() */
    public $runs = array();

    public function __construct($decide)
    {
        $this->decide = $decide;
    }

    public function run($order, array $options = array())
    {
        $this->runs[] = array('id_order' => (int) $order->id, 'options' => $options);

        return call_user_func($this->decide, $order, count($this->runs));
    }

    public function describe($r)
    {
        return 'described:' . $r->outcome;
    }
}

/** Stand-in sync service: one date per receipt, a callable decides each syncByIssueDate(). */
class BulkRunnerFakeSync
{
    /** @var callable|null */
    public $decide;

    /** @var string[] dates of every syncByIssueDate() */
    public $dates = array();

    public function lookupDate($receipt)
    {
        return (string) $receipt->issue_date;
    }

    public function syncByIssueDate($date, array $receipts)
    {
        $this->dates[] = $date;
        $summary = array('checked' => count($receipts), 'rate_limited' => 0, 'error' => '');
        if ($this->decide) {
            $summary = call_user_func($this->decide, $date, $receipts, count($this->dates), $summary);
        }

        return $summary;
    }
}

/** Stand-in delivery service: a callable decides each resend(). */
class BulkRunnerFakeDelivery
{
    /** @var callable */
    public $decide;

    /** @var int */
    public $calls = 0;

    public function __construct($decide)
    {
        $this->decide = $decide;
    }

    public function resend($receipt, $order = null)
    {
        $this->calls++;

        return call_user_func($this->decide, $receipt, $this->calls);
    }
}

/** A value whose string conversion throws, to make the client construction fail. */
class BulkRunnerThrowingString
{
    public function __toString()
    {
        throw new RuntimeException('no credentials');
    }
}

/**
 * The bulk runner: one batch of issue, retry, resend or refresh over the module services, the stop rules and the
 * log entry.
 */
class BulkRunnerTest extends TestCase
{
    use IfirmaWiringFixture;

    /** @var int */
    private $now;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->now = 1000;
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
    }

    private function runner()
    {
        $self = $this;

        return new IfirmaBulkRunner($this->module, function () use ($self) {
            return $self->now;
        });
    }

    /** Registers order $id for customer 5 and returns it. */
    private function order($id)
    {
        $o = $this->wiringOrder();
        $o->id = $id;
        $o->reference = 'REF' . $id;
        Order::register($o);

        return $o;
    }

    private function result($outcome, $ok = false)
    {
        $r = new IfirmaPipelineResult();
        $r->outcome = $outcome;
        $r->ok = $ok;

        return $r;
    }

    private function outcomes(array $data)
    {
        return array_column($data['results'], 'outcome');
    }

    public function testTheBatchSizesAndKinds()
    {
        $this->assertSame(3, IfirmaBulkRunner::batchSize('issue'));
        $this->assertSame(3, IfirmaBulkRunner::batchSize('retry'));
        $this->assertSame(5, IfirmaBulkRunner::batchSize('resend'));
        $this->assertSame(25, IfirmaBulkRunner::batchSize('refresh'));
        $this->assertSame(array('issue', 'retry', 'resend', 'refresh'), IfirmaBulkRunner::kinds());
    }

    public function testAnUnknownKindThrows()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->run('explode', array(1), 1);
    }

    public function testAnUnknownKindThrowsFromTheBatchSizeToo()
    {
        $this->expectException(InvalidArgumentException::class);
        IfirmaBulkRunner::batchSize('explode');
    }

    // --- mixed selection ---------------------------------------------------------------------------------------

    public function testMixedSelectionRunsThePipelineForTheUnissuedAndSkipsTheIssued()
    {
        $orders = array(21, 22, 23, 24);
        foreach ($orders as $id) {
            $this->order($id);
        }
        $issued = $this->makeReceipt(array('id_order' => 21));
        $e1 = $this->makeReceipt(array('id_order' => 22, 'status' => 'error', 'ifirma_id' => ''));
        $e2 = $this->makeReceipt(array('id_order' => 23, 'status' => 'error', 'ifirma_id' => ''));
        $u = $this->makeReceipt(array('id_order' => 24, 'status' => 'unknown', 'ifirma_id' => ''));

        $pipeline = new BulkRunnerFakePipeline(function ($order) {
            return $this->result('issued', true);
        });
        $runner = $this->runner()->setPipeline($pipeline);

        $first = $runner->run('retry', array($issued->id, $e1->id, $e2->id, $u->id), 9);
        $second = $runner->run('retry', $first['remaining'], 9);

        $this->assertSame(array('skipped', 'issued', 'issued'), $this->outcomes($first));
        $this->assertSame(array($u->id), $first['remaining']);
        $this->assertSame(array('issued'), $this->outcomes($second));
        $this->assertCount(3, $pipeline->runs);
        $this->assertSame(array(22, 23, 24), array_column($pipeline->runs, 'id_order'));
        $this->assertSame('', $first['stopped']);
        $this->assertSame(3, $first['processed']);
        // the retry result names the receipt id as given and the order behind it
        $this->assertSame((int) $e1->id, $first['results'][1]['id']);
        $this->assertSame(22, $first['results'][1]['id_order']);
        $this->assertSame('described:issued', $first['results'][1]['message']);
        $this->assertIsArray($first['results'][1]['receipt']);
        $this->assertArrayNotHasKey('cell', $first['results'][1]);
    }

    public function testThePipelineRunsAsManualWithTheEmployeeAndNeverBypassesTheGuard()
    {
        $this->order(15);
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });

        $this->runner()->setPipeline($pipeline)->run('issue', array(15), 9);

        $this->assertSame(
            array('trigger' => IfirmaIssuancePipeline::TRIGGER_MANUAL, 'id_employee' => 9),
            $pipeline->runs[0]['options']
        );
        $this->assertArrayNotHasKey('bypass_guard', $pipeline->runs[0]['options']);
    }

    public function testIssueSkipsAnOrderWhoseRecordIsIssuedAndFailsAnUnknownOrder()
    {
        $this->order(15);
        $this->makeReceipt(array('id_order' => 15));
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });

        $data = $this->runner()->setPipeline($pipeline)->run('issue', array(15, 999), 9);

        $this->assertSame(array('skipped', 'failed'), $this->outcomes($data));
        $this->assertCount(0, $pipeline->runs);
        $this->assertNotSame('', $data['results'][1]['message']);
        $this->assertNull($data['results'][1]['receipt']);
    }

    public function testRetryOfAReceiptThatDoesNotLoadFails()
    {
        $data = $this->runner()->setPipeline(new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        }))->run('retry', array(555), 9);

        $this->assertSame(array('failed'), $this->outcomes($data));
        $this->assertSame(555, $data['results'][0]['id']);
    }

    public function testThePipelineOutcomesMapToTheBulkOutcomes()
    {
        $map = array(
            array('adopted', true, 'adopted'),
            array('issued', true, 'issued'),
            array('suspected', false, 'suspected'),
            array('held', false, 'held'),
            array('error', false, 'failed'),
            array('inconclusive', false, 'failed'),
            array('unknown', false, 'failed'),
        );
        $out = array();
        foreach ($map as $i => $case) {
            $this->order(30 + $i);
        }
        $pipeline = new BulkRunnerFakePipeline(function ($order, $n) use ($map) {
            return $this->result($map[$n - 1][0], $map[$n - 1][1]);
        });
        $runner = $this->runner()->setPipeline($pipeline);
        $ids = array(30, 31, 32, 33, 34, 35, 36);
        while ($ids) {
            $data = $runner->run('issue', $ids, 9);
            $out = array_merge($out, $this->outcomes($data));
            $ids = $data['remaining'];
        }

        $this->assertSame(array_column($map, 2), $out);
    }

    // --- real pipeline -----------------------------------------------------------------------------------------

    public function testIssueBuildsTheRealPipelineOnceAndIssuesTheDocument()
    {
        $this->queueFullSuccess();

        $data = $this->runner()->run('issue', array(15), 9);

        $this->assertSame(array('issued'), $this->outcomes($data));
        $this->assertSame(15, $data['results'][0]['id_order']);
        $this->assertSame('77', $data['results'][0]['receipt']['ifirma_id']);
        $this->assertCount(1, $this->posts());
    }

    public function testAnUnknownOutcomeAdoptsTheDocumentTheGuardFinds()
    {
        $this->makeReceipt(array('status' => 'unknown', 'ifirma_id' => '', 'ifirma_number' => ''));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array(
            'IdentyfikatorKontrahenta' => 'PS-C5',
            'Rodzaj' => 'prz_faktura_kraj',
        )))));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}}'));

        $data = $this->runner()->run('retry', array(IfirmaReceipt::getByOrder(15)->id), 9);

        $this->assertSame(array('adopted'), $this->outcomes($data));
        $this->assertCount(0, $this->posts(), 'no second document is created');
        $this->assertSame('77', $data['results'][0]['receipt']['ifirma_id']);
    }

    public function testASuspectedDuplicateStaysSuspected()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array(
            'IdentyfikatorKontrahenta' => 'PS-C5',
            'Rodzaj' => 'prz_faktura_kraj',
        )))));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => json_encode(array('response' => array(
            'Uwagi' => 'other',
            'DataSprzedazy' => date('Y-m-d', time() - 86400),
            'FormatDatySprzedazy' => 'DZN',
            'Pozycje' => array(array('NazwaPelna' => 'W', 'Ilosc' => 1, 'CenaJednostkowa' => 123.0)),
        )))));

        $data = $this->runner()->run('issue', array(15), 9);

        $this->assertSame(array('suspected'), $this->outcomes($data));
        $this->assertCount(0, $this->posts());
        $this->assertSame('duplicate_suspected', $data['results'][0]['receipt']['status']);
    }

    // --- resend ------------------------------------------------------------------------------------------------

    public function testFailedDeliveriesAreResent()
    {
        $ids = array();
        for ($i = 0; $i < 5; $i++) {
            $this->order(40 + $i);
            $r = $this->makeReceipt(array('id_order' => 40 + $i, 'delivery_status' => 'failed', 'ifirma_id' => (string) (100 + $i)));
            $ids[] = (int) $r->id;
            $this->queuePdf();
        }

        $data = $this->runner()->run('resend', $ids, 9);

        $this->assertSame(array_fill(0, 5, 'sent'), $this->outcomes($data));
        $this->assertSame(array(), $data['remaining']);
        $this->assertCount(5, Mail::$sent);
        foreach ($ids as $id) {
            $this->assertSame('sent', IfirmaTestState::objects('ifirma_receipt')[$id]['delivery_status']);
        }
        $this->assertSame('sent', $data['results'][0]['receipt']['delivery_status']);
    }

    public function testResendWithTheChannelNoneSkipsEverythingWithoutACall()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $ids = array();
        for ($i = 0; $i < 3; $i++) {
            $this->order(50 + $i);
            $ids[] = (int) $this->makeReceipt(array('id_order' => 50 + $i, 'delivery_status' => 'failed'))->id;
        }

        $data = $this->runner()->run('resend', $ids, 9);

        $this->assertSame(array_fill(0, 3, 'skipped'), $this->outcomes($data));
        $this->assertStringContainsString('disabled', $data['results'][0]['message']);
        $this->assertCount(0, $this->t->calls);
        $this->assertCount(0, Mail::$sent);
    }

    public function testResendSkipsADocumentThatWasNeverIssued()
    {
        $r = $this->makeReceipt(array('status' => 'error', 'ifirma_id' => ''));
        $delivery = new BulkRunnerFakeDelivery(function () {
            return new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        });

        $data = $this->runner()->setDeliveryService($delivery)->run('resend', array($r->id), 9);

        $this->assertSame(array('skipped'), $this->outcomes($data));
        $this->assertSame(0, $delivery->calls);
    }

    public function testAFailedResendKeepsItsMessage()
    {
        $r = $this->makeReceipt();
        $delivery = new BulkRunnerFakeDelivery(function () {
            return new IfirmaDeliveryResult(false, 'failed', 'shop_mail', 'The customer has no e-mail address.');
        });

        $data = $this->runner()->setDeliveryService($delivery)->run('resend', array($r->id), 9);

        $this->assertSame(array('failed'), $this->outcomes($data));
        $this->assertSame('The customer has no e-mail address.', $data['results'][0]['message']);
    }

    public function testARateLimitedResendStopsTheRun()
    {
        $ids = array();
        for ($i = 0; $i < 4; $i++) {
            $this->order(60 + $i);
            $ids[] = (int) $this->makeReceipt(array('id_order' => 60 + $i))->id;
        }
        $delivery = new BulkRunnerFakeDelivery(function ($receipt, $n) {
            if ($n === 2) {
                return new IfirmaDeliveryResult(false, 'failed', 'ifirma_mail', 'iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.', 'rate_limited');
            }

            return new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        });

        $data = $this->runner()->setDeliveryService($delivery)->run('resend', $ids, 9);

        $this->assertSame(array('sent', 'not_attempted', 'not_attempted', 'not_attempted'), $this->outcomes($data));
        $this->assertSame('rate_limited', $data['stopped']);
        $this->assertSame(array(), $data['remaining']);
        // deliberate change (task 4.5): the message names the window that refused
        $this->assertSame('not attempted (rate limited). Run it again in a minute.', $data['results'][1]['message']);
        $this->assertSame(2, $delivery->calls);
        $this->assertSame(1, $data['processed']);
    }

    public function testAResendFailureThatOnlyMentionsTheGuardIsNotAStop()
    {
        $r = $this->makeReceipt();
        $delivery = new BulkRunnerFakeDelivery(function () {
            return new IfirmaDeliveryResult(false, 'failed', 'shop_mail', 'iFirma rate limit guard: mentioned in a mail error');
        });

        $data = $this->runner()->setDeliveryService($delivery)->run('resend', array($r->id), 9);

        $this->assertSame(array('failed'), $this->outcomes($data));
        $this->assertSame('', $data['stopped']);
    }

    public function testAResendTheClientRefusedBecauseOfRejectedCredentialsStopsTheRun()
    {
        $ids = array();
        for ($i = 0; $i < 3; $i++) {
            $this->order(70 + $i);
            $ids[] = (int) $this->makeReceipt(array('id_order' => 70 + $i))->id;
        }
        $delivery = new BulkRunnerFakeDelivery(function ($receipt, $n) {
            return $n === 2
                ? new IfirmaDeliveryResult(false, 'failed', 'ifirma_mail', 'paused', 'auth_blocked')
                : new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        });

        $data = $this->runner()->setDeliveryService($delivery)->run('resend', $ids, 9);

        $this->assertSame(array('sent', 'not_attempted', 'not_attempted'), $this->outcomes($data));
        $this->assertSame('auth_blocked', $data['stopped']);
        $this->assertSame('not attempted (credentials rejected)', $data['results'][1]['message']);
    }

    public function testAnOrderThatGotItsDocumentMeanwhileIsSkippedNotIssued()
    {
        $this->order(15);
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('already_issued', true);
        });

        $data = $this->runner()->setPipeline($pipeline)->run('issue', array(15), 9);

        $this->assertSame(array('skipped'), $this->outcomes($data));
        $this->assertSame('described:already_issued', $data['results'][0]['message']);
        $this->assertSame(1, $data['processed'], 'a skipped item still counts as processed');
    }

    // --- refresh -----------------------------------------------------------------------------------------------

    public function testARefreshOfADocumentIFirmaNoLongerListsFailsWithTheMissingMessage()
    {
        $a = $this->makeReceipt();
        $b = $this->makeReceipt(array('id_order' => 16, 'ifirma_id' => '78'));
        $sync = new BulkRunnerFakeSync();
        $sync->decide = function ($date, array $receipts, $n, array $summary) {
            $receipts[0]->remote_missing = 1;

            return $summary;
        };

        $data = $this->runner()->setSyncService($sync)->run('refresh', array($a->id, $b->id), 9);

        $this->assertSame(array('failed', 'refreshed'), $this->outcomes($data));
        $this->assertSame('The document is missing in iFirma.', $data['results'][0]['message']);
        $this->assertSame('', $data['stopped']);
    }

    public function testRecordsOfTwoIssueDatesCostTwoListCalls()
    {
        $ids = array();
        for ($i = 0; $i < 10; $i++) {
            $this->order(70 + $i);
            $date = $i % 2 === 0 ? '2026-11-01' : '2026-11-02';
            $ids[] = (int) $this->makeReceipt(array('id_order' => 70 + $i, 'ifirma_id' => (string) (200 + $i), 'issue_date' => $date))->id;
        }
        // both dates list all of their documents, so none is reported missing
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array_map(function ($n) {
            return $this->remoteRow(array('FakturaId' => $n));
        }, array(200, 202, 204, 206, 208))));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array_map(function ($n) {
            return $this->remoteRow(array('FakturaId' => $n));
        }, array(201, 203, 205, 207, 209))));

        $data = $this->runner()->run('refresh', $ids, 9);

        $this->assertSame(2, $this->listCalls());
        $this->assertSame(array_fill(0, 10, 'refreshed'), $this->outcomes($data));
        $this->assertSame($ids, array_column($data['results'], 'id'), 'results keep the order of the ids');
    }

    public function testRefreshFailureCarriesTheSyncError()
    {
        $a = $this->makeReceipt();
        $b = $this->makeReceipt(array('id_order' => 16, 'status' => 'error', 'ifirma_id' => ''));
        $sync = new BulkRunnerFakeSync();
        $sync->decide = function ($date, array $receipts, $n, array $summary) {
            $receipts[0]->sync_error = 'HTTP 500';

            return $summary;
        };

        $data = $this->runner()->setSyncService($sync)->run('refresh', array($a->id, $b->id), 9);

        $this->assertSame(array('failed', 'skipped'), $this->outcomes($data));
        $this->assertSame('HTTP 500', $data['results'][0]['message']);
        $this->assertCount(1, $sync->dates);
    }

    public function testTheGuardRefusingMidRunMarksTheRestNotAttempted()
    {
        $ids = array();
        for ($i = 0; $i < 40; $i++) {
            $ids[] = (int) $this->makeReceipt(array(
                'id_order' => 100 + $i,
                'ifirma_id' => (string) (300 + $i),
                'issue_date' => date('Y-m-d', mktime(0, 0, 0, 10, 1 + $i % 28, 2026)) . '',
            ))->id;
        }
        // 25 distinct issue dates in the batch: give each of the first 25 its own day
        foreach (array_slice($ids, 0, 25) as $i => $id) {
            $r = new IfirmaReceipt($id);
            $r->issue_date = '2026-09-' . str_pad((string) (1 + $i), 2, '0', STR_PAD_LEFT);
            $r->save();
        }
        $sync = new BulkRunnerFakeSync();
        $sync->decide = function ($date, array $receipts, $n, array $summary) {
            if ($n === 12) {
                $summary['rate_limited'] = 1;
            }

            return $summary;
        };

        $data = $this->runner()->setSyncService($sync)->run('refresh', $ids, 9);

        $this->assertCount(25, $data['results']);
        $this->assertSame(array_fill(0, 11, 'refreshed'), array_slice($this->outcomes($data), 0, 11));
        $this->assertSame(array_fill(0, 14, 'not_attempted'), array_slice($this->outcomes($data), 11));
        $this->assertSame('rate_limited', $data['stopped']);
        $this->assertSame(array(), $data['remaining']);
        $this->assertSame(11, $data['processed']);
        $this->assertCount(12, $sync->dates);
        $this->assertSame('not attempted (rate limited). Run it again in a minute.', $data['results'][11]['message']);
    }

    public function testTheGuardRefusingAnIssueStopsTheBatch()
    {
        foreach (array(80, 81, 82) as $id) {
            $this->order($id);
        }
        $pipeline = new BulkRunnerFakePipeline(function ($order, $n) {
            return $n === 2 ? $this->result('rate_limited') : $this->result('issued', true);
        });

        $data = $this->runner()->setPipeline($pipeline)->run('issue', array(80, 81, 82, 83, 84), 9);

        $this->assertSame(array('issued', 'not_attempted', 'not_attempted'), $this->outcomes($data));
        $this->assertSame('rate_limited', $data['stopped']);
        $this->assertSame(array(), $data['remaining']);
        $this->assertSame(81, $data['results'][1]['id']);
        $this->assertCount(2, $pipeline->runs);
    }

    /** Day cap that lets the first order of the run issue and refuses the create request of the second. */
    private function dayCapThatRefusesTheSecondOrdersCreate()
    {
        $requestsOfAnIssuedOrder = 4; // guard list, create, number, PDF
        $this->now = time(); // the runner names the window from its own clock, the client counts by the real one
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', $requestsOfAnIssuedOrder + 1);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd') . ':0');
    }

    private function statusOfOrder($idOrder)
    {
        $receipt = IfirmaReceipt::getByOrder($idOrder);

        return $receipt ? (string) $receipt->status : '';
    }

    public function testTheOrderTheGuardRefusesInABulkIssueIsQueuedAndTheRestIsNotAttempted()
    {
        foreach (array(16, 17, 18) as $id) {
            $this->order($id);
        }
        $this->dayCapThatRefusesTheSecondOrdersCreate();
        $this->queueFullSuccess();
        $this->queueGuardNone();

        $data = $this->runner()->run('issue', array(15, 16, 17, 18), 9);

        $this->assertSame(array('issued', 'not_attempted', 'not_attempted'), $this->outcomes($data), 'one batch holds three orders');
        $this->assertSame('rate_limited', $data['stopped']);
        $this->assertSame(array(), $data['remaining']);
        $this->assertSame(array(15, 16, 17), array_column($data['results'], 'id'));
        $this->assertSame('queued', $this->statusOfOrder(16), 'the refused order waits for the cron');
        $this->assertSame('', $this->statusOfOrder(17), 'the orders after it are not touched');
        $this->assertSame('created', $this->statusOfOrder(15));
        $this->assertCount(1, $this->posts(), 'only the first order was created');
        $this->assertSame('not attempted (rate limited). Run it again tomorrow.', $data['results'][2]['message']);
    }

    public function testTheRecordTheGuardRefusesInABulkRetryIsQueuedAndTheRestIsNotAttempted()
    {
        $ids = array();
        foreach (array(15, 16, 17) as $idOrder) {
            $idOrder === 15 ?: $this->order($idOrder);
            $ids[] = (int) $this->makeReceipt(array('id_order' => $idOrder, 'status' => 'error', 'ifirma_id' => '', 'ifirma_number' => ''))->id;
        }
        $this->dayCapThatRefusesTheSecondOrdersCreate();
        $this->queueFullSuccess();
        $this->queueGuardNone();

        $data = $this->runner()->run('retry', $ids, 9);

        $this->assertSame(array('issued', 'not_attempted', 'not_attempted'), $this->outcomes($data));
        $this->assertSame('rate_limited', $data['stopped']);
        $this->assertSame('queued', $this->statusOfOrder(16));
        $this->assertSame('error', $this->statusOfOrder(17), 'the record after it keeps its state');
        $this->assertSame('not attempted (rate limited). Run it again tomorrow.', $data['results'][2]['message']);
    }

    public function testRejectedCredentialsStopTheRunAfterAFailedItem()
    {
        foreach (array(80, 81, 82) as $id) {
            $this->order($id);
        }
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('error');
        });

        $data = $this->runner()->setPipeline($pipeline)->setAuthProbe(function () {
            return true;
        })->run('issue', array(80, 81, 82), 9);

        $this->assertSame(array('failed', 'not_attempted', 'not_attempted'), $this->outcomes($data));
        $this->assertSame('auth_blocked', $data['stopped']);
        $this->assertSame('not attempted (credentials rejected)', $data['results'][1]['message']);
        $this->assertCount(1, $pipeline->runs);
    }

    public function testAFailedItemWithoutBlockedCredentialsLetsTheRunGoOn()
    {
        foreach (array(80, 81) as $id) {
            $this->order($id);
        }
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('error');
        });

        $data = $this->runner()->setPipeline($pipeline)->setAuthProbe(function () {
            return false;
        })->run('issue', array(80, 81), 9);

        $this->assertSame(array('failed', 'failed'), $this->outcomes($data));
        $this->assertSame('', $data['stopped']);
    }

    // --- budget, cap, ids --------------------------------------------------------------------------------------

    public function testAnExhaustedBudgetPutsTheRestIntoRemaining()
    {
        foreach (array(80, 81, 82) as $id) {
            $this->order($id);
        }
        $self = $this;
        $pipeline = new BulkRunnerFakePipeline(function () use ($self) {
            $self->now += 15;

            return $self->result('issued', true);
        });

        $data = $this->runner()->setPipeline($pipeline)->run('issue', array(80, 81, 82), 9, array('budget' => 20));

        $this->assertSame(array('issued', 'issued'), $this->outcomes($data));
        $this->assertSame(array(82), $data['remaining']);
        $this->assertSame('', $data['stopped']);
        $this->assertCount(0, PrestaShopLogger::$logs, 'neither final nor stopped: no log entry');
    }

    public function testTheCapPutsTheOverflowIntoRemaining()
    {
        foreach (array(80, 81, 82, 83, 84) as $id) {
            $this->order($id);
        }
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });

        $data = $this->runner()->setPipeline($pipeline)->run('issue', array(80, 81, 82, 83, 84), 9);

        $this->assertCount(3, $data['results']);
        $this->assertSame(array(83, 84), $data['remaining']);
    }

    public function testIdsAreCastDeduplicatedAndKeepTheirOrder()
    {
        foreach (array(80, 81) as $id) {
            $this->order($id);
        }
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });

        $data = $this->runner()->setPipeline($pipeline)->run('issue', array('81', 80, 81, '80', 0, -3), 9);

        $this->assertSame(array(81, 80), array_column($data['results'], 'id'));
        $this->assertSame('issue', $data['kind']);
    }

    // --- log ---------------------------------------------------------------------------------------------------

    public function testTheFinalBatchLogsTheWholeRun()
    {
        $this->order(80);
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });

        $this->runner()->setPipeline($pipeline)->run('issue', array(80), 5, array(
            'final' => true,
            'previous_totals' => array('issued' => 3, 'failed' => 1),
        ));

        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame('iFirma bulk issue by employee 5: issued=4, failed=1', PrestaShopLogger::$logs[0]['message']);
        $this->assertSame(1, PrestaShopLogger::$logs[0]['severity']);
    }

    public function testOnlyTheLastBatchOfARunLogs()
    {
        foreach (array(80, 81, 82, 83) as $id) {
            $this->order($id);
        }
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });
        $runner = $this->runner()->setPipeline($pipeline);

        // a batch that is not the last, and a "final" batch that still has ids left over, write nothing
        $runner->run('issue', array(80, 81, 82), 5);
        $this->assertCount(0, PrestaShopLogger::$logs);
        $first = $runner->run('issue', array(80, 81, 82, 83), 5, array('final' => true));
        $this->assertSame(array(83), $first['remaining']);
        $this->assertCount(0, PrestaShopLogger::$logs);

        $runner->run('issue', $first['remaining'], 5, array('final' => true, 'previous_totals' => array('issued' => 3)));
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame('iFirma bulk issue by employee 5: issued=4', PrestaShopLogger::$logs[0]['message']);
    }

    public function testThePostedTotalsKeepOnlyKnownOutcomesWithNonNegativeCounts()
    {
        $this->order(80);
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });

        $this->runner()->setPipeline($pipeline)->run('issue', array(80), 5, array(
            'final' => true,
            'previous_totals' => array('issued' => -5, 'failed' => 2, 'forged line, stopped: x' => 9, 'sent' => '-1'),
        ));

        $this->assertSame('iFirma bulk issue by employee 5: issued=1, failed=2, sent=0', PrestaShopLogger::$logs[0]['message']);
    }

    public function testAStoppedRunLogsWithTheStopReason()
    {
        foreach (array(80, 81) as $id) {
            $this->order($id);
        }
        $pipeline = new BulkRunnerFakePipeline(function ($order, $n) {
            return $n === 2 ? $this->result('rate_limited') : $this->result('issued', true);
        });

        $this->runner()->setPipeline($pipeline)->run('issue', array(80, 81), 5, array('previous_totals' => array('sent' => 2)));

        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame(
            'iFirma bulk issue by employee 5: sent=2, issued=1, not_attempted=1, stopped: rate_limited',
            PrestaShopLogger::$logs[0]['message']
        );
    }

    public function testResendOfAReceiptThatDoesNotLoadFails()
    {
        $delivery = new BulkRunnerFakeDelivery(function () {
            return new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        });

        $data = $this->runner()->setDeliveryService($delivery)->run('resend', array(555), 9);

        $this->assertSame(array('failed'), $this->outcomes($data));
        $this->assertSame(0, $delivery->calls);
    }

    public function testRefreshOfAReceiptThatDoesNotLoadFails()
    {
        $sync = new BulkRunnerFakeSync();

        $data = $this->runner()->setSyncService($sync)->run('refresh', array(555), 9);

        $this->assertSame(array('failed'), $this->outcomes($data));
        $this->assertSame(555, $data['results'][0]['id']);
        $this->assertCount(0, $sync->dates);
    }

    public function testRefreshStopsBetweenDatesWhenTheBudgetIsSpent()
    {
        $self = $this;
        $a = $this->makeReceipt(array('issue_date' => '2026-11-01'));
        $b = $this->makeReceipt(array('id_order' => 16, 'ifirma_id' => '78', 'issue_date' => '2026-11-02'));
        $sync = new BulkRunnerFakeSync();
        $sync->decide = function ($date, array $receipts, $n, array $summary) use ($self) {
            $self->now += 30;

            return $summary;
        };

        $data = $this->runner()->setSyncService($sync)->run('refresh', array($a->id, $b->id), 9, array('budget' => 20));

        $this->assertSame(array('2026-11-01'), $sync->dates, 'the second date is left for the next batch');
        $this->assertSame(array('refreshed'), $this->outcomes($data));
    }

    public function testRefreshStopsWhenAFailedDateLeavesTheCredentialsRejected()
    {
        $a = $this->makeReceipt(array('issue_date' => '2026-11-01'));
        $b = $this->makeReceipt(array('id_order' => 16, 'ifirma_id' => '78', 'issue_date' => '2026-11-02'));
        $sync = new BulkRunnerFakeSync();
        $sync->decide = function ($date, array $receipts, $n, array $summary) {
            $receipts[0]->sync_error = 'HTTP 401';

            return $summary;
        };

        $data = $this->runner()->setSyncService($sync)->setAuthProbe(function () {
            return true;
        })->run('refresh', array($a->id, $b->id), 9);

        $this->assertSame('auth_blocked', $data['stopped']);
        $this->assertSame(array('2026-11-01'), $sync->dates);
        $this->assertSame(array('failed', 'not_attempted'), $this->outcomes($data));
    }

    public function testACredentialCheckThatThrowsCountsAsNotBlocked()
    {
        Configuration::$values['IFIRMA_USER'] = new BulkRunnerThrowingString();
        $a = $this->makeReceipt(array('issue_date' => '2026-11-01'));
        $b = $this->makeReceipt(array('id_order' => 16, 'ifirma_id' => '78', 'issue_date' => '2026-11-02'));
        $sync = new BulkRunnerFakeSync();
        $sync->decide = function ($date, array $receipts, $n, array $summary) {
            $receipts[0]->sync_error = 'HTTP 500';

            return $summary;
        };

        $data = $this->runner()->setSyncService($sync)->run('refresh', array($a->id, $b->id), 9);

        $this->assertSame('', $data['stopped']);
        $this->assertCount(2, $sync->dates);
    }

    public function testAMalformedPreviousTotalsIsIgnoredByTheLog()
    {
        $this->order(80);
        $pipeline = new BulkRunnerFakePipeline(function () {
            return $this->result('issued', true);
        });

        $this->runner()->setPipeline($pipeline)->run('issue', array(80), 5, array('final' => true, 'previous_totals' => 'oops'));

        $this->assertSame('iFirma bulk issue by employee 5: issued=1', PrestaShopLogger::$logs[0]['message']);
    }
    public function testTheRemainingRecordsAreToldToRunAgainInAMinuteWhenTheMinuteRefused()
    {
        $ids = $this->fourReceiptsWhoseSecondResendIsRefused();

        $data = $this->runner()->setDeliveryService($this->refusingDelivery())->run('resend', $ids, 9);

        $this->assertSame('not attempted (rate limited). Run it again in a minute.', $data['results'][2]['message']);
    }

    /** A back-off iFirma asked for can last far longer than a minute: the hint names the time it ends. */
    public function testTheRemainingRecordsAreToldWhenTheBackOffEndsWhenIfirmaAskedTheModuleToWait()
    {
        $ids = $this->fourReceiptsWhoseSecondResendIsRefused();
        $backoffEnd = $this->now + 3 * 3600;
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) $backoffEnd);

        $data = $this->runner()->setDeliveryService($this->refusingDelivery())->run('resend', $ids, 9);

        $this->assertSame(
            'not attempted (rate limited). Run it again after ' . date('H:i', $backoffEnd) . '.',
            $data['results'][3]['message']
        );
    }

    public function testABackOffThatEndsOnAnotherDayIsNamedWithItsDate()
    {
        $this->now = 20 * 3600;
        $ids = $this->fourReceiptsWhoseSecondResendIsRefused();
        $backoffEnd = $this->now + 6 * 3600;
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) $backoffEnd);

        $data = $this->runner()->setDeliveryService($this->refusingDelivery())->run('resend', $ids, 9);

        $this->assertSame(
            'not attempted (rate limited). Run it again after ' . date('Y-m-d H:i', $backoffEnd) . '.',
            $data['results'][3]['message']
        );
    }

    public function testTheRemainingRecordsAreToldToRunAgainTomorrowWhenTheDayCapRefused()
    {
        $ids = $this->fourReceiptsWhoseSecondResendIsRefused();
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 500);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now) . ':500');

        $data = $this->runner()->setDeliveryService($this->refusingDelivery())->run('resend', $ids, 9);

        $this->assertSame('not attempted (rate limited). Run it again tomorrow.', $data['results'][1]['message']);
        $this->assertSame('not attempted (rate limited). Run it again tomorrow.', $data['results'][3]['message']);
    }

    public function testTheHintIsNotAddedWhenTheCredentialsWereRejected()
    {
        $ids = $this->fourReceiptsWhoseSecondResendIsRefused();
        $delivery = new BulkRunnerFakeDelivery(function ($receipt, $n) {
            if ($n === 2) {
                return new IfirmaDeliveryResult(false, 'failed', 'ifirma_mail', 'paused', 'auth_blocked');
            }

            return new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        });

        $data = $this->runner()->setDeliveryService($delivery)->run('resend', $ids, 9);

        $this->assertSame('not attempted (credentials rejected)', $data['results'][1]['message']);
    }

    /** @return int[] receipt ids */
    private function fourReceiptsWhoseSecondResendIsRefused()
    {
        $ids = array();
        for ($i = 0; $i < 4; $i++) {
            $this->order(60 + $i);
            $ids[] = (int) $this->makeReceipt(array('id_order' => 60 + $i))->id;
        }

        return $ids;
    }

    private function refusingDelivery()
    {
        return new BulkRunnerFakeDelivery(function ($receipt, $n) {
            if ($n === 2) {
                return new IfirmaDeliveryResult(false, 'failed', 'ifirma_mail', 'refused', 'rate_limited');
            }

            return new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        });
    }
}
