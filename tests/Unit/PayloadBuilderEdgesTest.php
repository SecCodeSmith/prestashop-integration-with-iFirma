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
 * The edges of the payload code that PayloadBuilderTest and the golden payloads leave open, pinned
 * before the code moves out of IfirmaReceiptService (refactor-srp-splits 5.3):
 *  - the table of field limits and the limit each contractor field is cut to;
 *  - when a contractor is looked up by NIP (ten digits and nothing else);
 *  - when the buyer of the invoice address is a business (a company and a VAT number or DNI), and the
 *    street of an override;
 *  - the rounding of the shipping price;
 *  - product rows that are not arrays, or carry no values;
 *  - positions that have neither a stored rate nor a price ratio to take the rate from;
 *  - the half-point edge of snapVatRate() and the list of documented rates;
 *  - the translation of the shipping line, which has to stay in the domain of the receipt service, and
 *    the module of the service that does the translating;
 *  - the names a buyer is looked up by, and the cut of a long order reference.
 */
class PayloadBuilderEdgesTest extends TestCase
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

    /**
     * @param string|null $answer what l() answers for every text; null tags the text with [pl]
     * @return Module a translator that remembers which texts it was asked for and in which source
     */
    private function recordingModule($answer = null)
    {
        $module = new class extends Module {
            /** @var array[] one [text, source] per l() call */
            public $asked = array();

            /** @var string|null */
            public $answer;

            public function l($string, $specific = null, $locale = null)
            {
                $this->asked[] = array($string, $specific);

                return $this->answer !== null ? $this->answer : '[pl] ' . $string;
            }
        };
        $module->answer = $answer;

        return $module;
    }

    // ---- field limits ------------------------------------------------------------------------

    public function testTheFieldLimitsAreTheDocumentedMaxima()
    {
        $this->assertSame(array(
            'Nazwa' => 150,
            'Email' => 65,
            'NIP' => 13,
            'Ulica' => 65,
            'Miejscowosc' => 65,
            'KodPocztowy' => 16,
            'Uwagi' => 1000,
            'NazwaPelna' => 500,
            'Identyfikator' => 15,
            'Kraj' => 70,
        ), IfirmaReceiptService::fieldLimits());
    }

    public function testEveryContractorFieldIsCutToItsOwnLimit()
    {
        $a = Address::$instances[7];
        $a->company = str_repeat('C', 200);
        $a->vat_number = str_repeat('1', 20);
        $a->address1 = str_repeat('S', 70);
        $a->address2 = '';
        $a->postcode = str_repeat('9', 20);
        $a->city = str_repeat('M', 70);
        Customer::$instances[12345]->email = str_repeat('e', 70);
        Country::$instances[14]->name = str_repeat('K', 80);

        $k = $this->service->buildContractor($this->order());

        $this->assertSame(str_repeat('C', 150), $k['Nazwa']);
        $this->assertSame(str_repeat('1', 13), $k['NIP']);
        $this->assertSame(str_repeat('S', 65), $k['Ulica']);
        $this->assertSame(str_repeat('9', 16), $k['KodPocztowy']);
        $this->assertSame(str_repeat('K', 70), $k['Kraj']);
        $this->assertSame(str_repeat('M', 65), $k['Miejscowosc']);
        $this->assertSame(str_repeat('e', 65), $k['Email']);
    }

    public function businessBuyers()
    {
        return array(
            'a company and a VAT number' => array('Firma', '525-000-11-22', '', 'Firma', '5250001122', false),
            'a company and a DNI' => array('Firma', '', '5250001122', 'Firma', '5250001122', false),
            'a company without a VAT number' => array('Firma', '', '', 'Jan Kowalski', '', true),
            'a VAT number without a company' => array('', '525-000-11-22', '', 'Jan Kowalski', '', true),
            'a blank company and a blank VAT number' => array('  ', ' ', '', 'Jan Kowalski', '', true),
            'neither' => array('', '', '', 'Jan Kowalski', '', true),
        );
    }

    /**
     * @dataProvider businessBuyers
     */
    public function testTheBuyerOfTheInvoiceAddressIsABusinessOnlyWithACompanyAndAVatNumber($company, $vat, $dni, $name, $nip, $person)
    {
        $a = Address::$instances[7];
        $a->company = $company;
        $a->vat_number = $vat;
        $a->dni = $dni;

        $k = $this->service->buildContractor($this->order());

        $this->assertSame($name, $k['Nazwa']);
        $this->assertSame($nip, $k['NIP']);
        $this->assertSame($person, $k['OsobaFizyczna']);
    }

    public function testTheStreetOfAnOverrideJoinsBothAddressLines()
    {
        $k = $this->service->buildContractor($this->order(), array(
            'want_invoice_for_company' => false,
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Hauptstr. 5',
            'address2' => 'm. 7',
            'postcode' => '10115',
            'city' => 'Berlin',
            'id_country' => 1,
        ));

        $this->assertSame('Hauptstr. 5 m. 7', $k['Ulica']);
        $this->assertSame('Anna Nowak', $k['Nazwa']);
    }

    public function testTheCountryCodeIsCutToTwoCharactersAndTheNameFallsBackToTheFullCode()
    {
        $three = new Country();
        $three->id = 2;
        $three->iso_code = 'deu';
        Country::register($three);
        Address::$instances[7]->id_country = 2;

        $k = $this->service->buildContractor($this->order());

        $this->assertSame('DE', $k['KodKraju']);
        $this->assertSame('DEU', $k['Kraj']);
    }

    public function testALongOrderReferenceIsCutInUwagiButNotInTheOrderNumberNote()
    {
        $reference = 'PS-ORDER-15-' . str_repeat('R', 1100);

        $p = $this->service->buildInvoicePayload($this->order(12345, 15, str_repeat('R', 1100)));

        $this->assertSame(1000, mb_strlen($p['Uwagi'], 'UTF-8'));
        $this->assertSame(substr($reference, 0, 1000), $p['Uwagi']);
        $this->assertSame($reference, $p['DodatkoweUwagi']['Info']);
    }

    public function testThePaidAmountIsRoundedToTwoDecimalsWhateverTheModeReads()
    {
        $o = $this->order();
        $o->total_paid_tax_incl = 44.446;
        $o->totalPaidReal = 33.337;

        $p = $this->service->buildInvoicePayload($o);
        $this->assertSame(33.34, $p['Zaplacono']);
        $this->assertSame(33.34, $p['ZaplaconoNaDokumencie']);

        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', 1);
        $p = $this->service->buildInvoicePayload($o);
        $this->assertSame(44.45, $p['Zaplacono']);
        $this->assertSame(44.45, $p['ZaplaconoNaDokumencie']);
    }

    public function testAShippingPriceIsRoundedToTwoDecimals()
    {
        $o = $this->order();
        $o->total_shipping_tax_incl = 12.344;
        $o->total_shipping_tax_excl = 10.00;

        $p = $this->service->buildInvoicePayload($o);

        $this->assertCount(2, $p['Pozycje']);
        $this->assertSame(12.34, $p['Pozycje'][1]['CenaJednostkowa']);
    }

    // ---- the contractor lookup by NIP --------------------------------------------------------

    public function nipInputs()
    {
        return array(
            'ten digits' => array('5250001122', '5250001122'),
            'ten digits with separators' => array('525-000-11-22', '5250001122'),
            'ten digits with spaces' => array(' 525 000 11 22 ', '5250001122'),
            'nine digits' => array('525000112', null),
            'eleven digits' => array('52500011223', null),
            'fourteen digits, cut to the NIP limit' => array('52500011223344', null),
            'ten digits and a country prefix' => array('PL5250001122', null),
            'nine digits and a letter' => array('525000112X', null),
        );
    }

    /**
     * @dataProvider nipInputs
     */
    public function testTheContractorIsLookedUpByNipOnlyForTenDigitsAndNothingElse($vat, $expected)
    {
        $a = Address::$instances[7];
        $a->company = 'Firma';
        $a->vat_number = $vat;

        $p = $this->service->buildInvoicePayload($this->order());

        $this->assertSame($expected, $p['NIPKontrahenta']);
    }

    public function testAPrivateBuyerIsNeverLookedUpByNip()
    {
        $a = Address::$instances[7];
        $a->company = '';
        $a->vat_number = '5250001122';

        $p = $this->service->buildInvoicePayload($this->order());

        $this->assertSame('', $p['Kontrahent']['NIP']);
        $this->assertNull($p['NIPKontrahenta']);
    }

    // ---- product lines -----------------------------------------------------------------------

    public function testRowsThatAreNotArraysAreNotProductLines()
    {
        $o = $this->order();
        $o->details = array('junk', array(
            'product_name' => 'First',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 10.00,
            'unit_price_tax_excl' => 10.00,
            'tax_rate' => 0,
        ), null, 5, array(
            'product_name' => 'Second',
            'product_quantity' => 3,
            'unit_price_tax_incl' => 20.00,
            'unit_price_tax_excl' => 20.00,
            'tax_rate' => 0,
        ));

        $names = array();
        foreach (IfirmaReceiptService::orderProductLines($o) as $line) {
            $names[] = $line['name'];
        }
        $this->assertSame(array('First', 'Second'), $names);

        $p = $this->service->buildInvoicePayload($o);
        $this->assertCount(2, $p['Pozycje']);
        $this->assertSame('Second', $p['Pozycje'][1]['NazwaPelna']);
        $this->assertSame(3.0, $p['Pozycje'][1]['Ilosc']);
    }

    public function testARowWithoutValuesDefaultsToAnEmptyNameAndZeros()
    {
        $o = $this->order();
        $o->details = array(array('product_name' => '  Only a name  '), array());
        $zero = array(
            'name' => '',
            'quantity' => 0.0,
            'unit_price_tax_incl' => 0.0,
            'unit_price_tax_excl' => 0.0,
            'tax_rate' => null,
        );

        $this->assertSame(
            array(array_merge($zero, array('name' => 'Only a name')), $zero),
            IfirmaReceiptService::orderProductLines($o)
        );
    }

    // ---- VAT rates ---------------------------------------------------------------------------

    public function testPositionsWithoutAStoredRateOrAUsablePriceRatioAreZeroRated()
    {
        $o = $this->order();
        $o->details = array(
            array('product_name' => 'No net price', 'product_quantity' => 1, 'unit_price_tax_incl' => 10.00, 'unit_price_tax_excl' => 0.00),
            array('product_name' => 'No gross price', 'product_quantity' => 1, 'unit_price_tax_incl' => 0.00, 'unit_price_tax_excl' => 10.00),
            array('product_name' => 'No prices', 'product_quantity' => 1),
            array('product_name' => 'A rate that is no number', 'product_quantity' => 1, 'unit_price_tax_incl' => 108.00, 'unit_price_tax_excl' => 100.00, 'tax_rate' => 'n/a'),
        );
        $o->total_shipping_tax_incl = 5.00;
        $o->total_shipping_tax_excl = 0.00;
        $o->carrier_tax_rate = null;

        $positions = $this->service->buildInvoicePayload($o)['Pozycje'];

        $this->assertCount(5, $positions);
        $this->assertSame(0.0, $positions[0]['StawkaVat']);
        $this->assertSame(0.0, $positions[1]['StawkaVat']);
        $this->assertSame(0.0, $positions[2]['StawkaVat']);
        // not a number: the rate comes from the ratio of the prices instead
        $this->assertSame(0.08, $positions[3]['StawkaVat']);
        // the shipping has a gross price, no net price and no carrier rate
        $this->assertSame(5.0, $positions[4]['CenaJednostkowa']);
        $this->assertSame(0.0, $positions[4]['StawkaVat']);
    }

    public function testARateTakenFromThePricesKeepsTwoDecimalsWhenItIsNoDocumentedOne()
    {
        $o = $this->order();
        $o->details = array(array(
            'product_name' => 'Odd ratio',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 106.75,
            'unit_price_tax_excl' => 100.00,
        ));

        $positions = $this->service->buildInvoicePayload($o)['Pozycje'];

        $this->assertSame(0.0675, $positions[0]['StawkaVat']);
    }

    public function testAStoredRateIsSnappedToTheDocumentedOneToo()
    {
        $o = $this->order();
        $o->details = array(array(
            'product_name' => 'Stored near 23',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 123.00,
            'unit_price_tax_excl' => 100.00,
            'tax_rate' => 22.8,
        ));

        $positions = $this->service->buildInvoicePayload($o)['Pozycje'];

        $this->assertSame(0.23, $positions[0]['StawkaVat']);
    }

    public function testTheDocumentedVatRatesAreZeroFiveEightAndTwentyThree()
    {
        $this->assertSame(array(0.0, 5.0, 8.0, 23.0), IfirmaReceiptService::allowedVatRates());
    }

    public function testAMiddleRateSnapsToTheDocumentedOneUpToHalfAPointAway()
    {
        foreach (array(
            '0.5' => 0.0,
            '-0.5' => 0.0,
            '5.5' => 5.0,
            '7.5' => 8.0,
            '8.5' => 8.0,
            '22.5' => 23.0,
            '23.5' => 23.0,
        ) as $rate => $snapped) {
            $this->assertSame($snapped, IfirmaReceiptService::snapVatRate((float) $rate), 'rate ' . $rate);
        }
    }

    public function testARateMoreThanHalfAPointAwayFromEveryDocumentedOneIsLeftAlone()
    {
        foreach (array(0.51, 5.51, 6.5, 8.51, 15.0, 23.51, -0.51) as $rate) {
            $this->assertSame($rate, IfirmaReceiptService::snapVatRate($rate), 'rate ' . $rate);
        }
    }

    public function testASnappedRateIsAFloatWhateverTheInput()
    {
        $this->assertSame(8.0, IfirmaReceiptService::snapVatRate('8.2'));
        $this->assertSame(23.0, IfirmaReceiptService::snapVatRate(23));
        $this->assertSame(6.7, IfirmaReceiptService::snapVatRate('6.7'));
    }

    // ---- the shipping line -------------------------------------------------------------------

    public function testTheShippingLineIsNamedByTheReceiptServiceTranslation()
    {
        $module = $this->recordingModule();
        $service = new IfirmaReceiptService(new IfirmaApiClient('u', array()), $module);
        $o = $this->order();
        $o->total_shipping_tax_incl = 12.30;
        $o->total_shipping_tax_excl = 10.00;

        $p = $service->buildInvoicePayload($o);

        $this->assertCount(2, $p['Pozycje']);
        $this->assertSame('[pl] Shipping', $p['Pozycje'][1]['NazwaPelna']);
        $this->assertSame(array(array('Shipping', 'ifirmareceiptservice')), $module->asked);
    }

    public function testIssuingTheDocumentTranslatesTheShippingLineWithTheModuleOfTheService()
    {
        $module = $this->recordingModule();
        $t = new ScriptedTransport();
        $t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","PelnyNumer":"7/2026"}}'));
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $t);
        $service = new IfirmaReceiptService($client, $module);
        $o = $this->order();
        $o->total_shipping_tax_incl = 12.30;
        $o->total_shipping_tax_excl = 10.00;

        $service->createReceipt($o, array('skip_contractor_sync' => true));

        $this->assertSame(array(array('Shipping', 'ifirmareceiptservice')), $module->asked);
        $sent = json_decode($t->calls[0]['body'], true);
        $this->assertCount(2, $sent['Pozycje']);
        $this->assertSame('[pl] Shipping', $sent['Pozycje'][1]['NazwaPelna']);
    }

    public function testAnOrderWithoutShippingNeverAsksTheTranslator()
    {
        $module = $this->recordingModule();
        $service = new IfirmaReceiptService(new IfirmaApiClient('u', array()), $module);

        $p = $service->buildInvoicePayload($this->order());

        $this->assertCount(1, $p['Pozycje']);
        $this->assertSame(array(), $module->asked);
    }

    public function testTheTranslatedShippingNameIsTrimmedAndCutToTheNameLimit()
    {
        $module = $this->recordingModule('  ' . str_repeat('ł', 600));
        $service = new IfirmaReceiptService(new IfirmaApiClient('u', array()), $module);
        $o = $this->order();
        $o->total_shipping_tax_incl = 12.30;
        $o->total_shipping_tax_excl = 10.00;

        $p = $service->buildInvoicePayload($o);

        $this->assertSame(str_repeat('ł', 500), $p['Pozycje'][1]['NazwaPelna']);
    }

    // ---- the names a buyer is looked up by ---------------------------------------------------

    public function testBuyerNamesLeaveOutABlankCompanyAndABlankPerson()
    {
        $a = Address::$instances[7];
        $a->company = '   ';
        $a->firstname = '';
        $a->lastname = '';
        $this->assertSame(array(), IfirmaReceiptService::buyerNames($this->order()));

        $a->company = ' ACME ';
        $this->assertSame(array('ACME'), IfirmaReceiptService::buyerNames($this->order()));
    }

    public function testBuyerNamesJoinWhateverPartOfThePersonNameThereIs()
    {
        $a = Address::$instances[7];
        $a->lastname = '';
        $this->assertSame(array('Jan'), IfirmaReceiptService::buyerNames($this->order()));

        $a->firstname = '';
        $a->lastname = 'Kowalski';
        $this->assertSame(array('Kowalski'), IfirmaReceiptService::buyerNames($this->order()));

        $a->firstname = ' Jan ';
        $a->lastname = ' Kowalski ';
        $this->assertSame(array('Jan Kowalski'), IfirmaReceiptService::buyerNames($this->order()));
    }

    public function testBuyerNamesAreCutToTheNameLimit()
    {
        $a = Address::$instances[7];
        $a->company = str_repeat('C', 200);
        $a->firstname = str_repeat('J', 200);
        $a->lastname = '';

        $this->assertSame(
            array(str_repeat('C', 150), str_repeat('J', 150)),
            IfirmaReceiptService::buyerNames($this->order())
        );
    }
}
