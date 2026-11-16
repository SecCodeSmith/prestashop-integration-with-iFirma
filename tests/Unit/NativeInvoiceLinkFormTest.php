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

/**
 * The "Built-in invoice link setting" scenarios on the configuration page: the switch, and the warning
 * that shows only while the switch is on and PrestaShop invoices are off.
 */
class NativeInvoiceLinkFormTest extends TestCase
{
    use IfirmaWiringFixture;

    const SWITCH_NAME = 'IFIRMA_NATIVE_INVOICE_LINK';
    const WARNING_NAME = 'IFIRMA_NATIVE_INVOICE_LINK_WARNING';

    protected function setUp(): void
    {
        $this->wiringSetUp();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function inputsWith($switch, $psInvoice)
    {
        Configuration::updateValue(self::SWITCH_NAME, $switch);
        Configuration::updateValue('PS_INVOICE', $psInvoice);
        $this->module->getContent();

        $inputs = array();
        foreach (HelperForm::$lastForm[0]['form']['input'] as $input) {
            $inputs[$input['name']] = $input;
        }

        return $inputs;
    }

    public function testTheFormShowsTheSwitchWithItsStoredValue()
    {
        $inputs = $this->inputsWith(1, 1);

        $this->assertSame('switch', $inputs[self::SWITCH_NAME]['type']);
        $this->assertSame(1, HelperForm::$lastHelper->fields_value[self::SWITCH_NAME]);
    }

    public function testASwitchedOffSettingReadsAsZeroInTheForm()
    {
        $this->inputsWith(0, 1);

        $this->assertSame(0, HelperForm::$lastHelper->fields_value[self::SWITCH_NAME]);
    }

    public function testNoWarningWhileTheSwitchIsOnAndPrestaShopInvoicesAreOn()
    {
        $this->assertArrayNotHasKey(self::WARNING_NAME, $this->inputsWith(1, 1));
    }

    public function testTheWarningShowsWhileTheSwitchIsOnAndPrestaShopInvoicesAreOff()
    {
        $inputs = $this->inputsWith(1, 0);

        $this->assertArrayHasKey(self::WARNING_NAME, $inputs);
        $this->assertSame('html', $inputs[self::WARNING_NAME]['type']);
        $this->assertStringContainsString('PrestaShop invoices are disabled', $inputs[self::WARNING_NAME]['html_content']);
        $this->assertStringContainsString('alert-warning', $inputs[self::WARNING_NAME]['html_content']);
    }

    public function testNoWarningWhileTheSwitchIsOffAndPrestaShopInvoicesAreOn()
    {
        $this->assertArrayNotHasKey(self::WARNING_NAME, $this->inputsWith(0, 1));
    }

    public function testNoWarningWhileTheSwitchIsOffAndPrestaShopInvoicesAreOff()
    {
        $this->assertArrayNotHasKey(self::WARNING_NAME, $this->inputsWith(0, 0));
    }

    public function testTheSwitchSitsRightAfterTheCustomerDataRequestSwitch()
    {
        $this->inputsWith(1, 1);
        $names = array();
        foreach (HelperForm::$lastForm[0]['form']['input'] as $input) {
            $names[] = $input['name'];
        }

        $this->assertSame(
            array_search('IFIRMA_ALLOW_DATA_REQUEST', $names, true) + 1,
            array_search(self::SWITCH_NAME, $names, true)
        );
    }

    public function testInputsForAnUnknownAnchorGoToTheEnd()
    {
        $insert = new ReflectionMethod($this->module, 'insertInputsAfter');
        $insert->setAccessible(true);

        $result = $insert->invoke($this->module, array(array('name' => 'a'), array('name' => 'b')), 'missing', array(array('name' => 'new')));

        $this->assertSame(array('a', 'b', 'new'), array_column($result, 'name'));
    }

    public function testTheSwitchIsStoredFromTheFormLikeTheOtherSwitches()
    {
        $values = IfirmaSettings::fromInput(array(self::SWITCH_NAME => '0'), function () {
            return array();
        })['values'];

        $this->assertSame(0, $values[self::SWITCH_NAME]);
    }
}
