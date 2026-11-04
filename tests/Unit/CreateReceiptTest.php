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

class CreateReceiptTest extends TestCase
{
    use IfirmaContractorMapDb;

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

        $c = new Customer();
        $c->id = 5;
        $c->email = 'a@example.test';
        Customer::register($c);
        $a = new Address();
        $a->id = 7;
        $a->firstname = 'Jan';
        $a->lastname = 'K';
        $a->id_country = 14;
        Address::register($a);
    }

    private function order()
    {
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = date('Y-m-d H:i:s');
        $o->total_paid_tax_incl = 10;
        $o->details = array(array(
            'product_name' => 'W',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 10,
            'unit_price_tax_excl' => 10,
            'tax_rate' => 0,
        ));

        return $o;
    }

    public function testSuccessStampsReference()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77"}}'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Wynik' => array(array('FakturaId' => 77, 'PelnyNumer' => '7/2026')),
        )))));

        $this->service->createReceipt($this->order());

        $rows = IfirmaTestState::objects('ifirma_receipt');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $row['order_reference']);
        $this->assertSame('module', $row['source']);
        $this->assertSame('created', $row['status']);
        $this->assertSame('7/2026', $row['ifirma_number']);
        $this->assertSame('77', (string) $row['ifirma_id']);
        $this->assertStringContainsString('PS-ORDER-15-ABCDEFGHI', $this->t->calls[0]['body']);
        $this->assertStringContainsString('NUMER_ZAMOWIENIA', $this->t->calls[0]['body']);
    }

    public function testSkipOptionReachesRequest()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Identyfikator":"1","PelnyNumer":"1/2026"}}'));
        $this->service->createReceipt($this->order(), array('skip_dodatkowe_uwagi' => true));
        $this->assertStringNotContainsString('DodatkoweUwagi', $this->t->calls[0]['body']);
        $this->assertStringContainsString('"Uwagi":"PS-ORDER-15-ABCDEFGHI"', $this->t->calls[0]['body']);
    }

    public function testRejectionSetsErrorStatus()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));
        try {
            $this->service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('bad', $e->getMessage());
        }
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertSame('error', $row['status']);
    }

    public function testKod200WithDodatkoweUwagiRetriesOnceWithoutIt()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":200,"Informacja":"Niepoprawna zawartość żądania - nie można utworzyć obiektu na podstawie zawartości żądania."}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","PelnyNumer":"7/2026"}}'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Wynik' => array(array('FakturaId' => 77, 'PelnyNumer' => '7/2026')),
        )))));

        $this->service->createReceipt($this->order());

        $posts = array_values(array_filter($this->t->calls, function ($c) {
            return $c['method'] === 'POST';
        }));
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('DodatkoweUwagi', $posts[0]['body']);
        $this->assertStringNotContainsString('DodatkoweUwagi', $posts[1]['body']);
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertSame('created', $row['status']);
        $this->assertSame('7/2026', $row['ifirma_number']);
    }

    public function testKod200WithoutDodatkoweUwagiIsAnError()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":200,"Informacja":"Niepoprawna zawartość żądania - nie można utworzyć obiektu na podstawie zawartości żądania."}}'));
        try {
            $this->service->createReceipt($this->order(), array('skip_dodatkowe_uwagi' => true));
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Niepoprawna', $e->getMessage());
        }
        $this->assertCount(1, $this->t->calls);
        $this->assertStringNotContainsString('DodatkoweUwagi', $this->t->calls[0]['body']);
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertSame('error', $row['status']);
    }

    public function testExistingIssuedRecordBlocksWithoutTransportCall()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->status = IfirmaReceipt::STATUS_CREATED;
        $r->ifirma_id = '5';
        $r->save();
        Db::getInstance()->whenGetValue('/ifirma_receipt/', (int) $r->id);

        try {
            $this->service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame(array(), $this->t->calls);
    }

    // ---- contractor registration -----------------------------------------------------------

    private function enableSync()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 1);
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'pl';
        $country->name = 'Polska';
        Country::register($country);
    }

    private function docOk()
    {
        return array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","PelnyNumer":"7/2026"}}');
    }

    private function docCalls()
    {
        $out = array();
        foreach ($this->t->calls as $c) {
            if ($c['method'] === 'POST' && substr(parse_url($c['url'], PHP_URL_PATH), -16) === 'fakturakraj.json') {
                $out[] = $c;
            }
        }

        return $out;
    }

    private function storedPayload()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);

        return json_decode($row['request_payload'], true);
    }

    private function contractorRow($city = '')
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array(array(
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
        ))))));
    }

    private function notFound()
    {
        return array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
    }

    /** Verification GET after the contractor POST: iFirma reports the contractor under $identifier. */
    private function foundContractor($identifier)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array(array('Identyfikator' => $identifier, 'Nazwa' => 'Jan K'))))));
    }

    public function testContractorPayloadCarriesCountryNameAndCode()
    {
        $this->enableSync();
        $contractor = $this->service->buildContractor($this->order());
        $this->assertSame('PL', $contractor['KodKraju']);
        $this->assertSame('Polska', $contractor['Kraj']);
        $this->assertSame('PS-C5', $contractor['Identyfikator']);
    }

    public function testContractorCountryNameFallsBackToIso()
    {
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'de';
        $country->name = '';
        Country::register($country);
        $contractor = $this->service->buildContractor($this->order());
        $this->assertSame('DE', $contractor['KodKraju']);
        $this->assertSame('DE', $contractor['Kraj']);
    }

    public function testNewContractorIsCreatedBeforeTheDocument()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFound());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFound());
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'GET', 'POST', 'GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertStringContainsString('/kontrahenci/id/PS-C5.json', $this->t->calls[0]['url']);
        $this->assertStringContainsString('/kontrahenci/Jan%20K.json', $this->t->calls[1]['url']);
        $this->assertStringContainsString('/kontrahenci.json', $this->t->calls[2]['url']);
        $this->assertStringContainsString('/kontrahenci/id/PS-C5.json', $this->t->calls[3]['url']);
        $docs = $this->docCalls();
        $this->assertCount(1, $docs);
        $sent = json_decode($docs[0]['body'], true);
        $this->assertSame('PS-C5', $sent['IdentyfikatorKontrahenta']);
        $this->assertArrayNotHasKey('Identyfikator', $sent['Kontrahent']);
        $this->assertArrayNotHasKey('_contractor', $sent);
        $this->assertStringNotContainsString('_contractor', $docs[0]['body']);
        $stored = $this->storedPayload();
        $this->assertSame('created', $stored['_contractor']['action']);
    }

    public function testExistingUnchangedContractorIsKept()
    {
        $this->enableSync();
        $row = $this->service->buildContractor($this->order());
        $this->t->queue('GET', '#kontrahenci/id/#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row))))));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'POST'), array_column($this->t->calls, 'method'));
        $sent = json_decode($this->docCalls()[0]['body'], true);
        $this->assertSame('PS-C5', $sent['IdentyfikatorKontrahenta']);
        $this->assertSame('kept', $this->storedPayload()['_contractor']['action']);
    }

    public function testChangedAddressUpdatesContractorFirst()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', $this->contractorRow('Stare Miasto'));
        $this->t->queue('PUT', '#kontrahenci/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Zaktualizowano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        Address::$instances[7]->city = 'Nowe Miasto';

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'PUT', 'POST'), array_column($this->t->calls, 'method'));
        $put = json_decode($this->t->calls[1]['body'], true);
        $this->assertSame('Nowe Miasto', $put['Miejscowosc']);
        $this->assertSame('updated', $this->storedPayload()['_contractor']['action']);
        $this->assertSame('PS-C5', json_decode($this->docCalls()[0]['body'], true)['IdentyfikatorKontrahenta']);
    }

    public function testContractorLookupFailureFallsBackToNullIdentifier()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $sent = json_decode($this->docCalls()[0]['body'], true);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', $sent);
        $this->assertArrayNotHasKey('Identyfikator', $sent['Kontrahent']);
        $stored = $this->storedPayload();
        $this->assertSame('fallback', $stored['_contractor']['action']);
        $this->assertStringContainsString('timeout', $stored['_contractor']['message']);
    }

    public function testMissingContractorKodRetriesOnceWithNullIdentifier()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', $this->contractorRow('Stare Miasto'));
        $this->t->queue('PUT', '#kontrahenci/#', array('body' => '{"response":{"Kod":0,"Informacja":"Zaktualizowano kontrahenta"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Validation error in document data (Kod 201): Nie znaleziono kontrahenta o zadanym identyfikatorze"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        Address::$instances[7]->city = 'Nowe Miasto';

        $receipt = $this->service->createReceipt($this->order());

        $docs = $this->docCalls();
        $this->assertCount(2, $docs);
        $this->assertSame('PS-C5', json_decode($docs[0]['body'], true)['IdentyfikatorKontrahenta']);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', json_decode($docs[1]['body'], true));
        $this->assertSame('created', $receipt->status);
        $this->assertSame('fallback', $this->storedPayload()['_contractor']['action']);
    }

    public function testMissingContractorKodIsNotRetriedWhenIdentifierAlreadyNull()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta"}}'));
        try {
            $this->service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Nie znaleziono kontrahenta', $e->getMessage());
        }
        $this->assertCount(1, $this->docCalls());
    }

    public function testSyncDisabledSkipsContractorCalls()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertCount(1, $this->t->calls);
        $this->assertStringNotContainsString('kontrahenci', $this->t->calls[0]['url']);
        $sent = json_decode($this->t->calls[0]['body'], true);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', $sent);
        $this->assertArrayNotHasKey('Identyfikator', $sent['Kontrahent']);
        $this->assertSame('disabled', $this->storedPayload()['_contractor']['action']);
    }

    public function testSkipContractorSyncOptionSendsNoReferenceAndMakesNoContractorCall()
    {
        $this->enableSync();
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order(), array('skip_contractor_sync' => true));

        $this->assertCount(1, $this->t->calls);
        $this->assertStringNotContainsString('kontrahenci', $this->t->calls[0]['url']);
        $sent = json_decode($this->t->calls[0]['body'], true);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', $sent);
        $this->assertArrayNotHasKey('Identyfikator', $sent['Kontrahent']);
        $this->assertSame('PL', $sent['Kontrahent']['KodKraju']);
        $this->assertSame('Polska', $sent['Kontrahent']['Kraj']);
    }

    // ---- existing contractors (design D13) -------------------------------------------------

    public function testMappedCustomerUsesTheMappedIdentifierWithoutSearching()
    {
        $this->enableSync();
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'import-nip', 'Jan K');
        $row = array_merge($this->service->buildContractor($this->order()), array('Identyfikator' => 'KOWALSKI-01'));
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row))))));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertStringContainsString('/kontrahenci/id/KOWALSKI-01.json', $this->t->calls[0]['url']);
        $sent = json_decode($this->docCalls()[0]['body'], true);
        $this->assertSame('KOWALSKI-01', $sent['IdentyfikatorKontrahenta']);
        $stored = $this->storedPayload();
        $this->assertSame('kept', $stored['_contractor']['action']);
        $this->assertSame('import-nip', $stored['_contractor']['matched_by']);
        $this->assertSame('KOWALSKI-01', $stored['_contractor']['identifier']);
    }

    public function testBusinessCustomerWithoutMappingIsMatchedByNipSearch()
    {
        $this->enableSync();
        $address = Address::$instances[7];
        $address->company = 'ACME Sp. z o.o.';
        $address->vat_number = '525-224-84-81';
        $row = array_merge($this->service->buildContractor($this->order()), array('Identyfikator' => 'ACME-02'));
        $this->assertSame('5252248481', $row['NIP']);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFound());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row))))));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertStringContainsString('/kontrahenci/id/PS-C5.json', $this->t->calls[0]['url']);
        $this->assertStringContainsString('/kontrahenci/5252248481.json', $this->t->calls[1]['url']);
        $sent = json_decode($this->docCalls()[0]['body'], true);
        $this->assertSame('ACME-02', $sent['IdentyfikatorKontrahenta']);
        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('ACME-02', $map->ifirma_identifier);
        $this->assertSame('nip', $map->matched_by);
        $stored = $this->storedPayload();
        $this->assertSame('nip', $stored['_contractor']['matched_by']);
        $this->assertSame('ACME-02', $stored['_contractor']['identifier']);
    }

    public function testCreatedContractorIsStoredAsTheModuleLink()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFound());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFound());
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('PS-C5', $map->ifirma_identifier);
        $this->assertSame('module', $map->matched_by);
        $this->assertSame('module', $this->storedPayload()['_contractor']['matched_by']);
    }

    public function testContractorHintsCarryNipEmailPostcodeAndName()
    {
        Address::$instances[7]->vat_number = 'PL 525-224-84-81';
        Address::$instances[7]->postcode = '00-950';
        $contractor = $this->service->buildContractor($this->order());

        $hints = $this->service->contractorHints($this->order(), $contractor);
        $this->assertSame('5252248481', $hints['nip']);
        $this->assertSame('a@example.test', $hints['email']);
        $this->assertSame('00-950', $hints['postcode']);
        $this->assertSame('Jan K', $hints['name']);

        $override = $this->service->contractorHints($this->order(), $contractor, array('vat_number' => '123-456-78-90'));
        $this->assertSame('1234567890', $override['nip']);
    }
}
