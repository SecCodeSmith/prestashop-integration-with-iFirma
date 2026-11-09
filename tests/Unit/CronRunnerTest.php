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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCronRunner.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaStatusSyncJob.php';

/**
 * A cron job that records when it ran and answers with a prepared report.
 */
class CronRunnerFakeJob implements IfirmaCronJob
{
    /** @var string */
    private $name;

    /** @var IfirmaCronJobReport */
    private $report;

    /** @var string[] shared log of "<name>:run" and "<name>:skip" in call order */
    public $log;

    /** @var IfirmaCronContext|null the context of the last run */
    public $context;

    public function __construct($name, IfirmaCronJobReport $report, array &$log)
    {
        $this->name = $name;
        $this->report = $report;
        $this->log = &$log;
    }

    public function name()
    {
        return $this->name;
    }

    public function run(IfirmaCronContext $context)
    {
        $this->log[] = $this->name . ':run';
        $this->context = $context;

        return $this->report;
    }

    public function skip(IfirmaCronContext $context)
    {
        $this->log[] = $this->name . ':skip';

        return IfirmaCronJobReport::skipped($this->name);
    }
}

class CronRunnerTest extends TestCase
{
    /** @var string[] */
    private $log = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->log = array();
        Configuration::updateValue('IFIRMA_CRON_MAX_ITEMS', 7);
    }

    private function job($name, IfirmaCronJobReport $report = null)
    {
        return new CronRunnerFakeJob($name, $report ?: IfirmaCronJobReport::counts($name, 1, 1, 0, 0), $this->log);
    }

    private function runner(array $jobs)
    {
        return new IfirmaCronRunner($jobs, function () {
            return mktime(10, 15, 20, 11, 1, 2026);
        });
    }

    public function testTheJobsRunInTheOrderTheyAreRegistered()
    {
        $this->runner(array($this->job('queue'), $this->job('ksef'), $this->job('sync'), $this->job('delivery')))->run();

        $this->assertSame(array('queue:run', 'ksef:run', 'sync:run', 'delivery:run'), $this->log);
    }

    public function testTheLockOfARunningCronMakesTheSecondRunSkipEverything()
    {
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\\(/', 0);

        $summary = $this->runner(array($this->job('queue'), $this->job('sync')))->run();

        $this->assertSame(array('skipped' => 'another cron run is busy'), $summary);
        $this->assertSame(array(), $this->log, 'no job ran, so no iFirma request was made');
    }

    public function testTheRunLockIsTakenWithoutWaitingAndReleased()
    {
        $asked = array();
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\\(/', function ($sql) use (&$asked) {
            $asked[] = $sql;

            return 1;
        });

        $this->runner(array($this->job('queue')))->run();

        $this->assertSame(array("SELECT GET_LOCK('ifirma_cron', 0)"), $asked);
        $released = array_filter(Db::getInstance()->queries, function ($sql) {
            return strpos($sql, "RELEASE_LOCK('ifirma_cron')") !== false;
        });
        $this->assertCount(1, $released);
    }

    public function testARateLimitedJobStopsTheLaterOnes()
    {
        $jobs = array(
            $this->job('queue', IfirmaCronJobReport::counts('queue', 3, 2, 0, 4)->limited(IfirmaRateDecision::WINDOW_DAY)),
            $this->job('ksef'),
            $this->job('sync'),
            $this->job('delivery'),
        );

        $summary = $this->runner($jobs)->run();

        $this->assertSame(array('queue:run', 'ksef:skip', 'sync:skip', 'delivery:skip'), $this->log);
        $this->assertSame(1, $summary['rate_limited']);
        $this->assertSame('day', $summary['rate_limited_window']);
    }

    public function testTheSummaryHasABlockPerJobAndTheCountsOfEachRun()
    {
        $jobs = array(
            $this->job('queue', IfirmaCronJobReport::counts('queue', 5, 4, 1, 12)),
            $this->job('ksef', IfirmaCronJobReport::counts('ksef', 2, 2, 0, 0)),
            $this->job('delivery', IfirmaCronJobReport::counts('delivery', 1, 0, 1, 3)),
        );

        $summary = $this->runner($jobs)->run();

        $this->assertSame(array('handled' => 5, 'succeeded' => 4, 'failed' => 1, 'left' => 12), $summary['queue']);
        $this->assertSame(array('handled' => 2, 'succeeded' => 2, 'failed' => 0, 'left' => 0), $summary['ksef']);
        $this->assertSame(array('handled' => 1, 'succeeded' => 0, 'failed' => 1, 'left' => 3), $summary['delivery']);
        $this->assertSame(0, $summary['rate_limited']);
        $this->assertSame('', $summary['rate_limited_window']);
    }

    public function testTheContextCarriesTheItemCapAndTheClock()
    {
        $job = $this->job('queue');

        $this->runner(array($job))->run();

        $this->assertSame(7, $job->context->getMaxItems());
        $this->assertSame(mktime(10, 15, 20, 11, 1, 2026), $job->context->now());
    }

    public function testTheItemCapFallsBackToTenWhenTheSettingIsMissing()
    {
        Configuration::updateValue('IFIRMA_CRON_MAX_ITEMS', 0);
        $job = $this->job('queue');

        $this->runner(array($job))->run();

        $this->assertSame(10, $job->context->getMaxItems());
    }

    public function testTheTopLevelKeysOfTheStatusSyncAreStillThere()
    {
        $service = $this->getMockBuilder(IfirmaStatusSyncService::class)->disableOriginalConstructor()->onlyMethods(array('runCron'))->getMock();
        $service->method('runCron')->willReturn(array(
            'receipts_checked' => 4, 'updated' => 3, 'marked_paid' => 1, 'marked_missing' => 0, 'errors' => 0,
            'rate_limited' => 0, 'calls' => 2, 'dates_total' => 1, 'dates_processed' => 1, 'held_orders' => 0,
            'started_at' => '2026-11-01T10:15:20+01:00', 'finished_at' => '2026-11-01T10:15:21+01:00',
            'auth_blocked' => false, 'message' => '',
        ));

        $summary = $this->runner(array(new IfirmaStatusSyncJob($service)))->run();

        foreach (array('receipts_checked', 'updated', 'marked_paid', 'marked_missing', 'errors', 'rate_limited', 'calls',
            'dates_total', 'dates_processed', 'held_orders', 'started_at', 'finished_at', 'auth_blocked', 'message') as $key) {
            $this->assertArrayHasKey($key, $summary, $key);
        }
        $this->assertSame(4, $summary['receipts_checked']);
        $this->assertSame(0, $summary['rate_limited']);
        $this->assertArrayNotHasKey('sync', $summary, 'the sync keeps its keys at the top level');
    }

    public function testASkippedStatusSyncStillReportsItsKeysAsZero()
    {
        $service = $this->getMockBuilder(IfirmaStatusSyncService::class)->disableOriginalConstructor()->onlyMethods(array('runCron'))->getMock();
        $service->expects($this->never())->method('runCron');
        $jobs = array(
            $this->job('queue', IfirmaCronJobReport::counts('queue', 1, 0, 0, 5)->limited(IfirmaRateDecision::WINDOW_MINUTE)),
            new IfirmaStatusSyncJob($service),
        );

        $summary = $this->runner($jobs)->run();

        $this->assertSame(0, $summary['receipts_checked']);
        $this->assertSame(0, $summary['calls']);
        $this->assertSame(1, $summary['rate_limited']);
        $this->assertSame('minute', $summary['rate_limited_window']);
    }

    public function testARateLimitedStatusSyncIsReportedWithItsWindow()
    {
        $service = $this->getMockBuilder(IfirmaStatusSyncService::class)->disableOriginalConstructor()->onlyMethods(array('runCron'))->getMock();
        $service->method('runCron')->willReturn(array('receipts_checked' => 1, 'rate_limited' => 1, 'calls' => 1));
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', mktime(10, 15, 20, 11, 1, 2026)) . ':80');

        $summary = $this->runner(array(new IfirmaStatusSyncJob($service), $this->job('delivery')))->run();

        $this->assertSame(1, $summary['rate_limited']);
        $this->assertSame('minute', $summary['rate_limited_window']);
        $this->assertSame(array('delivery:skip'), $this->log);
    }
    public function testTheJobReportKnowsItsName()
    {
        $this->assertSame('queue', IfirmaCronJobReport::counts('queue', 0, 0, 0, 0)->getName());
    }

    public function testTheBuiltRunnerChecksTheCredentialPauseBeforeEveryQueuedOrder()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', time() . '|Invalid signature');
        $runner = IfirmaCronRunner::build(new Module());
        $jobs = new ReflectionProperty(IfirmaCronRunner::class, 'jobs');
        $jobs->setAccessible(true);
        $queueJob = $jobs->getValue($runner)[1];   // [0] is the limit read, which runs before the queue
        $probe = new ReflectionProperty(IfirmaIssueQueueJob::class, 'authBlocked');
        $probe->setAccessible(true);

        $this->assertTrue(call_user_func($probe->getValue($queueJob)));
    }
}
