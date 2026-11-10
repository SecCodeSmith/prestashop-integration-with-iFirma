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

class ConfigurationKeysTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    public function testInstallSetsEveryKeyAndUninstallRemovesThem()
    {
        $module = IfirmaTestModuleLoader::loadModule();
        $this->assertSame(self::packageVersion(dirname(__DIR__, 2)), $module->version, 'ifirma.php $this->version');

        $this->assertTrue($module->install());

        foreach (IfirmaSchema::configurationDefaults() as $key => $default) {
            $this->assertTrue(Configuration::hasKey($key), $key . ' should be set');
            if ($default !== null) {
                $this->assertEquals($default, Configuration::get($key), $key);
            }
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Configuration::get('IFIRMA_CRON_TOKEN'));

        $this->assertTrue($module->uninstall());
        foreach (array_keys(IfirmaSchema::configurationDefaults()) as $key) {
            $this->assertFalse(Configuration::hasKey($key), $key . ' should be removed');
        }
    }

    public function testTheFourOneSixZeroKeysAreInstalledWithTheirDefaultsAndRemoved()
    {
        $expected = array(
            'IFIRMA_DATA_REQUEST_AUTO_ACCEPT' => 'none',
            'IFIRMA_DOC_TEMPLATE' => '',
            'IFIRMA_ORDER_STAMP_PREFIX' => 'PS-ORDER',
            'IFIRMA_SHOP_URL' => '',
        );
        $module = IfirmaTestModuleLoader::loadModule();
        $this->assertTrue($module->install());

        foreach ($expected as $key => $default) {
            $this->assertSame($default, IfirmaSchema::configurationDefaults()[$key], $key . ' default');
            $this->assertTrue(Configuration::hasKey($key), $key . ' should be set');
            $this->assertSame($default, Configuration::get($key), $key);
        }

        $this->assertTrue($module->uninstall());
        foreach (array_keys($expected) as $key) {
            $this->assertFalse(Configuration::hasKey($key), $key . ' should be removed');
        }
    }

    public function testEveryRequestKindHasAnEmptyUsageCounterInstalledAndRemoved()
    {
        $module = IfirmaTestModuleLoader::loadModule();
        $this->assertTrue($module->install());

        foreach (IfirmaRequestKind::all() as $kind) {
            $key = IfirmaApiUsage::keyOf($kind);
            $this->assertSame('IFIRMA_USAGE_' . strtoupper($kind), $key);
            $this->assertArrayHasKey($key, IfirmaSchema::configurationDefaults());
            $this->assertSame('', Configuration::get($key), $key);
        }

        $this->assertTrue($module->uninstall());
        foreach (IfirmaRequestKind::all() as $kind) {
            $this->assertFalse(Configuration::hasKey(IfirmaApiUsage::keyOf($kind)), $kind . ' counter should be removed');
        }
    }

    public function testTheDownloadBudgetCountersAreEmptyRuntimeRowsInstalledAndRemoved()
    {
        $keys = array(
            IfirmaDownloadBudget::CUSTOMER_MINUTE_KEY,
            IfirmaDownloadBudget::CUSTOMER_DAY_KEY,
            IfirmaDownloadBudget::EMPLOYEE_MINUTE_KEY,
        );
        $module = IfirmaTestModuleLoader::loadModule();
        $this->assertTrue($module->install());

        foreach ($keys as $key) {
            $this->assertSame('', IfirmaSchema::configurationDefaults()[$key], $key . ' default');
            $this->assertSame('', Configuration::get($key), $key);
        }

        $this->assertTrue($module->uninstall());
        foreach ($keys as $key) {
            $this->assertFalse(Configuration::hasKey($key), $key . ' should be removed');
        }
    }

    public function testAuthFailureDefaultIsEmptyAndUninstallRemovesIt()
    {
        $module = IfirmaTestModuleLoader::loadModule();
        $this->assertTrue($module->install());

        $this->assertArrayHasKey('IFIRMA_AUTH_FAILURE', IfirmaSchema::configurationDefaults());
        $this->assertSame('', IfirmaSchema::configurationDefaults()['IFIRMA_AUTH_FAILURE']);
        $this->assertTrue(Configuration::hasKey('IFIRMA_AUTH_FAILURE'));
        $this->assertSame('', Configuration::get('IFIRMA_AUTH_FAILURE'));

        $this->assertTrue($module->uninstall());
        $this->assertFalse(Configuration::hasKey('IFIRMA_AUTH_FAILURE'));
    }

    /**
     * Every version source must agree with package.json, whatever the version is,
     * so `npm version <level>` never needs a test edit.
     */
    public function testVersionSourcesAgree()
    {
        $root = dirname(__DIR__, 2);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', self::packageVersion($root));
        $this->assertSame(array(), self::versionMismatches($root));
    }

    /**
     * Runs on minimal fixtures, not on copies of the real files, so drift in the
     * real tree cannot leak in: every source says 9.9.9 except config.xml.
     */
    public function testVersionMismatchNamesTheLaggingSource()
    {
        $tmp = sys_get_temp_dir() . '/ifirma-version-' . uniqid();
        mkdir($tmp);
        try {
            file_put_contents($tmp . '/package.json', json_encode(array('version' => '9.9.9')));
            file_put_contents($tmp . '/package-lock.json', json_encode(array(
                'version' => '9.9.9',
                'packages' => array('' => array('version' => '9.9.9')),
            )));
            file_put_contents($tmp . '/ifirma.php', "<?php\n\$this->version = '9.9.9';\n");
            file_put_contents($tmp . '/config.xml', '<module><version><![CDATA[0.0.1]]></version></module>');

            $this->assertSame(
                array("config.xml <version>: '0.0.1' != 9.9.9"),
                self::versionMismatches($tmp)
            );
        } finally {
            array_map('unlink', glob($tmp . '/*'));
            rmdir($tmp);
        }
    }

    private static function packageVersion($root)
    {
        $package = json_decode(file_get_contents($root . '/package.json'), true);

        return (string) $package['version'];
    }

    /**
     * @return string[] one "<source>: <version> != <package.json version>" entry per lagging source
     */
    private static function versionMismatches($root)
    {
        $expected = self::packageVersion($root);
        $lock = json_decode(file_get_contents($root . '/package-lock.json'), true);
        preg_match('/\$this->version\s*=\s*\'([^\']*)\'/', file_get_contents($root . '/ifirma.php'), $php);
        preg_match('/<version><!\[CDATA\[([^\]]*)\]\]><\/version>/', file_get_contents($root . '/config.xml'), $xml);

        $sources = array(
            'package-lock.json version' => isset($lock['version']) ? $lock['version'] : null,
            "package-lock.json packages['']" => isset($lock['packages']['']['version']) ? $lock['packages']['']['version'] : null,
            'ifirma.php $this->version' => isset($php[1]) ? $php[1] : null,
            'config.xml <version>' => isset($xml[1]) ? $xml[1] : null,
        );
        $mismatches = array();
        foreach ($sources as $source => $version) {
            if ($version !== $expected) {
                $mismatches[] = $source . ': ' . var_export($version, true) . ' != ' . $expected;
            }
        }

        return $mismatches;
    }
}
