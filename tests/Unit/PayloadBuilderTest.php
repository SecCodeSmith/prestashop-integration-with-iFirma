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

class PayloadBuilderTest extends TestCase
{
    /** @var IfirmaPayloadBuilder */
    private $builder;

    /** @var IfirmaReceiptService only for getDocumentType(), which stays with the service */
    private $service;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->builder = IfirmaPayloadBuilder::build(new Module());
        $this->service = new IfirmaReceiptService(new IfirmaApiClient('u', array()), new Module());

        $c = new Customer();
        $c->id = 12345;
        $c->email = 'jan@example.test';
        Customer::register($c);

        $pl = new Country();
        $pl->id = 14;
        $pl->iso_code = 'pl';
        Country::register($pl);
        $de = new Country();
        $de->id = 1;
        $de->iso_code = 'de';
        Country::register($de);

        $a = new Address();
        $a->id = 7;
        $a->firstname = 'Jan';
        $a->lastname = 'Kowalski';
        $a->address1 = 'Długa 1';
        $a->address2 = 'm. 2';
        $a->postcode = '00-001';
        $a->city = 'Warszawa';
        $a->id_country = 14;
        Address::register($a);
    }

    private function order($idCustomer = 12345, $id = 15, $reference = 'ABCDEFGHI')
    {
        $o = new Order();
        $o->id = $id;
        $o->id_customer = $idCustomer;
        $o->id_address_invoice = 7;
        $o->reference = $reference;
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

    public function testPaymentMethodFollowsOrderModule()
    {
        $o = $this->order();
        $o->module = 'ps_cashondelivery';
        $p = $this->builder->buildInvoicePayload($o);
        $this->assertSame('POB', $p['SposobZaplaty']);
    }

    public function testFixedModeUsesConfiguredCode()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', 'fixed');
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', 'GTK');
        $o = $this->order();
        $o->module = 'ps_cashondelivery';
        $p = $this->builder->buildInvoicePayload($o);
        $this->assertSame('GTK', $p['SposobZaplaty']);
    }

    public function testIdentifierScheme()
    {
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('PS-C12345', $p['Kontrahent']['Identyfikator']);
    }

    public function testGuestCustomerSameScheme()
    {
        $g = new Customer();
        $g->id = 99;
        $g->is_guest = 1;
        $g->email = 'guest@example.test';
        Customer::register($g);
        $p = $this->builder->buildInvoicePayload($this->order(99));
        $this->assertSame('PS-C99', $p['Kontrahent']['Identyfikator']);
    }

    public function testIdentifierCappedAt15()
    {
        $p = $this->builder->buildInvoicePayload($this->order(PHP_INT_MAX));
        $this->assertSame(15, strlen($p['Kontrahent']['Identyfikator']));
        $this->assertSame(substr('PS-C' . PHP_INT_MAX, 0, 15), $p['Kontrahent']['Identyfikator']);
    }

    public function testLongNamesTruncated()
    {
        Address::$instances[7]->firstname = str_repeat('A', 200);
        Address::$instances[7]->lastname = '';
        $o = $this->order();
        $o->details[0]['product_name'] = str_repeat('P', 600);
        $p = $this->builder->buildInvoicePayload($o);
        $this->assertSame(150, mb_strlen($p['Kontrahent']['Nazwa']));
        $this->assertSame(500, mb_strlen($p['Pozycje'][0]['NazwaPelna']));
    }

    public function testDiacriticsTruncationCountsCharacters()
    {
        Address::$instances[7]->firstname = str_repeat('ł', 160);
        Address::$instances[7]->lastname = '';
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame(150, mb_strlen($p['Kontrahent']['Nazwa'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($p['Kontrahent']['Nazwa'], 'UTF-8'));
        $this->assertSame(str_repeat('ł', 150), $p['Kontrahent']['Nazwa']);
        $this->assertNotFalse(json_encode($p));
    }

    public function testWithinLimitUnchanged()
    {
        $p = $this->builder->buildInvoicePayload($this->order());
        $k = $p['Kontrahent'];
        $this->assertSame('Jan Kowalski', $k['Nazwa']);
        $this->assertSame('Długa 1 m. 2', $k['Ulica']);
        $this->assertSame('00-001', $k['KodPocztowy']);
        $this->assertSame('Warszawa', $k['Miejscowosc']);
        $this->assertSame('jan@example.test', $k['Email']);
        $this->assertSame('PL', $k['Kraj']);
        $this->assertSame('Widget', $p['Pozycje'][0]['NazwaPelna']);
    }

    public function testTruncateHelperAndLimits()
    {
        $this->assertSame('ab', IfirmaPayloadBuilder::truncate('  abc ', 2));
        $this->assertSame('', IfirmaPayloadBuilder::truncate(null, 5));
        $l = IfirmaPayloadBuilder::fieldLimits();
        $this->assertSame(150, $l['Nazwa']);
        $this->assertSame(15, $l['Identyfikator']);
        $this->assertSame(1000, $l['Uwagi']);
    }

    public function testOrderReferenceStamping()
    {
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $p['Uwagi']);
        $this->assertSame(array('Typ' => 'NUMER_ZAMOWIENIA', 'Info' => 'PS-ORDER-15-ABCDEFGHI'), $p['DodatkoweUwagi']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', IfirmaPayloadBuilder::buildOrderReference($this->order()));
    }

    public function testSkipDodatkoweUwagi()
    {
        $p = $this->builder->buildInvoicePayload($this->order(), null, array('skip_dodatkowe_uwagi' => true));
        $this->assertArrayNotHasKey('DodatkoweUwagi', $p);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $p['Uwagi']);
    }

    public function testOverrideCompany()
    {
        $k = $this->builder->buildContractor($this->order(), array(
            'want_invoice_for_company' => true,
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => 'PL 123-456-78-90',
            'firstname' => 'X',
            'lastname' => 'Y',
            'address1' => 'Hauptstr. 5',
            'address2' => '',
            'postcode' => '10115',
            'city' => 'Berlin',
            'id_country' => 1,
        ));
        $this->assertSame('ACME Sp. z o.o.', $k['Nazwa']);
        $this->assertSame('PL1234567890', $k['NIP']);
        $this->assertFalse($k['OsobaFizyczna']);
        $this->assertSame('Hauptstr. 5', $k['Ulica']);
        $this->assertSame('10115', $k['KodPocztowy']);
        $this->assertSame('Berlin', $k['Miejscowosc']);
        $this->assertSame('DE', $k['Kraj']);
        $this->assertSame('jan@example.test', $k['Email']);
        $this->assertSame('PS-C12345', $k['Identyfikator']);
    }

    public function testOverridePrivate()
    {
        $k = $this->builder->buildContractor($this->order(), array(
            'want_invoice_for_company' => false,
            'company' => 'Ignored',
            'vat_number' => '123',
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Polna 3',
            'postcode' => '11-111',
            'city' => 'Kraków',
            'id_country' => 999,
        ));
        $this->assertSame('Anna Nowak', $k['Nazwa']);
        $this->assertSame('', $k['NIP']);
        $this->assertTrue($k['OsobaFizyczna']);
        $this->assertSame('PL', $k['Kraj']);
        $this->assertSame('Polna 3', $k['Ulica']);
    }

    public function testNoOverrideUsesInvoiceAddress()
    {
        $a = Address::$instances[7];
        $a->company = 'Firma';
        $a->vat_number = '525-000-11-22';
        $k = $this->builder->buildContractor($this->order());
        $this->assertSame('Firma', $k['Nazwa']);
        $this->assertSame('5250001122', $k['NIP']);
        $this->assertFalse($k['OsobaFizyczna']);

        $a->company = '';
        $k = $this->builder->buildContractor($this->order());
        $this->assertSame('Jan Kowalski', $k['Nazwa']);
        $this->assertTrue($k['OsobaFizyczna']);
    }

    public function testShippingVatAndLiczOd()
    {
        $o = $this->order();
        $o->total_shipping_tax_incl = 12.30;
        $o->total_shipping_tax_excl = 10.00;
        $o->details[] = array(
            'product_name' => 'Book',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 10.00,
            'unit_price_tax_excl' => 10.00,
            'tax_rate' => 0,
        );
        $p = $this->builder->buildInvoicePayload($o);
        $this->assertSame('BRT', $p['LiczOd']);
        $this->assertSame(0.23, $p['Pozycje'][0]['StawkaVat']);
        $this->assertSame('PRC', $p['Pozycje'][0]['TypStawkiVat']);
        // 0% lines are PRC too: ZW (exempt) would need a PKWiU code per position
        $this->assertSame('PRC', $p['Pozycje'][1]['TypStawkiVat']);
        $this->assertSame(0.0, $p['Pozycje'][1]['StawkaVat']);
        $this->assertCount(3, $p['Pozycje']);
        $this->assertSame(12.3, $p['Pozycje'][2]['CenaJednostkowa']);
        $this->assertSame(1, $p['Pozycje'][2]['Ilosc']);
        $this->assertSame('2026-03-04', $p['DataSprzedazy']);
    }

    public function testDocumentTypeNormalisesToFakturakraj()
    {
        $this->assertSame('fakturakraj', IfirmaDocumentSettings::normalizeDocumentType('fakturakraj'));
        $this->assertSame('fakturakraj', IfirmaDocumentSettings::normalizeDocumentType('fakturaparagon'));
        $this->assertSame('fakturakraj', IfirmaDocumentSettings::normalizeDocumentType(''));
        $this->assertSame('fakturakraj', IfirmaDocumentSettings::normalizeDocumentType(null));
        $this->assertSame('fakturakraj', IfirmaDocumentSettings::normalizeDocumentType('something-else'));
    }

    public function testOnlyFakturakrajIsEnabled()
    {
        $this->assertSame(array('fakturakraj'), array_keys(IfirmaDocumentSettings::enabledDocumentTypes()));
    }

    public function testStoredParagonSettingReadsAsFakturakraj()
    {
        Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturaparagon');
        $this->assertSame('fakturakraj', $this->service->getDocumentType());
    }

    public function testZeroPercentLineIsPercentageRate()
    {
        $o = $this->order();
        $o->details = array(array(
            'product_name' => 'Zero rated',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 10.00,
            'unit_price_tax_excl' => 10.00,
            'tax_rate' => 0,
        ));
        $p = $this->builder->buildInvoicePayload($o);
        $this->assertSame('PRC', $p['Pozycje'][0]['TypStawkiVat']);
        $this->assertSame(0.0, $p['Pozycje'][0]['StawkaVat']);
    }

    public function testShippingWithZeroTaxIsPercentageRate()
    {
        $o = $this->order();
        $o->total_shipping_tax_incl = 10.00;
        $o->total_shipping_tax_excl = 10.00;
        $o->carrier_tax_rate = 0;
        $p = $this->builder->buildInvoicePayload($o);
        $last = $p['Pozycje'][count($p['Pozycje']) - 1];
        $this->assertSame('PRC', $last['TypStawkiVat']);
        $this->assertSame(0.0, $last['StawkaVat']);
    }

    public function testEightPercentLineIsPercentageRate()
    {
        $o = $this->order();
        $o->details = array(array(
            'product_name' => 'Food',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 108.00,
            'unit_price_tax_excl' => 100.00,
            'tax_rate' => 8,
        ));
        $p = $this->builder->buildInvoicePayload($o);
        $this->assertSame('PRC', $p['Pozycje'][0]['TypStawkiVat']);
        $this->assertSame(0.08, $p['Pozycje'][0]['StawkaVat']);
    }

    public function testSnapVatRateToDocumentedRates()
    {
        $this->assertSame(8.0, IfirmaPayloadBuilder::snapVatRate(7.99));
        $this->assertSame(23.0, IfirmaPayloadBuilder::snapVatRate(23.2));
        $this->assertSame(5.0, IfirmaPayloadBuilder::snapVatRate(4.6));
        $this->assertSame(7.0, IfirmaPayloadBuilder::snapVatRate(7.0));
        $this->assertSame(0.0, IfirmaPayloadBuilder::snapVatRate(0.3));
    }

    public function testStoredLineRateBeatsRoundingNoise()
    {
        $o = $this->order();
        $o->details = array(array(
            'product_name' => 'Noisy',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 107.99,
            'unit_price_tax_excl' => 100.00,
            'tax_rate' => '8.000',
        ));
        $p = $this->builder->buildInvoicePayload($o);
        $this->assertSame(0.08, $p['Pozycje'][0]['StawkaVat']);
    }

    public function testSignatureTypeDefaultsToBpo()
    {
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('BPO', $p['RodzajPodpisuOdbiorcy']);
    }

    public function testConfiguredSignatureTypeIsNormalised()
    {
        Configuration::updateValue('IFIRMA_SIGNATURE_TYPE', ' bwo ');
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('BWO', $p['RodzajPodpisuOdbiorcy']);
    }

    public function testRemovedBrkValueFallsBackToBpo()
    {
        Configuration::updateValue('IFIRMA_SIGNATURE_TYPE', 'BRK');
        $p = $this->builder->buildInvoicePayload($this->order());
        $this->assertSame('BPO', $p['RodzajPodpisuOdbiorcy']);
    }

    public function testSignatureTypesAreTheFourAcceptedCodes()
    {
        $this->assertSame(array('BPO', 'BWO', 'OUP', 'UPO'), array_keys(IfirmaDocumentSettings::signatureTypes()));
    }

    public function testEmptyOrNullSignatureTypeFallsBackToBpo()
    {
        $this->assertSame('BPO', IfirmaDocumentSettings::normalizeSignatureType(null));
        $this->assertSame('BPO', IfirmaDocumentSettings::normalizeSignatureType(''));
        $this->assertSame('OUP', IfirmaDocumentSettings::normalizeSignatureType('oup'));
    }

    public function testOrderSaleDateIsTheOrderDate()
    {
        $o = $this->order();
        $this->assertSame('2026-03-04', IfirmaPayloadBuilder::orderSaleDate($o));
        $this->assertSame('2026-03-04', $this->builder->buildInvoicePayload($o)['DataSprzedazy']);
        $o->date_add = '';
        $this->assertSame(date('Y-m-d'), IfirmaPayloadBuilder::orderSaleDate($o));
    }

    public function testOrderProductLinesCarryTruncatedNameAndFloatQuantity()
    {
        $o = $this->order();
        $o->details[] = array(
            'product_name' => str_repeat('x', 600),
            'product_quantity' => '3',
            'unit_price_tax_incl' => 10,
            'unit_price_tax_excl' => 10,
        );
        $lines = IfirmaPayloadBuilder::orderProductLines($o);
        $this->assertCount(2, $lines);
        $this->assertSame('Widget', $lines[0]['name']);
        $this->assertSame(2.0, $lines[0]['quantity']);
        $this->assertSame(23, $lines[0]['tax_rate']);
        $this->assertSame(500, mb_strlen($lines[1]['name'], 'UTF-8'));
        $this->assertSame(3.0, $lines[1]['quantity']);
        $this->assertNull($lines[1]['tax_rate']);

        $p = $this->builder->buildInvoicePayload($o);
        $this->assertCount(2, $p['Pozycje']);
        $this->assertSame('Widget', $p['Pozycje'][0]['NazwaPelna']);
        $this->assertSame(2.0, $p['Pozycje'][0]['Ilosc']);
        $this->assertSame(500, mb_strlen($p['Pozycje'][1]['NazwaPelna'], 'UTF-8'));
    }

    public function testBuyerNamesBusinessAndPrivate()
    {
        $this->assertSame(array('Jan Kowalski'), IfirmaPayloadBuilder::buyerNames($this->order()));

        $a = new Address();
        $a->id = 7;
        $a->firstname = 'Jan';
        $a->lastname = 'Kowalski';
        $a->company = ' ACME Sp. z o.o. ';
        $a->vat_number = '1234567890';
        $a->id_country = 14;
        Address::register($a);
        $this->assertSame(array('ACME Sp. z o.o.', 'Jan Kowalski'), IfirmaPayloadBuilder::buyerNames($this->order()));
    }
}
