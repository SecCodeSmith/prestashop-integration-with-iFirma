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
 * Golden comparison of the requests createReceipt() sends: the scenarios of CreateReceiptTest, each
 * run again with the same setup and compared with the fixture under tests/fixtures/payloads that
 * was captured from IfirmaReceiptService before createReceipt() was split (refactor-srp-splits 5.1).
 *
 * Every test has the name of the CreateReceiptTest test whose scenario it repeats. The fixture holds
 * every body the module sent to iFirma (the document, and the contractor create and update before
 * it), the request payload it stored on the receipt, and the message of the exception the call
 * ended in; see IfirmaGoldenPayloads. The order's date is fixed, so that only DataWystawienia moves.
 */
class CreateReceiptGoldenTest extends TestCase
{
    use IfirmaContractorMapDb;
    use IfirmaGoldenPayloads;
    use IfirmaCreateReceiptScenario;

    protected function setUp(): void
    {
        $this->setUpCreateReceiptScenario();
    }

    private function docOk()
    {
        return array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","PelnyNumer":"7/2026"}}');
    }

    private function notFound()
    {
        return array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
    }

    /** The contractor record iFirma holds for the buyer: Jan K in $city, as the module builds it. */
    private function contractorRow($city = '')
    {
        return array(
            'Nazwa' => 'Jan K',
            'Identyfikator' => 'PS-C5',
            'NIP' => '',
            'Ulica' => '',
            'KodPocztowy' => '',
            'Kraj' => 'Polska',
            'KodKraju' => 'PL',
            'Miejscowosc' => $city,
            'Email' => 'a@example.test',
            'OsobaFizyczna' => true,
        );
    }

