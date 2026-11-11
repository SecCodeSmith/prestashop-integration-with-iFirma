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

/**
 * Spec: receipt-documents-list "Delivery retry shown in the list": the delivery cell of a failed record.
 */
class DocumentsListDeliveryTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        IfirmaSchema::resetEnsureCache();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id_profile = 4;
        Access::reset();
    }

    protected function tearDown(): void
    {
        IfirmaSchema::resetEnsureCache();
    }

    private function controller()
    {
        Tools::$values = array();

        return new AdminIfirmaController();
    }

    public function testRetryScheduledShowsFailedTheAttemptAndTheTime()
    {
        $cell = $this->controller()->renderDelivery('failed', array(
            'delivery_status' => 'failed', 'delivery_attempts' => '1', 'delivery_retry_at' => '2026-11-10 10:15:00',
        ));

        $this->assertStringContainsString('Failed', $cell);
        $this->assertStringContainsString('Attempt 1 of 3, next attempt at 2026-11-10 10:15', $cell);
    }

    public function testNoAttemptRemainsShowsFailedAndThatNoneRemains()
    {
        $cell = $this->controller()->renderDelivery('failed', array(
            'delivery_status' => 'failed', 'delivery_attempts' => '3', 'delivery_retry_at' => null,
        ));

        $this->assertStringContainsString('Failed', $cell);
        $this->assertStringContainsString('No automatic attempt remains', $cell);
    }

    public function testAFailureFromBeforeTheUpgradeShowsThatNothingIsScheduled()
    {
        $cell = $this->controller()->renderDelivery('failed', array('delivery_status' => 'failed', 'delivery_attempts' => '0', 'delivery_retry_at' => null));
        $withoutTheColumns = $this->controller()->renderDelivery('failed', array('delivery_status' => 'failed'));

        $this->assertStringContainsString('No automatic attempt is scheduled', $cell);
        $this->assertStringContainsString('No automatic attempt is scheduled', $withoutTheColumns);
    }

    /**
     * @dataProvider deliveriesThatDidNotFail
     */
    public function testADeliveryThatDidNotFailShowsOnlyItsStateLabel($status, $label)
    {
        $cell = $this->controller()->renderDelivery($status, array('delivery_status' => $status, 'delivery_attempts' => '2', 'delivery_retry_at' => '2026-11-10 10:15:00'));

        $this->assertSame($label, $cell);
    }

    public function deliveriesThatDidNotFail()
    {
        return array(
            'sent' => array('sent', 'Sent'),
            'none' => array('none', 'Not sent'),
            'skipped' => array('skipped', 'Skipped'),
            'awaiting KSeF' => array('awaiting_ksef', 'Awaiting KSeF'),
        );
    }

    public function testAStateOfAnotherVersionIsShownEscapedAsItIs()
    {
        $this->assertSame('a&lt;b', $this->controller()->renderDelivery('a<b', array()));
    }

    public function testMarkupInTheRetryTextIsShownAsText()
    {
        $c = $this->controller();
        $cell = $c->renderDelivery('failed', array(
            'delivery_status' => 'failed', 'delivery_attempts' => '1', 'delivery_retry_at' => '<script>alert(1)</script>',
        ));

        $this->assertStringNotContainsString('<script>', $cell);
    }

    public function testTheDeliveryColumnRendersThroughTheCallback()
    {
        $column = $this->controller()->fields_list['delivery_status'];

        $this->assertSame('renderDelivery', $column['callback']);
        $this->assertSame('a!delivery_status', $column['filter_key'], 'the filter stays on the stored state');
        $this->assertArrayHasKey('failed', $column['list']);
    }

    private function schemaStatements()
    {
        return array_values(array_filter(Db::getInstance()->queries, function ($sql) {
            return strpos($sql, 'COLUMN_NAME') !== false || strpos($sql, 'ALTER TABLE') !== false;
        }));
    }

    public function testTheRetryColumnsAreEnsuredBeforeTheListQuery()
    {
        Access::grant(IfirmaPermission::ROLE_READ, 4);
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'delivery_/", '0');

        $this->controller()->renderList();

        $this->assertContains(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `delivery_attempts` INT(11) UNSIGNED NOT NULL DEFAULT 0',
            Db::getInstance()->executed
        );
        $listQuery = null;
        $firstSchema = null;
        foreach (Db::getInstance()->queries as $i => $sql) {
            if ($firstSchema === null && strpos($sql, 'COLUMN_NAME') !== false) {
                $firstSchema = $i;
            }
            if ($listQuery === null && strpos($sql, 'FROM `ps_ifirma_receipt` a') !== false) {
                $listQuery = $i;
            }
        }
        $this->assertNotNull($listQuery, 'the list was queried');
        $this->assertLessThan($listQuery, $firstSchema);
    }

    public function testOpeningThePageTouchesNoSchemaBeforeTheCoreHasCheckedAccess()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'delivery_/", '0');

        $this->controller()->init();

        $this->assertSame(array(), $this->schemaStatements());
    }

    public function testAnEmployeeWithoutTheViewLevelTriggersNoSchemaStatementOnAnAction()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'delivery_/", '0');
        $controller = new IfirmaTestDispatchController();

        $controller->dispatch('IssueFeed');

        $this->assertSame(array(), $this->schemaStatements());
        $this->assertFalse($controller->bodyRan);
    }

    public function testAnEmployeeWithTheViewLevelHasTheSchemaEnsuredBeforeTheActionRuns()
    {
        Access::grant(IfirmaPermission::ROLE_READ, 4);
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'delivery_/", '0');
        $controller = new IfirmaTestDispatchController();

        $controller->dispatch('IssueFeed');

        $this->assertTrue($controller->bodyRan);
        $this->assertNotSame(array(), $this->schemaStatements());
        $this->assertTrue($controller->schemaWasEnsuredWhenTheBodyRan);
    }
}

/** Runs an action through the controller's access decision and records what the schema looked like by then. */
class IfirmaTestDispatchController extends AdminIfirmaController
{
    public $bodyRan = false;
    public $schemaWasEnsuredWhenTheBodyRan = false;

    public function dispatch($action)
    {
        $this->handle($action, function () {
            $this->bodyRan = true;
            $this->schemaWasEnsuredWhenTheBodyRan = count(Db::getInstance()->executed) > 0;
        });
    }

    protected function output(array $payload)
    {
    }

    protected function statusHeader($code)
    {
    }
}
