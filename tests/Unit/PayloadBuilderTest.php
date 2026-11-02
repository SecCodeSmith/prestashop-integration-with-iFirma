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
    /** @var IfirmaReceiptService */
    private $service;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
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
        $p = $this->service->buildInvoicePayload($o);
        $this->assertSame('POB', $p['SposobZaplaty']);
    }

    public function testFixedModeUsesConfiguredCode()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', 'fixed');
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', 'GTK');
        $o = $this->order();
        $o->module = 'ps_cashondelivery';
        $p = $this->service->buildInvoicePayload($o);
        $this->assertSame('GTK', $p['SposobZaplaty']);
    }

    public function testIdentifierScheme()
    {
        $p = $this->service->buildInvoicePayload($this->order());
        $this->assertSame('PS-C12345', $p['Kontrahent']['Identyfikator']);
    }

    public function testGuestCustomerSameScheme()
    {
        $g = new Customer();
        $g->id = 99;
        $g->is_guest = 1;
        $g->email = 'guest@example.test';
        Customer::register($g);
        $p = $this->service->buildInvoicePayload($this->order(99));
        $this->assertSame('PS-C99', $p['Kontrahent']['Identyfikator']);
    }

    public function testIdentifierCappedAt15()
    {
        $p = $this->service->buildInvoicePayload($this->order(PHP_INT_MAX));
        $this->assertSame(15, strlen($p['Kontrahent']['Identyfikator']));
        $this->assertSame(substr('PS-C' . PHP_INT_MAX, 0, 15), $p['Kontrahent']['Identyfikator']);
    }

    public function testLongNamesTruncated()
    {
        Address::$instances[7]->firstname = str_repeat('A', 200);
        Address::$instances[7]->lastname = '';
        $o = $this->order();
        $o->details[0]['product_name'] = str_repeat('P', 600);
        $p = $this->service->buildInvoicePayload($o);
        $this->assertSame(150, mb_strlen($p['Kontrahent']['Nazwa']));
        $this->assertSame(500, mb_strlen($p['Pozycje'][0]['NazwaPelna']));
    }

    public function testDiacriticsTruncationCountsCharacters()
    {
        Address::$instances[7]->firstname = str_repeat('ł', 160);
        Address::$instances[7]->lastname = '';
        $p = $this->service->buildInvoicePayload($this->order());
        $this->assertSame(150, mb_strlen($p['Kontrahent']['Nazwa'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($p['Kontrahent']['Nazwa'], 'UTF-8'));
        $this->assertSame(str_repeat('ł', 150), $p['Kontrahent']['Nazwa']);
        $this->assertNotFalse(json_encode($p));
    }

    public function testWithinLimitUnchanged()
    {
        $p = $this->service->buildInvoicePayload($this->order());
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
        $this->assertSame('ab', IfirmaReceiptService::truncate('  abc ', 2));
        $this->assertSame('', IfirmaReceiptService::truncate(null, 5));
        $l = IfirmaReceiptService::fieldLimits();
        $this->assertSame(150, $l['Nazwa']);
        $this->assertSame(15, $l['Identyfikator']);
        $this->assertSame(1000, $l['Uwagi']);
    }

    public function testOrderReferenceStamping()
    {
        $p = $this->service->buildInvoicePayload($this->order());
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $p['Uwagi']);
        $this->assertSame(array('Typ' => 'NUMER_ZAMOWIENIA', 'Info' => 'PS-ORDER-15-ABCDEFGHI'), $p['DodatkoweUwagi']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', IfirmaReceiptService::buildOrderReference($this->order()));
    }

    public function testSkipDodatkoweUwagi()
    {
        $p = $this->service->buildInvoicePayload($this->order(), null, array('skip_dodatkowe_uwagi' => true));
        $this->assertArrayNotHasKey('DodatkoweUwagi', $p);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $p['Uwagi']);
    }

    public function testOverrideCompany()
    {
        $k = $this->service->buildContractor($this->order(), array(
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
        $k = $this->service->buildContractor($this->order(), array(
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
        $k = $this->service->buildContractor($this->order());
        $this->assertSame('Firma', $k['Nazwa']);
        $this->assertSame('5250001122', $k['NIP']);
        $this->assertFalse($k['OsobaFizyczna']);

        $a->company = '';
        $k = $this->service->buildContractor($this->order());
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
        $p = $this->service->buildInvoicePayload($o);
        $this->assertSame('BRT', $p['LiczOd']);
        $this->assertSame(0.23, $p['Pozycje'][0]['StawkaVat']);
        $this->assertSame('PRC', $p['Pozycje'][0]['TypStawkiVat']);
        $this->assertSame('ZW', $p['Pozycje'][1]['TypStawkiVat']);
        $this->assertCount(3, $p['Pozycje']);
        $this->assertSame(12.3, $p['Pozycje'][2]['CenaJednostkowa']);
        $this->assertSame(1, $p['Pozycje'][2]['Ilosc']);
        $this->assertSame('2026-03-04', $p['DataSprzedazy']);
    }
}