    private function answerWith(array $row)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row)))));
    }

    /** Verification GET after the contractor POST: iFirma reports the contractor under $identifier. */
    private function foundContractor($identifier)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array(array('Identyfikator' => $identifier, 'Nazwa' => 'Jan K'))))));
    }

    /**
     * Scripts what iFirma answers while the module creates the contractor PS-C5 before the document.
     */
    private function scriptContractorCreation()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFound());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFound());
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
    }

    /**
     * The scenario: createReceipt() of the order with the options, compared with the fixture.
     *
     * @param array  $options
     * @param string $variant
     */
    private function assertCreatesGolden(array $options = array(), $variant = '')
    {
        $this->assertGoldenPayloads($this->t, function () use ($options) {
            $this->service->createReceipt($this->order(), $options);
        }, $variant);
    }

    public function testSuccessStampsReference()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77"}}'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Wynik' => array(array('FakturaId' => 77, 'PelnyNumer' => '7/2026')),
        )))));

        $this->assertCreatesGolden();
    }

    public function testSkipOptionReachesRequest()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Identyfikator":"1","PelnyNumer":"1/2026"}}'));

        $this->assertCreatesGolden(array('skip_dodatkowe_uwagi' => true));
    }

    public function testRejectionSetsErrorStatus()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));

        $this->assertCreatesGolden();
    }

    public function testRejectionRecordsTheInformacjaInOneWrite()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));

        $this->assertCreatesGolden();
    }

    public function testRejectionWithoutInformacjaRecordsTheGenericMessage()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201}}'));

        $this->assertCreatesGolden();
    }

    public function testRejectionUpdatesAnExistingUnissuedRecordInOneWrite()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_HELD;
        $old->last_error = 'held: data request #4 pending';
        $old->security_token = 'keep-me';
        $old->save();
        Db::getInstance()->whenGetValue('/ifirma_receipt/', (int) $old->id);
        IfirmaTestState::clearWrites();
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));

        $this->assertCreatesGolden();
    }

    public function testNoAnswerAfterTheRequestWasSentIsUnknown()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));

        $this->assertCreatesGolden();
    }

    public function testRequestRefusedLocallyIsAnErrorAndNotUnknown()
    {
        $client = new IfirmaApiClient('u@example.com', array('faktura' => ''), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $service = new IfirmaReceiptService($client, new Module());

        $this->assertGoldenPayloads($this->t, function () use ($service) {
            $service->createReceipt($this->order());
        });
    }

    public function testNumerNamesTheDocumentWhenPelnyNumerIsMissing()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","Numer":"12"}}'));

        $this->assertCreatesGolden();
    }

    public function testKod200WithDodatkoweUwagiRetriesOnceWithoutIt()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":200,"Informacja":"Niepoprawna zawartość żądania - nie można utworzyć obiektu na podstawie zawartości żądania."}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","PelnyNumer":"7/2026"}}'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Wynik' => array(array('FakturaId' => 77, 'PelnyNumer' => '7/2026')),
        )))));

        $this->assertCreatesGolden();
    }

    public function testKod200WithoutDodatkoweUwagiIsAnError()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":200,"Informacja":"Niepoprawna zawartość żądania - nie można utworzyć obiektu na podstawie zawartości żądania."}}'));

        $this->assertCreatesGolden(array('skip_dodatkowe_uwagi' => true));
    }

    // ---- contractor registration -----------------------------------------------------------

    public function testContractorPayloadCarriesCountryNameAndCode()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->answerWith($this->contractorRow()));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testContractorCountryNameFallsBackToIso()
    {
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'de';
        $country->name = '';
        Country::register($country);
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testNewContractorIsCreatedBeforeTheDocument()
    {
        $this->enableSync();
        $this->scriptContractorCreation();
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testExistingUnchangedContractorIsKept()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', $this->answerWith($this->contractorRow()));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testChangedAddressUpdatesContractorFirst()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', $this->answerWith($this->contractorRow('Stare Miasto')));
        $this->t->queue('PUT', '#kontrahenci/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Zaktualizowano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        Address::$instances[7]->city = 'Nowe Miasto';

        $this->assertCreatesGolden();
    }

    public function testContractorLookupFailureFallsBackToNullIdentifier()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testMissingContractorKodRetriesOnceWithNullIdentifier()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', $this->answerWith($this->contractorRow('Stare Miasto')));
        $this->t->queue('PUT', '#kontrahenci/#', array('body' => '{"response":{"Kod":0,"Informacja":"Zaktualizowano kontrahenta"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Validation error in document data (Kod 201): Nie znaleziono kontrahenta o zadanym identyfikatorze"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        Address::$instances[7]->city = 'Nowe Miasto';

        $this->assertCreatesGolden();
    }

    public function testMissingContractorKodIsNotRetriedWhenIdentifierAlreadyNull()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta"}}'));

        $this->assertCreatesGolden();
    }

    public function testSyncDisabledSkipsContractorCalls()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testSkipContractorSyncOptionSendsNoReferenceAndMakesNoContractorCall()
    {
        $this->enableSync();
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden(array('skip_contractor_sync' => true));
    }

    // ---- existing contractors (design D13) -------------------------------------------------

    public function testMappedCustomerUsesTheMappedIdentifierWithoutSearching()
    {
        $this->enableSync();
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'import-nip', 'Jan K');
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->answerWith(array_merge($this->contractorRow(), array('Identyfikator' => 'KOWALSKI-01'))));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testBusinessCustomerWithoutMappingIsMatchedByNipSearch()
    {
        $this->enableSync();
        $address = Address::$instances[7];
        $address->company = 'ACME Sp. z o.o.';
        $address->vat_number = '525-224-84-81';
        $row = array_merge($this->contractorRow(), array('Nazwa' => 'ACME Sp. z o.o.', 'Identyfikator' => 'ACME-02', 'NIP' => '5252248481', 'OsobaFizyczna' => false));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFound());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->answerWith($row));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    public function testCreatedContractorIsStoredAsTheModuleLink()
    {
        $this->enableSync();
        $this->scriptContractorCreation();
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->assertCreatesGolden();
    }

    // ---- the golden covers the source -------------------------------------------------------

    public function testTheGoldenAccountsForEveryTestOfCreateReceiptTest()
    {
        $this->assertEverySourceTestIsAccountedFor(__DIR__ . '/CreateReceiptTest.php', array(
            'testExistingIssuedRecordBlocksWithoutTransportCall' => 'throws before any payload is built',
            'testContractorHintsCarryNipEmailPostcodeAndName' => 'asserts the hints for the contractor lookup, which are not sent',
            'testACreateRefusedByTheLocalRateLimitIsQueuedAndNotAnError' => 'refused before the request is sent, nothing is posted',
            'testACreateRefusedByIfirmaWith429IsQueuedAndNotUnknown' => 'asserts the stored state of a refused create, not the payload',
            'testTheRecordIsUnknownWhileTheCreateRequestIsInFlight' => 'asserts the in-flight marker, not the payload',
            'testARequestThatDiesLeavesTheMarkerWithTheRealError' => 'asserts the stored state of a request that died, not the payload',
            'testNoMarkerIsWrittenWhenTheRequestIsRefusedBeforeItIsSent' => 'refused before the request is sent, nothing is posted',
            'testTheMarkerIsWrittenOnceWhenTheCreateIsRetried' => 'asserts how often the marker is written, not the payload',
        ));
    }
}
