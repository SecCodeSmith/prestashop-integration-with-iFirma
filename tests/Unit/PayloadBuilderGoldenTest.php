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
 * Golden comparison of the requests createReceipt() sends: the payload scenarios of PayloadBuilderTest,
 * each run again with the same shop and order but through createReceipt(), so that what is compared is
 * what goes over the wire, with the fixture under tests/fixtures/payloads that was captured from
 * IfirmaReceiptService before createReceipt() was split (refactor-srp-splits 5.1).
 *
 * PayloadBuilderTest asks buildInvoicePayload() and buildContractor() for the payload; the golden asks
 * the service to issue the document and reads the body the transport received, so it stays valid
 * when the payload is built elsewhere. Every test has the name of the PayloadBuilderTest test whose
 * scenario it repeats; see IfirmaGoldenPayloads for what a fixture holds. The contractor identifier of
 * the scenarios that pin its scheme reaches the wire as IdentyfikatorKontrahenta, which needs the
 * contractor synchronisation on and iFirma answering the contractor calls; the others switch it off.
 *
 * The scenarios after testOrderProductLinesCarryTruncatedNameAndFloatQuantity have no PayloadBuilderTest
 * counterpart. They pin what the payload carries and no test asserted: the paid amount, a configured
 * unit, slashes, and a VAT rate that is off the documented list or derived from the prices.
 */
class PayloadBuilderGoldenTest extends TestCase
{
    use IfirmaContractorMapDb;
    use IfirmaGoldenPayloads;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaReceiptService */
    private $service;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->scriptContractorMapDb();
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->service = new IfirmaReceiptService($client, new Module());
        $this->t->queue('POST', '#fakturakraj\.json#', function () {
            return array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","PelnyNumer":"7/2026"}}');
        });

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

