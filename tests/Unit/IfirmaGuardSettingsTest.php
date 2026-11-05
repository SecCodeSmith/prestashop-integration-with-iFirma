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

class IfirmaGuardSettingsTest extends TestCase
{
    /** @var IfirmaDuplicateGuard */
    private $guard;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, new ScriptedTransport());
        $this->guard = new IfirmaDuplicateGuard($client);
    }

    /** The six settings of a run as plain values, to compare in one assertion. */
    private static function describe(IfirmaGuardSettings $s)
    {
        return array(
            'enabled' => $s->isEnabled(),
            'bypass' => $s->isBypassed(),
            'tolerance' => $s->getTolerance(),
            'windowDays' => $s->getWindowDays(),
            'timeout' => $s->getTimeout(),
            'maxDetailCalls' => $s->getMaxDetailCalls(),
        );
    }

    private static function defaults(array $over = array())
    {
        return array_merge(array(
            'enabled' => true,
            'bypass' => false,
            'tolerance' => 0.01,
            'windowDays' => 60,
            'timeout' => null,
            'maxDetailCalls' => 10,
        ), $over);
    }

    // ---- the value object ---------------------------------------------------------------------

    public function testTheDefaultsAreTheValuesTheLiteralsOfTheGuardHad()
    {
        $this->assertTrue(IfirmaGuardSettings::DEFAULT_ENABLED);
        $this->assertSame(0.01, IfirmaGuardSettings::DEFAULT_AMOUNT_TOLERANCE);
        $this->assertSame(60, IfirmaGuardSettings::DEFAULT_WINDOW_DAYS);
        $this->assertSame(10, IfirmaGuardSettings::DEFAULT_MAX_DETAIL_CALLS);
    }

    public function testTheInstallDefaultsOfTheConfigurationAreTheDefaultsOfTheGuard()
    {
        require_once dirname(__DIR__, 2) . '/classes/IfirmaSchema.php';
        $installed = IfirmaSchema::configurationDefaults();

        $this->assertSame((int) IfirmaGuardSettings::DEFAULT_ENABLED, $installed['IFIRMA_DUPLICATE_CHECK']);
        $this->assertSame(IfirmaGuardSettings::DEFAULT_WINDOW_DAYS, $installed['IFIRMA_DUPLICATE_WINDOW_DAYS']);
        $this->assertSame(IfirmaGuardSettings::DEFAULT_AMOUNT_TOLERANCE, (float) $installed['IFIRMA_DUPLICATE_AMOUNT_TOLERANCE']);
    }

    public static function constructions()
    {
        return array(
            'values as they are' => array(
                array(true, false, 0.5, 45, 7, 3),
                self::defaults(array('tolerance' => 0.5, 'windowDays' => 45, 'timeout' => 7, 'maxDetailCalls' => 3)),
            ),
            'text and numbers are cast' => array(
                array(1, 'yes', '0.5', '45', '7', '3'),
                self::defaults(array('bypass' => true, 'tolerance' => 0.5, 'windowDays' => 45, 'timeout' => 7, 'maxDetailCalls' => 3)),
            ),
            'zeros are values' => array(
                array(0, 0, 0, 0, 0, 0),
                array('enabled' => false, 'bypass' => false, 'tolerance' => 0.0, 'windowDays' => 0, 'timeout' => 0, 'maxDetailCalls' => 0),
            ),
            'no timeout stays null' => array(
                array(true, false, 0.01, 60, null, 10),
                self::defaults(),
            ),
            'a negative cap counts as zero' => array(
                array(true, false, 0.01, 60, null, -4),
                self::defaults(array('maxDetailCalls' => 0)),
            ),
        );
    }

    /**
     * @dataProvider constructions
     */
    public function testTheConstructorTypesTheValues(array $arguments, array $expected)
    {
        $settings = new IfirmaGuardSettings($arguments[0], $arguments[1], $arguments[2], $arguments[3], $arguments[4], $arguments[5]);

        $this->assertSame($expected, self::describe($settings));
    }

    public function testTheCapDecidesWhichPositionsAreRead()
    {
        $settings = new IfirmaGuardSettings(true, false, 0.01, 60, null, 3);

        $this->assertTrue($settings->readsDetailOf(0));
        $this->assertTrue($settings->readsDetailOf(2));
        $this->assertTrue($settings->readsDetailOf('2'));
        $this->assertFalse($settings->readsDetailOf(3));
        $this->assertFalse($settings->readsDetailOf(10));
    }

    public function testACapOfZeroReadsNothing()
    {
        $settings = new IfirmaGuardSettings(true, false, 0.01, 60, null, 0);

        $this->assertFalse($settings->readsDetailOf(0));
    }

    // ---- resolving: an option wins, then the configuration, then the default -----------------

    public function testNothingConfiguredAndNoOptionsResolvesToTheDefaults()
    {
        $this->assertSame(self::defaults(), self::describe($this->guard->resolveSettings(array())));
    }

    public static function resolutions()
    {
        // configuration (key => value, a key left out is not set), options, the settings that differ from the defaults
        return array(
            'the option switches on, the configuration off' => array(
                array('IFIRMA_DUPLICATE_CHECK' => '0'), array('enabled' => true), array(),
            ),
            'the option switches off, the configuration on' => array(
                array('IFIRMA_DUPLICATE_CHECK' => '1'), array('enabled' => false), array('enabled' => false),
            ),
            'a null option counts as off' => array(
                array('IFIRMA_DUPLICATE_CHECK' => '1'), array('enabled' => null), array('enabled' => false),
            ),
            'the configuration switches off with an integer' => array(
                array('IFIRMA_DUPLICATE_CHECK' => 0), array(), array('enabled' => false),
            ),
            'the configuration switches off with text' => array(
                array('IFIRMA_DUPLICATE_CHECK' => '0'), array(), array('enabled' => false),
            ),
            'text without a number switches off' => array(
                array('IFIRMA_DUPLICATE_CHECK' => 'off'), array(), array('enabled' => false),
            ),
            'an empty configuration is on' => array(
                array('IFIRMA_DUPLICATE_CHECK' => ''), array(), array(),
            ),
            'a null configuration is on' => array(
                array('IFIRMA_DUPLICATE_CHECK' => null), array(), array(),
            ),
            'a false configuration is on' => array(
                array('IFIRMA_DUPLICATE_CHECK' => false), array(), array(),
            ),
            'a configuration of two is on' => array(
                array('IFIRMA_DUPLICATE_CHECK' => '2'), array(), array(),
            ),
            'a bypass' => array(
                array(), array('bypass' => true), array('bypass' => true),
            ),
            'an empty bypass is none' => array(
                array(), array('bypass' => '0'), array(),
            ),
            'the configured tolerance' => array(
                array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0.05'), array(), array('tolerance' => 0.05),
            ),
            'an empty configured tolerance is the default' => array(
                array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => ''), array(), array(),
            ),
            'a configured tolerance of zero is a value' => array(
                array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0'), array(), array('tolerance' => 0.0),
            ),
            'the option wins over the configured tolerance' => array(
                array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0.05'), array('tolerance' => '0.5'), array('tolerance' => 0.5),
            ),
            'a tolerance option of zero is a value' => array(
                array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0.05'), array('tolerance' => 0), array('tolerance' => 0.0),
            ),
            'a null tolerance option falls back to the configuration' => array(
                array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0.05'), array('tolerance' => null), array('tolerance' => 0.05),
            ),
            'the configured window' => array(
                array('IFIRMA_DUPLICATE_WINDOW_DAYS' => '30'), array(), array('windowDays' => 30),
            ),
            'a configured window with decimals is cut' => array(
                array('IFIRMA_DUPLICATE_WINDOW_DAYS' => '45.9'), array(), array('windowDays' => 45),
            ),
            'an empty configured window is the default' => array(
                array('IFIRMA_DUPLICATE_WINDOW_DAYS' => ''), array(), array(),
            ),
            'a configured window of zero is a value' => array(
                array('IFIRMA_DUPLICATE_WINDOW_DAYS' => '0'), array(), array('windowDays' => 0),
            ),
            'the option wins over the configured window' => array(
                array('IFIRMA_DUPLICATE_WINDOW_DAYS' => '30'), array('window_days' => '10'), array('windowDays' => 10),
            ),
            'a window option of zero is a value' => array(
                array('IFIRMA_DUPLICATE_WINDOW_DAYS' => '30'), array('window_days' => 0), array('windowDays' => 0),
            ),
            'a null window option falls back to the configuration' => array(
                array('IFIRMA_DUPLICATE_WINDOW_DAYS' => '30'), array('window_days' => null), array('windowDays' => 30),
            ),
            'a timeout' => array(
                array(), array('timeout' => '9'), array('timeout' => 9),
            ),
            'a timeout of zero is a value' => array(
                array(), array('timeout' => 0), array('timeout' => 0),
            ),
            'a null timeout is none' => array(
                array(), array('timeout' => null), array(),
            ),
            'a cap' => array(
                array(), array('max_detail_calls' => '2'), array('maxDetailCalls' => 2),
            ),
            'a cap of zero is a value' => array(
                array(), array('max_detail_calls' => 0), array('maxDetailCalls' => 0),
            ),
            'a negative cap counts as zero' => array(
                array(), array('max_detail_calls' => -2), array('maxDetailCalls' => 0),
            ),
            'a null cap is the default' => array(
                array(), array('max_detail_calls' => null), array(),
            ),
            'every setting from its option' => array(
                array('IFIRMA_DUPLICATE_CHECK' => '0', 'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '9', 'IFIRMA_DUPLICATE_WINDOW_DAYS' => '99'),
                array('enabled' => true, 'bypass' => 1, 'tolerance' => 0.5, 'window_days' => 7, 'timeout' => 4, 'max_detail_calls' => 1),
                array('bypass' => true, 'tolerance' => 0.5, 'windowDays' => 7, 'timeout' => 4, 'maxDetailCalls' => 1),
            ),
            'every setting from the configuration' => array(
                array('IFIRMA_DUPLICATE_CHECK' => '0', 'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '9', 'IFIRMA_DUPLICATE_WINDOW_DAYS' => '99'),
                array(),
                array('enabled' => false, 'tolerance' => 9.0, 'windowDays' => 99),
            ),
        );
    }

    /**
     * @dataProvider resolutions
     */
    public function testAnOptionWinsOverTheConfigurationWhichWinsOverTheDefault(array $configured, array $options, array $expected)
    {
        foreach ($configured as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        $settings = $this->guard->resolveSettings($options);

        $this->assertSame(self::defaults($expected), self::describe($settings));
    }

    public function testTheConfigurationIsReadWhenTheSettingsAreResolved()
    {
        $this->assertSame(60, $this->guard->resolveSettings(array())->getWindowDays());

        Configuration::updateValue('IFIRMA_DUPLICATE_WINDOW_DAYS', '15');

        $this->assertSame(15, $this->guard->resolveSettings(array())->getWindowDays());
    }
}
