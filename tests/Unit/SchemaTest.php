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
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorMap.php';
    }

    public function testInstallCreatesThreeTables()
    {
        $result = include dirname(__DIR__, 2) . '/sql/install.php';
        $this->assertTrue($result);

        $creates = array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'CREATE TABLE') === 0;
        }));
        $this->assertCount(3, $creates);
        $this->assertCount(3, Db::getInstance()->executed);

        foreach (array_keys(IfirmaSchema::receiptColumns()) as $col) {
            $this->assertStringContainsString('`' . $col . '`', $creates[0]);
        }
        foreach (array_keys(IfirmaSchema::dataRequestColumns()) as $col) {
            $this->assertStringContainsString('`' . $col . '`', $creates[1]);
        }
        foreach (array_keys(IfirmaSchema::contractorMapColumns()) as $col) {
            $this->assertStringContainsString('`' . $col . '`', $creates[2]);
        }
        $this->assertStringContainsString('UNIQUE KEY', $creates[2]);
        $this->assertStringContainsString('ps_ifirma_contractor_map', $creates[2]);
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
        $this->assertSame(
            $this->sorted(array_keys(IfirmaSchema::contractorMapColumns())),
            $this->sorted(array_merge(array_keys(IfirmaContractorMap::$definition['fields']), array(IfirmaContractorMap::$definition['primary'])))
        );
        $this->assertCount(20, IfirmaSchema::newReceiptColumnsFor110());
    }

    public function testUninstallDropsThreeTables()
    {
        $this->assertTrue(include dirname(__DIR__, 2) . '/sql/uninstall.php');

        $drops = array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'DROP TABLE') === 0;
        });
        $this->assertCount(3, $drops);
    }

    private function modifyStatements()
    {
        return array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0 && strpos($q, 'MODIFY') !== false;
        }));
    }

    public function testWidenAltersAContractorMapIdentifierNarrowerThan64()
    {
        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', 15);

        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());

        $this->assertSame(
            array('ALTER TABLE `ps_ifirma_contractor_map` MODIFY `ifirma_identifier` VARCHAR(64) NOT NULL'),
            $this->modifyStatements()
        );
    }

    public function testWidenLeavesAlreadyWideContractorMapIdentifierAlone()
    {
        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', 64);

        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());

        $this->assertSame(array(), $this->modifyStatements());
    }

    public function testWidenSkipsAnUnreadableLength()
    {
        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', false);
        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());

        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', 0);
        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());

        $this->assertSame(array(), $this->modifyStatements());
    }

    public function testKsefColumnsAreDefinedAndDefaultModeIsB2b()
    {
        $columns = IfirmaSchema::receiptColumns();
        $this->assertArrayHasKey('ksef_sent_at', $columns);
        $this->assertArrayHasKey('ksef_error', $columns);
        $this->assertArrayHasKey('ksef_sent_at', IfirmaReceipt::$definition['fields']);
        $this->assertArrayHasKey('ksef_error', IfirmaReceipt::$definition['fields']);
        $this->assertSame('b2b', IfirmaSchema::configurationDefaults()['IFIRMA_KSEF_MODE']);
    }

    public function testEnsureReceiptKsefColumnsAddsBothColumnsOnce()
    {
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());

        $alters = array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0 && strpos($q, 'ADD COLUMN') !== false;
        }));
        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_sent_at` DATETIME NULL',
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_error` TEXT NULL',
        ), $alters);
    }

    public function testEnsureReceiptKsefColumnsSkipsExistingColumns()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'ksef_sent_at'/", 1);
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'ksef_error'/", 1);

        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());

        $alters = array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0;
        });
        $this->assertSame(array(), array_values($alters));
    }

    public function testSignatureTypeDefaultIsBpo()
    {
        $this->assertSame('BPO', IfirmaSchema::configurationDefaults()['IFIRMA_SIGNATURE_TYPE']);
    }

    public function testDeliveryCopyDefaults()
    {
        $defaults = IfirmaSchema::configurationDefaults();
        $this->assertSame('none', $defaults['IFIRMA_SEND_COPY_MODE']);
        $this->assertSame(0, $defaults['IFIRMA_SEND_COPY_EMPLOYEE']);
    }

    private function sorted(array $a)
    {
        sort($a);

        return $a;
    }
}
