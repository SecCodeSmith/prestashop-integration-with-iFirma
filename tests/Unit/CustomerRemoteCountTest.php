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
 * IfirmaCustomerContractorService::remoteCount() end to end, pinned beyond the scenarios of
 * CustomerContractorServiceTest: the exact answer (keys, order and types), every request of a count (its
 * method, query string, body and timeout, in order), what a count reads and writes, the windows it asks
 * for and the registration dates they come from, the filters and their fallback, the budget, the
 * documents it counts (duplicates, rows without an id, rows that are no documents) and how it tells the
 * types and the paid documents apart. Every expectation is written out by hand.
 */
class CustomerRemoteCountTest extends TestCase
{
    use IfirmaContractorMapDb;

    const BASE = 'http://ifirma-mock:8001/iapi/';

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaCustomerContractorService */
    private $svc;

    /** @var int fixed "now": 2026-11-04 12:00:00 UTC */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = gmmktime(12, 0, 0, 11, 4, 2026);
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), self::BASE, 30, $this->t);
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

        $c = new Customer();
        $c->id = 7;
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        $c->email = 'jan.kowalski@example.test';
        $c->date_add = '2026-03-10 09:30:00';
        Customer::register($c);
    }

    // ---- fixtures ---------------------------------------------------------------------------

    private function linkCustomer($identifier = 'KOWALSKI-01')
    {
        IfirmaContractorMap::link(7, $identifier, IfirmaContractorMap::BY_IMPORT_EMAIL, 'Jan Kowalski');
    }

    /** The customer gets an order whose invoice address carries the VAT number. */
    private function withNip($vatNumber = '1234563218')
    {
        $a = new Address();
        $a->id = 1;
        $a->id_customer = 7;
        $a->vat_number = $vatNumber;
        Address::register($a);

        $o = new Order();
        $o->id = 10;
        $o->id_customer = 7;
        $o->date_add = '2026-04-01 10:00:00';
        $o->id_address_invoice = 1;
        Order::register($o);
    }

    private function registered($dateAdd)
    {
        Customer::$instances[7]->date_add = $dateAdd;
    }

    private function answer(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows))));
    }

    private function refusal($kod, $text)
    {
        return array('body' => json_encode(array('response' => array('Kod' => $kod, 'Informacja' => $text))));
    }

    private function noRows()
    {
        return $this->answer(array());
    }

    private function listed($id, $rodzaj = 'prz_faktura_kraj', $brutto = 100, $zaplacono = 100)
    {
        return array('FakturaId' => $id, 'Rodzaj' => $rodzaj, 'Brutto' => $brutto, 'Zaplacono' => $zaplacono);
    }

    /** Documents 1..$size of page $page of a long list: ids (page - 1) * 100 + 1 and up. */
    private function page($page, $size = 100)
    {
        $rows = array();
        for ($i = 1; $i <= $size; ++$i) {
            $rows[] = $this->listed(($page - 1) * 100 + $i);
        }

        return $rows;
    }

    private function queueList($response)
    {
        $this->t->queue('GET', '#faktury\.json#', $response);
    }

    /** Every list call answers an empty list. */
    private function queueEmptyLists()
    {
        $this->queueList(function () {
            return array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[]}}');
        });
    }

    /** @return string[] endpoint and query string of every request sent, in order */
    private function requests()
    {
        return array_map(function ($call) {
            return substr($call['url'], strlen(self::BASE));
        }, $this->t->calls);
    }

    /** The request of one list call, spelled out. */
    private function url($from, $to, $filter)
    {
        return 'faktury.json?dataOd=' . $from . '&dataDo=' . $to . '&iloscNaStronie=100&' . $filter;
    }

    private static function noTypes()
    {
        return array('receipt' => 0, 'invoice' => 0, 'other' => 0);
    }

    /** The answer of a count that found nothing to count, after $calls calls. */
    private function nothing($calls, array $over = array())
    {
        return array_merge(array(
            'ok' => true,
            'error' => '',
            'total' => 0,
            'by_type' => self::noTypes(),
            'paid' => 0,
            'period_from' => '2026-03-10',
            'period_to' => '2026-11-04',
            'calls' => $calls,
            'complete' => true,
            'identifier' => 'KOWALSKI-01',
            'nip' => '',
        ), $over);
    }

    // ---- the answer and the requests ----------------------------------------------------------

    public function testTheAnswerOfACountWithBothFiltersIsPinnedWhole()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->answer(array(
            $this->listed(1),
            $this->listed(2, 'prz_faktura_paragon', 100, 0),
            $this->listed(3, 'PRZ_FAKTURA_PROFORMA', 100, 99.995),
        )));
        $this->queueList($this->answer(array(
            $this->listed(3, 'PRZ_FAKTURA_PROFORMA', 100, 99.995),
            $this->listed(4, 'prz_rachunek', '49,99', '49,99'),
            $this->listed(5, 'prz_faktura_kraj', 10, 0),
        )));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array(
            'ok' => true,
            'error' => '',
            'total' => 5,
            'by_type' => array('receipt' => 2, 'invoice' => 2, 'other' => 1),
            'paid' => 3,
            'period_from' => '2026-03-10',
            'period_to' => '2026-11-04',
            'calls' => 2,
            'complete' => true,
            'identifier' => 'KOWALSKI-01',
            'nip' => '1234563218',
        ), $count);
    }

    public function testACustomerWithoutALinkIsCountedUnderTheIdentifierTheModuleGivesIt()
    {
        $this->queueEmptyLists();

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(1, array('identifier' => 'PS-C7')), $count);
        $this->assertSame(array($this->url('2026-03-10', '2026-11-04', 'kontrahent=PS-C7')), $this->requests());
    }

    public function testEveryRequestOfACountIsAGetOfTheListEndpointSignedWithTheInvoiceKey()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->answer(array($this->listed(1))));
        $this->queueList($this->answer(array($this->listed(2))));

        $this->svc->remoteCount(7);

        $sent = array();
        foreach ($this->t->calls as $call) {
            $sent[] = array($call['method'], substr($call['url'], strlen(self::BASE)), $call['body'], $call['timeout']);
        }
        $this->assertSame(array(
            array('GET', $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'), '', 30),
            array('GET', $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=1234563218'), '', 30),
        ), $sent);
        $this->assertSame(
            'IAPIS user=u@example.com, hmac-sha1=' . hash_hmac('sha1', self::BASE . 'faktury.json' . 'u@example.com' . 'faktura', hex2bin(str_repeat('1', 64))),
            $this->t->lastAuthHeader()
        );
    }

    public function testTheIdentifierAndTheNipAreSentAsTheyAreStored()
    {
        $this->linkCustomer('Jan Kowalski/01 ' . "\xC4\x85\xC4\x99");
        $this->withNip('012-345-67-89');
        $this->queueEmptyLists();

        $count = $this->svc->remoteCount(7);

        $this->assertSame('0123456789', $count['nip']);
        $this->assertSame(array(
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=Jan+Kowalski%2F01+%C4%85%C4%99'),
            $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=0123456789'),
        ), $this->requests());
    }

    public function testACountOnlyReadsAndWritesNothingButTheRateWindow()
    {
        $this->linkCustomer();
        $this->withNip();
        IfirmaTestState::clearWrites();
        $configurationBefore = Configuration::$values;
        $db = Db::getInstance();
        $queriesBefore = count($db->queries);
        $executedBefore = count($db->executed);
        $this->queueList($this->answer(array($this->listed(1))));
        $this->queueList($this->answer(array($this->listed(2))));

        $this->svc->remoteCount(7);

        $this->assertSame(array('IFIRMA_RATE_WINDOW' => date('YmdHi', $this->now) . ':2'), array_diff_assoc(Configuration::$values, $configurationBefore));
        $this->assertSame(array(), array_diff_assoc($configurationBefore, Configuration::$values));
        $this->assertSame(array(), array_slice($db->executed, $executedBefore));
        $this->assertSame(array(), $db->inserts);
        $this->assertSame(array(), $db->updates);
        $this->assertSame(array(), $db->deletes);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_contractor_map'));
        $this->assertSame(array(), ObjectModel::$writeLog);
        $queries = array_slice($db->queries, $queriesBefore);
        $this->assertCount(1, $queries, 'the contractor link is the only thing a count reads from the database');
        $this->assertStringContainsString('ps_ifirma_contractor_map', $queries[0]);
        $this->assertStringContainsString('id_customer = 7', $queries[0]);
    }

    public function testTheCustomerIsLoadedOnceForTheInvoiceDataAndOnceForTheRegistrationDate()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueEmptyLists();

        $this->svc->remoteCount(7);

        $this->assertSame(array(7, 7), Customer::$loads);
    }

    public function testTheCustomerIdIsReadAsAnInteger()
    {
        $this->linkCustomer();
        $this->queueEmptyLists();

        $count = $this->svc->remoteCount('7');

        $this->assertSame(array(7, 7), Customer::$loads);
        $this->assertSame($this->nothing(1), $count);
    }

    public function testTheClockIsReadOnceForTheWholeCount()
    {
        $reads = 0;
        $now = $this->now;
        $svc = new IfirmaCustomerContractorService(
            $this->client,
            new IfirmaContractorService($this->client, function () {
            }),
            function () use (&$reads, $now) {
                ++$reads;

                return $now;
            }
        );
        $this->registered('2024-01-15 10:00:00');
        $this->linkCustomer();
        $this->queueEmptyLists();

        $svc->remoteCount(7);

        $this->assertCount(3, $this->t->calls, 'three windows');
        $this->assertSame(1, $reads);
    }

    public function testTheInvoiceDataIsReadBeforeTheFirstRequestIsSent()
    {
        $this->linkCustomer();
        Order::$customerOrdersHook = function () {
            throw new TypeError('order query broke');
        };
        $this->queueEmptyLists();

        try {
            $this->svc->remoteCount(7);
            $this->fail('the TypeError of the order query should reach the caller');
        } catch (TypeError $e) {
            $this->assertSame('order query broke', $e->getMessage());
        }
        $this->assertSame(array(), $this->t->calls);
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_WINDOW'));
    }

    public function testAnExceptionOfTheTransportIsNotCaught()
    {
        $this->linkCustomer();
        $this->queueList(function () {
            throw new RuntimeException('line down');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('line down');

        $this->svc->remoteCount(7);
    }

    // ---- pages ---------------------------------------------------------------------------------

    public function testPagesAreAskedForByNumberAfterTheFilterAndUntilAShortPage()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->answer($this->page(1)));
        $this->queueList($this->answer($this->page(2)));
        $this->queueList($this->answer($this->page(3, 3)));
        $this->queueList($this->noRows());

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array(
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=2'),
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=3'),
            $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=1234563218'),
        ), $this->requests());
        $this->assertSame(array(
            'ok' => true,
            'error' => '',
            'total' => 203,
            'by_type' => array('receipt' => 0, 'invoice' => 203, 'other' => 0),
            'paid' => 203,
            'period_from' => '2026-03-10',
            'period_to' => '2026-11-04',
            'calls' => 4,
            'complete' => true,
            'identifier' => 'KOWALSKI-01',
            'nip' => '1234563218',
        ), $count);
    }

    public function testAFullPageIsFollowedByAnEmptyPageThatEndsTheFilter()
    {
        $this->linkCustomer();
        $this->queueList($this->answer($this->page(1)));
        $this->queueList($this->noRows());

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(2, array(
            'total' => 100,
            'by_type' => array('receipt' => 0, 'invoice' => 100, 'other' => 0),
            'paid' => 100,
        )), $count);
        $this->assertSame(array(
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=2'),
        ), $this->requests());
    }

    public function testAPageOfOneHundredRowsIsFullWhateverTheRowsAre()
    {
        $this->linkCustomer();
        $page = array_merge($this->page(1, 99), array('junk'));
        $this->queueList($this->answer($page));
        $this->queueList($this->answer(array($this->listed(500))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(2, $count['calls'], 'the row that is no document still fills the page');
        $this->assertSame(100, $count['total'], '99 documents and the one of the next page');
    }

    public function testAPageOfNinetyNineRowsIsTheLastOne()
    {
        $this->linkCustomer();
        $this->queueList($this->answer($this->page(1, 99)));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array($this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01')), $this->requests());
        $this->assertSame($this->nothing(1, array(
            'total' => 99,
            'by_type' => array('receipt' => 0, 'invoice' => 99, 'other' => 0),
            'paid' => 99,
        )), $count);
    }

    public function testRowsThatAreNoDocumentsAreNotCounted()
    {
        $this->linkCustomer();
        $this->queueList($this->answer(array('junk', null, 5, $this->listed(1), 'junk', array('Rodzaj' => 'prz_rachunek'))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(1, array(
            'total' => 2,
            'by_type' => array('receipt' => 1, 'invoice' => 1, 'other' => 0),
            'paid' => 2,
        )), $count);
    }

    // ---- windows ------------------------------------------------------------------------------

    public function testTheWindowsAreYearlyNewestFirstWithoutAGapOrAnOverlapAndEachAsksTheContractorFirst()
    {
        $this->registered('2024-01-15 10:00:00');
        $this->linkCustomer();
        $this->withNip();
        $this->queueEmptyLists();

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array(
            $this->url('2025-11-05', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2025-11-05', '2026-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-11-05', '2025-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2024-11-05', '2025-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-01-15', '2024-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2024-01-15', '2024-11-04', 'nipKontrahenta=1234563218'),
        ), $this->requests());
        $this->assertSame($this->nothing(6, array('period_from' => '2024-01-15', 'nip' => '1234563218')), $count);
    }

    /** @return array[] window (dataOd, dataDo) of a customer who registered ten years ago or earlier */
    private static function tenYearsOfWindows()
    {
        return array(
            array('2025-11-05', '2026-11-04'),
            array('2024-11-05', '2025-11-04'),
            array('2023-11-06', '2024-11-04'),
            array('2022-11-06', '2023-11-05'),
            array('2021-11-06', '2022-11-05'),
            array('2020-11-06', '2021-11-05'),
            array('2019-11-07', '2020-11-05'),
            array('2018-11-07', '2019-11-06'),
            array('2017-11-07', '2018-11-06'),
            array('2016-11-07', '2017-11-06'),
            array('2016-11-04', '2016-11-06'),
        );
    }

    /** @return array label => array(date_add of the customer, the windows asked for) */
    public static function registrations()
    {
        $today = array(array('2026-11-04', '2026-11-04'));
        $ten = self::tenYearsOfWindows();
        $shortLast = $ten;
        $shortLast[10] = array('2016-11-05', '2016-11-06');

        return array(
            'in the middle of the first window' => array('2026-03-10 09:30:00', array(array('2026-03-10', '2026-11-04'))),
            'on the first day of the first window' => array('2025-11-05 00:00:00', array(array('2025-11-05', '2026-11-04'))),
            'the day before the first window: a window of one day' => array('2025-11-04 23:59:59', array(array('2025-11-05', '2026-11-04'), array('2025-11-04', '2025-11-04'))),
            'two days after the start of the first window' => array('2025-11-06', array(array('2025-11-06', '2026-11-04'))),
            'today' => array('2026-11-04 08:00:00', $today),
            'tomorrow, the first second of it' => array('2026-11-05 00:00:01', $today),
            'far in the future' => array('2030-01-01 00:00:00', $today),
            'exactly ten years ago' => array('2016-11-04 00:00:00', $ten),
            'a day after ten years ago' => array('2016-11-05 00:00:00', $shortLast),
            'long before' => array('2001-01-01 00:00:00', $ten),
            'no date at all' => array('', $ten),
            'a null date' => array(null, $ten),
            'the zero date of an empty column' => array('0000-00-00 00:00:00', $ten),
            'a text that is no date' => array('n/a', $ten),
        );
    }

    /**
     * @dataProvider registrations
     */
    public function testTheWindowsFollowTheRegistrationDateAndTheTenYearLimit($dateAdd, array $windows)
    {
        $this->registered($dateAdd);
        $this->queueEmptyLists();

        $count = $this->svc->remoteCount(7);

        $expected = array();
        foreach ($windows as $window) {
            $expected[] = $this->url($window[0], $window[1], 'kontrahent=PS-C7');
        }
        $this->assertSame($expected, $this->requests());
        $last = end($windows);
        $this->assertSame($this->nothing(count($windows), array('identifier' => 'PS-C7', 'period_from' => $last[0])), $count);
    }

    // ---- the contractor filter and its fallback -------------------------------------------------

    /** @return array label => array(Kod, Informacja, error of the count, or null when the filter is dropped) */
    public static function contractorAnswers()
    {
        return array(
            'Kod 201 naming the contractor' => array(201, 'Nie znaleziono kontrahenta o podanym identyfikatorze', null),
            'the same in capitals' => array(201, 'NIE ZNALEZIONO KONTRAHENTA', null),
            'Kod 201 about something else' => array(201, 'Nieprawidlowa wartosc NIP', 'Validation error in document data (Kod 201): Nieprawidlowa wartosc NIP'),
            'Kod 201 without a text' => array(201, '', 'Validation error in document data (Kod 201)'),
            'another Kod naming the contractor' => array(202, 'Nie znaleziono kontrahenta', 'Wrong service or account settings (Kod 202): Nie znaleziono kontrahenta'),
        );
    }

    /**
     * @dataProvider contractorAnswers
     */
    public function testOnlyKod201AboutTheContractorDropsTheContractorFilter($kod, $text, $error)
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->refusal($kod, $text));
        $this->queueList($this->answer(array($this->listed(1))));

        $count = $this->svc->remoteCount(7);

        if ($error === null) {
            $this->assertSame($this->nothing(2, array(
                'total' => 1,
                'by_type' => array('receipt' => 0, 'invoice' => 1, 'other' => 0),
                'paid' => 1,
                'nip' => '1234563218',
            )), $count);
            $this->assertSame(array(
                $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
                $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=1234563218'),
            ), $this->requests());

            return;
        }
        $this->assertSame($this->nothing(1, array(
            'ok' => false,
            'error' => $error,
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
            'nip' => '1234563218',
        )), $count);
        $this->assertCount(1, $this->t->calls);
    }

    public function testTheContractorFilterStaysDroppedInTheOlderWindowsOfTheSameCount()
    {
        $this->registered('2024-01-15 10:00:00');
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));
        $this->queueEmptyLists();

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array(
            $this->url('2025-11-05', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2025-11-05', '2026-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-11-05', '2025-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-01-15', '2024-11-04', 'nipKontrahenta=1234563218'),
        ), $this->requests());
        $this->assertSame($this->nothing(4, array('period_from' => '2024-01-15', 'nip' => '1234563218')), $count);
    }

    public function testAnUnknownContractorWithoutANipCountsNothingAndStillCoversEveryWindow()
    {
        $this->registered('2024-01-15 10:00:00');
        $this->linkCustomer();
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array($this->url('2025-11-05', '2026-11-04', 'kontrahent=KOWALSKI-01')), $this->requests());
        $this->assertSame($this->nothing(1, array('period_from' => '2024-01-15')), $count);
    }

    public function testTheContractorUnknownOnALaterPageIsAFailure()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->answer($this->page(1)));
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(2, array(
            'ok' => false,
            'error' => 'Validation error in document data (Kod 201): Nie znaleziono kontrahenta',
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
            'nip' => '1234563218',
        )), $count);
        $this->assertCount(2, $this->t->calls, 'the NIP is not asked for after the failure');
    }

    public function testTheNipQueryIsNeverDroppedForAnUnknownContractor()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->answer(array($this->listed(1))));
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(2, array(
            'ok' => false,
            'error' => 'Validation error in document data (Kod 201): Nie znaleziono kontrahenta',
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
            'nip' => '1234563218',
        )), $count);
    }

    // ---- failures -------------------------------------------------------------------------------

    public function testAFailureInAnOlderWindowDropsEverythingCountedBefore()
    {
        $this->registered('2024-01-15 10:00:00');
        $this->linkCustomer();
        $this->queueList($this->answer(array($this->listed(1), $this->listed(2, 'prz_faktura_paragon'))));
        $this->queueList(array('status' => 0, 'error' => 'timeout'));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array(
            'ok' => false,
            'error' => 'cURL error: timeout',
            'total' => 0,
            'by_type' => self::noTypes(),
            'paid' => 0,
            'period_from' => '',
            'period_to' => '',
            'calls' => 2,
            'complete' => false,
            'identifier' => 'KOWALSKI-01',
            'nip' => '',
        ), $count);
        $this->assertCount(2, $this->t->calls, 'no older window is asked for after the failure');
    }

    public function testTheLocalRateGuardRefusingTheFirstRequestIsAFailureThatCountsTheAttempt()
    {
        $this->linkCustomer();
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':80');

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(1, array(
            'ok' => false,
            'error' => 'iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.',
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        )), $count);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheLocalRateGuardRefusingALaterRequestDropsTheDocumentsAlreadyRead()
    {
        $this->registered('2024-01-15 10:00:00');
        $this->linkCustomer();
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '2');
        $this->queueList($this->answer(array($this->listed(1))));
        $this->queueList($this->answer(array($this->listed(2))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(3, array(
            'ok' => false,
            'error' => 'iFirma rate limit guard: 2 requests in the current minute (cap 2). Retry shortly.',
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        )), $count);
        $this->assertCount(2, $this->t->calls);
    }

    // ---- the budget -------------------------------------------------------------------------------

    public function testTheBudgetSpentInTheNewestWindowCoversNothing()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList(function ($method, $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return $this->answer($this->page(isset($q['strona']) ? (int) $q['strona'] : 1));
        });

        $count = $this->svc->remoteCount(7);

        $this->assertSame(array(
            'ok' => true,
            'error' => '',
            'total' => 2000,
            'by_type' => array('receipt' => 0, 'invoice' => 2000, 'other' => 0),
            'paid' => 2000,
            'period_from' => '',
            'period_to' => '',
            'calls' => 20,
            'complete' => false,
            'identifier' => 'KOWALSKI-01',
            'nip' => '1234563218',
        ), $count);
        $requests = $this->requests();
        $this->assertCount(20, $requests);
        $this->assertSame($this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=20'), end($requests));
    }

    public function testTheBudgetEndingBetweenTwoWindowsKeepsTheWindowsBeforeIt()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->registered('');
        $this->queueEmptyLists();

        $count = $this->svc->remoteCount(7);

        $requests = $this->requests();
        $this->assertCount(20, $requests, 'ten windows of two queries; the eleventh window is not asked for');
        $this->assertSame($this->url('2016-11-07', '2017-11-06', 'nipKontrahenta=1234563218'), end($requests));
        $this->assertSame($this->nothing(20, array(
            'complete' => false,
            'period_from' => '2016-11-07',
            'nip' => '1234563218',
        )), $count);
    }

    // ---- the documents --------------------------------------------------------------------------------

    public function testDocumentsWithoutAnIdAreOneDocumentWhenTheyAreEqualAndTheLastRowOfAnIdWins()
    {
        $this->linkCustomer();
        $this->withNip();
        $noId = array('Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 0);
        $this->queueList($this->answer(array(
            $noId,
            $noId,
            array('Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 11, 'Zaplacono' => 11),
            array('FakturaId' => '', 'Rodzaj' => 'prz_rachunek', 'Brutto' => 5, 'Zaplacono' => 5),
            array('FakturaId' => 0, 'Rodzaj' => 'prz_faktura_paragon', 'Brutto' => 7, 'Zaplacono' => 7),
        )));
        $this->queueList($this->answer(array(
            $noId,
            array('FakturaId' => '0', 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 9, 'Zaplacono' => 0),
        )));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(4, $count['total'], 'the row without an id twice and again, one with an empty id, and the id 0 twice');
        $this->assertSame(array('receipt' => 1, 'invoice' => 3, 'other' => 0), $count['by_type'], 'the second row of the id 0 replaces the first');
        $this->assertSame(2, $count['paid']);
    }

    public function testRowsWithoutAnIdThatDifferInAnyFieldAreDifferentDocuments()
    {
        $this->linkCustomer();
        $this->queueList($this->answer(array(
            array('Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 10),
            array('Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 10, 'Numer' => '1/2026'),
            array('Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 10, 'Numer' => '2/2026'),
        )));

        $this->assertSame(3, $this->svc->remoteCount(7)['total']);
    }

    public function testRowsWithAnEmptyIdAreToldApartByTheirContent()
    {
        $this->linkCustomer();
        $this->queueList($this->answer(array(
            array('FakturaId' => '', 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 10),
            array('FakturaId' => '', 'Rodzaj' => 'prz_rachunek', 'Brutto' => 20, 'Zaplacono' => 0),
            array('FakturaId' => '', 'Rodzaj' => 'prz_rachunek', 'Brutto' => 20, 'Zaplacono' => 0),
        )));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(2, $count['total']);
        $this->assertSame(array('receipt' => 1, 'invoice' => 1, 'other' => 0), $count['by_type']);
        $this->assertSame(1, $count['paid']);
    }

    public function testTheRowsOfAnAnswerAreCountedWhateverTheirKeysAre()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->answer(array('a' => $this->listed(1), 'b' => $this->listed(2, 'prz_rachunek'))));
        $this->queueList($this->answer(array('a' => $this->listed(3), 'c' => $this->listed(4, 'prz_faktura_proforma'))));

        $count = $this->svc->remoteCount(7);

        $this->assertSame(4, $count['total'], 'the key a of the second answer does not replace the key a of the first');
        $this->assertSame(array('receipt' => 1, 'invoice' => 2, 'other' => 1), $count['by_type']);
        $this->assertSame(2, $count['calls']);
    }

    public function testTheSameIdAsANumberAndAsATextIsOneDocument()
    {
        $this->linkCustomer();
        $this->withNip();
        $this->queueList($this->answer(array($this->listed(5), $this->listed(6))));
        $this->queueList($this->answer(array($this->listed('5'), $this->listed('7'))));

        $this->assertSame(3, $this->svc->remoteCount(7)['total']);
    }

    /**
     * @return array label => array(rows of the one list a customer without a NIP is asked for, total,
     *                              documents by type, paid)
     */
    public static function countedDocuments()
    {
        $row = function ($id, $rodzaj, $brutto, $zaplacono) {
            return array('FakturaId' => $id, 'Rodzaj' => $rodzaj, 'Brutto' => $brutto, 'Zaplacono' => $zaplacono);
        };
        $receipt = array('receipt' => 1, 'invoice' => 0, 'other' => 0);
        $invoice = array('receipt' => 0, 'invoice' => 1, 'other' => 0);
        $other = array('receipt' => 0, 'invoice' => 0, 'other' => 1);

        return array(
            'nothing' => array(array(), 0, array('receipt' => 0, 'invoice' => 0, 'other' => 0), 0),
            'a receipt' => array(array($row(1, 'prz_faktura_paragon', 10, 10)), 1, $receipt, 1),
            'a bill' => array(array($row(1, 'prz_rachunek', 10, 10)), 1, $receipt, 1),
            'a bill in capitals' => array(array($row(1, 'PRZ_RACHUNEK', 10, 10)), 1, $receipt, 1),
            'a domestic invoice' => array(array($row(1, 'prz_faktura_kraj', 10, 10)), 1, $invoice, 1),
            'a domestic invoice without separators' => array(array($row(1, 'PRZ_FAKTURAKRAJ', 10, 10)), 1, $invoice, 1),
            'a pro forma' => array(array($row(1, 'prz_faktura_proforma', 10, 10)), 1, $other, 1),
            'an empty kind' => array(array($row(1, '', 10, 10)), 1, $other, 1),
            'no kind' => array(array(array('FakturaId' => 1, 'Brutto' => 10, 'Zaplacono' => 10)), 1, $other, 1),
            'paid in full' => array(array($row(1, 'prz_faktura_kraj', 100, 100)), 1, $invoice, 1),
            'paid a grosz short' => array(array($row(1, 'prz_faktura_kraj', 100, 99.99)), 1, $invoice, 1),
            'paid two groszy short' => array(array($row(1, 'prz_faktura_kraj', 100, 99.98)), 1, $invoice, 0),
            'not paid' => array(array($row(1, 'prz_faktura_kraj', 100, 0)), 1, $invoice, 0),
            'paid too much' => array(array($row(1, 'prz_faktura_kraj', 100, 150)), 1, $invoice, 1),
            'amounts with a decimal comma, paid' => array(array($row(1, 'prz_faktura_kraj', '1000,50', '1000,50')), 1, $invoice, 1),
            'amounts with a decimal comma, not paid' => array(array($row(1, 'prz_faktura_kraj', '1000,50', '1000,00')), 1, $invoice, 0),
            'amounts as texts' => array(array($row(1, 'prz_faktura_kraj', '100.00', '99.99')), 1, $invoice, 1),
            'a correction of a negative amount' => array(array($row(1, 'prz_faktura_kraj', -100, 0)), 1, $invoice, 1),
            'no amounts' => array(array(array('FakturaId' => 1, 'Rodzaj' => 'prz_faktura_kraj')), 1, $invoice, 1),
            'no paid amount' => array(array(array('FakturaId' => 1, 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 100)), 1, $invoice, 0),
            'no gross amount' => array(array(array('FakturaId' => 1, 'Rodzaj' => 'prz_faktura_kraj', 'Zaplacono' => 100)), 1, $invoice, 1),
            'one of each, two paid' => array(array(
                $row(1, 'prz_faktura_paragon', 10, 10),
                $row(2, 'prz_faktura_kraj', 20, 0),
                $row(3, 'prz_faktura_proforma', 30, 30),
                $row(4, 'prz_faktura_kraj', 40, 0),
            ), 4, array('receipt' => 1, 'invoice' => 2, 'other' => 1), 2),
            'the same id twice' => array(array(
                $row(1, 'prz_faktura_kraj', 10, 0),
                $row(1, 'prz_faktura_paragon', 10, 10),
            ), 1, $receipt, 1),
        );
    }

    /**
     * @dataProvider countedDocuments
     */
    public function testTheDocumentsOfOneListAreCountedByTypeAndByPaid(array $rows, $total, array $byType, $paid)
    {
        $this->linkCustomer();
        $this->queueList($this->answer($rows));

        $count = $this->svc->remoteCount(7);

        $this->assertSame($this->nothing(1, array('total' => $total, 'by_type' => $byType, 'paid' => $paid)), $count);
    }
}
