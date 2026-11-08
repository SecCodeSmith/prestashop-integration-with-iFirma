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

    public function testInstallCreatesFourTables()
    {
        $result = include dirname(__DIR__, 2) . '/sql/install.php';
        $this->assertTrue($result);

        $creates = array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'CREATE TABLE') === 0;
        }));
        $this->assertCount(4, $creates);
        $this->assertCount(4, Db::getInstance()->executed);

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
        $this->assertStringContainsString('ps_ifirma_guest_code', $creates[3]);
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

    public function testUninstallDropsFourTables()
    {
        $this->assertTrue(include dirname(__DIR__, 2) . '/sql/uninstall.php');

        $drops = array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'DROP TABLE') === 0;
        });
        $this->assertCount(4, $drops);
        $this->assertContains('DROP TABLE IF EXISTS `ps_ifirma_guest_code`;', $drops);
    }

    public function testGuestCodeCreateSqlDeclaresEveryColumnAndBothKeys()
    {
        $sql = IfirmaSchema::guestCodeCreateSql();

        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ifirma_guest_code` (', $sql);
        $this->assertSame(array(
            'id_ifirma_guest_code', 'id_order', 'code_hash', 'salt', 'attempts', 'ip_hash', 'date_add',
            'expires_at', 'used_at', 'invalidated',
        ), array_keys(IfirmaSchema::guestCodeColumns()));
        foreach (array_keys(IfirmaSchema::guestCodeColumns()) as $column) {
            $this->assertStringContainsString('`' . $column . '`', $sql);
        }
        $this->assertStringContainsString('`code_hash` CHAR(64) NOT NULL', $sql);
        $this->assertStringContainsString('`salt` CHAR(32) NOT NULL', $sql);
        $this->assertStringContainsString('`used_at` DATETIME NULL', $sql);
        $this->assertStringContainsString('PRIMARY KEY (`id_ifirma_guest_code`)', $sql);
        $this->assertStringContainsString('KEY `idx_order` (`id_order`)', $sql);
        $this->assertStringContainsString('KEY `idx_ip_date` (`ip_hash`, `date_add`)', $sql);
    }

    public function testEnsureGuestCodeTableCreatesOnceAndIsIdempotent()
    {
        IfirmaSchema::resetEnsureCache();

        $this->assertTrue(IfirmaSchema::ensureGuestCodeTable());
        $this->assertTrue(IfirmaSchema::ensureGuestCodeTable());

        $this->assertSame(array(IfirmaSchema::guestCodeCreateSql()), Db::getInstance()->executed);
    }

    public function testEnsureGuestCodeTableReportsAFailureAndTriesAgain()
    {
        IfirmaSchema::resetEnsureCache();
        Db::getInstance()->whenExecute('/CREATE TABLE IF NOT EXISTS `ps_ifirma_guest_code`/', false);

        $this->assertFalse(IfirmaSchema::ensureGuestCodeTable());
        $this->assertFalse(IfirmaSchema::ensureGuestCodeTable());

        $this->assertCount(2, Db::getInstance()->executed);
    }

    public function testTheGuestCodeTableAndItsDefaultsAreRemovedOnUninstall()
    {
        $defaults = IfirmaSchema::configurationDefaults();
        $this->assertSame('none', $defaults['IFIRMA_DATA_REQUEST_AUTO_ACCEPT']);
        $this->assertSame('', $defaults['IFIRMA_DOC_TEMPLATE']);
        $this->assertSame('PS-ORDER', $defaults['IFIRMA_ORDER_STAMP_PREFIX']);
        $this->assertSame('', $defaults['IFIRMA_SHOP_URL']);
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

    public function testEveryContractorIdentifierLimitIsTheSchemaLength()
    {
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorImport.php';
        require_once dirname(__DIR__, 2) . '/classes/IfirmaCustomerContractorService.php';

        $length = IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH;

        $this->assertSame($length, IfirmaContractorMap::$definition['fields']['ifirma_identifier']['size']);
        $this->assertSame($length, IfirmaContractorImport::MAX_IDENTIFIER);
        $this->assertSame($length, IfirmaCustomerContractorService::IDENTIFIER_MAX);
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

    public function testReceiptCreateSqlCarriesTheDownloadColumnAndTheDateIndex()
    {
        $sql = IfirmaSchema::receiptCreateSql();

        $this->assertStringContainsString("    `last_download_at` DATETIME NULL,\n    PRIMARY KEY", $sql);
        $this->assertStringContainsString("    KEY `idx_date_upd` (`date_upd`)\n) ENGINE=", $sql);
        $this->assertStringContainsString("    KEY `idx_token` (`security_token`),\n    KEY `idx_date_upd`", $sql);
    }

    public function testReceiptDownloadColumnIsAddedToTheFreshSchemaOnly()
    {
        $this->assertSame(array('last_download_at' => 'DATETIME NULL'), IfirmaSchema::newReceiptColumnsFor150());
        $this->assertArrayHasKey('last_download_at', IfirmaSchema::receiptColumns());
        $this->assertArrayNotHasKey('last_download_at', IfirmaSchema::newReceiptColumnsFor110());
    }

    public function testIndexExistsAsksInformationSchemaAboutTheNamedIndex()
    {
        Db::getInstance()->whenGetValue("/INDEX_NAME = 'idx_wanted'/", 1);

        $this->assertTrue(IfirmaSchema::indexExists('ps_demo', 'idx_wanted'));
        $this->assertSame(array(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = 'ps_demo' AND INDEX_NAME = 'idx_wanted'",
        ), $this->indexProbes());
    }

    public function testIndexExistsReadsZeroAsMissing()
    {
        Db::getInstance()->whenGetValue('/information_schema\.STATISTICS/', '0');

        $this->assertFalse(IfirmaSchema::indexExists('ps_demo', 'idx_wanted'));
    }

    public function testEnsureReceiptDownloadColumnAddsTheColumnOnce()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'last_download_at'/", '0');

        $this->assertTrue(IfirmaSchema::ensureReceiptDownloadColumn());
        $this->assertTrue(IfirmaSchema::ensureReceiptDownloadColumn());

        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `last_download_at` DATETIME NULL',
        ), $this->addColumnStatements());
    }

    public function testEnsureReceiptDownloadColumnSkipsAnExistingColumn()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'last_download_at'/", '1');

        $this->assertTrue(IfirmaSchema::ensureReceiptDownloadColumn());
        $this->assertSame(array(), $this->addColumnStatements());
    }

    public function testEnsureReceiptDownloadColumnDoesNotRememberAFailure()
    {
        $db = Db::getInstance();
        $db->whenGetValue("/COLUMN_NAME = 'last_download_at'/", '0');
        $db->whenExecute('/ADD COLUMN `last_download_at`/', false);

        $this->assertFalse(IfirmaSchema::ensureReceiptDownloadColumn());

        $db->whenExecute('/ADD COLUMN `last_download_at`/', true);
        $this->assertTrue(IfirmaSchema::ensureReceiptDownloadColumn());
        $this->assertCount(2, $this->addColumnStatements());

        $this->assertTrue(IfirmaSchema::ensureReceiptDownloadColumn());
        $this->assertCount(2, $this->addColumnStatements());
    }

    public function testEnsureDataRequestAutoModeColumnAddsTheColumnOnce()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'auto_mode'/", '0');

        $this->assertTrue(IfirmaSchema::ensureDataRequestAutoModeColumn());
        $this->assertTrue(IfirmaSchema::ensureDataRequestAutoModeColumn());

        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_data_request` ADD COLUMN `auto_mode` VARCHAR(8) NULL',
        ), $this->addColumnStatements());
    }

    public function testEnsureDataRequestAutoModeColumnSkipsAnExistingColumn()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'auto_mode'/", '1');

        $this->assertTrue(IfirmaSchema::ensureDataRequestAutoModeColumn());
        $this->assertSame(array(), $this->addColumnStatements());
    }

    public function testEnsureReceiptDateIndexAddsTheIndexOnce()
    {
        Db::getInstance()->whenGetValue("/INDEX_NAME = 'idx_date_upd'/", '0');

        $this->assertTrue(IfirmaSchema::ensureReceiptDateIndex());
        $this->assertTrue(IfirmaSchema::ensureReceiptDateIndex());

        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD KEY `idx_date_upd` (`date_upd`)',
        ), $this->addKeyStatements());
    }

    public function testEnsureReceiptDateIndexSkipsAnExistingIndex()
    {
        Db::getInstance()->whenGetValue("/INDEX_NAME = 'idx_date_upd'/", '1');

        $this->assertTrue(IfirmaSchema::ensureReceiptDateIndex());
        $this->assertSame(array(), $this->addKeyStatements());
    }

    public function testEnsureReceiptDateIndexDoesNotRememberAFailure()
    {
        $db = Db::getInstance();
        $db->whenGetValue("/INDEX_NAME = 'idx_date_upd'/", '0');
        $db->whenExecute('/ADD KEY `idx_date_upd`/', false);

        $this->assertFalse(IfirmaSchema::ensureReceiptDateIndex());

        $db->whenExecute('/ADD KEY `idx_date_upd`/', true);
        $this->assertTrue(IfirmaSchema::ensureReceiptDateIndex());
        $this->assertCount(2, $this->addKeyStatements());

        $this->assertTrue(IfirmaSchema::ensureReceiptDateIndex());
        $this->assertCount(2, $this->addKeyStatements());
    }

    private function addKeyStatements()
    {
        return array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0 && strpos($q, 'ADD KEY') !== false;
        }));
    }

    private function indexProbes()
    {
        return array_values(array_filter(Db::getInstance()->queries, function ($q) {
            return strpos($q, 'information_schema.STATISTICS') !== false;
        }));
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

    // ---- the cron token (admin-receipt-ops-and-hardening 8.3) ----------------------------------

    public function testTheCronTokenIs32HexCharactersFromTheSecureGenerator()
    {
        $token = IfirmaSchema::generateCronToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertNotSame($token, IfirmaSchema::generateCronToken());
    }

    public function testTheCronTokenIsMadeOfTheBytesTheSeamReturns()
    {
        $asked = array();
        IfirmaSchema::$randomBytes = function ($length) use (&$asked) {
            $asked[] = $length;

            return str_repeat("\x01\xab", 8);
        };
        try {
            $token = IfirmaSchema::generateCronToken();
        } finally {
            IfirmaSchema::$randomBytes = null;
        }

        $this->assertSame(str_repeat('01ab', 8), $token);
        $this->assertSame(array(16), $asked);
    }

    public function provideGeneratorFailures()
    {
        return array(
            'the generator throws' => array(function () {
                throw new Exception('no entropy');
            }),
            'the generator fails hard' => array(function () {
                throw new Error('gone');
            }),
            'the generator returns nothing' => array(function () {
                return '';
            }),
            'the generator returns too little' => array(function () {
                return 'abc';
            }),
            'the generator returns false' => array(function () {
                return false;
            }),
        );
    }

    /**
     * @dataProvider provideGeneratorFailures
     */
    public function testGeneratorUnavailableThrowsInsteadOfFallingBackToAWeakToken($seam)
    {
        IfirmaSchema::$randomBytes = $seam;
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('The secure random generator is unavailable');
            IfirmaSchema::generateCronToken();
        } finally {
            IfirmaSchema::$randomBytes = null;
        }
    }
}
