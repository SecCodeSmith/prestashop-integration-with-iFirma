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

    public function testVersionMismatchNamesTheLaggingSource()
    {
        $root = dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir() . '/ifirma-version-' . uniqid();
        mkdir($tmp);
        try {
            foreach (array('package.json', 'package-lock.json', 'ifirma.php', 'config.xml') as $file) {
                copy($root . '/' . $file, $tmp . '/' . $file);
            }
            $xml = preg_replace(
                '/(<version><!\[CDATA\[)[^\]]*(\]\]><\/version>)/',
                '${1}0.0.1${2}',
                file_get_contents($tmp . '/config.xml')
            );
            file_put_contents($tmp . '/config.xml', $xml);

            $mismatches = self::versionMismatches($tmp);
            $this->assertCount(1, $mismatches);
            $this->assertStringContainsString('config.xml', $mismatches[0]);
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
