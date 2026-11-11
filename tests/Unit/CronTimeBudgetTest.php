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

/**
 * A cron run has a time budget: no job starts another record once it is used up, the later jobs only report what
 * is left, and the answer says so. The budget is checked before a record starts and never interrupts one.
 */
class CronTimeBudgetTest extends TestCase
{
    /** @var int */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 1, 2026);
        Configuration::updateValue('IFIRMA_CRON_MAX_ITEMS', 7);
    }

    private function clock()
    {
        return function () {
            return $this->now;
        };
    }

    public function testTheDefaultBudgetIsFortyFiveSeconds()
    {
        $this->assertSame(45, IfirmaCronContext::DEFAULT_TIME_BUDGET_SECONDS);
    }

    public function testARecordMayStartWhileTheBudgetLasts()
    {
        $context = new IfirmaCronContext(10, $this->clock());
        $this->now += 44;

        $this->assertTrue($context->allowsAnotherItem());
        $this->assertFalse($context->timeBudgetWasUsedUp());
    }

    public function testNoRecordStartsOnceTheBudgetIsUsedUpAndTheContextRemembersIt()
    {
        $context = new IfirmaCronContext(10, $this->clock(), 30);
        $this->now += 30;

        $this->assertFalse($context->allowsAnotherItem());
        $this->now -= 30;
        $this->assertTrue($context->timeBudgetWasUsedUp(), 'what was refused stays reported');
    }

    public function testTheBudgetIsMeasuredFromTheStartOfTheRun()
    {
        $this->now += 1000;
        $context = new IfirmaCronContext(10, $this->clock(), 30);
        $this->now += 29;

        $this->assertTrue($context->allowsAnotherItem());
    }

    public function testABudgetBelowOneSecondIsOneSecond()
    {
        $context = new IfirmaCronContext(10, $this->clock(), 0);

        $this->assertTrue($context->allowsAnotherItem());
        $this->now += 1;
        $this->assertFalse($context->allowsAnotherItem());
    }

    public function testALaterJobOnlyReportsWhatIsLeftOnceTheBudgetIsUsedUp()
    {
        $log = array();
        $slow = $this->jobThatTakes(60, 'slow', $log);
        $later = $this->job('later', $log);
        $runner = new IfirmaCronRunner(array($slow, $later), $this->clock());

        $summary = $runner->run();

        $this->assertSame(array('slow:run', 'later:skip'), $log);
        $this->assertSame(1, $summary['time_budget_exhausted']);
        $this->assertSame(0, $summary['rate_limited'], 'the existing keys keep their meaning');
    }

    public function testARunInsideTheBudgetRunsEveryJobAndReportsNoExhaustion()
    {
        $log = array();
        $runner = new IfirmaCronRunner(array($this->jobThatTakes(10, 'a', $log), $this->job('b', $log)), $this->clock());

        $summary = $runner->run();

        $this->assertSame(array('a:run', 'b:run'), $log);
        $this->assertSame(0, $summary['time_budget_exhausted']);
    }

    public function testAJobThatStoppedItselfOnTheBudgetIsReportedEvenWhenNoLaterJobWasSkipped()
    {
        $log = array();
        $stopping = new CronTimeBudgetFakeJob('only', $log, function (IfirmaCronContext $context) {
            $this->now += 60;
            $context->allowsAnotherItem();
        }, $this->clock());
        $runner = new IfirmaCronRunner(array($stopping), $this->clock());

        $summary = $runner->run();

        $this->assertSame(1, $summary['time_budget_exhausted']);
    }

    private function job($name, array &$log)
    {
        return new CronTimeBudgetFakeJob($name, $log, null, $this->clock());
    }

    private function jobThatTakes($seconds, $name, array &$log)
    {
        return new CronTimeBudgetFakeJob($name, $log, function () use ($seconds) {
            $this->now += $seconds;
        }, $this->clock());
    }
}

class CronTimeBudgetFakeJob implements IfirmaCronJob
{
    private $name;
    private $log;
    private $work;

    public function __construct($name, array &$log, $work, $clock)
    {
        $this->name = $name;
        $this->log = &$log;
        $this->work = $work;
    }

    public function name()
    {
        return $this->name;
    }

    public function run(IfirmaCronContext $context)
    {
        $this->log[] = $this->name . ':run';
        if ($this->work !== null) {
            call_user_func($this->work, $context);
        }

        return IfirmaCronJobReport::counts($this->name, 1, 1, 0, 0);
    }

    public function skip(IfirmaCronContext $context)
    {
        $this->log[] = $this->name . ':skip';

        return IfirmaCronJobReport::skipped($this->name);
    }
}
