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

class UpgradeTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.1.0.php';
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-1.2.0.php';
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
}
