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
 * IfirmaDownloadBudget: a named budget of requests per calendar minute and day, apart from the rate guard.
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
        $this->assertSame('202611101015:20', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
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
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', '202611101015:4');

        $this->customers()->take();

        $this->assertSame('202611101015:4', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testReleaseGivesBackWhatTheLastTakeSpentInEveryWindow()
    {
        $budget = $this->customers();
        $budget->take();

        $budget->release();

        $this->assertSame('202611101015:0', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
        $this->assertSame('20261110:0', Configuration::get('IFIRMA_DL_CUSTOMER_DAY'));
    }

    public function testReleaseTwiceGivesBackOnlyOneUnit()
    {
        $budget = $this->customers();
        $budget->take();
        $budget->take();

        $budget->release();
        $budget->release();

        $this->assertSame('202611101015:1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testAReleaseAfterTheMinuteHasMovedOnLeavesTheNewMinuteAlone()
    {
        $budget = $this->customers();
        $budget->take();
        $this->now += 60;
        $other = $this->customers();
        $other->take();

        $budget->release();

        $this->assertSame('202611101016:1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testAReleaseWithoutATakeChangesNothing()
    {
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', '202611101015:6');

        $this->customers()->release();

        $this->assertSame('202611101015:6', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testANewMinuteStartsANewCount()
    {
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', '202611101015:20');
        $this->assertFalse($this->customers()->take());

        $this->now += 60;

        $this->assertTrue($this->customers()->take());
        $this->assertSame('202611101016:1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
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
        $this->assertSame('202611101015:1', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testTheEmployeeBudgetAdmitsThirtyAMinuteWithNoDayCap()
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

    public function testTheSecondsToTheNextMinuteCountDownToTheMinuteEnd()
    {
        $this->assertSame(55, $this->employees()->secondsToNextMinute());
        $this->now = strtotime('2026-11-10 10:15:00');
        $this->assertSame(60, $this->employees()->secondsToNextMinute());
        $this->now = strtotime('2026-11-10 10:15:59');
        $this->assertSame(1, $this->employees()->secondsToNextMinute());
    }

    public function testWithoutAClockTheBudgetUsesTheRealTime()
    {
        $this->assertTrue(IfirmaDownloadBudget::forEmployees()->take());
        $this->assertSame(date('YmdHi') . ':1', Configuration::get('IFIRMA_DL_EMPLOYEE_MINUTE'));
    }
}
