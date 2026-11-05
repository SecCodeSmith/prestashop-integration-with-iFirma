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

    private function addColumnStatements()
    {
        return array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0 && strpos($q, 'ADD COLUMN') !== false;
        }));
    }

    private function columnProbes()
    {
        return array_values(array_filter(Db::getInstance()->queries, function ($q) {
            return strpos($q, 'information_schema.COLUMNS') !== false;
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

    public function testWidenReadsTheIdentifierLengthFromTheCurrentDatabase()
    {
        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', 64);

        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());

        $this->assertSame(array(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'ps_ifirma_contractor_map' AND COLUMN_NAME = 'ifirma_identifier'",
        ), $this->columnProbes());
    }

    public function identifierLengthProvider()
    {
        return array(
            'the documented 15 characters' => array(15, true),
            'a string from the driver, one short' => array('63', true),
            'exactly the target length' => array('64', false),
            'one wider than the target' => array('65', false),
            'much wider, never narrowed' => array(255, false),
        );
    }

    /**
     * @dataProvider identifierLengthProvider
     */
    public function testWidenAltersOnlyAColumnNarrowerThanTheTargetLength($length, $altered)
    {
        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', $length);

        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());

        $this->assertCount($altered ? 1 : 0, $this->modifyStatements());
    }

    public function untrustedLengthProvider()
    {
        return array(
            'no row' => array(null),
            'empty string' => array(''),
            'not a number' => array('n/a'),
            'zero as text' => array('0'),
            'negative' => array(-1),
        );
    }

    /**
     * @dataProvider untrustedLengthProvider
     */
    public function testWidenSkipsEveryLengthItCannotTrust($length)
    {
        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', $length);

        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());

        $this->assertSame(array(), $this->modifyStatements());
    }

    public function testWidenReportsAFailedAlter()
    {
        Db::getInstance()->whenGetValue('/CHARACTER_MAXIMUM_LENGTH/', 15);
        Db::getInstance()->whenExecute('/MODIFY `ifirma_identifier`/', false);

        $this->assertFalse(IfirmaSchema::widenContractorMapIdentifier());

        $this->assertCount(1, $this->modifyStatements());
    }

    public function testContractorMapDeclaresTheIdentifierColumnAsVarchar64()
    {
        $columns = IfirmaSchema::contractorMapColumns();

        $this->assertSame('VARCHAR(64) NOT NULL', $columns['ifirma_identifier']);
        $this->assertStringContainsString(
            '`ifirma_identifier` VARCHAR(64) NOT NULL',
            IfirmaSchema::contractorMapCreateSql()
        );
    }

    public function testContractorIdentifierLengthIs64()
    {
        $this->assertSame(64, IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH);
    }

    public function columnProbeResultProvider()
    {
        return array(
            'one match, as the driver returns it' => array('1', true),
            'one match, as an int' => array(1, true),
            'no match' => array('0', false),
            'no match, as an int' => array(0, false),
            'query failed' => array(false, false),
            'no row' => array(null, false),
        );
    }

    /**
     * @dataProvider columnProbeResultProvider
     */
    public function testColumnExistsReadsTheNumberOfMatchingColumns($count, $expected)
    {
        Db::getInstance()->whenGetValue('/information_schema/', $count);

        $this->assertSame($expected, IfirmaSchema::columnExists('ps_ifirma_receipt', 'source'));
    }

    public function testColumnExistsAsksInformationSchemaAboutTheNamedColumn()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'wanted'/", 1);

        $this->assertTrue(IfirmaSchema::columnExists('ps_demo', 'wanted'));
        $this->assertFalse(IfirmaSchema::columnExists('ps_demo', 'other'));

        $this->assertSame(array(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'ps_demo' AND COLUMN_NAME = 'wanted'",
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'ps_demo' AND COLUMN_NAME = 'other'",
        ), $this->columnProbes());
    }

    public function testColumnExistsEscapesTheTableAndColumnNames()
    {
        IfirmaSchema::columnExists("ps_a'b", "c'd");

        $this->assertSame(array(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'ps_a\\'b' AND COLUMN_NAME = 'c\\'d'",
        ), $this->columnProbes());
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

    public function testEnsureReceiptKsefColumnsProbesEachColumnOfTheReceiptTable()
    {
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());

        $this->assertSame(array(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'ps_ifirma_receipt' AND COLUMN_NAME = 'ksef_sent_at'",
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'ps_ifirma_receipt' AND COLUMN_NAME = 'ksef_error'",
        ), $this->columnProbes());
    }

    public function oneKsefColumnPresentProvider()
    {
        return array(
            'ksef_sent_at is there, ksef_error is missing' => array(
                'ksef_sent_at',
                'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_error` TEXT NULL',
            ),
            'ksef_error is there, ksef_sent_at is missing' => array(
                'ksef_error',
                'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_sent_at` DATETIME NULL',
            ),
        );
    }

    /**
     * @dataProvider oneKsefColumnPresentProvider
     */
    public function testEnsureReceiptKsefColumnsAddsOnlyTheMissingColumn($present, $expectedAlter)
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = '" . $present . "'/", '1');

        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());

        $this->assertSame(array($expectedAlter), $this->addColumnStatements());
    }

    public function failingKsefColumnProvider()
    {
        return array(
            'ksef_sent_at fails' => array('ksef_sent_at'),
            'ksef_error fails' => array('ksef_error'),
        );
    }

    /**
     * @dataProvider failingKsefColumnProvider
     */
    public function testEnsureReceiptKsefColumnsReportsAFailedAlterAndStillTriesTheOtherColumn($failing)
    {
        Db::getInstance()->whenExecute('/ADD COLUMN `' . $failing . '`/', false);

        $this->assertFalse(IfirmaSchema::ensureReceiptKsefColumns());

        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_sent_at` DATETIME NULL',
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_error` TEXT NULL',
        ), $this->addColumnStatements());
    }

    public function testEnsureReceiptKsefColumnsDoesNotRememberAFailure()
    {
        $db = Db::getInstance();
        $db->whenExecute('/ADD COLUMN `ksef_sent_at`/', false);
        $this->assertFalse(IfirmaSchema::ensureReceiptKsefColumns());
        $this->assertCount(2, $this->addColumnStatements());

        // ksef_error went in on the first run, so the retry adds only the column that failed
        $db->whenGetValue("/COLUMN_NAME = 'ksef_error'/", 1);
        $db->whenExecute('/ADD COLUMN `ksef_sent_at`/', true);
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());
        $this->assertCount(3, $this->addColumnStatements());

        // only a success is remembered for the rest of the request
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());
        $this->assertCount(3, $this->addColumnStatements());
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
