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

class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaSchema.php';
        require_once dirname(__DIR__, 2) . '/classes/IfirmaDataRequest.php';
    }

    public function testInstallCreatesTwoTables()
    {
        $result = include dirname(__DIR__, 2) . '/sql/install.php';
        $this->assertTrue($result);

        $creates = array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'CREATE TABLE') === 0;
        }));
        $this->assertCount(2, $creates);
        $this->assertCount(2, Db::getInstance()->executed);

        foreach (array_keys(IfirmaSchema::receiptColumns()) as $col) {
            $this->assertStringContainsString('`' . $col . '`', $creates[0]);
        }
        foreach (array_keys(IfirmaSchema::dataRequestColumns()) as $col) {
            $this->assertStringContainsString('`' . $col . '`', $creates[1]);
        }
        $this->assertStringContainsString('ps_ifirma_receipt', $creates[0]);
        $this->assertStringContainsString('ps_ifirma_data_request', $creates[1]);
    }

    public function testDefinitionsMatchColumns()
    {
        $this->assertSame(
            $this->sorted(array_keys(IfirmaSchema::receiptColumns())),
            $this->sorted(array_merge(array_keys(IfirmaReceipt::$definition['fields']), array(IfirmaReceipt::$definition['primary'])))
        );
        $this->assertSame(
            $this->sorted(array_keys(IfirmaSchema::dataRequestColumns())),
            $this->sorted(array_merge(array_keys(IfirmaDataRequest::$definition['fields']), array(IfirmaDataRequest::$definition['primary'])))
        );
        $this->assertCount(18, IfirmaSchema::newReceiptColumnsFor110());
    }

    public function testUninstallDropsTwoTables()
    {
        $this->assertTrue(include dirname(__DIR__, 2) . '/sql/uninstall.php');

        $drops = array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'DROP TABLE') === 0;
        });
        $this->assertCount(2, $drops);
    }

    private function sorted(array $a)
    {
        sort($a);

        return $a;
    }
}
