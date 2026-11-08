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
 * Document template name, order stamp prefix and shop webpage in the payload. Kept apart from
 * PayloadBuilderTest, whose golden ledger expects a recorded payload per test.
 */
class PayloadBuilderStampTest extends TestCase
{
    /** @var IfirmaPayloadBuilder */
    private $builder;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->builder = IfirmaPayloadBuilder::build(new Module());

        $c = new Customer();
        $c->id = 12345;
        $c->email = 'jan@example.test';
        Customer::register($c);

        $pl = new Country();
        $pl->id = 14;
        $pl->iso_code = 'pl';
        Country::register($pl);

        $a = new Address();
        $a->id = 7;
        $a->firstname = 'Jan';
        $a->lastname = 'Kowalski';
        $a->address1 = 'Długa 1';
        $a->postcode = '00-001';
        $a->city = 'Warszawa';
        $a->id_country = 14;
        Address::register($a);
    }

    private function order()
    {
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 12345;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = '2026-03-04 10:00:00';
        $o->details = array(array(
            'product_name' => 'Widget',
            'product_quantity' => 2,
            'unit_price_tax_incl' => 123.00,
            'unit_price_tax_excl' => 100.00,
            'tax_rate' => 23,
        ));

        return $o;
    }

    public function testTemplateNameIsSentWhenSet()
    {
        Configuration::updateValue('IFIRMA_DOC_TEMPLATE', '  Szablon SC Medical ');
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('Szablon SC Medical', $p['NazwaSzablonu']);
    }

    public function testTemplateNameIsInheritedByTheRetryPayloads()
    {
        Configuration::updateValue('IFIRMA_DOC_TEMPLATE', 'Szablon SC Medical');
        $withoutNote = $this->builder->buildInvoicePayload($this->order(), null, array('skip_dodatkowe_uwagi' => true));
        $this->assertArrayNotHasKey('DodatkoweUwagi', $withoutNote);
        $this->assertSame('Szablon SC Medical', $withoutNote['NazwaSzablonu']);

        // the contractor-reference retry clears IdentyfikatorKontrahenta on the payload it already has
        $withoutReference = $withoutNote;
        $withoutReference['IdentyfikatorKontrahenta'] = null;
        $this->assertSame('Szablon SC Medical', $withoutReference['NazwaSzablonu']);
    }

    public function testTemplateNameIsAbsentWhenEmpty()
    {
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertArrayNotHasKey('NazwaSzablonu', $p);
        Configuration::updateValue('IFIRMA_DOC_TEMPLATE', '   ');
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertArrayNotHasKey('NazwaSzablonu', $p);
    }

    public function testDefaultPrefixKeepsTheStamp()
    {
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'PS-ORDER');
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', IfirmaPayloadBuilder::buildOrderReference($this->order()));
        $this->assertSame(array('PS-ORDER-15-ABCDEFGHI'), IfirmaPayloadBuilder::orderReferenceStamps($this->order()));
    }

    public function testConfiguredPrefixIsUsedEverywhere()
    {
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'scmedical');
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('scmedical-15-ABCDEFGHI', $p['Uwagi']);
        $this->assertSame('scmedical-15-ABCDEFGHI', $p['DodatkoweUwagi']['Info']);
        $this->assertSame('scmedical-15-ABCDEFGHI', IfirmaPayloadBuilder::buildOrderReference($this->order()));
    }

    public function testStampsListHasLegacyStampSecondWhenPrefixIsNotDefault()
    {
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'scmedical');
        $this->assertSame(
            array('scmedical-15-ABCDEFGHI', 'PS-ORDER-15-ABCDEFGHI'),
            IfirmaPayloadBuilder::orderReferenceStamps($this->order())
        );
    }

    public function testPrefixIsNormalised()
    {
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', ' scmedical- ');
        $this->assertSame('scmedical-15-ABCDEFGHI', IfirmaPayloadBuilder::buildOrderReference($this->order()));
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', '');
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', IfirmaPayloadBuilder::buildOrderReference($this->order()));
        Configuration::updateValue('IFIRMA_ORDER_STAMP_PREFIX', 'PS-ORDER-');
        $this->assertSame(array('PS-ORDER-15-ABCDEFGHI'), IfirmaPayloadBuilder::orderReferenceStamps($this->order()));
    }

    public function testShopWebpageFollowsTheStampInUwagiOnly()
    {
        Configuration::updateValue('IFIRMA_SHOP_URL', 'https://scmedical.pl');
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('PS-ORDER-15-ABCDEFGHI https://scmedical.pl', $p['Uwagi']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $p['DodatkoweUwagi']['Info']);
    }

    public function testLongWebpageIsCutNeverTheStamp()
    {
        Configuration::updateValue('IFIRMA_SHOP_URL', 'https://scmedical.pl/' . str_repeat('a', 1200));
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame(1000, mb_strlen($p['Uwagi'], 'UTF-8'));
        $this->assertSame('PS-ORDER-15-ABCDEFGHI https://scmedical.pl/', substr($p['Uwagi'], 0, 43));
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $p['DodatkoweUwagi']['Info']);
    }

    public function testEmptyWebpageLeavesTheStampOnly()
    {
        Configuration::updateValue('IFIRMA_SHOP_URL', '  ');
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $p['Uwagi']);
    }
}
