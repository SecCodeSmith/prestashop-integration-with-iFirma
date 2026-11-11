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

/** A day counter whose give-back fails, as a database error would make it. */
class DownloadBudgetTestFailingDayCounter extends IfirmaWindowCounter
{
    public function giveBack(IfirmaRateWindow $window, $bucket)
    {
        throw new RuntimeException('database gone');
    }
}

/**
 * IfirmaDownloadBudget: a named budget of requests in any 60 seconds and per calendar day, apart from the rate guard.
 */
class DownloadBudgetTest extends TestCase
{
    /** @var int */
    public $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = strtotime('2026-11-10 10:15:05');
    }

    private function clock()
    {
        $self = $this;

        return function () use ($self) {
            return $self->now;
        };
    }

    private function customers()
    {
        return IfirmaDownloadBudget::forCustomers($this->clock());
    }

    private function employees()
    {
        return IfirmaDownloadBudget::forEmployees($this->clock());
    }

    public function testTheCustomerBudgetAdmitsTwentyAMinuteAndRefusesTheTwentyFirst()
    {
        $budget = $this->customers();
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($budget->take(), 'download ' . ($i + 1));
        }

        $this->assertFalse($budget->take());
        $this->assertSame($this->now . '=20', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testTheCustomerDayBudgetRefusesAtTwoThousand()
    {
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_DAY', '20261110:2000');

        $this->assertFalse($this->customers()->take());
        $this->assertSame('20261110:2000', Configuration::get('IFIRMA_DL_CUSTOMER_DAY'));
    }

    public function testARefusalByTheDayGivesTheMinuteUnitBack()
    {
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_DAY', '20261110:2000');
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->now . '=4');

        $this->customers()->take();

        $this->assertSame($this->now . '=4', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testReleaseGivesBackWhatTheLastTakeSpentInEveryWindow()
    {
        $budget = $this->customers();
        $budget->take();

        $budget->release();

        $this->assertSame('', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
        $this->assertSame('20261110:0', Configuration::get('IFIRMA_DL_CUSTOMER_DAY'));
    }

    public function testReleaseTwiceGivesBackOnlyOneUnit()
    {
        $budget = $this->customers();
        $budget->take();
        $budget->take();

        $budget->release();
        $budget->release();

        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testAReleaseAfterTheUnitLeftTheWindowLeavesTheNewCountAlone()
    {
        $budget = $this->customers();
        $budget->take();
        $this->now += 61;
        $other = $this->customers();
        $other->take();

        $budget->release();

        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testAReleaseWithoutATakeChangesNothing()
    {
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->now . '=6');

        $this->customers()->release();

        $this->assertSame($this->now . '=6', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testTheCountRestartsOnceTheUnitsAreOlderThanSixtySeconds()
    {
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->now . '=20');
        $this->assertFalse($this->customers()->take());

        $this->now += 60;
        $this->assertFalse($this->customers()->take(), 'exactly 60 seconds old is still counted');
        $this->now += 1;

        $this->assertTrue($this->customers()->take());
        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testANewDayStartsANewDayCount()
    {
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_DAY', '20261109:2000');

        $this->assertTrue($this->customers()->take());
        $this->assertSame('20261110:1', Configuration::get('IFIRMA_DL_CUSTOMER_DAY'));
    }

    public function testAMissingCounterRowIsCreatedOnFirstUse()
    {
        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_CUSTOMER_MINUTE'));

        $this->assertTrue($this->customers()->take());
        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testTheEmployeeBudgetAdmitsThirtyAMinute()
    {
        $budget = $this->employees();
        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue($budget->take());
        }

        $this->assertFalse($budget->take());
        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_CUSTOMER_DAY'));
        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testCustomersAndEmployeesDoNotShareACounter()
    {
        $customers = $this->customers();
        for ($i = 0; $i < 20; $i++) {
            $customers->take();
        }

        $this->assertTrue($this->employees()->take());
    }

    public function testTheBudgetsDoNotTouchTheModulesRateGuardCounters()
    {
        $this->customers()->take();
        $this->employees()->take();

        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_WINDOW'));
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testABudgetWithoutADayWindowSpendsOnlyTheMinuteUnit()
    {
        $budget = new IfirmaRequestBudget('IFIRMA_DL_TEST_MINUTE', 2, null, $this->clock());

        $this->assertTrue($budget->take());
        $this->assertTrue($budget->take());
        $this->assertFalse($budget->take());
        $budget->release();
        $this->assertSame($this->now . '=2', Configuration::get('IFIRMA_DL_TEST_MINUTE'));
    }

    public function testAReleaseThatFailsHalfWayDoesNotGiveTheMinuteUnitBackTwice()
    {
        $day = new IfirmaRateWindow(IfirmaRateDecision::WINDOW_DAY, 'IFIRMA_DL_TEST_DAY', 'Ymd', 100);
        $budget = new IfirmaRequestBudget('IFIRMA_DL_TEST_MINUTE', 5, $day, $this->clock(), new DownloadBudgetTestFailingDayCounter());
        Configuration::updateValue('IFIRMA_DL_TEST_MINUTE', $this->now . '=3');
        Db::getInstance()->whenExecute('/IFIRMA_DL_TEST_DAY/', 1);
        $budget->take();

        foreach (array(1, 2) as $attempt) {
            try {
                $budget->release();
            } catch (RuntimeException $e) {
                // the caller's catch block releases again
                continue;
            }
        }

        $this->assertSame($this->now . '=3', Configuration::get('IFIRMA_DL_TEST_MINUTE'), 'the one unit was given back once');
    }

    public function testADayRefusalWaitsTheDayRuleNotOneSecond()
    {
        Configuration::updateValue('IFIRMA_DL_EMPLOYEE_DAY', '20261110:2000');
        $budget = $this->employees();

        $this->assertFalse($budget->take());

        $this->assertSame(3600, $budget->secondsUntilRoom());
        $this->now = strtotime('2026-11-10 23:59:30');
        $this->assertFalse($budget->take());
        $this->assertSame(30, $budget->secondsUntilRoom());
    }

    public function testAMinuteRefusalWaitsUntilTheWindowHasRoom()
    {
        $budget = $this->employees();
        for ($i = 0; $i < 30; $i++) {
            $budget->take();
        }
        $this->assertFalse($budget->take());

        $this->assertSame(61, $budget->secondsUntilRoom());
    }

    public function testAFreeBudgetWaitsOneSecond()
    {
        $this->assertSame(1, $this->employees()->secondsUntilRoom());
    }

    public function testAFullBudgetWaitsUntilTheOldestUnitsAreSixtyOneSecondsOld()
    {
        $budget = $this->employees();
        for ($i = 0; $i < 30; $i++) {
            $budget->take();
        }
        $budget->take();

        $this->assertSame(61, $budget->secondsUntilRoom());
        $this->now += 30;
        $this->assertSame(31, $budget->secondsUntilRoom());
        $this->now += 31;
        $this->assertSame(1, $budget->secondsUntilRoom(), 'a window that freed meanwhile waits one second');
    }

    public function testWithoutAClockTheBudgetUsesTheRealTime()
    {
        $before = time();
        $this->assertTrue(IfirmaDownloadBudget::forEmployees()->take());
        $this->assertMatchesRegularExpression('/^(\d+)=1$/', Configuration::get('IFIRMA_DL_EMPLOYEE_MINUTE'));
        $this->assertGreaterThanOrEqual($before, (int) Configuration::get('IFIRMA_DL_EMPLOYEE_MINUTE'));
    }

    public function testTheEmployeeDayBudgetRefusesAtTwoThousand()
    {
        Configuration::updateValue('IFIRMA_DL_EMPLOYEE_DAY', '20261110:2000');

        $this->assertFalse($this->employees()->take());
        $this->assertSame('', Configuration::get('IFIRMA_DL_EMPLOYEE_MINUTE'), 'the minute unit went back');
    }
}