    /** Verification GET after the contractor POST: iFirma reports the contractor under $identifier. */
    private function foundContractor($identifier)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array(array('Identyfikator' => $identifier, 'Nazwa' => 'Jan Kowalski'))))));
    }

    /**
     * Switches the contractor synchronisation on and scripts what iFirma answers while the module
     * creates the contractor $identifier of the buyer Jan Kowalski before the document.
     *
     * @param string $identifier
     */
    private function scriptContractorCreation($identifier)
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 1);
        $notFound = array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
        $this->t->queue('GET', '#kontrahenci/id/' . preg_quote($identifier, '#') . '\.json#', $notFound);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $notFound);
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/' . preg_quote($identifier, '#') . '\.json#', $this->foundContractor($identifier));
    }

    /**
     * The scenario: createReceipt() of the order with the options, compared with the fixture.
     *
     * @param Order  $order
     * @param array  $options
     * @param string $variant
     */
    private function assertCreatesGolden(Order $order, array $options = array(), $variant = '')
    {
        $this->assertGoldenPayloads($this->t, function () use ($order, $options) {
            $this->service->createReceipt($order, $options);
        }, $variant);
    }

    public function testPaymentMethodFollowsOrderModule()
    {
        $o = $this->order();
        $o->module = 'ps_cashondelivery';

        $this->assertCreatesGolden($o);
    }

    public function testFixedModeUsesConfiguredCode()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', 'fixed');
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', 'GTK');
        $o = $this->order();
        $o->module = 'ps_cashondelivery';

        $this->assertCreatesGolden($o);
    }

    public function testIdentifierScheme()
    {
        $this->scriptContractorCreation('PS-C12345');

        $this->assertCreatesGolden($this->order());
    }

    public function testGuestCustomerSameScheme()
    {
        $g = new Customer();
        $g->id = 99;
        $g->is_guest = 1;
        $g->email = 'guest@example.test';
        Customer::register($g);
        $this->scriptContractorCreation('PS-C99');

        $this->assertCreatesGolden($this->order(99));
    }

    public function testIdentifierCappedAt15()
    {
        $this->scriptContractorCreation(substr('PS-C' . PHP_INT_MAX, 0, 15));

        $this->assertCreatesGolden($this->order(PHP_INT_MAX));
    }

    public function testLongNamesTruncated()
    {
        Address::$instances[7]->firstname = str_repeat('A', 200);
        Address::$instances[7]->lastname = '';
        $o = $this->order();
        $o->details[0]['product_name'] = str_repeat('P', 600);

        $this->assertCreatesGolden($o);
    }

    public function testDiacriticsTruncationCountsCharacters()
    {
        Address::$instances[7]->firstname = str_repeat('ł', 160);
        Address::$instances[7]->lastname = '';

        $this->assertCreatesGolden($this->order());
    }

    public function testWithinLimitUnchanged()
    {
        $this->assertCreatesGolden($this->order());
    }

    public function testOrderReferenceStamping()
    {
        $this->assertCreatesGolden($this->order());
    }

    public function testSkipDodatkoweUwagi()
    {
        $this->assertCreatesGolden($this->order(), array('skip_dodatkowe_uwagi' => true));
    }

    public function testOverrideCompany()
    {
        $this->assertCreatesGolden($this->order(), array('override' => array(
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
        )));
    }

    public function testOverridePrivate()
    {
        $this->assertCreatesGolden($this->order(), array('override' => array(
            'want_invoice_for_company' => false,
            'company' => 'Ignored',
            'vat_number' => '123',
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Polna 3',
            'postcode' => '11-111',
            'city' => 'Kraków',
            'id_country' => 999,
        )));
    }

    public function testNoOverrideUsesInvoiceAddress()
    {
        $a = Address::$instances[7];
        $a->company = 'Firma';
        $a->vat_number = '525-000-11-22';
        $this->assertCreatesGolden($this->order(), array(), 'company');

        $a->company = '';
        $this->assertCreatesGolden($this->order(), array(), 'private');
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

        $this->assertCreatesGolden($o);
    }

    public function testStoredParagonSettingReadsAsFakturakraj()
    {
        Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturaparagon');

        $this->assertCreatesGolden($this->order());
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

        $this->assertCreatesGolden($o);
    }

    public function testShippingWithZeroTaxIsPercentageRate()
    {
        $o = $this->order();
        $o->total_shipping_tax_incl = 10.00;
        $o->total_shipping_tax_excl = 10.00;
        $o->carrier_tax_rate = 0;

        $this->assertCreatesGolden($o);
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

        $this->assertCreatesGolden($o);
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

        $this->assertCreatesGolden($o);
    }

    public function testSignatureTypeDefaultsToBpo()
    {
        $this->assertCreatesGolden($this->order());
    }

    public function testConfiguredSignatureTypeIsNormalised()
    {
        Configuration::updateValue('IFIRMA_SIGNATURE_TYPE', ' bwo ');

        $this->assertCreatesGolden($this->order());
    }

    public function testRemovedBrkValueFallsBackToBpo()
    {
        Configuration::updateValue('IFIRMA_SIGNATURE_TYPE', 'BRK');

        $this->assertCreatesGolden($this->order());
    }

    public function testOrderSaleDateIsTheOrderDate()
    {
        $o = $this->order();
        $this->assertCreatesGolden($o, array(), 'dated');

        $o->date_add = '';
        $this->assertCreatesGolden($o, array(), 'undated');
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

        $this->assertCreatesGolden($o);
    }

    // ---- payload facts PayloadBuilderTest does not pin, which moving the builder could change ----

    public function testPaidAmountIsTheOrderTotalWhenMarkedPaidOnCreate()
    {
        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', 1);
        $o = $this->order();
        $o->total_paid_tax_incl = 123.456;
        $o->totalPaidReal = 50.5;

        $this->assertCreatesGolden($o);
    }

    public function testPaidAmountIsWhatTheCustomerHasPaidOtherwise()
    {
        $o = $this->order();
        $o->total_paid_tax_incl = 123.456;
        $o->totalPaidReal = 50.5;

        $this->assertCreatesGolden($o);
    }

    public function testConfiguredDefaultUnitIsSent()
    {
        Configuration::updateValue('IFIRMA_DEFAULT_UNIT', 'kg');

        $this->assertCreatesGolden($this->order());
    }

    public function testSlashesInTheStreetAndTheProductNameAreSentUnescaped()
    {
        Address::$instances[7]->address1 = 'Długa 5/7';
        $o = $this->order();
        $o->details[0]['product_name'] = 'Kabel 1/2 cala';

        $this->assertCreatesGolden($o);
    }

    public function testRateOffTheDocumentedOnesIsSentUnchanged()
    {
        $o = $this->order();
        $o->details = array(array(
            'product_name' => 'Odd rate',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 106.75,
            'unit_price_tax_excl' => 100.00,
            'tax_rate' => 6.75,
        ));

        $this->assertCreatesGolden($o);
    }

    public function testRatesDerivedFromThePricesSnapToTheDocumentedOnes()
    {
        $o = $this->order();
        $o->details = array(array(
            'product_name' => 'No stored rate',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 108.45,
            'unit_price_tax_excl' => 100.00,
        ));
        $o->total_shipping_tax_incl = 12.30;
        $o->total_shipping_tax_excl = 10.00;
        $o->carrier_tax_rate = null;

        $this->assertCreatesGolden($o);
    }

    // ---- the golden covers the source -------------------------------------------------------

    public function testTheGoldenAccountsForEveryTestOfPayloadBuilderTest()
    {
        $this->assertEverySourceTestIsAccountedFor(__DIR__ . '/PayloadBuilderTest.php', array(
            'testTruncateHelperAndLimits' => 'asserts the truncate() and fieldLimits() helpers',
            'testDocumentTypeNormalisesToFakturakraj' => 'asserts normalizeDocumentType()',
            'testOnlyFakturakrajIsEnabled' => 'asserts enabledDocumentTypes()',
            'testSnapVatRateToDocumentedRates' => 'asserts snapVatRate()',
            'testSignatureTypesAreTheFourAcceptedCodes' => 'asserts signatureTypes()',
            'testEmptyOrNullSignatureTypeFallsBackToBpo' => 'asserts normalizeSignatureType(); the default shows in testSignatureTypeDefaultsToBpo',
            'testBuyerNamesBusinessAndPrivate' => 'asserts buyerNames(), which names the buyer for the contractor search and is not sent',
        ));
    }
}
