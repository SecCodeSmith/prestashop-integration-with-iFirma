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

class PaymentMethodResolverTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function order($module, $payment = '')
    {
        $o = new Order();
        $o->id = 1;
        $o->module = $module;
        $o->payment = $payment;

        return $o;
    }

    public function testCodesHasFourteenEntries()
    {
        $this->assertSame(
            array('GTK', 'POB', 'PRZ', 'KAR', 'PZA', 'CZK', 'KOM', 'BAR', 'DOT', 'PAL', 'ALG', 'P24', 'TPA', 'ELE'),
            array_keys(IfirmaPaymentMethodResolver::codes())
        );
    }

    public function testDefaultModuleMappings()
    {
        $r = new IfirmaPaymentMethodResolver('order', 'PRZ');

        $this->assertSame('PRZ', $r->resolve($this->order('ps_wirepayment')));
        $this->assertSame('CZK', $r->resolve($this->order('ps_checkpayment')));
        $this->assertSame('POB', $r->resolve($this->order('ps_cashondelivery')));
        $this->assertSame('PAL', $r->resolve($this->order('paypal_official')));
        $this->assertSame('KAR', $r->resolve($this->order('stripe_official')));
    }

    public function testMerchantMapOverridesDefault()
    {
        $r = new IfirmaPaymentMethodResolver('order', 'PRZ', array('ps_checkpayment' => 'PRZ'));

        $this->assertSame('PRZ', $r->resolve($this->order('ps_checkpayment')));
    }

    public function testMerchantWildcardPattern()
    {
        $r = new IfirmaPaymentMethodResolver('order', 'PRZ', array('mygateway*' => 'ELE'));

        $this->assertSame('ELE', $r->resolve($this->order('MyGateway_Pro')));
    }

    public function testLabelFallbackForUnknownModule()
    {
        $r = new IfirmaPaymentMethodResolver('order', 'GTK');

        $this->assertSame('PAL', $r->resolve($this->order('unknown_module', 'PayPal')));
        $this->assertSame('POB', $r->resolve($this->order('unknown_module', 'Płatność za pobraniem')));
        $this->assertSame('KAR', $r->resolve($this->order('unknown_module', 'Karta kredytowa')));
        $this->assertSame('PRZ', $r->resolve($this->order('unknown_module', 'Przelew bankowy')));
    }

    public function testNothingMatchesUsesFixedCode()
    {
        $r = new IfirmaPaymentMethodResolver('order', 'GTK');

        $this->assertSame('GTK', $r->resolve($this->order('unknown_module', 'Something else')));
    }

    public function testUnknownFixedCodeDefaultsToPrz()
    {
        $r = new IfirmaPaymentMethodResolver('order', 'XYZ');

        $this->assertSame('PRZ', $r->getFixedCode());
    }

    public function testFixedModeIgnoresModuleAndLabel()
    {
        $r = new IfirmaPaymentMethodResolver('fixed', 'GTK');

        $this->assertSame('GTK', $r->resolve($this->order('ps_cashondelivery', 'PayPal')));
    }

    public function testParseMapIgnoresBlanksAndComments()
    {
        $map = IfirmaPaymentMethodResolver::parseMap("\n# comment\nPS_CheckPayment = prz\n\nMyGateway*=ele\n");

        $this->assertSame(array('ps_checkpayment' => 'PRZ', 'mygateway*' => 'ELE'), $map);
    }

    public function testParseMapReportsBadLineNumber()
    {
        try {
            IfirmaPaymentMethodResolver::parseMap("ps_wirepayment=PRZ\nbroken line\n");
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('line 2:', $e->getMessage());
        }
    }

    public function testParseMapRejectsUnknownCode()
    {
        try {
            IfirmaPaymentMethodResolver::parseMap('ps_checkpayment=XYZ');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('line 1: ps_checkpayment=XYZ', $e->getMessage());
        }
    }

    public function testBuildReadsConfiguration()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', 'fixed');
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', 'GTK');
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MAP', 'ps_checkpayment=PRZ');

        $fixed = IfirmaPaymentMethodResolver::build();
        $this->assertSame('fixed', $fixed->getMode());
        $this->assertSame('GTK', $fixed->getFixedCode());
        $this->assertSame('GTK', $fixed->resolve($this->order('ps_checkpayment')));

        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', 'order');
        $this->assertSame('PRZ', IfirmaPaymentMethodResolver::build()->resolve($this->order('ps_checkpayment')));
    }

    public function testBuildIgnoresUnparsableMap()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', 'order');
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MAP', 'ps_checkpayment=XYZ');

        $this->assertSame('CZK', IfirmaPaymentMethodResolver::build()->resolve($this->order('ps_checkpayment')));
    }
}
