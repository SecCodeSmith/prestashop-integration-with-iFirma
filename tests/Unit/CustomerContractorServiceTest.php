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

    /**
     * Each receipt status is counted in exactly its own buckets. The multiplicities are distinct powers of two,
     * so a status that lands in a wrong bucket (or is missing from one) changes a sum.
     */
    public function testEveryReceiptStatusIsCountedInExactlyItsOwnBuckets()
    {
        $multiplicity = array('created' => 1, 'paid' => 2, 'error' => 4, 'unknown' => 8, 'duplicate_suspected' => 16, 'held' => 32);
        foreach ($multiplicity as $status => $n) {
            for ($i = 0; $i < $n; ++$i) {
                $this->addReceipt('fakturakraj', $status);
            }
        }

        $counts = $this->svc->localCounts(7);

        $this->assertSame(3, $counts['issued']);
        $this->assertSame(2, $counts['paid']);
        $this->assertSame(32, $counts['held']);
        $this->assertSame(28, $counts['attention']);
        $this->assertSame(array('receipt' => 0, 'invoice' => 3, 'other' => 0), $counts['by_type']);
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

    // ---- the customer's invoice data: names, company, phone, NIP, address order and reads -----
    // (pins what refactor-srp-splits 1.2 moves into IfirmaCustomerInvoiceProfile)

    /**
     * Counts the reads of one call: the runs of Order::getCustomerOrders() and the language id of
     * every Customer::getAddresses() call.
     *
     * @return stdClass orders (int), languages (array)
     */
    private function recordReads()
    {
        $log = new stdClass();
        $log->orders = 0;
        $log->languages = array();
        Order::$customerOrdersHook = function ($idCustomer, $rows) use ($log) {
            ++$log->orders;

            return $rows;
        };
        Customer::$addressesHook = function ($idLang, $rows) use ($log) {
            $log->languages[] = $idLang;

            return $rows;
        };

        return $log;
    }

    public function testDefaultCriteriaTrimsTheCustomerNamesAndEmail()
    {
        $c = Customer::$instances[7];
        $c->firstname = "  Jan\t";
        $c->lastname = "\nKowalski ";
        $c->email = ' jan@example.test ';

        $this->assertSame(
            array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => '', 'nip' => '', 'email' => 'jan@example.test', 'phone' => ''),
            $this->svc->defaultCriteria(7)
        );
    }

    public function testDefaultCriteriaOfAnUnknownCustomerIsEmpty()
    {
        $this->assertSame(
            array('firstname' => '', 'lastname' => '', 'company' => '', 'nip' => '', 'email' => '', 'phone' => ''),
            $this->svc->defaultCriteria(99)
        );
        $this->assertSame('', $this->svc->customerNip(99));
    }

    public function testDefaultCriteriaOfACustomerWithoutAddressesTakesTheCompanyFromTheCustomer()
    {
        Customer::$instances[7]->company = ' Customer Co ';

        $this->assertSame(
            array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'Customer Co', 'nip' => '', 'email' => 'Jan.Kowalski@Example.test', 'phone' => ''),
            $this->svc->defaultCriteria(7)
        );
    }

    /** @return array label => array(company of the address, company of the customer, expected company) */
    public static function companyProvider()
    {
        return array(
            'the address company, trimmed' => array("  ACME Sp. z o.o.\t", 'Customer Co', 'ACME Sp. z o.o.'),
            'no address company: the customer company, trimmed' => array('', '  Customer Co ', 'Customer Co'),
            'a blank address company counts as none' => array(" \t ", 'Customer Co', 'Customer Co'),
            'an unset address company counts as none' => array(null, 'Customer Co', 'Customer Co'),
            'a company called 0 is kept' => array('0', 'Customer Co', '0'),
            'no company anywhere' => array('', null, ''),
        );
    }

    /**
     * @dataProvider companyProvider
     */
    public function testDefaultCriteriaCompany($addressCompany, $customerCompany, $expected)
    {
        $this->address(1, array('company' => $addressCompany));
        Customer::$instances[7]->company = $customerCompany;

        $this->assertSame($expected, $this->svc->defaultCriteria(7)['company']);
    }

    /** @return array label => array(phone, mobile phone, expected phone) */
    public static function phoneProvider()
    {
        return array(
            'the phone, trimmed' => array(' 600 100 200 ', '500 000 000', '600 100 200'),
            'no phone: the mobile phone, trimmed' => array('', ' 500 000 000 ', '500 000 000'),
            'a blank phone counts as none' => array(" \t", '500 000 000', '500 000 000'),
            'an unset phone counts as none' => array(null, '500 000 000', '500 000 000'),
            'a phone called 0 is kept' => array('0', '500 000 000', '0'),
            'neither' => array('', '', ''),
            'neither is set' => array(null, null, ''),
        );
    }

    /**
     * @dataProvider phoneProvider
     */
    public function testDefaultCriteriaPhone($phone, $mobile, $expected)
    {
        $this->address(1, array('phone' => $phone, 'phone_mobile' => $mobile));

        $this->assertSame($expected, $this->svc->defaultCriteria(7)['phone']);
    }

    /** Company and phone come from the latest address only; the NIP alone looks further back. */
    public function testCompanyAndPhoneAreNotTakenFromAnOlderAddress()
    {
        $this->address(1, array('company' => 'Old Co', 'phone' => '111', 'phone_mobile' => '112', 'vat_number' => '525-224-84-81'));
        $this->address(2, array('company' => '', 'phone' => '', 'phone_mobile' => '', 'vat_number' => ''));
        Customer::$instances[7]->company = 'Customer Co';

        $this->assertSame(
            array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'Customer Co', 'nip' => '5252248481', 'email' => 'Jan.Kowalski@Example.test', 'phone' => ''),
            $this->svc->defaultCriteria(7)
        );
    }

    /**
     * The latest address is 2; an older one (1) carries a valid NIP.
     *
     * @return array label => array(vat_number of the latest address, nip of the form, customerNip())
     */
    public static function nipProvider()
    {
        return array(
            'ten digits' => array('123-456-32-18', '1234563218', '1234563218'),
            'letters and spaces are dropped' => array('PL 123 456 32 18', '1234563218', '1234563218'),
            'five digits are kept as typed, whatever the length' => array('12-345', '12345', '5252248481'),
            'nine digits are kept too' => array('123456789', '123456789', '5252248481'),
            'eleven digits are kept too' => array('12345678901', '12345678901', '5252248481'),
            'no digits: the NIP of an older address' => array('n/a', '5252248481', '5252248481'),
            'empty: the NIP of an older address' => array('', '5252248481', '5252248481'),
            'unset: the NIP of an older address' => array(null, '5252248481', '5252248481'),
        );
    }

    /**
     * @dataProvider nipProvider
     */
    public function testDefaultCriteriaNipAndCustomerNip($vatNumber, $expectedFormNip, $expectedCustomerNip)
    {
        $this->address(1, array('vat_number' => '525-224-84-81'));
        $this->address(2, array('vat_number' => $vatNumber));

        $this->assertSame($expectedFormNip, $this->svc->defaultCriteria(7)['nip']);
        $this->assertSame($expectedCustomerNip, $this->svc->customerNip(7));
    }

    public function testCustomerNipIsTheFirstAddressWithExactlyTenDigits()
    {
        $this->address(1, array('vat_number' => '5252248481'));
        $this->address(2, array('vat_number' => '222-222-22-22'));
        $this->address(3, array('vat_number' => '123456789'));
        $this->address(4, array('vat_number' => '12345678901'));
        $this->address(5, array('vat_number' => ''));

        // newest first: 5 has none, 4 has eleven digits, 3 has nine, 2 is the first with ten
        $this->assertSame('2222222222', $this->svc->customerNip(7));
        $this->assertSame('2222222222', $this->svc->defaultCriteria(7)['nip']);
    }

    public function testOrderAddressesComeBeforeTheOwnOnes()
    {
        $this->address(1, array('company' => 'Ordered Co'));
        $this->address(2, array('company' => 'Second Co'));
        $this->address(3, array('company' => 'Newest Co'));
        $this->order(10, '2026-05-01 10:00:00', 1);

        $this->assertSame('Ordered Co', $this->svc->defaultCriteria(7)['company']);
    }

    public function testAddressesAreLoadedOrdersNewestFirstThenOwnNewestFirstEachOnce()
    {
        foreach (array(1, 2, 3, 4) as $id) {
            $this->address($id, array('company' => 'Co ' . $id));
        }
        $this->order(10, '2026-01-01 10:00:00', 1);
        $this->order(11, '2026-03-01 10:00:00', 3);
        $this->order(12, '2026-05-01 10:00:00', 2);
        $this->order(13, '2026-04-01 10:00:00', 2);

        $this->svc->customerNip(7);

        // orders newest first: 12 -> 2, 13 -> 2, 11 -> 3, 10 -> 1; then the own ones: 4, 3, 2, 1
        $this->assertSame(array(2, 3, 1, 4), Address::$loads);
    }

    public function testAddressesThatDoNotLoadAreSkipped()
    {
        $this->address(1, array('company' => 'Existing Co'));
        $this->order(10, '2026-01-01 10:00:00', 1);
        $this->order(11, '2026-05-01 10:00:00', 99); // the address was deleted

        $this->svc->customerNip(7);

        $this->assertSame(array(99, 1), Address::$loads);
        $this->assertSame('Existing Co', $this->svc->defaultCriteria(7)['company']);
    }

    public function testOrdersWithoutAnInvoiceAddressAreSkipped()
    {
        $this->address(1, array('company' => 'Own Co'));
        $this->order(10, '2026-01-01 10:00:00', 0);
        $this->order(11, '2026-02-01 10:00:00', null);
        $this->order(12, '2026-03-01 10:00:00', '');

        $this->svc->customerNip(7);

        $this->assertSame(array(1), Address::$loads);
    }

    public function testTheAddressesOfAnotherCustomerAreNotLoaded()
    {
        $this->address(1, array('company' => 'Own Co'));
        $this->address(2, array('company' => 'Other Co'), 8);
        $this->order(10, '2026-05-01 10:00:00', 2, 8);

        $this->svc->customerNip(7);

        $this->assertSame(array(1), Address::$loads);
    }

    public function testAddressIdsAreLoadedAsIntegers()
    {
        // core hands the ids over as strings
        Order::$customerOrdersHook = function ($idCustomer, $rows) {
            foreach ($rows as $i => $row) {
                $rows[$i]['id_address_invoice'] = (string) $row['id_address_invoice'];
            }

            return $rows;
        };
        Customer::$addressesHook = function ($idLang, $rows) {
            foreach ($rows as $i => $row) {
                $rows[$i]['id_address'] = (string) $row['id_address'];
            }

            return $rows;
        };
        foreach (array(1, 2, 3) as $id) {
            $this->address($id, array('company' => 'Co ' . $id));
        }
        $this->order(10, '2026-05-01 10:00:00', 2);

        $this->svc->customerNip(7);

        $this->assertSame(array(2, 3, 1), Address::$loads);
    }

    public function testAnExceptionOfTheOrderQueryMeansNoOrderAddresses()
    {
        $this->address(1, array('company' => 'Old Co'));
        $this->address(2, array('company' => 'Own Co'));
        $this->order(10, '2026-05-01 10:00:00', 1);
        Order::$customerOrdersHook = function () {
            throw new RuntimeException('orders unavailable');
        };

        $this->svc->customerNip(7);

        $this->assertSame(array(2, 1), Address::$loads);
        $this->assertSame('Own Co', $this->svc->defaultCriteria(7)['company']);
    }

    /** @return array method => array(method name) */
    public static function readersOfTheInvoiceData()
    {
        return array(
            'defaultCriteria' => array('defaultCriteria'),
            'customerNip' => array('customerNip'),
            'remoteCount' => array('remoteCount'),
        );
    }

    /**
     * Only an Exception of the order query is caught; an Error is not.
     *
     * @dataProvider readersOfTheInvoiceData
     */
    public function testAnErrorOfTheOrderQueryIsNotSwallowed($method)
    {
        Order::$customerOrdersHook = function () {
            throw new TypeError('order query broke');
        };

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('order query broke');

        call_user_func(array($this->svc, $method), 7);
    }

    /** @return array label => array(answer) */
    public static function answersThatAreNoRows()
    {
        return array(
            'false' => array(false),
            'null' => array(null),
            'a string' => array('database error'),
        );
    }

    /**
     * @dataProvider answersThatAreNoRows
     */
    public function testAnOrderAnswerThatIsNoArrayMeansNoOrderAddresses($answer)
    {
        $this->address(1, array('company' => 'Old Co'));
        $this->address(2, array('company' => 'Own Co'));
        $this->order(10, '2026-05-01 10:00:00', 1);
        Order::$customerOrdersHook = function () use ($answer) {
            return $answer;
        };

        $this->assertSame('Own Co', $this->svc->defaultCriteria(7)['company']);
    }

    /**
     * @dataProvider answersThatAreNoRows
     */
    public function testAnAddressAnswerThatIsNoArrayMeansNoOwnAddresses($answer)
    {
        $this->address(1, array('company' => 'Ordered Co'));
        $this->address(2, array('company' => 'Own Co'));
        $this->order(10, '2026-05-01 10:00:00', 1);
        Customer::$addressesHook = function () use ($answer) {
            return $answer;
        };

        $this->svc->customerNip(7);

        $this->assertSame(array(1), Address::$loads);
    }

    public function testOwnAddressRowsWithoutAnIdAreIgnored()
    {
        $this->address(1, array('company' => 'Own Co'));
        Customer::$addressesHook = function ($idLang, $rows) {
            return array_merge(array(array('company' => 'No id'), 'junk'), $rows);
        };

        $this->svc->customerNip(7);

        $this->assertSame(array(1), Address::$loads);
    }

    /** @return array label => array(language of the context, language asked for) */
    public static function contextLanguages()
    {
        return array(
            'an int' => array(3, 3),
            'a numeric string' => array('2', 2),
        );
    }

    /**
     * @dataProvider contextLanguages
     */
    public function testTheOwnAddressesAreAskedForInTheContextLanguage($contextLanguage, $expected)
    {
        $reads = $this->recordReads();
        Context::getContext()->language->id = $contextLanguage;

        $this->svc->customerNip(7);

        $this->assertSame(array($expected), $reads->languages);
    }

    public function testCustomerNipReadsTheAddressesOnce()
    {
        $this->address(1, array('vat_number' => '525-224-84-81'));
        $this->order(10, '2026-05-01 10:00:00', 1);
        $reads = $this->recordReads();

        $this->assertSame('5252248481', $this->svc->customerNip(7));

        $this->assertSame(1, $reads->orders);
        $this->assertSame(array(1), $reads->languages);
        $this->assertSame(array(1), Address::$loads);
    }

    public function testDefaultCriteriaReadsTheAddressesOnceWhenTheLatestAddressHasANip()
    {
        $this->address(1, array('vat_number' => '525-224-84-81'));
        $this->order(10, '2026-05-01 10:00:00', 1);
        $reads = $this->recordReads();

        $this->assertSame('5252248481', $this->svc->defaultCriteria(7)['nip']);

        $this->assertSame(1, $reads->orders);
        $this->assertSame(array(1), $reads->languages);
        $this->assertSame(array(1), Address::$loads);
    }

    public function testRemoteCountReadsTheAddressesOnce()
    {
        $this->linkCustomer();
        $this->address(1, array('vat_number' => '525-224-84-81'));
        $this->order(10, '2026-05-01 10:00:00', 1);
        $this->queueList($this->results(array()));
        $this->queueList($this->results(array()));
        $reads = $this->recordReads();

        $count = $this->svc->remoteCount(7);

        $this->assertSame('5252248481', $count['nip']);
        $this->assertSame(1, $reads->orders);
        $this->assertSame(array(1), $reads->languages);
        $this->assertSame(array(1), Address::$loads);
    }

    /** The count searches the NIP that customerNip() gives: the first address with exactly ten digits. */
    public function testRemoteCountSearchesTheFirstTenDigitNip()
    {
        $this->linkCustomer();
        $this->address(1, array('vat_number' => '123-456-32-18'));
        $this->address(2, array('vat_number' => '12-345'));
        $this->queueList($this->results(array($this->invoice(1))));
        $this->queueList($this->results(array($this->invoice(2))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame('1234563218', $count['nip']);
        $queries = $this->listQueries();
        $this->assertCount(2, $queries);
        $this->assertSame('KOWALSKI-01', $queries[0]['kontrahent']);
        $this->assertSame('1234563218', $queries[1]['nipKontrahenta']);
    }

    public function testRemoteCountSearchesNoNipWhenNoAddressHasTenDigits()
    {
        $this->linkCustomer();
        $this->address(1, array('vat_number' => '123456789'));
        $this->queueList($this->results(array($this->invoice(1))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame('', $count['nip']);
        $queries = $this->listQueries();
        $this->assertCount(1, $queries);
        $this->assertArrayNotHasKey('nipKontrahenta', $queries[0]);
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

    /** Scenario: Search error - the message is the one of the failure, kept per phrase (refactor-clean-code-tda 13.1 + 13.2) */
    public function testSearchErrorsCarryTheExactMessageOfEachFailingPhrase()
    {
        $this->t->queue('GET', '#kontrahenci/ACME%20Sp\.%20z%20o\.o\.\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->body(array('Kod' => 403, 'Informacja' => 'Bledny podpis')));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->body(array('Kod' => 500, 'Informacja' => '')));

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'ACME Sp. z o.o.')));

        $this->assertSame(array('ACME Sp. z o.o.', 'Jan Kowalski', 'Kowalski'), $result['phrases']);
        $this->assertSame(
            array('ACME Sp. z o.o.' => 'cURL error: timeout', 'Jan Kowalski' => 'Bledny podpis', 'Kowalski' => 'Kod 500'),
            $result['errors']
        );
        $this->assertSame(array(), $result['rows']);
    }

    public function testAPhraseThatFailedDoesNotMarkTheNextOneAndTheOthersKeepTheirRows()
    {
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->results(array($this->contractor('KOWALSKI-01'))));

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski')));

        $this->assertSame(array('Jan Kowalski' => 'cURL error: timeout'), $result['errors']);
        $this->assertSame(array('KOWALSKI-01'), array_column($result['rows'], 'identifier'));
        $this->assertFalse($result['refused']);
        $this->assertSame('', $result['reason']);
    }

    public function testAnAnswerWithoutRecordsAndWithoutAKodIsNoRowAndNoError()
    {
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->body(array('Informacja' => '?')));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->body(array('Kod' => 0, 'Informacja' => '')));

        $result = $this->svc->searchCandidates($this->criteria(array('firstname' => 'Jan', 'lastname' => 'Kowalski')));

        $this->assertSame(array(), $result['rows']);
        $this->assertSame(array(), $result['errors']);
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

    public function testIdentifierOfExactlyTheLimitIsStoredWholeByTheMap()
    {
        $id = str_repeat('A', 63) . 'B';
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found($id));

        $result = $this->svc->link(7, $id);

        $this->assertSame(array('success' => true, 'code' => 'linked', 'identifier' => $id, 'name' => 'Jan Kowalski', 'message' => ''), $result);
        $this->assertSame(array($id), array_column($this->mapRows(), 'ifirma_identifier'));
        $this->assertSame($id, $this->svc->linkState(7)['identifier']);
    }

    public function testTheIdentifierLimitCountsCharactersNotBytes()
    {
        $polish = str_repeat("\xC5\xBB", 64);
        $this->t->queue('GET', '#kontrahenci/id/#', $this->found($polish));

        $this->assertTrue($this->svc->link(7, $polish)['success']);
        $this->assertSame($polish, $this->svc->linkState(7)['identifier']);

        $this->assertSame('invalid', $this->svc->link(8, $polish . "\xC5\xBB")['code']);
        $this->assertCount(1, $this->t->calls, 'the 65-character identifier made no request');
        $this->assertSame(array($polish), array_column($this->mapRows(), 'ifirma_identifier'));
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
