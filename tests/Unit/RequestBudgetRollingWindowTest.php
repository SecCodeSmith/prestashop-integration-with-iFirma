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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDownloadBudget.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCustomerSyncBudget.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRateGuard.php';

/**
 * The request budgets count any 60 seconds, like the rate guard, so a burst around the turn of a calendar minute
 * cannot take more than the cap (specs customer-receipt-visibility, receipt-access-control, receipt-status-sync and
 * ifirma-api-compliance "Downloads cannot take the whole window").
 */
class RequestBudgetRollingWindowTest extends TestCase
{
    /** @var int */
    public $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->clockAt(10, 0, 59);
    }

    private function clockAt($hour, $minute, $second)
    {
        $this->now = mktime($hour, $minute, $second, 11, 10, 2026);
    }

    private function clock()
    {
        $self = $this;

        return function () use ($self) {
            return $self->now;
        };
    }

    private function granted(IfirmaRequestBudget $budget, $times)
    {
        $granted = 0;
        for ($i = 0; $i < $times; $i++) {
            if ($budget->take()) {
                $granted++;
            }
        }

        return $granted;
    }

    public function testACustomerBurstAcrossTheTurnOfAMinuteIsRefused()
    {
        $this->assertSame(20, $this->granted(IfirmaDownloadBudget::forCustomers($this->clock()), 20));
        $this->clockAt(10, 1, 0);

        $this->assertSame(0, $this->granted(IfirmaDownloadBudget::forCustomers($this->clock()), 20));
    }

    public function testAnEmployeeBurstAcrossTheTurnOfAMinuteIsRefused()
    {
        $this->assertSame(30, $this->granted(IfirmaDownloadBudget::forEmployees($this->clock()), 30));
        $this->clockAt(10, 1, 0);

        $this->assertSame(0, $this->granted(IfirmaDownloadBudget::forEmployees($this->clock()), 30));
    }

    public function testAPageSyncBurstAcrossTheTurnOfAMinuteIsRefused()
    {
        $this->assertSame(10, $this->granted(IfirmaCustomerSyncBudget::create($this->clock()), 10));
        $this->clockAt(10, 1, 0);

        $this->assertSame(0, $this->granted(IfirmaCustomerSyncBudget::create($this->clock()), 10));
    }

    public function testDownloadsAtTheTurnOfAMinuteCannotTakeTheWholeRequestWindow()
    {
        $guard = new IfirmaRateGuard($this->clock());
        $sent = 0;
        foreach (array(array(IfirmaDownloadBudget::forCustomers($this->clock()), 20), array(IfirmaDownloadBudget::forEmployees($this->clock()), 30)) as $batch) {
            for ($i = 0; $i < $batch[1]; $i++) {
                if ($batch[0]->take() && $guard->admit()->isAllowed()) {
                    $sent++;
                }
            }
        }
        $this->assertSame(50, $sent);

        $this->clockAt(10, 1, 0);
        $sent = 0;
        foreach (array(array(IfirmaDownloadBudget::forCustomers($this->clock()), 20), array(IfirmaDownloadBudget::forEmployees($this->clock()), 30)) as $batch) {
            for ($i = 0; $i < $batch[1]; $i++) {
                if ($batch[0]->take() && $guard->admit()->isAllowed()) {
                    $sent++;
                }
            }
        }

        $this->assertSame(0, $sent, 'none of the second downloads is sent');
        $this->assertTrue($guard->admit()->isAllowed(), 'the order that reaches the trigger status is issued');
    }
}
