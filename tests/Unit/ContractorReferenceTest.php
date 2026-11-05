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
 * How the document references its contractor: top-level IdentyfikatorKontrahenta / NIPKontrahenta,
 * never Kontrahent.Identyfikator, plus the learn-back of the contractor iFirma attached.
 */
class ContractorReferenceTest extends TestCase
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

    /** Contractor record iFirma returns for the buyer, stored under $identifier. */
    private function foundContractor($identifier)
    {
        $row = array_merge($this->service->buildContractor($this->order()), array('Identyfikator' => $identifier));

        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row)))));
    }

    /** Sent document body (decoded) of the single document POST. */
    private function sentDocument()
    {
        $docs = $this->docCalls();
        $this->assertCount(1, $docs);

        return json_decode($docs[0]['body'], true);
    }

    private function urls()
    {
        return array_column($this->t->calls, 'url');
    }

    // ---- a. reference shape ---------------------------------------------------------------

    public function testSentPayloadNeverCarriesNestedContractorIdentifier()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $sent = $this->sentDocument();
        $this->assertSame('PS-C5', $sent['IdentyfikatorKontrahenta']);
        $this->assertArrayHasKey('Kontrahent', $sent);
        $this->assertArrayNotHasKey('Identyfikator', $sent['Kontrahent']);
        $this->assertStringNotContainsString('"Identyfikator":"PS-C5","Nazwa"', $this->docCalls()[0]['body']);
    }

    // ---- b. NIPKontrahenta -----------------------------------------------------------------

    public function testBusinessBuyerWithDashedNipSendsNipKontrahenta()
    {
        $address = Address::$instances[7];
        $address->company = 'ACME Sp. z o.o.';
        $address->vat_number = '123-456-78-90';
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $sent = $this->sentDocument();
        $this->assertSame('1234567890', $sent['NIPKontrahenta']);
        $this->assertSame('1234567890', $sent['Kontrahent']['NIP']);
    }

    public function testPrivatePersonSendsNoNipKontrahenta()
    {
        $address = Address::$instances[7];
        $address->company = '';
        $address->vat_number = '';
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $sent = $this->sentDocument();
        $this->assertArrayNotHasKey('NIPKontrahenta', $sent);
    }

    public function testForeignVatIdSendsNoNipKontrahenta()
    {
        $address = Address::$instances[7];
        $address->company = 'ACME Sp. z o.o.';
        $address->vat_number = 'PL1234567890';
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $sent = $this->sentDocument();
        $this->assertArrayNotHasKey('NIPKontrahenta', $sent);
    }

    // ---- c. Kod 201 about the contractor reference ----------------------------------------

    public function testContractorReferenceKodRetriesOnceWithoutIdentifier()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"IdentyfikatorKontrahenta: wartość nieprawidłowa"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $receipt = $this->service->createReceipt($this->order());

        $docs = $this->docCalls();
        $this->assertCount(2, $docs);
        $this->assertSame('PS-C5', json_decode($docs[0]['body'], true)['IdentyfikatorKontrahenta']);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', json_decode($docs[1]['body'], true));
        $this->assertSame('created', $receipt->status);
        $this->assertSame('fallback', $this->storedPayload()['_contractor']['action']);
        $note = $this->storedPayload()['_contractor']['note'];
        $this->assertSame('reference_rejected', $note['code']);
        $this->assertSame('PS-C5', $note['params']['identifier']);
        $this->assertSame('IdentyfikatorKontrahenta: wartość nieprawidłowa', $note['params']['error']);
    }

    public function testContractorFoundUnderAnAssignedIdentifierIsReferencedAndRecorded()
    {
        $this->enableSync();
        Address::$instances[7]->vat_number = 'PL 525-224-84-81';
        $waits = array();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->service->setContractorService(new IfirmaContractorService($client, function ($seconds) use (&$waits) {
            $waits[] = $seconds;
        }));
        $notFound = array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
        $assigned = array_merge($this->service->buildContractor($this->order()), array('Identyfikator' => 'IFI1909556979', 'NIP' => '5252248481'));

        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $notFound);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $notFound);
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $notFound);
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"PS-C5"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $notFound);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $notFound);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($assigned))))));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $receipt = $this->service->createReceipt($this->order());

        $this->assertSame('created', $receipt->status);
        $this->assertSame(array(2), $waits);
        $this->assertSame('IFI1909556979', $this->sentDocument()['IdentyfikatorKontrahenta']);
        $stored = $this->storedPayload()['_contractor'];
        $this->assertSame('created', $stored['action']);
        $this->assertSame('IFI1909556979', $stored['identifier']);
        $this->assertSame('IFI1909556979', $stored['assigned_identifier']);
        $this->assertSame('assigned', $stored['note']['code']);
        $this->assertSame('IFI1909556979', $stored['note']['params']['identifier']);
        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('IFI1909556979', $map->ifirma_identifier);
        $this->assertSame('module', $map->matched_by);
    }

    public function testSecondContractorReferenceKodIsReportedAsError()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"IdentyfikatorKontrahenta: wartość nieprawidłowa"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"IdentyfikatorKontrahenta: nadal nieprawidłowa"}}'));

        try {
            $this->service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('nadal nieprawidłowa', $e->getMessage());
        }

        $this->assertCount(2, $this->docCalls());
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertSame('error', $row['status']);
    }

    // ---- c2. Kod 201 retry rules (length and wording) --------------------------------------

    public function testKod201NamingContractorIdentifierLimitRetriesOnceWithoutIdentifier()
    {
        $this->enableSync();
        IfirmaContractorMap::link(5, 'PS-C000005', 'import-nip', 'Jan K');
        $this->t->queue('GET', '#kontrahenci/id/PS-C000005\.json#', $this->foundContractor('PS-C000005'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Identyfikator kontrahenta może mieć maksymalnie 15 znaków."}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $receipt = $this->service->createReceipt($this->order());

        $docs = $this->docCalls();
        $this->assertCount(2, $docs);
        $this->assertSame('PS-C000005', json_decode($docs[0]['body'], true)['IdentyfikatorKontrahenta']);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', json_decode($docs[1]['body'], true));
        $this->assertSame('created', $receipt->status);
        $this->assertSame('fallback', $this->storedPayload()['_contractor']['action']);
    }

    public function testSixteenCharacterReferenceRetriesOnEmptyKod201()
    {
        $this->enableSync();
        IfirmaContractorMap::link(5, 'EC_JACEK ORZECHO', 'import-nip', 'Jan K');
        $this->t->queue('GET', '#kontrahenci/id/EC_JACEK%20ORZECHO\.json#', $this->foundContractor('EC_JACEK ORZECHO'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":""}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('status' => 0, 'error' => 'timeout'));

        $receipt = $this->service->createReceipt($this->order());

        $docs = $this->docCalls();
        $this->assertCount(2, $docs);
        $this->assertSame('EC_JACEK ORZECHO', json_decode($docs[0]['body'], true)['IdentyfikatorKontrahenta']);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', json_decode($docs[1]['body'], true));
        $this->assertSame('created', $receipt->status);
        $this->assertSame('77', (string) $receipt->ifirma_id);
    }

    public function testUnrelatedKod201WithShortReferenceIsNotRetried()
    {
        $this->enableSync();
        IfirmaContractorMap::link(5, 'PS-C007', 'import-nip', 'Jan K');
        $this->t->queue('GET', '#kontrahenci/id/PS-C007\.json#', $this->foundContractor('PS-C007'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Pozycje: brak pozycji"}}'));

        try {
            $this->service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Pozycje: brak pozycji', $e->getMessage());
        }

        $this->assertCount(1, $this->docCalls());
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertSame('error', $row['status']);
        $this->assertStringContainsString('Pozycje: brak pozycji', $row['last_error']);
    }

    // ---- d. learning the contractor iFirma attached ----------------------------------------

    public function testAttachedContractorIsLearnedFromIssuedDocument()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}'));
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 555,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-77'),
        )))));

        $receipt = $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'POST', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertStringContainsString('fakturakraj/555.json', $this->t->calls[2]['url']);
        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('AUTO-77', $map->ifirma_identifier);
        $this->assertSame('document', $map->matched_by);
        $this->assertSame('AUTO-77', $this->storedPayload()['_contractor']['document_identifier']);
        $this->assertSame('created', $receipt->status);
    }

    // ---- e. long (16-character) mapped identifier ------------------------------------------

    public function testLongMappedIdentifierIsReadBackAndRelinkedWhenIssuedUnderAnother()
    {
        $this->enableSync();
        IfirmaContractorMap::link(5, 'EC_JACEK ORZECHO', 'import-nip', 'Jan K');
        $this->t->queue('GET', '#kontrahenci/id/EC_JACEK%20ORZECHO\.json#', $this->foundContractor('EC_JACEK ORZECHO'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 77,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-78'),
        )))));

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'POST', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame('EC_JACEK ORZECHO', $this->sentDocument()['IdentyfikatorKontrahenta']);
        $this->assertStringContainsString('fakturakraj/77.json', $this->t->calls[2]['url']);
        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('AUTO-78', $map->ifirma_identifier);
        $this->assertSame('document', $map->matched_by);
    }

    public function testLongMappedIdentifierKeepsMatchedByWhenIssuedUnderSameIdentifier()
    {
        $this->enableSync();
        IfirmaContractorMap::link(5, 'EC_JACEK ORZECHO', 'import-nip', 'Jan K');
        $this->t->queue('GET', '#kontrahenci/id/EC_JACEK%20ORZECHO\.json#', $this->foundContractor('EC_JACEK ORZECHO'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 77,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'EC_JACEK ORZECHO'),
        )))));

        $this->service->createReceipt($this->order());

        $this->assertStringContainsString('fakturakraj/77.json', $this->t->calls[2]['url']);
        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('EC_JACEK ORZECHO', $map->ifirma_identifier);
        $this->assertSame('import-nip', $map->matched_by);
    }

    // ---- f. short identifier: no detail read ----------------------------------------------

    public function testShortReferencedIdentifierMakesNoDetailCall()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'POST'), array_column($this->t->calls, 'method'));
        foreach ($this->urls() as $url) {
            $this->assertStringNotContainsString('fakturakraj/77.json', $url);
        }
    }

    // ---- g. failed detail read never blocks issuance ---------------------------------------

    public function testFailedDetailReadDoesNotBlockIssuance()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}'));
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('status' => 0, 'error' => 'timeout'));

        $receipt = $this->service->createReceipt($this->order());

        $this->assertSame('created', $receipt->status);
        $this->assertSame('555', (string) $receipt->ifirma_id);
        $this->assertFalse(IfirmaContractorMap::getByCustomer(5) ? true : false);
        $this->assertArrayNotHasKey('document_identifier', $this->storedPayload()['_contractor']);
    }

    // ---- manual link (customer-ifirma-panel) -----------------------------------------------

    /** Scenario: Link a found contractor - the next document references the chosen contractor. */
    public function testDocumentAfterAManualLinkReferencesTheChosenContractor()
    {
        $this->enableSync();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $customerService = new IfirmaCustomerContractorService($client, new IfirmaContractorService($client));

        // the employee links customer 5 to an older contractor; the link re-reads it once
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->foundContractor('KOWALSKI-01'));
        $linked = $customerService->link(5, 'KOWALSKI-01');
        $this->assertTrue($linked['success']);
        $this->assertSame('manual', IfirmaContractorMap::getByCustomer(5)->matched_by);

        // issuing the next document uses the stored link instead of PS-C5
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->foundContractor('KOWALSKI-01'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame('KOWALSKI-01', $this->sentDocument()['IdentyfikatorKontrahenta']);
        $this->assertNotContains('PS-C5', array_map(function ($url) {
            return rawurldecode(basename((string) parse_url($url, PHP_URL_PATH), '.json'));
        }, $this->urls()));
        $this->assertSame('KOWALSKI-01', IfirmaContractorMap::getByCustomer(5)->ifirma_identifier);
    }

    // ---- the stored "_contractor" record, key for key (refactor-clean-code-tda 13.1 + 13.2) -------
    // The admin order panel and the smoke scenarios read this record from the stored request, so
    // its keys, their order, their types and the keys left out must not change.

    private function notFoundAnswer()
    {
        return array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
    }

    /** Contractor POST answered with $identifier as Wynik. */
    private function contractorAdded($identifier)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => 'Dodano kontrahenta', 'Wynik' => $identifier))));
    }

    /** Sleeper that records the wait instead of sleeping. */
    private function recordWaits(array &$waits)
    {
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->service->setContractorService(new IfirmaContractorService($client, function ($seconds) use (&$waits) {
            $waits[] = $seconds;
        }));
    }

    public function testStoredRecordOfASwitchedOffSync()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(
            array('action' => 'disabled', 'message' => '', 'matched_by' => '', 'identifier' => ''),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordOfAKeptContractor()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(
            array('action' => 'kept', 'message' => '', 'matched_by' => 'module', 'identifier' => 'PS-C5'),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordOfAnUpdatedContractor()
    {
        $this->enableSync();
        $outdated = array_merge($this->service->buildContractor($this->order()), array('Identyfikator' => 'PS-C5', 'Miejscowosc' => 'Stare Miasto'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($outdated))))));
        $this->t->queue('PUT', '#kontrahenci/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Zaktualizowano kontrahenta"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(array('GET', 'PUT', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertSame(
            array('action' => 'updated', 'message' => '', 'matched_by' => 'module', 'identifier' => 'PS-C5'),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordOfACreatedContractor()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFoundAnswer());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->contractorAdded('PS-C5'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame(
            array('action' => 'created', 'message' => '', 'matched_by' => 'module', 'identifier' => 'PS-C5'),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordOfAContractorCreatedUnderAnAssignedIdentifier()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFoundAnswer());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->contractorAdded('124451232'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', $this->foundContractor('124451232'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order());

        $this->assertSame('124451232', $this->sentDocument()['IdentyfikatorKontrahenta']);
        $this->assertSame(
            array(
                'action' => 'created',
                'message' => '',
                'matched_by' => 'module',
                'identifier' => '124451232',
                'note' => array('code' => 'assigned', 'params' => array('identifier' => '124451232')),
                'assigned_identifier' => '124451232',
            ),
            $this->storedPayload()['_contractor']
        );
        // and the bytes of that record, as written to the receipt
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $raw = (string) reset($rows)['request_payload'];
        $this->assertSame(
            "    \"_contractor\": {\n"
            . "        \"action\": \"created\",\n"
            . "        \"message\": \"\",\n"
            . "        \"matched_by\": \"module\",\n"
            . "        \"identifier\": \"124451232\",\n"
            . "        \"note\": {\n"
            . "            \"code\": \"assigned\",\n"
            . "            \"params\": {\n"
            . "                \"identifier\": \"124451232\"\n"
            . "            }\n"
            . "        },\n"
            . "        \"assigned_identifier\": \"124451232\"\n"
            . "    }\n"
            . '}',
            substr($raw, (int) strpos($raw, '    "_contractor": {'))
        );
    }

    public function testStoredRecordOfAFailedLookup()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('status' => 0, 'error' => 'timeout'));

        $this->service->createReceipt($this->order());

        $this->assertSame(
            array(
                'action' => 'fallback',
                'message' => 'cURL error: timeout',
                'matched_by' => '',
                'identifier' => '',
                'note' => array('code' => 'lookup_failed', 'params' => array('identifier' => 'PS-C5', 'error' => 'cURL error: timeout')),
            ),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordOfAReferenceTheDocumentApiRejected()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"IdentyfikatorKontrahenta: wartość nieprawidłowa"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('status' => 0, 'error' => 'timeout'));

        $this->service->createReceipt($this->order());

        $this->assertSame(
            array(
                'action' => 'fallback',
                'message' => 'IdentyfikatorKontrahenta: wartość nieprawidłowa',
                'matched_by' => '',
                'identifier' => '',
                'note' => array(
                    'code' => 'reference_rejected',
                    'params' => array('identifier' => 'PS-C5', 'error' => 'IdentyfikatorKontrahenta: wartość nieprawidłowa'),
                ),
            ),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordOfAContractorThatCouldNotBeReadBack()
    {
        $this->enableSync();
        $waits = array();
        $this->recordWaits($waits);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFoundAnswer());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->contractorAdded('PS-C5'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFoundAnswer());
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('status' => 0, 'error' => 'timeout'));

        $this->service->createReceipt($this->order());

        $this->assertSame(array(2), $waits);
        $miss = 'Kod 208: Nie znaleziono kontrahenta';
        $this->assertSame(
            array(
                'action' => 'fallback',
                'message' => 'iFirma created the contractor but it cannot be read back (PS-C5: ' . $miss . '); search: none.',
                'matched_by' => '',
                'identifier' => '',
                'note' => array(
                    'code' => 'readback_failed',
                    'params' => array(
                        'tried' => array(
                            array('identifier' => 'PS-C5', 'answer' => $miss),
                            array('identifier' => 'PS-C5', 'answer' => $miss),
                        ),
                        'search' => 'none',
                        'search_error' => '',
                    ),
                ),
            ),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordAddsTheDocumentIdentifierLast()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}'));
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 555,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-77'),
        )))));

        $this->service->createReceipt($this->order());

        $this->assertSame(
            array(
                'action' => 'fallback',
                'message' => 'cURL error: timeout',
                'matched_by' => '',
                'identifier' => '',
                'note' => array('code' => 'lookup_failed', 'params' => array('identifier' => 'PS-C5', 'error' => 'cURL error: timeout')),
                'document_identifier' => 'AUTO-77',
            ),
            $this->storedPayload()['_contractor']
        );
    }

    public function testStoredRecordKeepsTheOrderOfNoteAssignedIdentifierAndDocumentIdentifier()
    {
        $this->enableSync();
        // iFirma stores the contractor under an identifier longer than the document API takes, so the
        // document is read back for the contractor it really attached
        $long = 'IFI-LONG-ASSIGNED-1';
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFoundAnswer());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->contractorAdded($long));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFoundAnswer());
        $this->t->queue('GET', '#kontrahenci/id/' . $long . '\.json#', $this->foundContractor($long));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 77,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-80'),
        )))));

        $this->service->createReceipt($this->order());

        $this->assertSame(
            array(
                'action' => 'created',
                'message' => '',
                'matched_by' => 'module',
                'identifier' => $long,
                'note' => array('code' => 'assigned', 'params' => array('identifier' => $long)),
                'assigned_identifier' => $long,
                'document_identifier' => 'AUTO-80',
            ),
            $this->storedPayload()['_contractor']
        );
        $this->assertSame('AUTO-80', IfirmaContractorMap::getByCustomer(5)->ifirma_identifier);
    }

    public function testAContractorCreatedForAnOrderWithoutACustomerIsStillReferenced()
    {
        $this->enableSync();
        $order = $this->order();
        $order->id_customer = 0;
        $this->t->queue('GET', '#kontrahenci/id/PS-C0\.json#', $this->notFoundAnswer());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->contractorAdded('PS-C0'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C0\.json#', $this->foundContractor('PS-C0'));
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($order);

        // no customer: nothing to search, nothing to link, but the created contractor is referenced
        $this->assertSame(array('GET', 'POST', 'GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertSame('PS-C0', $this->sentDocument()['IdentyfikatorKontrahenta']);
        $this->assertSame(
            array('action' => 'created', 'message' => '', 'matched_by' => '', 'identifier' => 'PS-C0'),
            $this->storedPayload()['_contractor']
        );
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_contractor_map'));
    }

    public function testNoRecordIsStoredWhenTheContractorStepIsSkipped()
    {
        $this->enableSync();
        $this->t->queue('POST', '#fakturakraj\.json#', $this->docOk());

        $this->service->createReceipt($this->order(), array('skip_contractor_sync' => true));

        $rows = IfirmaTestState::objects('ifirma_receipt');
        $raw = (string) reset($rows)['request_payload'];
        $this->assertStringNotContainsString('_contractor', $raw);
        $this->assertArrayNotHasKey('_contractor', json_decode($raw, true));
    }

    // ---- learning from a document detail that is odd ----------------------------------------------

    public function testAttachedContractorIsLearnedFromADetailWithoutTheResponseEnvelope()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}'));
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('body' => json_encode(array(
            'Kod' => 0,
            'Identyfikator' => 555,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-79'),
        ))));

        $this->service->createReceipt($this->order());

        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('AUTO-79', $map->ifirma_identifier);
        $this->assertSame('document', $map->matched_by);
        $this->assertSame('AUTO-79', $this->storedPayload()['_contractor']['document_identifier']);
    }

    public function testADetailWhoseContractorHasNoIdentifierLearnsNothing()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}'));
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 555,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => '  '),
        )))));

        $receipt = $this->service->createReceipt($this->order());

        $this->assertSame('created', $receipt->status);
        $this->assertNull(IfirmaContractorMap::getByCustomer(5));
        $this->assertArrayNotHasKey('document_identifier', $this->storedPayload()['_contractor']);
    }

    public function testAMapTableThatFailsWhileLearningNeverBlocksTheDocument()
    {
        $this->enableSync();
        Db::getInstance()->whenGetValue('/ifirma_contractor_map/', function () {
            throw new Exception('table is gone');
        });
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}'));
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 555,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-77'),
        )))));

        $receipt = $this->service->createReceipt($this->order());

        $this->assertSame('created', $receipt->status);
        $this->assertSame('555', (string) $receipt->ifirma_id);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_contractor_map'));
        $this->assertSame('AUTO-77', $this->storedPayload()['_contractor']['document_identifier']);
    }
}
