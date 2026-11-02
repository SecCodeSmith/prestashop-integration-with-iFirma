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
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaContractorService */
    private $svc;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->svc = new IfirmaContractorService($client);
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
        $this->assertNull($r['identifier']);
        $this->assertSame('disabled', $r['action']);
        $this->assertSame(array(), $this->t->calls);
        $last = $this->svc->getLastAction();
        $this->assertSame('disabled', $last['action']);
    }

    public function testEnsureKept()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found());
        $r = $this->svc->ensure($this->owned());
        $this->assertSame('PS-C7', $r['identifier']);
        $this->assertSame('kept', $r['action']);
        $this->assertCount(1, $this->t->calls);
    }

    public function testEnsureUpdated()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found(array('Ulica' => 'Stara 2')));
        $this->t->queue('PUT', '#kontrahenci/PS-C7\.json#', $this->ok('Zaktualizowano kontrahenta'));
        $r = $this->svc->ensure($this->owned());
        $this->assertSame('PS-C7', $r['identifier']);
        $this->assertSame('updated', $r['action']);
        $this->assertSame(array('GET', 'PUT'), array_column($this->t->calls, 'method'));
        $this->assertSame($r, $this->svc->getLastAction());
    }

    public function testEnsureUpdateFailureFallsBack()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found(array('Ulica' => 'Stara 2')));
        $this->t->queue('PUT', '#kontrahenci/#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));
        $r = $this->svc->ensure($this->owned());
        $this->assertNull($r['identifier']);
        $this->assertSame('fallback', $r['action']);
        $this->assertStringContainsString('bad', $r['message']);
    }

    public function testEnsureCreated()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', $this->ok());
        $r = $this->svc->ensure($this->owned());
        $this->assertSame('PS-C7', $r['identifier']);
        $this->assertSame('created', $r['action']);
        $this->assertSame(array('GET', 'POST'), array_column($this->t->calls, 'method'));
        $sent = json_decode($this->t->calls[1]['body'], true);
        $this->assertSame('PS-C7', $sent['Identyfikator']);
    }

    public function testEnsureFallbackOnGetFailure()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));
        $r = $this->svc->ensure($this->owned());
        $this->assertNull($r['identifier']);
        $this->assertSame('fallback', $r['action']);
        $this->assertStringContainsString('timeout', $r['message']);
        $this->assertCount(1, $this->t->calls);
    }

    public function testEnsureFallbackOnPostFailure()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('body' => '{"response":{"Kod":208,"Informacja":"x"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Zly kod pocztowy"}}'));
        $r = $this->svc->ensure($this->owned());
        $this->assertNull($r['identifier']);
        $this->assertSame('fallback', $r['action']);
        $this->assertStringContainsString('Zly kod pocztowy', $r['message']);
    }
}
