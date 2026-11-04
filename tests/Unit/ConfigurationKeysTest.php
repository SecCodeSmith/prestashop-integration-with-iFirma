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
        $this->assertSame('1.2.0', $module->version);

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

    public function testVersionFilesAreBumped()
    {
        $root = dirname(__DIR__, 2);
        $this->assertStringContainsString('<![CDATA[1.2.0]]>', file_get_contents($root . '/config.xml'));
        $package = json_decode(file_get_contents($root . '/package.json'), true);
        $this->assertSame('1.2.0', $package['version']);
        $lock = json_decode(file_get_contents($root . '/package-lock.json'), true);
        $this->assertSame('1.2.0', $lock['version']);
        $this->assertSame('1.2.0', $lock['packages']['']['version']);
    }
}
