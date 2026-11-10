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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/admin/AdminIfirmaController.php';

/** The admin controller whose answers are recorded, whose download budget runs on a fixed clock. */
class IfirmaTestBudgetAdminController extends AdminIfirmaController
{
    /** @var int */
    public static $now = 0;

    /** @var array[] status and text of every plain-text answer */
    public $texts = array();

    /** @var array[] seconds and text of every limit answer */
    public $limits = array();

    /** @var int PDFs streamed */
    public $streamed = 0;

    protected function employeeBudget()
    {
        return IfirmaDownloadBudget::forEmployees(function () {
            return IfirmaTestBudgetAdminController::$now;
        });
    }

    protected function textResponse($status, $text)
    {
        $this->texts[] = array($status, $text);
    }

    protected function refusedByRateLimit($retryAfterSeconds, $text)
    {
        $this->limits[] = array($retryAfterSeconds, $text);
    }

    protected function streamPdf($pdf, IfirmaReceipt $receipt)
    {
        $this->streamed++;
    }
}

/**
 * Spec receipt-access-control, "Employee PDF downloads have their own request budget": all back-office downloads
 * together send at most 30 requests per calendar minute, and the budget does not touch the module's shared limit.
 */
class AdminDownloadBudgetTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Access::reset();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id = 1;
        Context::getContext()->employee->id_profile = 1;
        IfirmaTestBudgetAdminController::$now = strtotime('2026-11-10 10:15:05');
        $this->t->queue('GET', '#\.pdf#', function () {
            return array('body' => "%PDF-1.4\nfake body\n%%EOF");
        });
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        Tools::reset();
    }

    private function download()
    {
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_order' => 15);
        $controller = new IfirmaTestBudgetAdminController();
        $controller->ajaxProcessDownloadPdf();

        return $controller;
    }

    private function minute()
    {
        return date('YmdHi', IfirmaTestBudgetAdminController::$now);
    }

    public function testThirtyDownloadsInAMinuteReachIfirmaAndTheThirtyFirstIsRefusedWith429AndNothingIsSent()
    {
        $this->makeReceipt();
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(1, $this->download()->streamed, 'download ' . ($i + 1));
        }

        $controller = $this->download();

        $this->assertCount(30, $this->t->calls);
        $this->assertSame(0, $controller->streamed);
        $this->assertCount(1, $controller->limits);
        $this->assertSame(55, $controller->limits[0][0], 'the seconds to the next minute');
        $this->assertSame(
            'The PDF was not downloaded: the back-office download limit is reached. Try again in a moment.',
            $controller->limits[0][1]
        );
    }

    public function testAnIssuanceInTheSameMinuteIsNotRefusedByTheModulesLimit()
    {
        $this->makeReceipt();
        for ($i = 0; $i < 31; $i++) {
            $this->download();
        }

        $this->assertTrue((new IfirmaRateGuard())->admit()->isAllowed());
    }

    public function testTheNextMinuteStartsANewBudget()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_DL_EMPLOYEE_MINUTE', $this->minute() . ':30');
        $this->assertCount(1, $this->download()->limits);

        IfirmaTestBudgetAdminController::$now += 60;

        $this->assertSame(1, $this->download()->streamed);
    }

    public function testAnEmployeeWithoutTheViewLevelGetsTheAccessDeniedAnswerNotTheLimit()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_DL_EMPLOYEE_MINUTE', $this->minute() . ':30');
        Context::getContext()->employee->id_profile = 4;

        $controller = $this->download();

        $this->assertSame(array(), $controller->limits);
        $this->assertSame(403, $controller->texts[0][0]);
    }

    public function testAnOrderWithoutADocumentIsNotFoundAndSpendsNoBudget()
    {
        $controller = $this->download();

        $this->assertSame(404, $controller->texts[0][0]);
        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_EMPLOYEE_MINUTE'));
    }

    public function testBackOfficeDownloadsAreNotCountedInTheCustomerBudget()
    {
        $this->makeReceipt();

        $this->download();

        $this->assertSame($this->minute() . ':1', Configuration::get('IFIRMA_DL_EMPLOYEE_MINUTE'));
        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_CUSTOMER_MINUTE'));
        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_CUSTOMER_DAY'));
    }

    public function testADownloadThatTheSharedGuardRefusesGivesItsUnitBack()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_DL_EMPLOYEE_MINUTE', $this->minute() . ':3');
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) (time() + 5000));

        $controller = $this->download();

        $this->assertCount(1, $controller->limits);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame($this->minute() . ':3', Configuration::get('IFIRMA_DL_EMPLOYEE_MINUTE'));
    }

    public function testADownloadRefusedByTheBudgetSpendsNothingMore()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_DL_EMPLOYEE_MINUTE', $this->minute() . ':30');

        $this->download();

        $this->assertSame($this->minute() . ':30', Configuration::get('IFIRMA_DL_EMPLOYEE_MINUTE'));
    }
}
