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
 * Spec: customer-contractor-panel (link state, registration check, local count, remote count,
 * search, ranking, manual link, unlink).
 */
class CustomerContractorServiceTest extends TestCase
{
    use IfirmaContractorMapDb;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaCustomerContractorService */
    private $svc;

    /** @var array rows of the in-memory ifirma_receipt table: id_customer, document_type, status */
    private $receipts = array();

    /** @var int fixed "now": 2026-11-04 12:00:00 UTC */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = gmmktime(12, 0, 0, 11, 4, 2026);
        $this->receipts = array();
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $now = $this->now;
        $this->client->setClock(function () use ($now) {
            return $now;
        });
        $this->svc = new IfirmaCustomerContractorService(
            $this->client,
            new IfirmaContractorService($this->client, function () {
            }),
            function () use ($now) {
                return $now;
            }
        );
        $this->scriptContractorMapDb();
        $receipts = &$this->receipts;
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`\s+WHERE id_customer = (\d+)\s+GROUP BY document_type, status/', function ($sql, $m) use (&$receipts) {
            $groups = array();
            foreach ($receipts as $r) {
                if ((int) $r['id_customer'] !== (int) $m[1]) {
                    continue;
                }
                $k = $r['document_type'] . '|' . $r['status'];
                $groups[$k] = isset($groups[$k]) ? array_merge($groups[$k], array('total' => $groups[$k]['total'] + 1)) : array('document_type' => $r['document_type'], 'status' => $r['status'], 'total' => 1);
            }

            return array_values($groups);
        });

        $c = new Customer();
        $c->id = 7;
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        $c->email = 'Jan.Kowalski@Example.test';
        $c->date_add = '2026-03-10 09:30:00';
        Customer::register($c);
    }

    // ---- fixtures ---------------------------------------------------------------------------

    private function addReceipt($type, $status, $idCustomer = 7)
    {
        $this->receipts[] = array('id_customer' => $idCustomer, 'document_type' => $type, 'status' => $status);
    }

    private function address($id, array $props, $idCustomer = 7)
    {
        $a = new Address();
        $a->id = $id;
        $a->id_customer = $idCustomer;
        foreach ($props as $k => $v) {
            $a->$k = $v;
        }

        return Address::register($a);
    }

    private function order($id, $date, $idAddress, $idCustomer = 7)
    {
        $o = new Order();
        $o->id = $id;
        $o->id_customer = $idCustomer;
        $o->date_add = $date;
        $o->id_address_invoice = $idAddress;

        return Order::register($o);
    }

    private function body($node)
    {
        return array('body' => json_encode(array('response' => $node)));
    }

    private function contractor($identifier, array $extra = array())
    {
        return array_merge(array('Identyfikator' => $identifier, 'Nazwa' => 'Jan Kowalski'), $extra);
    }

    private function found($identifier, array $extra = array())
    {
        return $this->body(array('Kod' => 0, 'Informacja' => '', 'Wynik' => $this->contractor($identifier, $extra)));
    }

    private function results(array $rows)
    {
        return $this->body(array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows));
    }

    private function notFound()
    {
        return $this->body(array('Kod' => 208, 'Informacja' => 'Nie znaleziono kontrahenta'));
    }

    private function invoice($id, $brutto = 100, $zaplacono = 100, $rodzaj = 'prz_faktura_kraj')
    {
        return array('FakturaId' => $id, 'Rodzaj' => $rodzaj, 'Brutto' => $brutto, 'Zaplacono' => $zaplacono);
    }

    /** @return array[] decoded query of every faktury.json call */
    private function listQueries()
    {
        $out = array();
        foreach ($this->t->calls as $c) {
            if (strpos((string) parse_url($c['url'], PHP_URL_PATH), 'faktury.json') !== false) {
                parse_str((string) parse_url($c['url'], PHP_URL_QUERY), $q);
                $out[] = $q;
            }
        }

        return $out;
    }

    private function searchPaths()
    {
        $out = array();
        foreach ($this->t->calls as $c) {
            $p = (string) parse_url($c['url'], PHP_URL_PATH);
            if (preg_match('#/kontrahenci/(?!id/)([^/]+)\.json$#', $p, $m)) {
                $out[] = rawurldecode($m[1]);
            }
        }

        return $out;
    }

    private function linkCustomer($identifier = 'KOWALSKI-01', $by = IfirmaContractorMap::BY_IMPORT_EMAIL, $name = 'Jan Kowalski')
    {
        IfirmaContractorMap::link(7, $identifier, $by, $name);
    }

    private function days($from, $to)
    {
        return (int) round((strtotime($to . ' UTC') - strtotime($from . ' UTC')) / 86400);
    }

    // ---- contractor link state ----------------------------------------------------------------

    /** Scenario: Linked customer */
    public function testLinkedCustomer()
    {
        $this->linkCustomer();
        $this->assertSame(
            array('identifier' => 'KOWALSKI-01', 'linked' => true, 'name' => 'Jan Kowalski', 'matched_by' => 'import-email'),
            $this->svc->linkState(7)
        );
    }

    /** Scenario: Customer without link */
    public function testCustomerWithoutLink()
    {
        $this->assertSame(
            array('identifier' => 'PS-C7', 'linked' => false, 'name' => '', 'matched_by' => ''),
            $this->svc->linkState(7)
        );
        $this->assertCount(0, $this->t->calls);
    }

    // ---- local counts -------------------------------------------------------------------------

    /** Scenario: Mixed documents */
    public function testMixedDocuments()
    {
        $this->addReceipt('paragon', 'created');
        $this->addReceipt('rachunek', 'created');
        $this->addReceipt('fakturakraj', 'paid');
        $this->addReceipt('fakturakraj', 'error');
        $this->addReceipt('fakturakraj', 'created', 99); // another customer

        $counts = $this->svc->localCounts(7);

        $this->assertSame(3, $counts['issued']);
        $this->assertSame(1, $counts['paid']);
        $this->assertSame(0, $counts['held']);
        $this->assertSame(1, $counts['attention']);
        $this->assertSame(array('receipt' => 2, 'invoice' => 1, 'other' => 0), $counts['by_type']);
        $this->assertCount(0, $this->t->calls);
    }

    public function testHeldAndAttentionStatesAndOtherType()
    {
        $this->addReceipt('fakturakraj', 'held');
        $this->addReceipt('fakturakraj', 'unknown');
        $this->addReceipt('fakturakraj', 'duplicate_suspected');
        $this->addReceipt('fakturawysylka', 'created');

        $counts = $this->svc->localCounts(7);

        $this->assertSame(1, $counts['issued']);
        $this->assertSame(1, $counts['held']);
        $this->assertSame(2, $counts['attention']);
        $this->assertSame(array('receipt' => 0, 'invoice' => 0, 'other' => 1), $counts['by_type']);
    }

    /** Scenario: No documents */
    public function testNoDocuments()
    {
        $this->assertSame(
            array('issued' => 0, 'paid' => 0, 'held' => 0, 'attention' => 0, 'by_type' => array('receipt' => 0, 'invoice' => 0, 'other' => 0)),
            $this->svc->localCounts(7)
        );
    }

    public function testCountByCustomerQueryGroupsByTypeAndStatus()
    {
        $this->addReceipt('paragon', 'created');
        $rows = IfirmaReceipt::countByCustomer(7);
        $this->assertSame(array(array('document_type' => 'paragon', 'status' => 'created', 'total' => 1)), $rows);
        $sql = end(Db::getInstance()->queries);
        $this->assertStringContainsString('WHERE id_customer = 7', $sql);
        $this->assertStringContainsString('GROUP BY document_type, status', $sql);
    }

    // ---- defaults for the search form ---------------------------------------------------------

    public function testDefaultCriteriaUseLatestOrderInvoiceAddress()
    {
        $this->address(1, array('company' => 'Old Co', 'vat_number' => '111-111-11-11', 'phone' => '111'));
        $this->address(2, array('company' => 'ACME Sp. z o.o.', 'vat_number' => '123-456-32-18', 'phone' => '', 'phone_mobile' => '600 100 200'));
        $this->order(10, '2026-01-01 10:00:00', 1);
        $this->order(11, '2026-05-01 10:00:00', 2);

        $this->assertSame(
            array(
                'firstname' => 'Jan',
                'lastname' => 'Kowalski',
                'company' => 'ACME Sp. z o.o.',
                'nip' => '1234563218',
                'email' => 'Jan.Kowalski@Example.test',
                'phone' => '600 100 200',
            ),
            $this->svc->defaultCriteria(7)
        );
        $this->assertSame('1234563218', $this->svc->customerNip(7));
    }

    public function testDefaultCriteriaWithoutOrderUseNewestAddress()
    {
        $this->address(1, array('company' => 'Old Co', 'phone' => '111'));
        $this->address(2, array('company' => 'New Co', 'phone' => '222'));

        $criteria = $this->svc->defaultCriteria(7);

        $this->assertSame('New Co', $criteria['company']);
        $this->assertSame('222', $criteria['phone']);
        $this->assertSame('', $criteria['nip']);
        $this->assertSame('', $this->svc->customerNip(7));
    }

    // ---- registration check -------------------------------------------------------------------

    /** Scenario: Contractor exists */
    public function testContractorExists()
    {
        $this->linkCustomer();
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->found('KOWALSKI-01', array('Nazwa' => 'Jan Kowalski (iFirma)')));

        $check = $this->svc->check(7);

        $this->assertSame('registered', $check['state']);
        $this->assertSame('KOWALSKI-01', $check['identifier']);
        $this->assertSame('Jan Kowalski (iFirma)', $check['name']);
        $this->assertCount(1, $this->t->calls);
    }

    /** Scenario: Contractor missing */
    public function testContractorMissing()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C7\.json#', $this->notFound());

        $check = $this->svc->check(7);

        $this->assertSame('missing', $check['state']);
        $this->assertSame('PS-C7', $check['identifier']);
        $this->assertSame(array(), $this->mapRows());
    }

    /** Scenario: Check refused by the rate guard */
    public function testCheckRefusedByTheRateGuardLeavesTheLinkAlone()
    {
        $this->linkCustomer();
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':80');
        $before = $this->mapRows();

        $check = $this->svc->check(7);

        $this->assertSame('error', $check['state']);
        $this->assertStringContainsString('rate limit guard', $check['message']);
        $this->assertCount(0, $this->t->calls);
        $this->assertSame($before, $this->mapRows());
    }

    public function testCheckTransportErrorLeavesTheLinkAlone()
    {
        $this->linkCustomer();
        $before = $this->mapRows();
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));

        $check = $this->svc->check(7);

        $this->assertSame('error', $check['state']);
        $this->assertNotSame('', $check['message']);
        $this->assertSame($before, $this->mapRows());
    }

    // ---- search -------------------------------------------------------------------------------

    private function criteria(array $over = array())
    {
        return array_merge(array('firstname' => '', 'lastname' => '', 'company' => '', 'nip' => '', 'email' => '', 'phone' => ''), $over);
    }

    /** Scenario: Search by name and NIP */
    public function testSearchByNameAndNip()
    {
        $row = $this->contractor('KOWALSKI-01', array('NIP' => '1234563218', 'Email' => 'jan@example.test', 'Telefon' => '600100200', 'KodPocztowy' => '00-001', 'Miejscowosc' => 'Warszawa'));
        $this->t->queue('GET', '#kontrahenci/1234563218\.json#', $this->results(array($row)));
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->results(array($row)));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->results(array()));

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'nip' => '123-456-32-18')));

        $this->assertFalse($result['refused']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('KOWALSKI-01', $result['rows'][0]['identifier']);
        $this->assertSame('Jan Kowalski', $result['rows'][0]['name']);
        $this->assertSame('1234563218', $result['rows'][0]['nip']);
        $this->assertSame('00-001', $result['rows'][0]['postcode']);
        $this->assertSame('Warszawa', $result['rows'][0]['city']);
        $this->assertTrue($result['rows'][0]['flags']['nip']);
        $this->assertTrue($result['rows'][0]['flags']['name']);
        // NIP, full name, then the lastname alone (fewer than 3 phrases so far, D6)
        $this->assertSame(array('1234563218', 'Jan Kowalski', 'Kowalski'), $result['phrases']);
        $this->assertCount(3, $this->t->calls);
        $this->assertSame(array('1234563218', 'Jan Kowalski', 'Kowalski'), $this->searchPaths());
    }

    /** Scenario: Nothing found */
    public function testNothingFound()
    {
        $notFound = $this->notFound();
        $this->t->queue('GET', '#kontrahenci/#', function () use ($notFound) {
            return $notFound;
        });

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski')));

        $this->assertFalse($result['refused']);
        $this->assertSame(array(), $result['rows']);
        $this->assertSame(array(), $result['errors']);
    }

    /** Scenario: Search error */
    public function testSearchErrorNamesTheFailingPhrase()
    {
        $this->t->queue('GET', '#kontrahenci/ACME%20Sp\.%20z%20o\.o\.\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->results(array($this->contractor('KOWALSKI-01'))));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->results(array()));

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'ACME Sp. z o.o.')));

        $this->assertCount(1, $result['rows']);
        $this->assertSame('KOWALSKI-01', $result['rows'][0]['identifier']);
        $this->assertSame(array('ACME Sp. z o.o.'), array_keys($result['errors']));
        $this->assertNotSame('', $result['errors']['ACME Sp. z o.o.']);
    }

    /** Scenario: Phone confirms a contractor */
    public function testPhoneConfirmsAContractor()
    {
        $first = $this->contractor('KOWALSKI-01', array('Telefon' => '12 345 67 89'));
        $second = $this->contractor('KOWALSKI-02', array('DrugiTelefon' => '+48 600 100 200'));
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->results(array($first, $second)));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->results(array()));

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'phone' => '600100200')));

        $this->assertSame(array('KOWALSKI-02', 'KOWALSKI-01'), array_column($result['rows'], 'identifier'));
        $this->assertTrue($result['rows'][0]['flags']['name']);
        $this->assertTrue($result['rows'][0]['flags']['phone']);
        $this->assertFalse($result['rows'][0]['flags']['nip']);
        $this->assertFalse($result['rows'][1]['flags']['phone']);
    }

    public function testPhoneNormalisation()
    {
        $this->assertSame('600100200', IfirmaCustomerContractorService::phoneDigits('+48 600-100-200'));
        $this->assertSame('600100200', IfirmaCustomerContractorService::phoneDigits('0048 600 100 200'));
        $this->assertSame('600100200', IfirmaCustomerContractorService::phoneDigits('600100200'));
        $this->assertSame('481234567', IfirmaCustomerContractorService::phoneDigits('481234567'));
    }

    /** Scenario: E-mail only */
    public function testEmailOnlyIsRefusedWithoutARequest()
    {
        $result = $this->svc->searchCandidates($this->criteria(array('email' => 'jan@example.test', 'phone' => '600100200')));

        $this->assertTrue($result['refused']);
        $this->assertSame(array(), $result['rows']);
        $this->assertSame(array(), $result['phrases']);
        $this->assertCount(0, $this->t->calls);
    }

    public function testNipShorterThanTenDigitsIsNotAKey()
    {
        $result = $this->svc->searchCandidates($this->criteria(array('nip' => '12345')));

        $this->assertTrue($result['refused']);
        $this->assertCount(0, $this->t->calls);
    }

    public function testEmailMatchesEmailDlaFakturyCaseInsensitively()
    {
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->results(array(
            $this->contractor('KOWALSKI-01', array('Email' => 'other@example.test')),
            $this->contractor('KOWALSKI-02', array('EmailDlaFaktury' => 'JAN.KOWALSKI@example.TEST')),
        )));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->results(array()));

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'email' => 'Jan.Kowalski@Example.test')));

        $this->assertSame(array('KOWALSKI-02', 'KOWALSKI-01'), array_column($result['rows'], 'identifier'));
        $this->assertTrue($result['rows'][0]['flags']['email']);
        $this->assertFalse($result['rows'][1]['flags']['email']);
    }

    public function testNipMatchRanksFirstAndRowsWithoutIdentifierAreDropped()
    {
        $a = $this->contractor('A-NAME', array('Telefon' => '600100200', 'Email' => 'jan@example.test'));
        $b = $this->contractor('B-NIP', array('Nazwa' => 'Some Firm', 'NIP' => 'PL 1234563218'));
        $c = array('Nazwa' => 'No identifier', 'NIP' => '1234563218');
        $this->t->queue('GET', '#kontrahenci/1234563218\.json#', $this->results(array($a, $c)));
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->results(array($b, $a)));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->results(array()));

        $result = $this->svc->searchCandidates($this->criteria(array(
            'firstname' => 'Jan', 'lastname' => 'Kowalski', 'nip' => '1234563218', 'email' => 'jan@example.test', 'phone' => '+48600100200',
        )));

        $this->assertSame(array('B-NIP', 'A-NAME'), array_column($result['rows'], 'identifier'));
        $this->assertTrue($result['rows'][0]['flags']['nip']);
        $this->assertFalse($result['rows'][0]['flags']['name']);
        $this->assertSame(
            array('nip' => false, 'email' => true, 'phone' => true, 'name' => true),
            $result['rows'][1]['flags']
        );
    }

    public function testPhraseOrderCapAndCaseInsensitiveDeduplication()
    {
        $svc = 'IfirmaCustomerContractorService';

        // lastname alone is added only while fewer than 3 phrases exist
        $this->assertSame(
            array('1234563218', 'ACME', 'Jan Kowalski'),
            $svc::buildPhrases(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'ACME', 'nip' => '1234563218', 'email' => '', 'phone' => ''))
        );
        $this->assertSame(
            array('ACME', 'Jan Kowalski', 'Kowalski'),
            $svc::buildPhrases(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'ACME', 'nip' => '12345', 'email' => '', 'phone' => ''))
        );
        // company equal to the full name (other case) is sent once
        $this->assertSame(
            array('jan kowalski', 'Kowalski'),
            $svc::buildPhrases(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'jan kowalski', 'nip' => '', 'email' => '', 'phone' => ''))
        );
        $this->assertSame(array('Kowalski'), $svc::buildPhrases(array('firstname' => '', 'lastname' => 'Kowalski', 'company' => '', 'nip' => '', 'email' => '', 'phone' => '')));
    }

    public function testNeverMoreThanFourSearchRequests()
    {
        $notFound = $this->notFound();
        $this->t->queue('GET', '#kontrahenci/#', function () use ($notFound) {
            return $notFound;
        });

        $this->svc->searchCandidates($this->criteria(array(
            'firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'ACME', 'nip' => '1234563218', 'email' => 'a@b.test', 'phone' => '1',
        )));

        $this->assertLessThanOrEqual(IfirmaCustomerContractorService::MAX_PHRASES, count($this->t->calls));
        $this->assertCount(3, $this->t->calls);
    }

    // ---- remote count -------------------------------------------------------------------------

    private function queueList($callable)
    {
        $this->t->queue('GET', '#faktury\.json#', $callable);
    }

    /** Scenario: Count by contractor */
    public function testCountByContractor()
    {
        $this->linkCustomer();
        $rows = array(
            $this->invoice(1),
            $this->invoice(2),
            $this->invoice(3, 100, 100, 'prz_faktura_paragon'),
            $this->invoice(4, 100, 0, 'prz_rachunek'),
            $this->invoice(5, 100, 99.995, 'prz_faktura_proforma'),
        );
        $this->queueList($this->results($rows));

        $count = $this->svc->remoteCount(7);

        $this->assertTrue($count['ok']);
        $this->assertTrue($count['complete']);
        $this->assertSame(5, $count['total']);
        $this->assertSame(4, $count['paid']);
        $this->assertSame(array('receipt' => 2, 'invoice' => 2, 'other' => 1), $count['by_type']);
        $this->assertSame('2026-03-10', $count['period_from']);
        $this->assertSame('2026-11-04', $count['period_to']);
        $this->assertSame(1, $count['calls']);
        $this->assertSame('KOWALSKI-01', $count['identifier']);
        $queries = $this->listQueries();
        $this->assertCount(1, $queries);
        $this->assertSame('KOWALSKI-01', $queries[0]['kontrahent']);
        $this->assertArrayNotHasKey('nipKontrahenta', $queries[0]);
        $this->assertSame('100', $queries[0]['iloscNaStronie']);
    }

    /** Scenario: Same document returned by both filters */
    public function testSameDocumentReturnedByBothFiltersIsCountedOnce()
    {
        $this->linkCustomer();
        $this->address(1, array('company' => 'ACME', 'vat_number' => '123-456-32-18'));
        $this->order(10, '2026-04-01 10:00:00', 1);
        $this->queueList($this->results(array($this->invoice(1), $this->invoice(2))));
        $this->queueList($this->results(array($this->invoice(2), $this->invoice(3))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(3, $count['total']);
        $this->assertSame(2, $count['calls']);
        $this->assertSame('1234563218', $count['nip']);
        $queries = $this->listQueries();
        $this->assertSame('KOWALSKI-01', $queries[0]['kontrahent']);
        $this->assertSame('1234563218', $queries[1]['nipKontrahenta']);
    }

    /** Scenario: Contractor unknown to iFirma */
    public function testContractorUnknownToIfirmaStillCountsTheNip()
    {
        $c = Customer::$instances[7];
        $c->date_add = '2024-01-15 10:00:00'; // three windows
        $this->address(1, array('vat_number' => '1234563218'));
        $this->order(10, '2026-04-01 10:00:00', 1);
        $this->queueList(function ($method, $url) {
            if (strpos($url, 'kontrahent=') !== false) {
                return array('body' => '{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta o podanym identyfikatorze"}}');
            }

            return array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[{"FakturaId":9,"Rodzaj":"prz_faktura_kraj","Brutto":10,"Zaplacono":0}]}}');
        });

        $count = $this->svc->remoteCount(7);

        $this->assertTrue($count['ok']);
        $this->assertSame(1, $count['total']);
        $withKontrahent = 0;
        $withNip = 0;
        foreach ($this->listQueries() as $q) {
            $withKontrahent += isset($q['kontrahent']) ? 1 : 0;
            $withNip += isset($q['nipKontrahenta']) ? 1 : 0;
        }
        $this->assertSame(1, $withKontrahent, 'no further kontrahent calls after Kod 201');
        $this->assertSame(3, $withNip);
    }

    /** Scenario: Every list call is bounded */
    public function testEveryListCallIsBounded()
    {
        $c = Customer::$instances[7];
        $c->date_add = '2020-01-01 00:00:00'; // 7 windows
        $this->linkCustomer();
        $this->address(1, array('vat_number' => '1234563218'));
        $this->order(10, '2026-04-01 10:00:00', 1);
        $this->queueList($this->results(array()));
        $this->queueList($this->results(array()));
        $this->t->queue('GET', '#faktury\.json#', function () {
            return array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[]}}');
        });

        $this->svc->remoteCount(7);

        $queries = $this->listQueries();
        $this->assertGreaterThan(2, count($queries));
        $previousFrom = null;
        foreach ($queries as $q) {
            $this->assertArrayHasKey('dataOd', $q);
            $this->assertArrayHasKey('dataDo', $q);
            $this->assertArrayHasKey('iloscNaStronie', $q);
            $this->assertLessThanOrEqual(365, $this->days($q['dataOd'], $q['dataDo']));
            $this->assertGreaterThanOrEqual('2020-01-01', $q['dataOd']);
            $this->assertLessThanOrEqual('2026-11-04', $q['dataDo']);
        }
        $this->assertSame('2026-11-04', $queries[0]['dataDo'], 'newest window first');
        $this->assertSame('2020-01-01', end($queries)['dataOd']);
    }

    public function testLookbackIsLimitedToTenYears()
    {
        Customer::$instances[7]->date_add = '2001-01-01 00:00:00';
        $this->queueList(function () {
            return array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[]}}');
        });

        $count = $this->svc->remoteCount(7);

        $this->assertSame('2016-11-04', $count['period_from']);
        $this->assertTrue($count['complete']);
        $this->assertSame(11, $count['calls'], '3653 days need 11 windows of at most 365 days');
    }

    public function testPagesUntilAShortPage()
    {
        $this->linkCustomer();
        $full = array();
        for ($i = 1; $i <= 100; ++$i) {
            $full[] = $this->invoice($i);
        }
        $this->queueList($this->results($full));
        $this->queueList($this->results(array($this->invoice(101))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(101, $count['total']);
        $this->assertSame(2, $count['calls']);
        $queries = $this->listQueries();
        $this->assertArrayNotHasKey('strona', $queries[0]);
        $this->assertSame('2', $queries[1]['strona']);
    }

    /** Scenario: Budget exhausted */
    public function testBudgetExhausted()
    {
        Customer::$instances[7]->date_add = '2001-01-01 00:00:00';
        $this->linkCustomer();
        $this->address(1, array('vat_number' => '1234563218'));
        $this->order(10, '2026-04-01 10:00:00', 1);
        $this->queueList(function ($method, $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $rows = array();
            if (isset($q['kontrahent']) && !isset($q['strona']) && $q['dataDo'] === '2026-11-04') {
                for ($i = 1; $i <= 100; ++$i) {
                    $rows[] = array('FakturaId' => $i, 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 1, 'Zaplacono' => 1);
                }
            }

            return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows))));
        });

        $count = $this->svc->remoteCount(7);

        $this->assertCount(20, $this->listQueries());
        $this->assertSame(20, $count['calls']);
        $this->assertTrue($count['ok']);
        $this->assertFalse($count['complete']);
        $this->assertSame(100, $count['total']);
        // 3 calls for the newest window, then 2 per window: window 9 is the last complete one,
        // window 10 only got its contractor query before the budget ran out
        $queries = $this->listQueries();
        $this->assertSame($queries[18]['dataOd'], $count['period_from']);
        $this->assertSame('2017-11-07', $count['period_from']);
        $this->assertSame($queries[19]['dataDo'], date('Y-m-d', strtotime($count['period_from'] . ' -1 day')));
        $this->assertSame('2026-11-04', $count['period_to']);
    }

    public function testExactlyTheBudgetIsStillComplete()
    {
        Customer::$instances[7]->date_add = '2017-01-01 00:00:00'; // 10 windows x 2 queries
        $this->linkCustomer();
        $this->address(1, array('vat_number' => '1234563218'));
        $this->order(10, '2026-04-01 10:00:00', 1);
        $this->queueList(function () {
            return array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[]}}');
        });

        $count = $this->svc->remoteCount(7);

        $this->assertSame(20, $count['calls']);
        $this->assertTrue($count['complete']);
    }

    /** Scenario: Call failure */
    public function testCallFailureReturnsAnErrorAndNoTotal()
    {
        $this->linkCustomer();
        $this->address(1, array('vat_number' => '1234563218'));
        $this->order(10, '2026-04-01 10:00:00', 1);
        $this->queueList($this->results(array($this->invoice(1), $this->invoice(2))));
        $this->queueList(array('status' => 0, 'error' => 'timeout'));

        $count = $this->svc->remoteCount(7);

        $this->assertFalse($count['ok']);
        $this->assertNotSame('', $count['error']);
        $this->assertSame(0, $count['total']);
        $this->assertFalse($count['complete']);
    }

    public function testIfirmaErrorOnTheNipQueryIsAFailure()
    {
        $this->address(1, array('vat_number' => '1234563218'));
        $this->order(10, '2026-04-01 10:00:00', 1);
        $this->queueList($this->body(array('Kod' => 201, 'Informacja' => 'Nie znaleziono kontrahenta')));
        $this->queueList($this->body(array('Kod' => 201, 'Informacja' => 'Nieprawidlowa wartosc NIP')));

        $count = $this->svc->remoteCount(7);

        $this->assertFalse($count['ok']);
        $this->assertStringContainsString('NIP', $count['error']);
    }

    // ---- link ---------------------------------------------------------------------------------

    /** Scenario: Link a found contractor */
    public function testLinkAFoundContractor()
    {
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->found('KOWALSKI-01', array('Nazwa' => 'Jan Kowalski')));

        $result = $this->svc->link(7, '  KOWALSKI-01 ');

        $this->assertSame(array('success' => true, 'code' => 'linked', 'identifier' => 'KOWALSKI-01', 'name' => 'Jan Kowalski', 'message' => ''), $result);
        $rows = array_values($this->mapRows());
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['id_customer']);
        $this->assertSame('KOWALSKI-01', $rows[0]['ifirma_identifier']);
        $this->assertSame('manual', $rows[0]['matched_by']);
        $this->assertSame('Jan Kowalski', $rows[0]['ifirma_name']);
        $this->assertSame('KOWALSKI-01', $this->svc->linkState(7)['identifier']);
        $this->assertCount(1, $this->t->calls, 'only the re-read, no write');
        $this->assertSame('GET', $this->t->calls[0]['method']);
    }

    /** Scenario: Replace an existing link */
    public function testReplaceAnExistingLink()
    {
        $this->linkCustomer('PS-C7', IfirmaContractorMap::BY_MODULE, 'Jan K');
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->found('KOWALSKI-01'));

        $this->assertTrue($this->svc->link(7, 'KOWALSKI-01')['success']);

        $rows = array_values($this->mapRows());
        $this->assertCount(1, $rows);
        $this->assertSame('KOWALSKI-01', $rows[0]['ifirma_identifier']);
        $this->assertSame('manual', $rows[0]['matched_by']);
    }

    /** Scenario: Chosen contractor no longer exists */
    public function testChosenContractorNoLongerExists()
    {
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', $this->notFound());

        $result = $this->svc->link(7, 'KOWALSKI-01');

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['code']);
        $this->assertSame(array(), $this->mapRows());
    }

    public function testLinkReadErrorStoresNothing()
    {
        $this->t->queue('GET', '#kontrahenci/id/#', array('status' => 0, 'error' => 'timeout'));

        $result = $this->svc->link(7, 'KOWALSKI-01');

        $this->assertFalse($result['success']);
        $this->assertSame('error', $result['code']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame(array(), $this->mapRows());
    }

    public function testEmptyOrTooLongIdentifierIsRefusedWithoutARequest()
    {
        $this->assertSame('invalid', $this->svc->link(7, '   ')['code']);
        $this->assertSame('invalid', $this->svc->link(7, str_repeat('A', 65))['code']);
        $this->assertSame('invalid', $this->svc->link(0, 'KOWALSKI-01')['code']);
        $this->assertCount(0, $this->t->calls);
        $this->assertSame(array(), $this->mapRows());
    }

    public function testIdentifierOfExactlyTheLimitIsAccepted()
    {
        $id = str_repeat('A', 64);
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found($id));

        $this->assertTrue($this->svc->link(7, $id)['success']);
    }

    // ---- unlink -------------------------------------------------------------------------------

    /** Scenario: Unlink a customer */
    public function testUnlinkACustomer()
    {
        $this->linkCustomer();

        $this->assertTrue($this->svc->unlink(7));

        $this->assertSame(array(), $this->mapRows());
        $this->assertCount(0, $this->t->calls);
        $this->assertSame('PS-C7', $this->svc->linkState(7)['identifier']);
        $this->assertFalse($this->svc->linkState(7)['linked']);
    }

    public function testUnlinkWithoutLinkIsFalse()
    {
        $this->assertFalse($this->svc->unlink(7));
        $this->assertCount(0, $this->t->calls);
    }
}
