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

/** Stands in for the module where upgrade-1.5.0 must create the missing tab: records the call. */
class UpgradeTestModuleWithTab extends Module
{
    /** @var int */
    public $tabsInstalled = 0;

    public function installVisibleTab()
    {
        $this->tabsInstalled++;

        return true;
    }
}

class UpgradeTest extends TestCase
{
    use IfirmaSentQueries;

    const RECEIPT = 'ps_ifirma_receipt';
    const DATA_REQUEST = 'ps_ifirma_data_request';
    const CONTRACTOR_MAP = 'ps_ifirma_contractor_map';
    const GUEST_CODE = 'ps_ifirma_guest_code';
    const PDF_COPY = 'ps_ifirma_pdf_copy';

    /** @var array table => (column => DDL fragment): the database the install and upgrade paths run on */
    private $schema = array();

    /** @var array table => (index name => true): the indexes of the simulated database */
    private $indexes = array();

    /** @var bool when set, the simulated database refuses every ADD KEY, as MySQL would for a failed ALTER */
    private $refuseKeyAlters = false;

    /** @var array the KSeF receipt columns every shop needs, with their DDL */
    private static $ksefColumns = array(
        'ksef_status' => "VARCHAR(32) NOT NULL DEFAULT ''",
        'ksef_number' => "VARCHAR(64) NOT NULL DEFAULT ''",
        'ksef_sent_at' => 'DATETIME NULL',
        'ksef_error' => 'TEXT NULL',
    );

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.1.0.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.2.0.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.4.0.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.5.0.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.6.0.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.8.0.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.10.0.php';
    }

    protected function tearDown(): void
    {
        IfirmaSchema::resetEnsureCache();
    }

    private function scriptColumnProbe()
    {
        $db = Db::getInstance();
        $db->whenGetValue('/information_schema/', function ($sql) use ($db) {
            preg_match("/COLUMN_NAME = '([^']+)'/", $sql, $m);
            foreach ($db->executed as $q) {
                if (strpos($q, 'ADD COLUMN `' . $m[1] . '`') !== false) {
                    return 1;
                }
            }

            return 0;
        });
    }

    private function alters()
    {
        return array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0 && strpos($q, 'ADD COLUMN') !== false;
        }));
    }

    public function testUpgradeIsIdempotent()
    {
        $this->scriptColumnProbe();

        $this->assertTrue(upgrade_module_1_1_0(new Module()));
        $this->assertCount(20, $this->alters());

        $creates = array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'CREATE TABLE IF NOT EXISTS `ps_ifirma_data_request`') === 0;
        });
        $this->assertCount(1, $creates);
        $maps = array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'CREATE TABLE IF NOT EXISTS `ps_ifirma_contractor_map`') === 0;
        });
        $this->assertCount(1, $maps);

        $this->assertTrue(upgrade_module_1_1_0(new Module()));
        $this->assertCount(20, $this->alters());
    }

    public function testUpgradeProbesEveryReceiptColumnWithOneInformationSchemaQuery()
    {
        $this->scriptColumnProbe();

        $this->assertTrue(upgrade_module_1_1_0(new Module()));

        // the 1.1.0 columns first, then the two KSeF columns again through ensureReceiptKsefColumns()
        $columns = array_merge(array_keys(IfirmaSchema::newReceiptColumnsFor110()), array('ksef_sent_at', 'ksef_error'));
        $expected = array();
        foreach ($columns as $column) {
            $expected[] = "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
                . " AND TABLE_NAME = 'ps_ifirma_receipt' AND COLUMN_NAME = '" . $column . "'";
        }
        $probes = array_values(array_filter(Db::getInstance()->queries, function ($q) {
            return strpos($q, 'SELECT COUNT(*) FROM information_schema.COLUMNS') === 0;
        }));
        $this->assertSame($expected, $probes);
    }

    public function testUpgradeAddsOnlyTheColumnsThatAreMissing()
    {
        $present = array('source', 'ksef_sent_at', 'ksef_error');
        foreach ($present as $column) {
            Db::getInstance()->whenGetValue("/COLUMN_NAME = '" . $column . "'/", '1');
        }

        $this->assertTrue(upgrade_module_1_1_0(new Module()));

        $added = array();
        foreach ($this->alters() as $alter) {
            preg_match('/ADD COLUMN `([^`]+)`/', $alter, $m);
            $added[] = $m[1];
        }
        $this->assertSame(
            array_values(array_diff(array_keys(IfirmaSchema::newReceiptColumnsFor110()), $present)),
            $added
        );
        $this->assertCount(17, $added);
    }

    public function testConfigurationNeverOverwritten()
    {
        $this->scriptColumnProbe();
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');

        upgrade_module_1_1_0(new Module());

        $this->assertSame('none', Configuration::get('IFIRMA_SEND_CHANNEL'));
        $this->assertSame('60', (string) Configuration::get('IFIRMA_DUPLICATE_WINDOW_DAYS'));
        $this->assertSame('0.01', (string) Configuration::get('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Configuration::get('IFIRMA_CRON_TOKEN'));

        $token = Configuration::get('IFIRMA_CRON_TOKEN');
        upgrade_module_1_1_0(new Module());
        $this->assertSame($token, Configuration::get('IFIRMA_CRON_TOKEN'));
    }

    public function testPanelShownAfterUpgrade()
    {
        $module = new Module();
        $this->assertFalse($module->isRegisteredInHook('displayAdminCustomers'));

        $this->assertTrue(upgrade_module_1_2_0($module));
        $this->assertTrue(upgrade_module_1_2_0($module));

        $this->assertTrue($module->isRegisteredInHook('displayAdminCustomers'));
        $this->assertSame(array('displayAdminCustomers'), Module::$hooks);
    }

    public function testLegacyOrderHookRemovedOnUpgrade()
    {
        $module = new Module();
        $module->registerHook('displayAdminOrder');
        $module->registerHook('displayAdminOrderMainBottom');

        $this->assertTrue(upgrade_module_1_2_0($module));
        $this->assertTrue(upgrade_module_1_2_0($module));

        $this->assertFalse($module->isRegisteredInHook('displayAdminOrder'));
        $this->assertSame(array('displayAdminOrderMainBottom', 'displayAdminCustomers'), Module::$hooks);
    }

    public function testUpgrade140RegistersTheGridHooksOnce()
    {
        $module = new Module();
        $module->registerHook('displayAdminOrderMainBottom');
        $module->registerHook('displayAdminCustomers');
        $gridHooks = array(
            'actionOrderGridDefinitionModifier',
            'actionOrderGridQueryBuilderModifier',
            'actionOrderGridPresenterModifier',
        );
        foreach ($gridHooks as $hook) {
            $this->assertFalse($module->isRegisteredInHook($hook));
        }

        $this->assertTrue(upgrade_module_1_4_0($module));
        $this->assertTrue(upgrade_module_1_4_0($module));

        foreach ($gridHooks as $hook) {
            $this->assertTrue($module->isRegisteredInHook($hook));
        }
        $this->assertSame(array(
            'displayAdminOrderMainBottom',
            'displayAdminCustomers',
            'actionOrderGridDefinitionModifier',
            'actionOrderGridQueryBuilderModifier',
            'actionOrderGridPresenterModifier',
        ), Module::$hooks);
    }

    public function testUpgrade140LeavesAnAlreadyRegisteredGridHookAlone()
    {
        $module = new Module();
        $module->registerHook('actionOrderGridQueryBuilderModifier');

        $this->assertTrue(upgrade_module_1_4_0($module));

        $this->assertSame(array(
            'actionOrderGridQueryBuilderModifier',
            'actionOrderGridDefinitionModifier',
            'actionOrderGridPresenterModifier',
        ), Module::$hooks);
    }

    // ---- the schema each path ends with (refactor-srp-splits 9.1) ---------------------------------
    //
    // PrestaShop runs an upgrade file only when the version recorded for the shop is below the file's
    // version and the file's version is not above the module's (1.5.0), oldest file first. So:
    //   fresh install of 1.5.0  -> sql/install.php, through Ifirma::install()
    //   1.0.x                   -> upgrade-1.1.0.php, then upgrade-1.2.0.php, then upgrade-1.4.0.php, then upgrade-1.5.0.php
    //   1.1.0                   -> upgrade-1.2.0.php, then upgrade-1.4.0.php, then upgrade-1.5.0.php
    //                              (1.2.0 changes hooks, not the schema)
    //   1.2.0 .. 1.4.1 -> upgrade-1.4.0.php, then upgrade-1.5.0.php (1.4.0 registers hooks; 1.5.0 adds the
    //                     download column and the date index and moves the tab under the Orders menu)
    // The 1.1.0 build grew in place under one version number: the first build had no contractor map table,
    // the table came with 4452d02 and the KSeF sent/error receipt columns with 882f929. A shop recorded as
    // 1.1.0 by an earlier build never runs upgrade-1.1.0.php again.
    //
    // The tests that prove a path run with the runtime ensure*() checks switched off (startPath()), so the
    // schema they end with is the work of the install or upgrade code alone. The tests about what the upgrade
    // to 1.2.0 leaves behind, and about what the runtime checks add, leave the checks on (startFrom()).
    // The Db stub plays a database: it applies the CREATE TABLE and ALTER TABLE statements it receives and
    // answers the information_schema probes from the result.

    /**
     * Incomplete schemas for the 1.1.0 tests, none of them complete. The first two are the states that the earlier
     * 1.1.0 builds left (see the notes above); the third is hypothetical, no build left it (see
     * schemaWithANarrowContractorMap()).
     *
     * @return array description => array(name of the method that returns the schema)
     */
    public function incomplete110Schemas()
    {
        return array(
            'the first 1.1.0 build, before the contractor map' => array('schemaOfTheFirst110Build'),
            'a 1.1.0 build with the contractor map, before the KSeF sent and error columns' => array('schemaOfTheBuildWithTheContractorMap'),
            'a hypothetical contractor map whose identifier column is still 15 characters wide' => array('schemaWithANarrowContractorMap'),
        );
    }

    public function testAFreshInstallCreatesTheContractorMapAndTheKsefColumnsWithoutTheRuntimeChecks()
    {
        $this->startPath(array());

        $this->assertTrue(IfirmaTestModuleLoader::loadModule()->install());

        $this->assertEndsWithTheContractorMapAndTheKsefColumns();
        $this->assertSame($this->expectedSchema(), $this->schema);
        // install() sends the five CREATE statements and nothing else: no ALTER, no probe
        $sent = $this->sentQueries();
        $this->assertCount(5, $sent);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ifirma_pdf_copy` (', $sent[4]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ifirma_receipt` (', $sent[0]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ifirma_data_request` (', $sent[1]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ifirma_contractor_map` (', $sent[2]);
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ifirma_guest_code` (', $sent[3]);
        $this->assertStringContainsString('`ksef_sent_at` DATETIME NULL', $sent[0]);
        $this->assertStringContainsString('`ksef_error` TEXT NULL', $sent[0]);
        $this->assertStringContainsString('`last_download_at` DATETIME NULL', $sent[0]);
        $this->assertStringContainsString('KEY `idx_date_upd` (`date_upd`)', $sent[0]);
        $this->assertTrue($this->indexes[self::RECEIPT]['idx_date_upd']);
        $this->assertStringContainsString('`ifirma_identifier` VARCHAR(64) NOT NULL', $sent[2]);
    }

    public function testTheUpgradeFrom10xCreatesTheContractorMapAndTheKsefColumnsWithoutTheRuntimeChecks()
    {
        $this->startPath($this->schemaOf10x());
        $this->seedHiddenTabAndOrdersMenu();
        $this->assertSame(array(self::RECEIPT), array_keys($this->schema));
        $this->assertSame(array(
            'id_ifirma_receipt', 'id_order', 'id_customer', 'id_shop', 'document_type', 'ifirma_id', 'ifirma_number',
            'status', 'paid_amount', 'security_token', 'request_payload', 'response_payload', 'last_error', 'date_add',
            'date_upd',
        ), array_keys($this->schema[self::RECEIPT]), 'the 15 receipt columns sql/install.php of 1.0.0 creates');
        $this->assertArrayNotHasKey('ksef_sent_at', $this->schema[self::RECEIPT]);
        $this->assertArrayNotHasKey('ksef_error', $this->schema[self::RECEIPT]);

        $module = new Module();
        $this->assertTrue(upgrade_module_1_1_0($module));
        $this->assertTrue(upgrade_module_1_2_0($module));
        $this->assertTrue(upgrade_module_1_4_0($module));
        $this->assertTrue(upgrade_module_1_5_0($module));
        $this->assertTrue(upgrade_module_1_6_0($module));
        $this->assertTrue(upgrade_module_1_10_0($module));

        $this->assertEndsWithTheContractorMapAndTheKsefColumns();
        $sent = $this->sentQueries();
        $alters = array_values(array_filter($sent, function ($sql) {
            return strpos($sql, 'ALTER TABLE') === 0 && strpos($sql, 'ADD COLUMN') !== false;
        }));
        $this->assertCount(26, $alters, 'the 20 receipt columns of 1.1.0, last_download_at of 1.5.0 and the two retry, the two download count and the sync attempt column of 1.10.0, nothing else is added');
        $this->assertContains('ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `last_download_at` DATETIME NULL', $alters);
        $keys = array_values(array_filter($sent, function ($sql) {
            return strpos($sql, 'ALTER TABLE') === 0 && strpos($sql, 'ADD KEY') !== false;
        }));
        $this->assertSame(array('ALTER TABLE `ps_ifirma_receipt` ADD KEY `idx_date_upd` (`date_upd`)'), $keys);
        $this->assertTrue($this->indexes[self::RECEIPT]['idx_date_upd']);
        $this->assertContains('ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_sent_at` DATETIME NULL', $alters);
        $this->assertContains('ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `ksef_error` TEXT NULL', $alters);
        $maps = array_values(array_filter($sent, function ($sql) {
            return strpos($sql, 'CREATE TABLE IF NOT EXISTS `ps_ifirma_contractor_map`') === 0;
        }));
        $this->assertCount(1, $maps);
        $this->assertStringContainsString('`ifirma_identifier` VARCHAR(64) NOT NULL', $maps[0]);
    }

    /**
     * Not a path a shop takes (PrestaShop does not run the 1.1.0 file for a shop recorded as 1.1.0): it shows
     * what a script that ran these steps again would reach from each earlier schema.
     *
     * @dataProvider incomplete110Schemas
     */
    public function testTheUpgradeTo110CompletesEveryIncomplete110SchemaWithoutTheRuntimeChecks($incomplete)
    {
        $this->startPath($this->$incomplete());

        $this->assertTrue(upgrade_module_1_1_0(new Module()));

        $this->assertEndsWithTheContractorMapAndTheKsefColumns();
    }

    /**
     * The 1.2.0 script changes hooks and nothing else: a shop whose 1.1.0 was complete stays complete, and
     * (next test) a shop whose 1.1.0 was not stays as it is. The runtime checks stay on in this test and in
     * the next two, so that a script which completes a shop through them is seen as well.
     */
    public function testTheUpgradeTo120SendsNoSchemaStatement()
    {
        $this->startFrom($this->expectedSchema());

        $this->assertTrue(upgrade_module_1_2_0(new Module()));

        $this->assertSame(array(), $this->sentQueries());
        $this->assertSame($this->expectedSchema(), $this->schema);
    }

    /**
     * What the previous test means for the incomplete 1.1.0 schemas: the upgrade to 1.2.0 (and from there to
     * 1.4.0) leaves each as it found it. The first two are what a shop that stopped at an earlier 1.1.0 build
     * has; the third is hypothetical. If this fails, an upgrade script now completes these shops: re-read task
     * 9.2 of refactor-srp-splits, the runtime checks may then go.
     *
     * @dataProvider incomplete110Schemas
     */
    public function testTheUpgradeTo120LeavesAnIncomplete110SchemaAsItIs($incomplete)
    {
        $start = $this->$incomplete();
        $this->startFrom($start);

        $this->assertTrue(upgrade_module_1_2_0(new Module()));

        $this->assertSame($start, $this->schema);
        $this->assertNotSame($this->sortedSchema($this->expectedSchema()), $this->sortedSchema($this->schema));
    }

    /**
     * What the runtime checks still add: they complete the shops the install and upgrade code never reaches.
     *
     * @dataProvider incomplete110Schemas
     */
    public function testOnlyTheRuntimeChecksCompleteAnIncomplete110Schema($incomplete)
    {
        $this->startFrom($this->$incomplete());
        $this->assertTrue(upgrade_module_1_2_0(new Module()));

        $this->assertTrue(IfirmaSchema::ensureContractorMapTable());
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());

        $this->assertEndsWithTheContractorMapAndTheKsefColumns();
    }

    public function testTheSimulatedDatabaseRefusesWhatMysqlWouldRefuse()
    {
        $start = array('ps_demo' => array('name' => 'VARCHAR(15) NOT NULL'));
        $this->startFrom($start);
        $db = Db::getInstance();

        $this->assertFalse($db->execute('ALTER TABLE `ps_demo` ADD COLUMN `name` TEXT NULL'), 'duplicate column');
        $this->assertFalse($db->execute('ALTER TABLE `ps_demo` MODIFY `other` TEXT NULL'), 'unknown column');
        $this->assertFalse($db->execute('ALTER TABLE `ps_missing` ADD COLUMN `a` TEXT NULL'), 'unknown table');
        $this->assertFalse($db->execute('ALTER TABLE `ps_demo` DROP COLUMN `name`'), 'a statement it does not model');
        $this->assertFalse($db->execute('CREATE TABLE `ps_other` (`a` INT)'), 'a statement it does not model');
        $this->assertSame($start, $this->schema);

        $this->assertTrue($db->execute('ALTER TABLE `ps_demo` MODIFY `name` VARCHAR(64) NOT NULL'));
        $this->assertTrue($db->execute('ALTER TABLE `ps_demo` ADD COLUMN `note` TEXT NULL'));
        $this->assertSame(array('name' => 'VARCHAR(64) NOT NULL', 'note' => 'TEXT NULL'), $this->schema['ps_demo']);
    }

    public function testTheSimulatedDatabaseCreatesATableOnlyOnce()
    {
        $narrow = $this->schemaWithANarrowContractorMap();
        $this->startFrom($narrow);

        $this->assertTrue(Db::getInstance()->execute(IfirmaSchema::contractorMapCreateSql()));
        $this->assertSame($narrow, $this->schema, 'CREATE TABLE IF NOT EXISTS leaves an existing table alone');

        $this->startFrom(array());
        $this->assertTrue(Db::getInstance()->execute(IfirmaSchema::contractorMapCreateSql()));
        $this->assertSame(array(self::CONTRACTOR_MAP => IfirmaSchema::contractorMapColumns()), $this->schema);
    }

    public function testTheSimulatedDatabaseAnswersTheProbesFromTheSchema()
    {
        $this->startFrom(array(self::CONTRACTOR_MAP => array('ifirma_identifier' => 'VARCHAR(15) NOT NULL', 'ifirma_name' => 'TEXT NULL')));

        $this->assertTrue(IfirmaSchema::columnExists(self::CONTRACTOR_MAP, 'ifirma_name'));
        $this->assertFalse(IfirmaSchema::columnExists(self::CONTRACTOR_MAP, 'other'));
        $this->assertFalse(IfirmaSchema::columnExists('ps_missing', 'ifirma_name'));

        // the width is read from the column: a 15 character column is widened, a text column has none to read
        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());
        $this->assertSame('VARCHAR(64) NOT NULL', $this->schema[self::CONTRACTOR_MAP]['ifirma_identifier']);
        $this->startFrom(array(self::CONTRACTOR_MAP => array('ifirma_identifier' => 'TEXT NOT NULL')));
        $this->assertTrue(IfirmaSchema::widenContractorMapIdentifier());
        $this->assertSame('TEXT NOT NULL', $this->schema[self::CONTRACTOR_MAP]['ifirma_identifier']);

        $this->expectException(LogicException::class);
        Db::getInstance()->getValue('SELECT COUNT(*) FROM information_schema.COLUMNS');
    }

    // ---- upgrade-1.5.0.php: the visible tab, the hook, the download column and the date index -------------

    /** What a 1.4.1 shop has: the tab row of 1.4.0 under no menu, no download column, no date index. */
    private function schemaOf141()
    {
        $schema = $this->expectedSchema();
        unset($schema[self::RECEIPT]['last_download_at']);

        return $schema;
    }

    /** The hidden tab row of 1.4.1 (id 140) and the Orders menu it moves under (id 4). */
    private function seedHiddenTabAndOrdersMenu()
    {
        Tab::$rows[140] = array(
            'class_name' => 'AdminIfirma', 'module' => 'ifirma', 'id_parent' => -1,
            'name' => array(1 => 'iFirma', 2 => 'iFirma'), 'position' => 0, 'active' => 1,
        );
        Tab::$rows[4] = array(
            'class_name' => 'AdminParentOrders', 'module' => '', 'id_parent' => 0,
            'name' => array(1 => 'Orders', 2 => 'Zamówienia'), 'position' => 0, 'active' => 1,
        );
    }

    private function alterStatementsOf($sent)
    {
        return array_values(array_filter($sent, function ($sql) {
            return strpos($sql, 'ALTER TABLE') === 0;
        }));
    }

    public function testUpgrade150MovesTheHiddenTabUnderOrdersInPlaceAndIsIdempotent()
    {
        $this->seedHiddenTabAndOrdersMenu();
        Access::grant('ROLE_VIEW', 1);
        Access::grant('ROLE_ADD', 1);
        $grants = Access::$grants;
        $this->startFrom($this->schemaOf141());
        $module = new Module();

        $this->assertTrue(upgrade_module_1_5_0($module));

        $this->assertSame(4, Tab::$rows[140]['id_parent']);
        $this->assertSame(array(1 => 'iFirma documents', 2 => 'Dokumenty iFirma'), Tab::$rows[140]['name'], 'Polish gets the Polish name');
        $this->assertSame(array(), Tab::$deleted, 'the tab row is updated, never deleted');
        $this->assertSame(array(), Tab::$added, 'the tab row is updated, never added again');
        $this->assertSame(array('actionAdminControllerSetMedia'), Module::$hooks);
        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `last_download_at` DATETIME NULL',
            'ALTER TABLE `ps_ifirma_receipt` ADD KEY `idx_date_upd` (`date_upd`)',
        ), $this->alterStatementsOf($this->sentQueries()));
        $this->assertSame('', Configuration::get('IFIRMA_AUTH_FAILURE'));
        $this->assertSame($grants, Access::$grants, 'the permission rows of the tab are not touched');

        // a second request: the checks ask the database again and find everything in place
        IfirmaSchema::resetEnsureCache();
        Db::getInstance()->queries = array();
        Db::getInstance()->executed = array();
        Configuration::$writes = array();

        $this->assertTrue(upgrade_module_1_5_0($module));

        $this->assertSame(array(), $this->alterStatementsOf($this->sentQueries()));
        $this->assertSame(4, Tab::$rows[140]['id_parent']);
        $this->assertSame(array(), Tab::$deleted);
        $this->assertSame(array(), Tab::$added);
        $this->assertSame(array('actionAdminControllerSetMedia'), Module::$hooks);
        $this->assertSame(array(), Configuration::$writes, 'the default is not written again');
        $this->assertSame($grants, Access::$grants);
    }

    public function testUpgrade150KeepsTheTabHiddenAndRegistersNoSecondHookWithoutAnOrdersMenu()
    {
        Tab::$rows[140] = array(
            'class_name' => 'AdminIfirma', 'module' => 'ifirma', 'id_parent' => -1,
            'name' => array(1 => 'iFirma', 2 => 'iFirma'), 'position' => 0, 'active' => 1,
        );
        $module = new Module();
        $module->registerHook('actionAdminControllerSetMedia');
        $this->startFrom($this->schemaOf141());

        $this->assertTrue(upgrade_module_1_5_0($module));

        $this->assertSame(-1, Tab::$rows[140]['id_parent']);
        $this->assertSame('iFirma documents', Tab::$rows[140]['name'][1]);
        $this->assertSame(array('actionAdminControllerSetMedia'), Module::$hooks, 'an already registered hook stays once');
    }

    public function testUpgrade150CreatesTheTabWhenItIsMissing()
    {
        $module = new UpgradeTestModuleWithTab();
        $this->startFrom($this->schemaOf141());

        $this->assertTrue(upgrade_module_1_5_0($module));

        $this->assertSame(1, $module->tabsInstalled);
        $this->assertSame(array(), Tab::$rows, 'the module creates the tab; the upgrade does not write rows itself');
    }

    public function testUpgrade150AddsOnlyWhatIsMissing()
    {
        $this->seedHiddenTabAndOrdersMenu();
        $schema = $this->schemaOf141();
        $schema[self::RECEIPT]['last_download_at'] = 'DATETIME NULL';
        $this->startFrom($schema, array(self::RECEIPT => array('idx_date_upd' => true)));

        $this->assertTrue(upgrade_module_1_5_0(new Module()));

        $this->assertSame(array(), $this->alterStatementsOf($this->sentQueries()));
    }

    public function testUpgrade150ReportsAFailedAlterAndRetriesOnTheNextRun()
    {
        $this->seedHiddenTabAndOrdersMenu();
        $this->startFrom($this->schemaOf141());
        $this->refuseKeyAlters = true;

        $this->assertFalse(upgrade_module_1_5_0(new Module()));

        $this->refuseKeyAlters = false;
        $this->assertTrue(upgrade_module_1_5_0(new Module()));
        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `last_download_at` DATETIME NULL',
            'ALTER TABLE `ps_ifirma_receipt` ADD KEY `idx_date_upd` (`date_upd`)',
            'ALTER TABLE `ps_ifirma_receipt` ADD KEY `idx_date_upd` (`date_upd`)',
        ), $this->alterStatementsOf($this->sentQueries()));
    }

    // ---- upgrade-1.6.0.php: the guest code table and the four settings -------------------------------

    public function testUpgrade160CreatesTheGuestCodeTableAndWritesTheFourDefaults()
    {
        $schema = $this->expectedSchema();
        unset($schema[self::GUEST_CODE]);
        $this->startFrom($schema);

        $this->assertTrue(upgrade_module_1_6_0(new Module()));

        $this->assertSame(IfirmaSchema::guestCodeColumns(), $this->schema[self::GUEST_CODE]);
        $this->assertSame('none', Configuration::get('IFIRMA_DATA_REQUEST_AUTO_ACCEPT'));
        $this->assertSame('', Configuration::get('IFIRMA_DOC_TEMPLATE'));
        $this->assertSame('PS-ORDER', Configuration::get('IFIRMA_ORDER_STAMP_PREFIX'));
        $this->assertSame('', Configuration::get('IFIRMA_SHOP_URL'));
    }

    public function testUpgrade160IsIdempotentAndKeepsValuesTheMerchantSet()
    {
        $schema = $this->expectedSchema();
        unset($schema[self::GUEST_CODE]);
        $this->startFrom($schema);
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'scmedical');

        $this->assertTrue(upgrade_module_1_6_0(new Module()));
        $after = $this->schema;
        $this->assertTrue(upgrade_module_1_6_0(new Module()));

        $this->assertSame($after, $this->schema);
        $this->assertSame('scmedical', Configuration::get('IFIRMA_ORDER_STAMP_PREFIX'));
    }

    public function testUpgrade160ReportsAFailedCreate()
    {
        // no simulated database: the CREATE statement is refused
        Db::getInstance()->whenExecute('/ifirma_guest_code/', false);

        $this->assertFalse(upgrade_module_1_6_0(new Module()));
    }

    // ---- upgrade-1.8.0.php: the queue, the day cap, the back-off, the KSeF submitter and the cron cap ---

    /**
     * @return array[] key => default
     */
    public function newSettingsOf180()
    {
        return array(
            'the day cap' => array('IFIRMA_RATE_MAX_PER_DAY', 14000),
            'the day counter' => array('IFIRMA_RATE_DAY_WINDOW', ''),
            'the back-off end' => array('IFIRMA_RATE_BACKOFF_UNTIL', ''),
            'the remote limit reading' => array('IFIRMA_REMOTE_LIMIT', ''),
            'the KSeF submitter' => array('IFIRMA_KSEF_SUBMITTER', 'module'),
            'the cron item cap' => array('IFIRMA_CRON_MAX_ITEMS', 10),
        );
    }

    /**
     * @dataProvider newSettingsOf180
     */
    public function testUpgrade180AddsTheSettingWithItsDefault($key, $default)
    {
        $this->assertFalse(Configuration::hasKey($key));

        $this->assertTrue(upgrade_module_1_8_0(new Module()));

        $this->assertSame($default, Configuration::get($key));
    }

    public function testUpgrade180KeepsTheValuesTheMerchantSet()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 9000);
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');
        Configuration::updateValue('IFIRMA_CRON_MAX_ITEMS', 25);

        $this->assertTrue(upgrade_module_1_8_0(new Module()));

        $this->assertSame(9000, Configuration::get('IFIRMA_RATE_MAX_PER_DAY'));
        $this->assertSame('ifirma', Configuration::get('IFIRMA_KSEF_SUBMITTER'));
        $this->assertSame(25, Configuration::get('IFIRMA_CRON_MAX_ITEMS'));
    }

    public function testUpgrade180IsIdempotent()
    {
        $this->assertTrue(upgrade_module_1_8_0(new Module()));
        $after = Configuration::$values;

        $this->assertTrue(upgrade_module_1_8_0(new Module()));

        $this->assertSame($after, Configuration::$values);
    }

    public function testUpgrade180TouchesNoRowAndNoTable()
    {
        $this->assertTrue(upgrade_module_1_8_0(new Module()));

        $this->assertSame(array(), Db::getInstance()->inserts);
        $this->assertSame(array(), Db::getInstance()->updates);
        $this->assertSame(array(), Db::getInstance()->deletes);
        $this->assertSame(array(), Db::getInstance()->executed, 'no statement, so no receipt row changes');
    }

    // ---- upgrade-1.10.0.php: the automatic delivery retry columns -------------------------------------

    /** The receipt table of a 1.9.x shop: no retry columns yet. */
    private function schemaOf19x()
    {
        $schema = $this->expectedSchema();
        unset(
            $schema[self::RECEIPT]['delivery_attempts'],
            $schema[self::RECEIPT]['delivery_retry_at'],
            $schema[self::RECEIPT]['download_day'],
            $schema[self::RECEIPT]['download_count'],
            $schema[self::RECEIPT]['sync_attempt_at']
        );

        return $schema;
    }

    public function testUpgrade1100AddsTheRetryAndTheDownloadCountColumns()
    {
        $this->startFrom($this->schemaOf19x());

        $this->assertTrue(upgrade_module_1_10_0(new Module()));

        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `delivery_attempts` INT(11) UNSIGNED NOT NULL DEFAULT 0',
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `delivery_retry_at` DATETIME NULL',
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `download_day` DATE NULL',
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `download_count` INT(11) UNSIGNED NOT NULL DEFAULT 0',
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `sync_attempt_at` DATETIME NULL',
        ), $this->alterStatementsOf($this->sentQueries()));
        $this->assertSame($this->expectedSchema()[self::RECEIPT], $this->schema[self::RECEIPT]);
    }

    public function testUpgrade1100WritesNoRowSoOldFailuresKeepANullDueTime()
    {
        $this->startFrom($this->schemaOf19x());

        $this->assertTrue(upgrade_module_1_10_0(new Module()));

        $this->assertSame(array(), Db::getInstance()->updates);
        $this->assertSame(array(), Db::getInstance()->inserts);
        $this->assertSame(array(), Db::getInstance()->deletes);
        foreach ($this->sentQueries() as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^(UPDATE|INSERT|DELETE)/', $sql);
        }
    }

    public function testUpgrade1100IsIdempotent()
    {
        $this->startFrom($this->schemaOf19x());
        $this->assertTrue(upgrade_module_1_10_0(new Module()));
        $after = $this->schema;
        IfirmaSchema::resetEnsureCache();

        $this->assertTrue(upgrade_module_1_10_0(new Module()));

        $this->assertSame($after, $this->schema);
        $this->assertCount(5, $this->alterStatementsOf($this->sentQueries()), 'the second run adds nothing');
    }

    public function testUpgrade1100ReportsAFailedAlter()
    {
        Db::getInstance()->whenExecute('/ADD COLUMN `delivery_retry_at`/', false);
        $this->startFrom($this->schemaOf19x());

        $this->assertFalse(upgrade_module_1_10_0(new Module()));
    }

    public function testUpgrade1100ReportsAFailedDownloadCountAlter()
    {
        Db::getInstance()->whenExecute('/ADD COLUMN `download_count`/', false);
        $this->startFrom($this->schemaOf19x());

        $this->assertFalse(upgrade_module_1_10_0(new Module()));
    }

    public function testUpgrade1100CreatesThePdfCopyTable()
    {
        $schema = $this->expectedSchema();
        unset($schema[self::PDF_COPY]);
        $this->startFrom($schema);

        $this->assertTrue(upgrade_module_1_10_0(new Module()));

        $this->assertSame(IfirmaSchema::pdfCopyColumns(), $this->schema[self::PDF_COPY]);
    }

    public function testUpgrade1100ReportsAFailedPdfCopyTableCreate()
    {
        Db::getInstance()->whenExecute('/ifirma_pdf_copy/', false);
        $schema = $this->expectedSchema();
        unset($schema[self::PDF_COPY]);
        $this->startFrom($schema);

        $this->assertFalse(upgrade_module_1_10_0(new Module()));
    }

    public function testUpgrade1100ReportsAFailedSyncAttemptAlter()
    {
        Db::getInstance()->whenExecute('/ADD COLUMN `sync_attempt_at`/', false);
        $this->startFrom($this->schemaOf19x());

        $this->assertFalse(upgrade_module_1_10_0(new Module()));
    }

    // ---- helpers: the database each path runs on ----------------------------------------------------

    /** What the module's own column lists say a complete install has. */
    private function expectedSchema()
    {
        return array(
            self::RECEIPT => IfirmaSchema::receiptColumns(),
            self::DATA_REQUEST => IfirmaSchema::dataRequestColumns(),
            self::CONTRACTOR_MAP => IfirmaSchema::contractorMapColumns(),
            self::GUEST_CODE => IfirmaSchema::guestCodeColumns(),
            self::PDF_COPY => IfirmaSchema::pdfCopyColumns(),
        );
    }

    /** What sql/install.php of 1.0.0 created: the receipt table, without the columns 1.1.0 added. */
    private function schemaOf10x()
    {
        return array(self::RECEIPT => array_diff_key(
            IfirmaSchema::receiptColumns(),
            IfirmaSchema::newReceiptColumnsFor110(),
            IfirmaSchema::newReceiptColumnsFor150(),
            IfirmaSchema::newReceiptColumnsFor1100(),
            IfirmaSchema::newReceiptDownloadCountColumnsFor1100(),
            IfirmaSchema::newReceiptSyncAttemptColumnsFor1100()
        ));
    }

    /** The first 1.1.0 build: no contractor map yet, and not yet the KSeF sent and error columns. */
    private function schemaOfTheFirst110Build()
    {
        $schema = $this->expectedSchema();
        unset($schema[self::CONTRACTOR_MAP], $schema[self::RECEIPT]['ksef_sent_at'], $schema[self::RECEIPT]['ksef_error']);

        return $schema;
    }

    /** A 1.1.0 build that created the contractor map, but not yet the two KSeF columns added after it. */
    private function schemaOfTheBuildWithTheContractorMap()
    {
        $schema = $this->expectedSchema();
        unset($schema[self::RECEIPT]['ksef_sent_at'], $schema[self::RECEIPT]['ksef_error']);

        return $schema;
    }

    /**
     * A hypothetical start state, not a build that shipped: a hand-made or uncommitted development table whose
     * identifier column is still 15 characters wide. It exercises the widening step (widenContractorMapIdentifier()).
     * 4452d02, the commit that added the table, created the column as VARCHAR(64).
     */
    private function schemaWithANarrowContractorMap()
    {
        $schema = $this->expectedSchema();
        $schema[self::CONTRACTOR_MAP]['ifirma_identifier'] = 'VARCHAR(15) NOT NULL';

        return $schema;
    }

    /**
     * The runtime checks run at most once per request and remember a success. Letting them succeed once on an
     * empty stub leaves them doing nothing for the rest of the test.
     */
    private function switchOffTheRuntimeChecks()
    {
        $this->assertTrue(IfirmaSchema::ensureContractorMapTable());
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());
        Db::reset();

        $this->assertTrue(IfirmaSchema::ensureContractorMapTable());
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());
        $this->assertSame(array(), $this->sentQueries(), 'the runtime checks must send nothing');
    }

    /**
     * Starts a path on a database holding $schema, with the runtime checks switched off. The checks must then answer
     * from what they remember and send nothing, so a path that the checks would complete cannot pass unnoticed.
     */
    private function startPath(array $schema)
    {
        $this->switchOffTheRuntimeChecks();
        $this->startFrom($schema);

        $this->assertTrue(IfirmaSchema::ensureContractorMapTable());
        $this->assertTrue(IfirmaSchema::ensureReceiptKsefColumns());
        $this->assertSame(array(), $this->sentQueries(), 'the switched-off runtime checks must send nothing');
    }

    /**
     * Makes the Db stub a database holding $schema. A statement it does not model, or that MySQL would
     * refuse (an existing column added again, a column or table that is not there), makes execute() return
     * false, so that a path cannot pass by luck.
     */
    private function startFrom(array $schema, array $indexes = array())
    {
        $this->schema = $schema;
        $this->indexes = $indexes;
        $this->refuseKeyAlters = false;
        $db = Db::getInstance();
        $db->whenExecute('/^(CREATE|ALTER) TABLE/', function ($sql) {
            return $this->applyStatement($sql);
        });
        $db->whenGetValue('/FROM information_schema\.COLUMNS/', function ($sql) {
            return $this->answerProbe($sql);
        });
        $db->whenGetValue('/FROM information_schema\.STATISTICS/', function ($sql) {
            return $this->answerIndexProbe($sql);
        });
    }

    private function applyStatement($sql)
    {
        $sql = str_replace("\r\n", "\n", $sql);
        if (preg_match('/^CREATE TABLE IF NOT EXISTS `([^`]+)` \(\n(.*)\n\) ENGINE=/s', $sql, $m)) {
            if (!isset($this->schema[$m[1]])) {
                $this->schema[$m[1]] = array();
                $this->indexes[$m[1]] = isset($this->indexes[$m[1]]) ? $this->indexes[$m[1]] : array();
                foreach (explode("\n", $m[2]) as $line) {
                    if (preg_match('/^\s+`([^`]+)` (.+?),?$/', $line, $column)) {
                        $this->schema[$m[1]][$column[1]] = $column[2];
                    }
                    if (preg_match('/^\s+KEY `([^`]+)` /', $line, $key)) {
                        $this->indexes[$m[1]][$key[1]] = true;
                    }
                }
            }

            return true;
        }
        if (preg_match('/^ALTER TABLE `([^`]+)` ADD KEY `([^`]+)` \(`[^`]+`\)$/', $sql, $m) && isset($this->schema[$m[1]])) {
            if (!$this->refuseKeyAlters && !isset($this->indexes[$m[1]][$m[2]])) {
                $this->indexes[$m[1]][$m[2]] = true;

                return true;
            }

            return false;
        }
        if (preg_match('/^ALTER TABLE `([^`]+)` (ADD COLUMN|MODIFY) `([^`]+)` (.+)$/s', $sql, $m) && isset($this->schema[$m[1]])) {
            if (isset($this->schema[$m[1]][$m[3]]) === ($m[2] === 'MODIFY')) {
                $this->schema[$m[1]][$m[3]] = $m[4];

                return true;
            }
        }

        return false;
    }

    private function answerProbe($sql)
    {
        if (!preg_match(
            "/^SELECT (COUNT\(\*\)|CHARACTER_MAXIMUM_LENGTH) FROM information_schema\.COLUMNS WHERE TABLE_SCHEMA = DATABASE\(\)"
            . " AND TABLE_NAME = '([^']+)' AND COLUMN_NAME = '([^']+)'$/",
            $sql,
            $m
        )) {
            throw new LogicException('The simulated database does not know this probe: ' . $sql);
        }
        $ddl = isset($this->schema[$m[2]][$m[3]]) ? $this->schema[$m[2]][$m[3]] : null;
        if ($m[1] === 'COUNT(*)') {
            return $ddl === null ? '0' : '1';
        }

        return $ddl !== null && preg_match('/^VARCHAR\((\d+)\)/', $ddl, $length) ? $length[1] : false;
    }

    private function answerIndexProbe($sql)
    {
        if (!preg_match(
            "/^SELECT COUNT\(\*\) FROM information_schema\.STATISTICS WHERE TABLE_SCHEMA = DATABASE\(\)"
            . " AND TABLE_NAME = '([^']+)' AND INDEX_NAME = '([^']+)'$/",
            $sql,
            $m
        )) {
            throw new LogicException('The simulated database does not know this index probe: ' . $sql);
        }

        return isset($this->indexes[$m[1]][$m[2]]) ? '1' : '0';
    }

    /** The table order and the column order of a CREATE TABLE do not matter once a column was added later. */
    private function sortedSchema(array $schema)
    {
        ksort($schema);
        foreach ($schema as $table => $columns) {
            ksort($columns);
            $schema[$table] = $columns;
        }

        return $schema;
    }

    private function assertEndsWithTheContractorMapAndTheKsefColumns()
    {
        $this->assertArrayHasKey(self::CONTRACTOR_MAP, $this->schema, 'the contractor map table does not exist');
        $this->assertArrayHasKey('ifirma_identifier', $this->schema[self::CONTRACTOR_MAP]);
        $this->assertSame('VARCHAR(64) NOT NULL', $this->schema[self::CONTRACTOR_MAP]['ifirma_identifier']);
        $this->assertSame(
            'VARCHAR(' . IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH . ') NOT NULL',
            $this->schema[self::CONTRACTOR_MAP]['ifirma_identifier']
        );

        $this->assertArrayHasKey(self::RECEIPT, $this->schema, 'the receipt table does not exist');
        foreach (self::$ksefColumns as $column => $ddl) {
            $this->assertArrayHasKey($column, $this->schema[self::RECEIPT], 'receipt column ' . $column);
            $this->assertSame($ddl, $this->schema[self::RECEIPT][$column], 'receipt column ' . $column);
        }

        $this->assertSame(
            $this->sortedSchema($this->expectedSchema()),
            $this->sortedSchema($this->schema),
            'the schema of a fresh install'
        );
    }

    public function testUpgradeStopsWhenTheCronTokenCannotBeGenerated()
    {
        $this->scriptColumnProbe();
        Configuration::deleteByName('IFIRMA_CRON_TOKEN');
        IfirmaSchema::$randomBytes = function () {
            throw new Exception('no entropy');
        };
        try {
            $result = upgrade_module_1_1_0(new Module());
        } finally {
            IfirmaSchema::$randomBytes = null;
        }

        $this->assertFalse($result);
        $this->assertFalse(Configuration::get('IFIRMA_CRON_TOKEN'), 'no guessable token is stored');
    }
}
