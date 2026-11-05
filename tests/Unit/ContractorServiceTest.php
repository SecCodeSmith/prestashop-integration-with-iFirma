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

class ContractorServiceTest extends TestCase
{
    use IfirmaContractorMapDb;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaContractorService */
    private $svc;

    /** @var array seconds requested from the injected sleeper (no real wait ever happens) */
    private $waits = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->waits = array();
        $waits = &$this->waits;
        $this->svc = new IfirmaContractorService($client, function ($seconds) use (&$waits) {
            $waits[] = $seconds;
        });
        $this->scriptContractorMapDb();
    }

    private function owned()
    {
        return array(
            'Nazwa' => 'Jan Kowalski',
            'Identyfikator' => 'PS-C7',
            'NIP' => '',
            'Ulica' => 'Prosta 1',
            'KodPocztowy' => '00-001',
            'Kraj' => 'Polska',
            'KodKraju' => 'PL',
            'Miejscowosc' => 'Warszawa',
            'Email' => 'jan@example.test',
            'OsobaFizyczna' => true,
        );
    }

    private function found(array $extra = array())
    {
        $row = array_merge($this->owned(), $extra);

        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array($row)))));
    }

    private function ok($info = 'Dodano kontrahenta')
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => $info, 'Wynik' => '124451232'))));
    }

    /** Verification GET after a POST: the contractor is found under its identifier. */
    private function verifiedGet($identifier)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array(array('Identyfikator' => $identifier, 'Nazwa' => 'Jan Kowalski'))))));
    }

    private function paths($method)
    {
        $out = array();
        foreach ($this->t->calls as $c) {
            if ($c['method'] === $method) {
                $out[] = parse_url($c['url'], PHP_URL_PATH);
            }
        }

        return $out;
    }

    public function testConstantsListOwnedAndAllowedFields()
    {
        $this->assertContains('KodKraju', IfirmaContractorService::FIELDS_OWNED);
        $this->assertNotContains('NumerKonta', IfirmaContractorService::FIELDS_OWNED);
        $this->assertContains('NumerKonta', IfirmaContractorService::FIELDS_ALLOWED);
        $this->assertContains('AdresKorespondencyjnyKraj', IfirmaContractorService::FIELDS_ALLOWED);
    }

    public function testIsEnabledDefaultsToOn()
    {
        $this->assertTrue($this->svc->isEnabled());
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->assertFalse($this->svc->isEnabled());
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 1);
        $this->assertTrue($this->svc->isEnabled());
    }

    public function testFetchFound()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found(array('NumerKonta' => '123')));
        $row = $this->svc->fetch('PS-C7');
        $this->assertSame('Jan Kowalski', $row['Nazwa']);
        $this->assertSame('123', $row['NumerKonta']);
        $this->assertSame(array('/iapi/kontrahenci/id/PS-C7.json'), $this->paths('GET'));
    }

    public function testFetchNotFoundByKod()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono"}}'));
        $this->assertNull($this->svc->fetch('PS-C7'));
    }

    public function testFetchNotFoundByEmptyWynik()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[]}}'));
        $this->assertNull($this->svc->fetch('PS-C7'));
    }

    public function testFetchTransportFailureIsFalse()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));
        $this->assertFalse($this->svc->fetch('PS-C7'));
    }

    public function testCreateSuccessAndFailure()
    {
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));
        $this->assertTrue($this->svc->create($this->owned()));
        $this->assertFalse($this->svc->create($this->owned()));
        $this->assertSame(array('/iapi/kontrahenci.json', '/iapi/kontrahenci.json'), $this->paths('POST'));
    }

    public function testNeedsUpdate()
    {
        $this->assertFalse($this->svc->needsUpdate($this->owned(), $this->owned()));
        // whitespace and non-owned differences are ignored
        $existing = $this->owned();
        $existing['Ulica'] = '  Prosta 1 ';
        $existing['NumerKonta'] = '1';
        $this->assertFalse($this->svc->needsUpdate($existing, $this->owned()));
        // an owned field changed
        $changed = $this->owned();
        $changed['Miejscowosc'] = 'Krakow';
        $this->assertTrue($this->svc->needsUpdate($this->owned(), $changed));
        // bool compare: 1 and true are equal; business vs person differs
        $existing = $this->owned();
        $existing['OsobaFizyczna'] = 1;
        $this->assertFalse($this->svc->needsUpdate($existing, $this->owned()));
        $existing['OsobaFizyczna'] = false;
        $this->assertTrue($this->svc->needsUpdate($existing, $this->owned()));
        // missing existing field counts as different when the owned value is non-empty
        $existing = $this->owned();
        unset($existing['KodKraju']);
        $this->assertTrue($this->svc->needsUpdate($existing, $this->owned()));
    }

    public function testUpdateMergesWhitelistAndPuts()
    {
        $existing = $this->owned();
        $existing['NumerKonta'] = '11112222';
        $existing['Wybrany'] = true;
        $existing['Miejscowosc'] = 'Old';
        $this->t->queue('PUT', '#kontrahenci/PS-C7\.json#', $this->ok('Zaktualizowano kontrahenta'));

        $this->assertTrue($this->svc->update('PS-C7', $existing, $this->owned()));

        $this->assertSame(array('/iapi/kontrahenci/PS-C7.json'), $this->paths('PUT'));
        $sent = json_decode($this->t->calls[0]['body'], true);
        $this->assertSame('11112222', $sent['NumerKonta']);
        $this->assertArrayNotHasKey('Wybrany', $sent);
        $this->assertSame('Warszawa', $sent['Miejscowosc']);
        $this->assertSame('PL', $sent['KodKraju']);
    }

    public function testUpdateFailure()
    {
        $this->t->queue('PUT', '#kontrahenci/#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));
        $this->assertFalse($this->svc->update('PS-C7', $this->owned(), $this->owned()));
    }

    public function testEnsureDisabled()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $r = $this->svc->ensure($this->owned());
        $this->assertNull($r->getIdentifier());
        $this->assertSame('disabled', $r->getAction());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testEnsureKept()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found());
        $r = $this->svc->ensure($this->owned());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('kept', $r->getAction());
        $this->assertCount(1, $this->t->calls);
    }

    public function testEnsureUpdated()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found(array('Ulica' => 'Stara 2')));
        $this->t->queue('PUT', '#kontrahenci/PS-C7\.json#', $this->ok('Zaktualizowano kontrahenta'));
        $r = $this->svc->ensure($this->owned());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('updated', $r->getAction());
        $this->assertSame(array('GET', 'PUT'), array_column($this->t->calls, 'method'));
    }

    public function testEnsureUpdateFailureFallsBack()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found(array('Ulica' => 'Stara 2')));
        $this->t->queue('PUT', '#kontrahenci/#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));
        $r = $this->svc->ensure($this->owned());
        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertStringContainsString('bad', $r->getMessage());
        $this->assertSame('update_failed', $r->getNote()['code']);
        $this->assertSame('PS-C7', $r->getNote()['params']['identifier']);
        $this->assertStringContainsString('bad', $r->getNote()['params']['error']);
    }

    public function testEnsureCreated()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $r = $this->svc->ensure($this->owned());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertSame(array('GET', 'POST', 'GET'), array_column($this->t->calls, 'method'));
        $sent = json_decode($this->t->calls[1]['body'], true);
        $this->assertSame('PS-C7', $sent['Identyfikator']);
    }

    public function testEnsureFallbackOnGetFailure()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));
        $r = $this->svc->ensure($this->owned());
        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertStringContainsString('timeout', $r->getMessage());
        $this->assertCount(1, $this->t->calls);
        $this->assertSame('lookup_failed', $r->getNote()['code']);
        $this->assertSame('PS-C7', $r->getNote()['params']['identifier']);
        $this->assertStringContainsString('timeout', $r->getNote()['params']['error']);
    }

    public function testEnsureFallbackOnPostFailure()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('body' => '{"response":{"Kod":208,"Informacja":"x"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Zly kod pocztowy"}}'));
        $r = $this->svc->ensure($this->owned());
        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertStringContainsString('Zly kod pocztowy', $r->getMessage());
        $this->assertSame('create_rejected', $r->getNote()['code']);
        $this->assertStringContainsString('Zly kod pocztowy', $r->getNote()['params']['error']);
        $this->assertSame(array('GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertSame(array(), $this->waits);
    }

    // ---- existing contractor matching (design D13) -------------------------------------------

    private function rows(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows))));
    }

    private function notFoundBody()
    {
        return array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
    }

    private function remote($identifier, array $extra = array())
    {
        return array_merge($this->owned(), array('Identyfikator' => $identifier), $extra);
    }

    private function hints(array $over = array())
    {
        return array_merge(array('nip' => '', 'email' => 'jan@example.test', 'postcode' => '00-001', 'name' => 'Jan Kowalski'), $over);
    }

    private function businessOwned()
    {
        return array_merge($this->owned(), array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481', 'OsobaFizyczna' => false));
    }

    private function businessHints(array $over = array())
    {
        return $this->hints(array_merge(array('nip' => '5252248481', 'name' => 'ACME Sp. z o.o.', 'email' => 'biuro@acme.test'), $over));
    }

    private function searchPaths()
    {
        return array_values(array_filter($this->paths('GET'), function ($p) {
            return strpos($p, '/kontrahenci/id/') === false;
        }));
    }

    private function mapped()
    {
        $rows = IfirmaTestState::objects('ifirma_contractor_map');

        return $rows ? reset($rows) : null;
    }

    public function testStoredMappingWinsWithoutAnySearch()
    {
        IfirmaContractorMap::link(7, 'KOWALSKI-01', 'import-nip', 'Jan Kowalski');
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->rows(array($this->remote('KOWALSKI-01'))));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('KOWALSKI-01', $r->getIdentifier());
        $this->assertSame('kept', $r->getAction());
        $this->assertSame('import-nip', $r->getMatchedBy());
        $this->assertSame('KOWALSKI-01', $r->getLinkedIdentifier());
        $this->assertSame(array('/iapi/kontrahenci/id/KOWALSKI-01.json'), $this->paths('GET'));
        $this->assertSame('import-nip', $this->mapped()['matched_by']);
    }

    public function testStoredMappingIsUpdatedThroughTheOwnedFieldMerge()
    {
        IfirmaContractorMap::link(7, 'KOWALSKI-01', 'nip');
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->rows(array($this->remote('KOWALSKI-01', array('Miejscowosc' => 'Krakow', 'NumerKonta' => '123')))));
        $this->t->queue('PUT', '#kontrahenci/KOWALSKI-01\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('updated', $r->getAction());
        $put = json_decode($this->t->calls[1]['body'], true);
        $this->assertSame('Warszawa', $put['Miejscowosc']);
        $this->assertSame('123', $put['NumerKonta']);
        $this->assertSame('KOWALSKI-01', $put['Identyfikator']);
        $this->assertSame('nip', $this->mapped()['matched_by']);
    }

    public function testMappedContractorDisappearedIsUnlinkedAndResolutionContinues()
    {
        IfirmaContractorMap::link(7, 'GONE-1', 'nip');
        $this->t->queue('GET', '#kontrahenci/id/GONE-1\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $rows = IfirmaTestState::objects('ifirma_contractor_map');
        $this->assertCount(1, $rows);
        $this->assertSame('PS-C7', $this->mapped()['ifirma_identifier']);
        $this->assertSame('module', $this->mapped()['matched_by']);
    }

    public function testMappingFetchFailureFallsBackToNullIdentifier()
    {
        IfirmaContractorMap::link(7, 'KOWALSKI-01', 'nip');
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('KOWALSKI-01', $this->mapped()['ifirma_identifier']);
        $this->assertCount(1, $this->t->calls);
        $this->assertSame('lookup_failed', $r->getNote()['code']);
        $this->assertSame('KOWALSKI-01', $r->getNote()['params']['identifier']);
        $this->assertStringContainsString('timeout', $r->getNote()['params']['error']);
    }

    public function testModuleContractorFoundIsLinkedAsModule()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('kept', $r->getAction());
        $this->assertSame('module', $r->getMatchedBy());
        $this->assertSame('PS-C7', $this->mapped()['ifirma_identifier']);
        $this->assertSame('module', $this->mapped()['matched_by']);
        $this->assertSame(array(), $this->searchPaths());
    }

    public function testEnsureWithoutCustomerNeverSearchesOrLinks()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('', $r->getMatchedBy());
        $this->assertNull($r->getLinkedIdentifier());
        $this->assertSame(array(), $this->searchPaths());
        $this->assertNull($this->mapped());
    }

    public function testBusinessCustomerMatchedByExactNip()
    {
        $remote = $this->remote('ACME-02', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '525-224-84-81', 'OsobaFizyczna' => false));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array(
            $this->remote('OTHER-9', array('NIP' => '5252248482')),
            $remote,
        )));
        $this->t->queue('PUT', '#kontrahenci/ACME-02\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('ACME-02', $r->getIdentifier());
        $this->assertSame('updated', $r->getAction());
        $this->assertSame('nip', $r->getMatchedBy());
        $this->assertSame('ACME-02', $r->getLinkedIdentifier());
        $this->assertSame(array('/iapi/kontrahenci/5252248481.json'), $this->searchPaths());
        $this->assertSame('ACME-02', $this->mapped()['ifirma_identifier']);
        $this->assertSame('nip', $this->mapped()['matched_by']);
        $this->assertSame('ACME Sp. z o.o.', $this->mapped()['ifirma_name']);
        $this->assertSame(array(), $this->paths('POST'));
    }

    public function testNipMatchThatIsUpToDateIsKept()
    {
        $remote = $this->remote('ACME-02', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481', 'OsobaFizyczna' => false));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array($remote)));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('kept', $r->getAction());
        $this->assertSame(array(), $this->paths('PUT'));
    }

    public function testSeveralNipMatchesPreferTheEqualNormalisedName()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array(
            $this->remote('ACME-OLD', array('Nazwa' => 'ACME Holding', 'NIP' => '5252248481', 'OsobaFizyczna' => false)),
            $this->remote('ACME-02', array('Nazwa' => 'acme  SP Z O O', 'NIP' => '5252248481', 'OsobaFizyczna' => false)),
        )));
        $this->t->queue('PUT', '#kontrahenci/ACME-02\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('ACME-02', $r->getIdentifier());
        $this->assertSame('nip', $r->getMatchedBy());
    }

    public function testSeveralNipMatchesFallBackToThePostcode()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array(
            $this->remote('ACME-A', array('Nazwa' => 'ACME A', 'NIP' => '5252248481', 'KodPocztowy' => '30-001', 'OsobaFizyczna' => false)),
            $this->remote('ACME-B', array('Nazwa' => 'ACME B', 'NIP' => '5252248481', 'KodPocztowy' => '00001', 'OsobaFizyczna' => false)),
        )));
        $this->t->queue('PUT', '#kontrahenci/ACME-B\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('ACME-B', $r->getIdentifier());
    }

    public function testSeveralNipMatchesWithTheBuyerNameFallBackToThePostcodeAmongThem()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array(
            $this->remote('ACME-A', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481', 'KodPocztowy' => '30-001', 'OsobaFizyczna' => false)),
            $this->remote('ACME-B', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481', 'KodPocztowy' => '00-001', 'OsobaFizyczna' => false)),
            // another name: not a candidate, although it has the buyer's postcode as well
            $this->remote('ACME-HOLDING', array('Nazwa' => 'ACME Holding', 'NIP' => '5252248481', 'KodPocztowy' => '00-001', 'OsobaFizyczna' => false)),
        )));
        // were the first of the two rows with the buyer name taken for the buyer, it would be updated
        $this->t->queue('PUT', '#kontrahenci/ACME-A\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('ACME-B', $r->getIdentifier());
        $this->assertSame('kept', $r->getAction());
        $this->assertSame('nip', $r->getMatchedBy());
        $this->assertSame('ACME-B', $r->getLinkedIdentifier());
        $this->assertSame(array('/iapi/kontrahenci/5252248481.json'), $this->searchPaths());
        $this->assertSame(array(), $this->paths('POST'));
        $this->assertSame(array(), $this->paths('PUT'));
        $this->assertSame('ACME-B', $this->mapped()['ifirma_identifier']);
        $this->assertSame('nip', $this->mapped()['matched_by']);
    }

    public function testSeveralNipMatchesWithoutATieBreakGiveUpOnTheNipStep()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array(
            $this->remote('ACME-A', array('Nazwa' => 'ACME A', 'NIP' => '5252248481', 'KodPocztowy' => '30-001')),
            $this->remote('ACME-B', array('Nazwa' => 'ACME B', 'NIP' => '5252248481', 'KodPocztowy' => '40-001')),
        )));
        $this->t->queue('GET', '#kontrahenci/ACME%20Sp\.%20z%20o\.o\.\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
    }

    public function testNipRowsWithAnotherNipAreIgnored()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array(
            $this->remote('OTHER', array('NIP' => '9995252248481')),
        )));
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('created', $r->getAction());
    }

    public function testANipOfNineDigitsIsNotSearched()
    {
        $nine = '525224848';
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        // were the nine digits searched, this contractor would be taken for the buyer
        $this->t->queue('GET', '#kontrahenci/525224848\.json#', $this->rows(array(
            $this->remote('ACME-02', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => $nine, 'OsobaFizyczna' => false)),
        )));
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure(
            array_merge($this->businessOwned(), array('NIP' => $nine)),
            7,
            $this->businessHints(array('nip' => $nine))
        );

        // only the name is searched, nothing matches it, and the module creates the contractor
        $this->assertSame(array('/iapi/kontrahenci/ACME%20Sp.%20z%20o.o..json'), $this->searchPaths());
        $this->assertSame(array('GET', 'GET', 'POST', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('module', $r->getMatchedBy());
    }

    public function testPrivatePersonMatchedByNameAndEmail()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('KOWALSKI-01', array('Email' => 'JAN@example.test', 'KodPocztowy' => '31-111')),
            $this->remote('KOWALSKI-02', array('Email' => 'inny@example.test', 'KodPocztowy' => '31-111')),
            $this->remote('KOWALSKI-WIERZ', array('Nazwa' => 'Jan Kowalski Handel', 'Email' => 'jan@example.test')),
        )));
        $this->t->queue('PUT', '#kontrahenci/KOWALSKI-01\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('KOWALSKI-01', $r->getIdentifier());
        $this->assertSame('name', $r->getMatchedBy());
        $this->assertSame('KOWALSKI-01', $this->mapped()['ifirma_identifier']);
        $this->assertSame('name', $this->mapped()['matched_by']);
        $this->assertSame(array('/iapi/kontrahenci/Jan%20Kowalski.json'), $this->searchPaths());
    }

    public function testPrivatePersonMatchedByNameAndPostcode()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('KOWALSKI-01', array('Email' => '', 'KodPocztowy' => '30-001')),
            $this->remote('KOWALSKI-02', array('Email' => 'x@example.test', 'KodPocztowy' => '00001')),
        )));
        $this->t->queue('PUT', '#kontrahenci/KOWALSKI-02\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('KOWALSKI-02', $r->getIdentifier());
        $this->assertSame('name', $r->getMatchedBy());
    }

    public function testAmbiguousNameCreatesTheModuleContractor()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('KOWALSKI-01', array('Email' => 'a@example.test', 'KodPocztowy' => '30-001')),
            $this->remote('KOWALSKI-02', array('Email' => 'b@example.test', 'KodPocztowy' => '40-001')),
        )));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('module', $this->mapped()['matched_by']);
    }

    public function testNameWithoutAConfirmingFieldIsRefused()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('KOWALSKI-01', array('Email' => '', 'KodPocztowy' => '')),
        )));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints(array('email' => '', 'postcode' => '')));

        $this->assertSame('created', $r->getAction());
    }

    public function testNameMatchIgnoresCaseDiacriticsAndDoubleSpaces()
    {
        $owned = array_merge($this->owned(), array('Nazwa' => "Za\xC5\xBC\xC3\xB3\xC5\x82\xC4\x87  G\xC4\x99\xC5\x9Bl\xC4\x85"));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/#', $this->rows(array(
            $this->remote('ZOLC-03', array('Nazwa' => 'ZAZOLC GESLA', 'Email' => 'jan@example.test')),
        )));
        $this->t->queue('PUT', '#kontrahenci/ZOLC-03\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($owned, 7, $this->hints(array('name' => $owned['Nazwa'])));

        $this->assertSame('ZOLC-03', $r->getIdentifier());
        $this->assertSame('name', $r->getMatchedBy());
    }

    public function testNormalizeName()
    {
        $this->assertSame('zazolc gesla jazn', IfirmaContractorService::normalizeName("  Za\xC5\xBC\xC3\xB3\xC5\x82\xC4\x87   G\xC4\x99\xC5\x9Bl\xC4\x85\tJA\xC5\xB9\xC5\x83 "));
        $this->assertSame('acme sp z o o', IfirmaContractorService::normalizeName('ACME Sp. z o.o.'));
        $this->assertSame('', IfirmaContractorService::normalizeName('  .. '));
    }

    public function testSearchPhraseIsUrlEncodedInTheRequest()
    {
        $this->t->queue('GET', '#kontrahenci/#', $this->rows(array()));

        $this->svc->search('Jan Kowalski & Syn');

        $this->assertSame('/iapi/kontrahenci/Jan%20Kowalski%20%26%20Syn.json', parse_url($this->t->calls[0]['url'], PHP_URL_PATH));
    }

    public function testSearchReturnsRowsAndEmptyOn208()
    {
        $this->t->queue('GET', '#kontrahenci/ABC\.json#', $this->rows(array($this->remote('A-1'))));
        $this->t->queue('GET', '#kontrahenci/NONE\.json#', $this->notFoundBody());

        $found = $this->svc->search('ABC');
        $this->assertCount(1, $found->getRows());
        $this->assertSame('', $found->getError());
        $none = $this->svc->search('NONE');
        $this->assertSame(array(), $none->getRows());
        $this->assertSame('', $none->getError());
        $blank = $this->svc->search('   ');
        $this->assertSame(array(), $blank->getRows());
        $this->assertSame('', $blank->getError());
    }

    public function testSearchFailuresAreNoMatchAndRecorded()
    {
        $this->t->queue('GET', '#kontrahenci/BOOM\.json#', array('status' => 0, 'error' => 'timeout'));
        $boom = $this->svc->search('BOOM');
        $this->assertSame(array(), $boom->getRows());
        $this->assertStringContainsString('timeout', $boom->getError());

        $this->t->queue('GET', '#kontrahenci/SIGN\.json#', array('body' => '{"response":{"Kod":403,"Informacja":"Bledny podpis"}}'));
        $sign = $this->svc->search('SIGN');
        $this->assertSame(array(), $sign->getRows());
        $this->assertStringContainsString('Bledny podpis', $sign->getError());
    }

    public function testSearchTransportErrorNeverBlocksCreation()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('status' => 0, 'error' => 'connection reset'));
        $this->t->queue('GET', '#kontrahenci/ACME#', array('status' => 0, 'error' => 'connection reset'));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
        // both searches were tried and failed, and the contractor was created all the same
        $this->assertSame(array('/iapi/kontrahenci/5252248481.json', '/iapi/kontrahenci/ACME%20Sp.%20z%20o.o..json'), $this->searchPaths());
        // the message of such a failure is the one search() reports
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('status' => 0, 'error' => 'connection reset'));
        $this->assertStringContainsString('connection reset', $this->svc->search('5252248481')->getError());
    }

    public function testSearchKod403NeverBlocksCreation()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', array('body' => '{"response":{"Kod":403,"Informacja":"Bledny podpis"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('created', $r->getAction());
        // the name search was tried and refused, and the contractor was created all the same
        $this->assertSame(array('/iapi/kontrahenci/Jan%20Kowalski.json'), $this->searchPaths());
        // the message of such a refusal is the one search() reports
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', array('body' => '{"response":{"Kod":403,"Informacja":"Bledny podpis"}}'));
        $this->assertSame('Bledny podpis', $this->svc->search('Jan Kowalski')->getError());
    }

    public function testCreateAndResolveReturnsTheSentIdentifierWhenItIsFound()
    {
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->createAndResolve($this->owned(), 0, $this->hints());

        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertSame(array('POST', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame(array(), $this->waits);
        $this->assertNull($r->getAssignedId());
    }

    public function testCreatedContractorIsLinkedUnderTheWynikIdentifierWhenTheSentOneIsUnknown()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', $this->verifiedGet('124451232'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('124451232', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertSame('124451232', $r->getLinkedIdentifier());
        $this->assertSame('124451232', $this->mapped()['ifirma_identifier']);
        $this->assertSame('module', $this->mapped()['matched_by']);
        $this->assertSame(array('GET', 'GET', 'POST', 'GET', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame(array(), $this->waits);
        $this->assertSame('124451232', $r->getAssignedId());
        $this->assertSame('assigned', $r->getNote()['code']);
    }

    public function testCreatedContractorWhoseIdentifierCannotBeReadBackFallsBackWithBothIdentifiers()
    {
        $this->queueUntilCreate();
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        // message keeps the English sentence naming both identifiers (older panels, diagnostics)
        $this->assertStringContainsString('PS-C7', $r->getMessage());
        $this->assertStringContainsString('124451232', $r->getMessage());
        $this->assertNull($this->mapped());
    }

    // ---- created contractor read-back (harden-auto-issuance-delivery D1/D2) -------------------

    private function emptyListBody()
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array()))));
    }

    /** ensure() for the private person up to and including the successful create (GET id, name search, POST). */
    private function queueUntilCreate()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
    }

    /** $count Kod 208 read-backs, alternating PS-C7 / 124451232 (two per pass). */
    private function queueReadbackMisses($count)
    {
        for ($i = 0; $i < $count; $i++) {
            $this->t->queue('GET', $i % 2 === 0 ? '#kontrahenci/id/PS-C7\.json#' : '#kontrahenci/id/124451232\.json#', $this->notFoundBody());
        }
    }

    public function testReadbackConfirmedAtOnceMakesNoWaitAndNoSearch()
    {
        $this->queueUntilCreate();
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertNull($r->getNote());
        $this->assertNull($r->getAssignedId());
        $this->assertSame(array('GET', 'GET', 'POST', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame(array(), $this->waits);
        $this->assertSame(array('/iapi/kontrahenci/Jan%20Kowalski.json'), $this->searchPaths());
        $this->assertSame('PS-C7', $this->mapped()['ifirma_identifier']);
        $this->assertSame('module', $this->mapped()['matched_by']);
    }

    public function testSingleObjectReadbackAnswerCountsAsFound()
    {
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'Informacja' => '', 'Wynik' => array('Identyfikator' => 'PS-C7', 'Nazwa' => 'Jan Kowalski'),
        )))));

        $this->assertSame('PS-C7', $this->svc->createAndResolve($this->owned(), 0, $this->hints())->getIdentifier());
        $this->assertSame(array('POST', 'GET'), array_column($this->t->calls, 'method'));

        $this->t->queue('GET', '#kontrahenci/id/PS-C8\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'Informacja' => '', 'Wynik' => array('Identyfikator' => 'PS-C8'),
        )))));
        $row = $this->svc->fetch('PS-C8');
        $this->assertSame('PS-C8', $row['Identyfikator']);
    }

    public function testWynikObjectAndTopLevelIdentifierAreCandidates()
    {
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'Informacja' => '', 'Identyfikator' => 'TOP-1', 'Wynik' => array('Identyfikator' => 'OBJ-1'),
        )))));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/id/OBJ-1\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/id/TOP-1\.json#', $this->verifiedGet('TOP-1'));

        $r = $this->svc->createAndResolve($this->owned());

        $this->assertSame('TOP-1', $r->getIdentifier());
        $this->assertSame(
            array('/iapi/kontrahenci/id/PS-C7.json', '/iapi/kontrahenci/id/OBJ-1.json', '/iapi/kontrahenci/id/TOP-1.json'),
            $this->paths('GET')
        );
        $this->assertSame('TOP-1', $r->getAssignedId());
    }

    public function testContractorVisibleAfterTheWaitIsReferencedWithoutSearch()
    {
        $this->queueUntilCreate();
        $this->queueReadbackMisses(2);
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertNull($r->getNote());
        $this->assertSame(array(IfirmaContractorService::READBACK_WAIT_SECONDS), $this->waits);
        $this->assertSame(array(2), $this->waits);
        $this->assertSame(array('GET', 'GET', 'POST', 'GET', 'GET', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame(array('/iapi/kontrahenci/Jan%20Kowalski.json'), $this->searchPaths());
        $this->assertSame('PS-C7', $this->mapped()['ifirma_identifier']);
    }

    public function testBusinessContractorFoundByNipUnderTheIdentifierIFirmaAssigned()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array(
            array_merge($this->remote('IFI1909556979'), array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '525-224-84-81', 'OsobaFizyczna' => false)),
        )));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('IFI1909556979', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertSame('module', $r->getMatchedBy());
        $this->assertSame('IFI1909556979', $r->getAssignedId());
        $this->assertSame(array('code' => 'assigned', 'params' => array('identifier' => 'IFI1909556979')), $r->getNote());
        $this->assertSame('IFI1909556979', $this->mapped()['ifirma_identifier']);
        $this->assertSame('module', $this->mapped()['matched_by']);
        $this->assertSame(array(2), $this->waits);
        $this->assertSame('found', $r->getReadback()['search']);
    }

    public function testPrivatePersonFoundByNameAndEmailUnderAnAssignedIdentifier()
    {
        $this->queueUntilCreate();
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('IFI77', array('Email' => 'jan@example.test')),
        )));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('IFI77', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertSame('assigned', $r->getNote()['code']);
        $this->assertSame('IFI77', $this->mapped()['ifirma_identifier']);
        $this->assertSame('module', $this->mapped()['matched_by']);
    }

    public function testAmbiguousNipSearchAfterTheCreateFallsBackWithoutMapping()
    {
        $twoRows = $this->rows(array(
            array_merge($this->remote('X-1'), array('Nazwa' => 'Inna Spolka A', 'NIP' => '5252248481', 'KodPocztowy' => '30-001')),
            array_merge($this->remote('X-2'), array('Nazwa' => 'Inna Spolka B', 'NIP' => '5252248481', 'KodPocztowy' => '40-001')),
        ));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $twoRows);
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $twoRows);
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('readback_failed', $r->getNote()['code']);
        $this->assertSame('ambiguous', $r->getNote()['params']['search']);
        $this->assertNull($this->mapped());
    }

    public function testNothingFoundRecordsEveryAnswerAndTheEmptySearch()
    {
        $this->queueUntilCreate();
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertNull($this->mapped());
        $this->assertSame(array(2), $this->waits);
        $this->assertSame('readback_failed', $r->getNote()['code']);
        // shape: one entry per fetch, two passes over the two candidates (sent, reported) = 4 entries
        $miss = 'Kod 208: Nie znaleziono kontrahenta';
        $this->assertSame(array(
            array('identifier' => 'PS-C7', 'answer' => $miss),
            array('identifier' => '124451232', 'answer' => $miss),
            array('identifier' => 'PS-C7', 'answer' => $miss),
            array('identifier' => '124451232', 'answer' => $miss),
        ), $r->getNote()['params']['tried']);
        $this->assertSame('none', $r->getNote()['params']['search']);
        $this->assertSame('', $r->getNote()['params']['search_error']);
        $this->assertSame($r->getNote()['params']['tried'], $r->getReadback()['tried']);
        $this->assertStringContainsString('Kod 208: Nie znaleziono kontrahenta', $r->getMessage());
        $this->assertSame(array('GET', 'GET', 'POST', 'GET', 'GET', 'GET', 'GET', 'GET'), array_column($this->t->calls, 'method'));
    }

    public function testEmptyResultAnswersAreRecordedAsSuch()
    {
        $this->queueUntilCreate();
        for ($i = 0; $i < 2; $i++) {
            $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->emptyListBody());
            $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', $this->emptyListBody());
        }
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('fallback', $r->getAction());
        foreach ($r->getNote()['params']['tried'] as $entry) {
            $this->assertSame('empty result', $entry['answer']);
        }
        $this->assertCount(4, $r->getNote()['params']['tried']);
    }

    public function testReadbackTransportFailureContinuesToTheWaitAndTheSearch()
    {
        $this->queueUntilCreate();
        for ($i = 0; $i < 2; $i++) {
            $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', array('status' => 0, 'error' => 'connection reset by peer'));
            $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', array('status' => 0, 'error' => 'connection reset by peer'));
        }
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('fallback', $r->getAction());
        $this->assertSame(array(2), $this->waits);
        $this->assertSame('readback_failed', $r->getNote()['code']);
        foreach ($r->getNote()['params']['tried'] as $entry) {
            $this->assertStringContainsString('connection reset by peer', $entry['answer']);
        }
        $this->assertSame('none', $r->getNote()['params']['search']);
        // GET id, name search, POST, 4 read-backs, post-create name search
        $this->assertCount(8, $this->t->calls);
    }

    public function testReadbackFollowedByAFailingSearchRecordsTheSearchError()
    {
        $this->queueUntilCreate();
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', array('status' => 0, 'error' => 'search timeout'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('error', $r->getNote()['params']['search']);
        $this->assertStringContainsString('search timeout', $r->getNote()['params']['search_error']);
    }

    public function testCreateWithoutHintsSkipsTheSearch()
    {
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);

        $r = $this->svc->createAndResolve($this->owned());

        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertSame(array(), $this->searchPaths());
        $this->assertSame('none', $r->getReadback()['search']);
        $this->assertSame(array(2), $this->waits);
    }

    public function testRejectedCreateMakesNoReadbackWaitOrSearch()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Zly kod pocztowy"}}'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('create_rejected', $r->getNote()['code']);
        $this->assertStringContainsString('Zly kod pocztowy', $r->getNote()['params']['error']);
        $this->assertSame(array('GET', 'GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertSame(array(), $this->waits);
        $this->assertSame(array(), $r->getReadback()['tried']);
    }

    public function testMapTableFailuresNeverBlockEnsure()
    {
        Db::getInstance()->whenGetValue('/ifirma_contractor_map/', function () {
            throw new Exception('table is gone');
        });
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('kept', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
    }

    // ---- the exact ensure() answer of every outcome (refactor-clean-code-tda 13.1 + 13.2) -----------
    // Each outcome is pinned in full: the object that replaces the array has to reproduce every key,
    // in this order, with these types.

    public function testEnsureAnswersTheExactArrayWhenTheSyncIsOff()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array('identifier' => null, 'action' => 'disabled', 'message' => '', 'matched_by' => '', 'linked_identifier' => null, 'note' => null, 'assigned_identifier' => null), $r->toArray());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testEnsureWithoutAContractorIdentifierAnswersDisabledAndMakesNoCall()
    {
        $owned = $this->owned();
        $owned['Identyfikator'] = '  ';

        $r = $this->svc->ensure($owned, 7, $this->hints());

        $this->assertSame(array('identifier' => null, 'action' => 'disabled', 'message' => '', 'matched_by' => '', 'linked_identifier' => null, 'note' => null, 'assigned_identifier' => null), $r->toArray());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testEnsureAnswersTheExactArrayWhenTheModuleContractorIsKept()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array('identifier' => 'PS-C7', 'action' => 'kept', 'message' => '', 'matched_by' => 'module', 'linked_identifier' => 'PS-C7', 'note' => null, 'assigned_identifier' => null), $r->toArray());
    }

    public function testEnsureWithoutACustomerStillNamesTheModuleAsTheMatchOfAKeptContractor()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found());

        $r = $this->svc->ensure($this->owned());

        $this->assertSame(array('identifier' => 'PS-C7', 'action' => 'kept', 'message' => '', 'matched_by' => 'module', 'linked_identifier' => 'PS-C7', 'note' => null, 'assigned_identifier' => null), $r->toArray());
        $this->assertNull($this->mapped());
    }

    public function testEnsureAnswersTheExactArrayWhenTheStoredMappingIsKept()
    {
        IfirmaContractorMap::link(7, 'KOWALSKI-01', 'import-nip', 'Jan Kowalski');
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->rows(array($this->remote('KOWALSKI-01'))));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array('identifier' => 'KOWALSKI-01', 'action' => 'kept', 'message' => '', 'matched_by' => 'import-nip', 'linked_identifier' => 'KOWALSKI-01', 'note' => null, 'assigned_identifier' => null), $r->toArray());
    }

    public function testEnsureAnswersTheExactArrayWhenTheModuleContractorIsUpdated()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found(array('Ulica' => 'Stara 2')));
        $this->t->queue('PUT', '#kontrahenci/PS-C7\.json#', $this->ok('Zaktualizowano kontrahenta'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array('identifier' => 'PS-C7', 'action' => 'updated', 'message' => '', 'matched_by' => 'module', 'linked_identifier' => 'PS-C7', 'note' => null, 'assigned_identifier' => null), $r->toArray());
    }

    public function testEnsureAnswersTheExactArrayWhenTheNipMatchIsUpdated()
    {
        $remote = $this->remote('ACME-02', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '525-224-84-81', 'OsobaFizyczna' => false));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array($remote)));
        $this->t->queue('PUT', '#kontrahenci/ACME-02\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame(array('identifier' => 'ACME-02', 'action' => 'updated', 'message' => '', 'matched_by' => 'nip', 'linked_identifier' => 'ACME-02', 'note' => null, 'assigned_identifier' => null), $r->toArray());
    }

    public function testEnsureAnswersTheExactArrayWhenTheNameMatchIsUpdated()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array($this->remote('KOWALSKI-01', array('Email' => 'JAN@example.test', 'KodPocztowy' => '31-111')))));
        $this->t->queue('PUT', '#kontrahenci/KOWALSKI-01\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array('identifier' => 'KOWALSKI-01', 'action' => 'updated', 'message' => '', 'matched_by' => 'name', 'linked_identifier' => 'KOWALSKI-01', 'note' => null, 'assigned_identifier' => null), $r->toArray());
    }

    public function testEnsureAnswersTheExactArrayWhenTheModuleContractorIsCreated()
    {
        $this->queueUntilCreate();
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array('identifier' => 'PS-C7', 'action' => 'created', 'message' => '', 'matched_by' => 'module', 'linked_identifier' => 'PS-C7', 'note' => null, 'assigned_identifier' => null), $r->toArray());
    }

    public function testEnsureAnswersTheExactArrayWhenTheContractorIsCreatedForNoCustomer()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned());

        $this->assertSame(array('identifier' => 'PS-C7', 'action' => 'created', 'message' => '', 'matched_by' => '', 'linked_identifier' => null, 'note' => null, 'assigned_identifier' => null), $r->toArray());
    }

    public function testEnsureAnswersTheExactArrayWhenTheContractorIsCreatedUnderAnAssignedIdentifier()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', $this->verifiedGet('124451232'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array(
            'identifier' => '124451232',
            'action' => 'created',
            'message' => '',
            'matched_by' => 'module',
            'linked_identifier' => '124451232',
            'note' => array('code' => 'assigned', 'params' => array('identifier' => '124451232')),
            'assigned_identifier' => '124451232',
        ), $r->toArray());
    }

    public function testEnsureAnswersTheExactArrayWhenTheStoredMappingCannotBeLookedUp()
    {
        IfirmaContractorMap::link(7, 'KOWALSKI-01', 'nip');
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array(
            'identifier' => null,
            'action' => 'fallback',
            'message' => 'cURL error: timeout',
            'matched_by' => '',
            'linked_identifier' => null,
            'note' => array('code' => 'lookup_failed', 'params' => array('identifier' => 'KOWALSKI-01', 'error' => 'cURL error: timeout')),
            'assigned_identifier' => null,
        ), $r->toArray());
        $this->assertSame(array('tried' => array(), 'search' => 'none', 'search_error' => ''), $r->getReadback());
    }

    public function testEnsureAnswersTheExactArrayWhenTheModuleContractorCannotBeLookedUp()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array(
            'identifier' => null,
            'action' => 'fallback',
            'message' => 'cURL error: timeout',
            'matched_by' => '',
            'linked_identifier' => null,
            'note' => array('code' => 'lookup_failed', 'params' => array('identifier' => 'PS-C7', 'error' => 'cURL error: timeout')),
            'assigned_identifier' => null,
        ), $r->toArray());
        $this->assertSame(array('tried' => array(), 'search' => 'none', 'search_error' => ''), $r->getReadback());
    }

    public function testEnsureAnswersTheExactArrayWhenTheUpdateIsRejected()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found(array('Ulica' => 'Stara 2')));
        $this->t->queue('PUT', '#kontrahenci/#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array(
            'identifier' => null,
            'action' => 'fallback',
            'message' => 'Validation error in document data (Kod 201): bad',
            'matched_by' => '',
            'linked_identifier' => null,
            'note' => array('code' => 'update_failed', 'params' => array('identifier' => 'PS-C7', 'error' => 'Validation error in document data (Kod 201): bad')),
            'assigned_identifier' => null,
        ), $r->toArray());
        $this->assertSame(array('tried' => array(), 'search' => 'none', 'search_error' => ''), $r->getReadback());
    }

    public function testEnsureAnswersTheExactArrayWhenTheCreateIsRejected()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Zly kod pocztowy"}}'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array(
            'identifier' => null,
            'action' => 'fallback',
            'message' => 'Validation error in document data (Kod 201): Zly kod pocztowy',
            'matched_by' => '',
            'linked_identifier' => null,
            'note' => array('code' => 'create_rejected', 'params' => array('error' => 'Validation error in document data (Kod 201): Zly kod pocztowy')),
            'assigned_identifier' => null,
        ), $r->toArray());
        $this->assertSame(array('tried' => array(), 'search' => 'none', 'search_error' => ''), $r->getReadback());
    }

    public function testEnsureAnswersTheExactArrayWhenTheCreateNeverReachesIFirma()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array(
            'identifier' => null,
            'action' => 'fallback',
            'message' => 'cURL error: timeout',
            'matched_by' => '',
            'linked_identifier' => null,
            'note' => array('code' => 'create_rejected', 'params' => array('error' => 'cURL error: timeout')),
            'assigned_identifier' => null,
        ), $r->toArray());
        $this->assertSame(array('tried' => array(), 'search' => 'none', 'search_error' => ''), $r->getReadback());
        $this->assertSame(array('GET', 'GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertSame(array(), $this->waits);
        $this->assertNull($this->mapped());
    }

    public function testEnsureAnswersTheExactArrayWhenTheCreatedContractorCannotBeReadBack()
    {
        $this->queueUntilCreate();
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $miss = 'Kod 208: Nie znaleziono kontrahenta';
        $tried = array(
            array('identifier' => 'PS-C7', 'answer' => $miss),
            array('identifier' => '124451232', 'answer' => $miss),
            array('identifier' => 'PS-C7', 'answer' => $miss),
            array('identifier' => '124451232', 'answer' => $miss),
        );
        $this->assertSame(array(
            'identifier' => null,
            'action' => 'fallback',
            'message' => 'iFirma created the contractor but it cannot be read back (PS-C7: ' . $miss . '; 124451232: ' . $miss . '); search: none.',
            'matched_by' => '',
            'linked_identifier' => null,
            'note' => array('code' => 'readback_failed', 'params' => array('tried' => $tried, 'search' => 'none', 'search_error' => '')),
            'assigned_identifier' => null,
        ), $r->toArray());
        $this->assertSame(array('tried' => $tried, 'search' => 'none', 'search_error' => ''), $r->getReadback());
        $this->assertNull($r->getAssignedId());
    }

    public function testTheNipSearchAfterTheCreateFailingIsReportedWithItsMessage()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('status' => 0, 'error' => 'nip search down'));
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('error', $r->getNote()['params']['search']);
        $this->assertSame('cURL error: nip search down', $r->getNote()['params']['search_error']);
        $miss = 'Kod 208: Nie znaleziono kontrahenta';
        $this->assertSame(
            'iFirma created the contractor but it cannot be read back (PS-C7: ' . $miss . '; 124451232: ' . $miss . '); search: error (cURL error: nip search down).',
            $r->getMessage()
        );
        $this->assertNull($this->mapped());
    }

    public function testTheFirstSearchErrorAfterTheCreateWinsOverTheNameSearchOne()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('status' => 0, 'error' => 'nip search down'));
        $this->t->queue('GET', '#kontrahenci/ACME#', array('status' => 0, 'error' => 'name search down'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('error', $r->getNote()['params']['search']);
        $this->assertSame('cURL error: nip search down', $r->getNote()['params']['search_error']);
    }

    public function testASecondEnsureOnTheSameInstanceCarriesNothingOverFromTheFirst()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', $this->verifiedGet('124451232'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found());

        $first = $this->svc->ensure($this->owned(), 7, $this->hints());
        $this->assertSame('124451232', $first->getAssignedId());

        $second = $this->svc->ensure($this->owned());

        $this->assertSame(array('identifier' => 'PS-C7', 'action' => 'kept', 'message' => '', 'matched_by' => 'module', 'linked_identifier' => 'PS-C7', 'note' => null, 'assigned_identifier' => null), $second->toArray());
        $this->assertNull($second->getAssignedId());
        $this->assertSame(array('tried' => array(), 'search' => 'none', 'search_error' => ''), $second->getReadback());
        // the first answer is a value of its own: the second call did not touch it
        $this->assertSame('created', $first->getAction());
        $this->assertSame('124451232', $first->getAssignedId());
        $this->assertSame('assigned', $first->getNote()['code']);
    }

    public function testAKeptContractorHasNoReadback()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->found());

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame(array('tried' => array(), 'search' => 'none', 'search_error' => ''), $r->getReadback());
        $this->assertNull($r->getAssignedId());
    }

    public function testTheReadbackOfAConfirmedCreateListsTheAnswersUpToTheHit()
    {
        $miss = 'Kod 208: Nie znaleziono kontrahenta';

        // found at once under the sent identifier
        $this->queueUntilCreate();
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));
        $r = $this->svc->ensure($this->owned(), 7, $this->hints());
        $this->assertSame(
            array('tried' => array(array('identifier' => 'PS-C7', 'answer' => 'found')), 'search' => 'none', 'search_error' => ''),
            $r->getReadback()
        );

        // found under the identifier iFirma reported, in the first pass
        $this->queueUntilCreate();
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', $this->verifiedGet('124451232'));
        $r = $this->svc->ensure($this->owned(), 8, $this->hints());
        $this->assertSame(
            array(
                'tried' => array(array('identifier' => 'PS-C7', 'answer' => $miss), array('identifier' => '124451232', 'answer' => 'found')),
                'search' => 'none',
                'search_error' => '',
            ),
            $r->getReadback()
        );

        // found in the second pass, after the wait
        $this->queueUntilCreate();
        $this->queueReadbackMisses(2);
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));
        $r = $this->svc->ensure($this->owned(), 9, $this->hints());
        $this->assertSame(
            array(
                'tried' => array(
                    array('identifier' => 'PS-C7', 'answer' => $miss),
                    array('identifier' => '124451232', 'answer' => $miss),
                    array('identifier' => 'PS-C7', 'answer' => 'found'),
                ),
                'search' => 'none',
                'search_error' => '',
            ),
            $r->getReadback()
        );
        $this->assertSame(array(2), $this->waits);
    }

    public function testTheReadbackOfAContractorFoundBySearchListsEveryAnswerAndTheSearch()
    {
        $miss = 'Kod 208: Nie znaleziono kontrahenta';
        $this->queueUntilCreate();
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('IFI77', array('Email' => 'jan@example.test')),
        )));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('IFI77', $r->getIdentifier());
        $this->assertSame(
            array(
                'tried' => array(
                    array('identifier' => 'PS-C7', 'answer' => $miss),
                    array('identifier' => '124451232', 'answer' => $miss),
                    array('identifier' => 'PS-C7', 'answer' => $miss),
                    array('identifier' => '124451232', 'answer' => $miss),
                ),
                'search' => 'found',
                'search_error' => '',
            ),
            $r->getReadback()
        );
        $this->assertSame('IFI77', $r->getAssignedId());
    }

    public function testAmbiguousNipRowsAfterTheCreateStillLetTheNameSearchFindTheContractor()
    {
        $twoRows = $this->rows(array(
            array_merge($this->remote('X-1'), array('Nazwa' => 'Inna Spolka A', 'NIP' => '5252248481', 'KodPocztowy' => '30-001')),
            array_merge($this->remote('X-2'), array('Nazwa' => 'Inna Spolka B', 'NIP' => '5252248481', 'KodPocztowy' => '40-001')),
        ));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $twoRows);
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->rows(array(
            array_merge($this->remote('IFI-NAME'), array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481', 'Email' => 'biuro@acme.test', 'OsobaFizyczna' => false)),
        )));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('IFI-NAME', $r->getIdentifier());
        $this->assertSame('IFI-NAME', $r->getAssignedId());
        $this->assertSame('found', $r->getReadback()['search']);
        $this->assertSame('', $r->getReadback()['search_error']);
    }

    public function testANipRowWithoutAnIdentifierAfterTheCreateIsNotAMatch()
    {
        $noIdentifier = $this->remote('X-1', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481'));
        unset($noIdentifier['Identyfikator']);
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array($noIdentifier)));
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('readback_failed', $r->getNote()['code']);
        $this->assertSame('none', $r->getNote()['params']['search']);
        $this->assertSame('', $r->getNote()['params']['search_error']);
        // the NIP and the name were searched before the create and again after it
        $this->assertSame(
            array('/iapi/kontrahenci/5252248481.json', '/iapi/kontrahenci/ACME%20Sp.%20z%20o.o..json', '/iapi/kontrahenci/5252248481.json', '/iapi/kontrahenci/ACME%20Sp.%20z%20o.o..json'),
            $this->searchPaths()
        );
        $this->assertNull($this->mapped());
    }

    public function testANipMatchWithoutAnIdentifierIsNotUsedAndTheNameIsNotSearched()
    {
        $noIdentifier = $this->remote('X-1', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481'));
        unset($noIdentifier['Identyfikator']);
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->rows(array($noIdentifier)));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('module', $r->getMatchedBy());
        $this->assertSame(array('/iapi/kontrahenci/5252248481.json'), $this->searchPaths());
        $this->assertSame(array(), $this->paths('PUT'));
    }

    public function testCreateSearchesNothingAndLinksNothing()
    {
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $this->assertTrue($this->svc->create($this->owned()));

        $this->assertSame(array('POST', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame(array(), $this->searchPaths());
        $this->assertNull($this->mapped());
        $this->assertSame(array(), $this->waits);
    }

    // ---- matching rules not reached by the tests above ---------------------------------------------

    public function testAContractorNameThatNormalisesToNothingIsNeverSearched()
    {
        $owned = array_merge($this->owned(), array('Nazwa' => '..'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($owned, 7, $this->hints(array('name' => '..')));

        $this->assertSame('created', $r->getAction());
        $this->assertSame(array(), $this->searchPaths());
    }

    public function testANameMatchWithAnotherNipIsNotTheBuyer()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->rows(array(
            $this->remote('ACME-OTHER', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '1111111111', 'Email' => 'biuro@acme.test', 'OsobaFizyczna' => false)),
        )));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame(array(), $this->paths('PUT'));
        $this->assertSame('PS-C7', $this->mapped()['ifirma_identifier']);
    }

    public function testANameMatchWithoutANipStillCountsWhenTheEmailConfirmsIt()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->rows(array(
            $this->remote('ACME-NONIP', array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '', 'Email' => 'biuro@acme.test', 'OsobaFizyczna' => false)),
        )));
        $this->t->queue('PUT', '#kontrahenci/ACME-NONIP\.json#', $this->ok('Zaktualizowano'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('updated', $r->getAction());
        $this->assertSame('ACME-NONIP', $r->getIdentifier());
        $this->assertSame('name', $r->getMatchedBy());
    }

    public function testSeveralNameAndEmailMatchesFallBackToThePostcode()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('KOWALSKI-01', array('KodPocztowy' => '30-001')),
            $this->remote('KOWALSKI-02', array('KodPocztowy' => '00-001')),
        )));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('kept', $r->getAction());
        $this->assertSame('KOWALSKI-02', $r->getIdentifier());
        $this->assertSame('name', $r->getMatchedBy());
        $this->assertSame(array(), $this->paths('POST'));
    }

    public function testSeveralNameAndEmailMatchesWithTheSamePostcodeAreNoMatch()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->rows(array(
            $this->remote('KOWALSKI-01', array('KodPocztowy' => '00-001')),
            $this->remote('KOWALSKI-02', array('KodPocztowy' => '00001')),
        )));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));

        $r = $this->svc->ensure($this->owned(), 7, $this->hints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('PS-C7', $r->getIdentifier());
    }

    // ---- search(): the rows and the message, per call ---------------------------------------------

    public function testSearchAnswersTheRecordsAsAListAndSkipsWhatIsNotARecord()
    {
        $this->t->queue('GET', '#kontrahenci/ABC\.json#', $this->rows(array(
            'first' => $this->remote('A-1'),
            'junk' => 'text',
            'second' => $this->remote('A-2'),
        )));

        $found = $this->svc->search('ABC');

        $this->assertSame(array($this->remote('A-1'), $this->remote('A-2')), $found->getRows());
        $this->assertSame('', $found->getError());
    }

    public function testSearchTheErrorsAreExactlyTheMessageOfTheFailure()
    {
        $this->t->queue('GET', '#kontrahenci/BOOM\.json#', array('status' => 0, 'error' => 'timeout'));
        $boom = $this->svc->search('BOOM');
        $this->assertSame(array(), $boom->getRows());
        $this->assertSame('cURL error: timeout', $boom->getError());

        $this->t->queue('GET', '#kontrahenci/SIGN\.json#', array('body' => '{"response":{"Kod":403,"Informacja":"Bledny podpis"}}'));
        $sign = $this->svc->search('SIGN');
        $this->assertSame(array(), $sign->getRows());
        $this->assertSame('Bledny podpis', $sign->getError());
    }

    public function testSearchNamesTheKodWhenIFirmaGivesNoText()
    {
        $this->t->queue('GET', '#kontrahenci/NOTEXT\.json#', array('body' => '{"response":{"Kod":500,"Informacja":""}}'));
        $this->t->queue('GET', '#kontrahenci/NOINFO\.json#', array('body' => '{"response":{"Kod":201}}'));

        $noText = $this->svc->search('NOTEXT');
        $this->assertSame(array(), $noText->getRows());
        $this->assertSame('Kod 500', $noText->getError());
        $noInfo = $this->svc->search('NOINFO');
        $this->assertSame(array(), $noInfo->getRows());
        $this->assertSame('Kod 201', $noInfo->getError());
    }

    public function testSearchWithoutAKodIsNoMatchAndNoError()
    {
        $this->t->queue('GET', '#kontrahenci/NOKOD\.json#', array('body' => '{"response":{"Informacja":"?"}}'));

        $noKod = $this->svc->search('NOKOD');
        $this->assertSame(array(), $noKod->getRows());
        $this->assertSame('', $noKod->getError());
    }

    public function testSearchAnswerWithoutAListOfRecordsIsNoMatchAndNoError()
    {
        $this->t->queue('GET', '#kontrahenci/NOLIST\.json#', array('body' => '{"response":{"Kod":0,"Informacja":""}}'));
        $this->t->queue('GET', '#kontrahenci/TEXT\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":"none"}}'));

        $noList = $this->svc->search('NOLIST');
        $this->assertSame(array(), $noList->getRows());
        $this->assertSame('', $noList->getError());
        $text = $this->svc->search('TEXT');
        $this->assertSame(array(), $text->getRows());
        $this->assertSame('', $text->getError());
    }

    public function testASearchErrorBelongsToItsOwnCallOnly()
    {
        $this->t->queue('GET', '#kontrahenci/BOOM\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('GET', '#kontrahenci/ABC\.json#', $this->rows(array($this->remote('A-1'))));

        $boom = $this->svc->search('BOOM');
        $this->assertSame('cURL error: timeout', $boom->getError());

        $abc = $this->svc->search('ABC');
        $this->assertCount(1, $abc->getRows());
        $this->assertSame('', $abc->getError());
        // the answer of the first call is a value of its own: the second call did not touch it
        $this->assertSame('cURL error: timeout', $boom->getError());

        $blank = $this->svc->search('');
        $this->assertSame(array(), $blank->getRows());
        $this->assertSame('', $blank->getError());
    }

    // ---- the search after a create that could not be read back: what it records ------------------------

    public function testAReadbackWithNothingToSearchForRecordsNoSearchAndNoError()
    {
        // a name of punctuation only and no NIP: both post-create searches have nothing to ask
        $owned = array_merge($this->owned(), array('Nazwa' => '..'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);

        $r = $this->svc->ensure($owned, 7, $this->hints(array('name' => '..')));

        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('readback_failed', $r->getNote()['code']);
        $this->assertSame('none', $r->getNote()['params']['search']);
        $this->assertSame('', $r->getNote()['params']['search_error']);
        $this->assertSame(array(), $this->searchPaths());
        $this->assertNull($this->mapped());
    }

    public function testTheNipRowCountOfAnEarlierCallNeverMakesALaterReadbackAmbiguous()
    {
        // the first call meets two contractors with the buyer's NIP and creates a new one
        $twoRows = $this->rows(array(
            array_merge($this->remote('X-1'), array('Nazwa' => 'Inna Spolka A', 'NIP' => '5252248481', 'KodPocztowy' => '30-001')),
            array_merge($this->remote('X-2'), array('Nazwa' => 'Inna Spolka B', 'NIP' => '5252248481', 'KodPocztowy' => '40-001')),
        ));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $twoRows);
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->verifiedGet('PS-C7'));
        $first = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());
        $this->assertSame('created', $first->getAction());

        // the second call, on the same instance, is a private person without NIP whose read-back fails
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->notFoundBody());
        $second = $this->svc->ensure($this->owned(), 8, $this->hints());

        $this->assertSame('fallback', $second->getAction());
        $this->assertSame('none', $second->getNote()['params']['search']);
        $this->assertSame('', $second->getNote()['params']['search_error']);
    }

    public function testANipSearchFailureDoesNotHideAContractorTheNameSearchFinds()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('status' => 0, 'error' => 'nip search down'));
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->rows(array(
            array_merge($this->remote('IFI-NAME'), array('Nazwa' => 'ACME Sp. z o.o.', 'NIP' => '5252248481', 'Email' => 'biuro@acme.test', 'OsobaFizyczna' => false)),
        )));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('created', $r->getAction());
        $this->assertSame('IFI-NAME', $r->getIdentifier());
        $this->assertSame('IFI-NAME', $r->getAssignedId());
        $this->assertSame('found', $r->getReadback()['search']);
        $this->assertSame('', $r->getReadback()['search_error']);
    }

    public function testAnAmbiguousNipAndAFailingNameSearchAreReportedAsTheError()
    {
        $twoRows = $this->rows(array(
            array_merge($this->remote('X-1'), array('Nazwa' => 'Inna Spolka A', 'NIP' => '5252248481', 'KodPocztowy' => '30-001')),
            array_merge($this->remote('X-2'), array('Nazwa' => 'Inna Spolka B', 'NIP' => '5252248481', 'KodPocztowy' => '40-001')),
        ));
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $this->notFoundBody());
        $this->t->queue('GET', '#kontrahenci/ACME#', $this->notFoundBody());
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $this->queueReadbackMisses(4);
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', $twoRows);
        $this->t->queue('GET', '#kontrahenci/ACME#', array('status' => 0, 'error' => 'name search down'));

        $r = $this->svc->ensure($this->businessOwned(), 7, $this->businessHints());

        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('error', $r->getNote()['params']['search']);
        $this->assertSame('cURL error: name search down', $r->getNote()['params']['search_error']);
        $miss = 'Kod 208: Nie znaleziono kontrahenta';
        $this->assertSame(
            'iFirma created the contractor but it cannot be read back (PS-C7: ' . $miss . '; 124451232: ' . $miss . '); search: error (cURL error: name search down).',
            $r->getMessage()
        );
    }
}
