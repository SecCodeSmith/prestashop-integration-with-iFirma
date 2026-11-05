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
 * IfirmaRemoteDocumentCollector on its own: the list calls of a remote count (windows, pages, filters and
 * the call budget) and the answer it hands to the tally. It is given the identifier, the NIP, today and
 * the registration date, so no customer, order or contractor link is set up here, and a test fails if
 * the collector reads any of them. Every expectation is written out by hand.
 */
class RemoteDocumentCollectorTest extends TestCase
{
    const BASE = 'http://ifirma-mock:8001/iapi/';

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaRemoteDocumentCollector */
    private $collector;

    /** @var int fixed "now": 2026-11-04 12:00:00 UTC, the clock of the API client and the default "today" */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = gmmktime(12, 0, 0, 11, 4, 2026);
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), self::BASE, 30, $this->t);
        $now = $this->now;
        $client->setClock(function () use ($now) {
            return $now;
        });
        $this->collector = new IfirmaRemoteDocumentCollector($client);
    }

    protected function tearDown(): void
    {
        $this->assertSame(array(), Db::getInstance()->queries, 'the collector must not read the Db');
        $this->assertSame(array(), Customer::$loads, 'the collector must not load a customer');
        $this->assertSame(array(), ObjectModel::$writeLog, 'the collector must not write a model');
    }

    // ---- fixtures ---------------------------------------------------------------------------

    private function collect($identifier = 'KOWALSKI-01', $nip = '', $registeredAt = '2026-03-10 09:30:00', $now = null)
    {
        return $this->collector->collect($identifier, $nip, $now === null ? $this->now : $now, $registeredAt);
    }

    private function answer(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows))));
    }

    private function refusal($kod, $text)
    {
        return array('body' => json_encode(array('response' => array('Kod' => $kod, 'Informacja' => $text))));
    }

    private function listed($id, $rodzaj = 'prz_faktura_kraj', $brutto = 100, $zaplacono = 100)
    {
        return array('FakturaId' => $id, 'Rodzaj' => $rodzaj, 'Brutto' => $brutto, 'Zaplacono' => $zaplacono);
    }

    /** The rows ($size of them) of page $page of a long list: ids (page - 1) * 100 + 1 and up. */
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

    /** The answer of a collection that found nothing, after $calls calls, over the default registration. */
    private function nothing($calls, array $over = array())
    {
        return array_merge(array(
            'ok' => true,
            'error' => '',
            'docs' => array(),
            'calls' => $calls,
            'complete' => true,
            'period_from' => '2026-03-10',
            'period_to' => '2026-11-04',
        ), $over);
    }

    // ---- the answer ---------------------------------------------------------------------------------

    public function testTheAnswerHoldsEveryRowOfEveryListAnswerInTheOrderReadAndTheCoverage()
    {
        $this->queueList($this->answer(array($this->listed(1), 'junk', $this->listed(2))));
        $this->queueList($this->answer(array($this->listed(2), $this->listed(3, 'prz_rachunek', '49,99', 0))));

        $found = $this->collect('KOWALSKI-01', '1234563218');

        $this->assertSame(array(
            'ok' => true,
            'error' => '',
            'docs' => array($this->listed(1), 'junk', $this->listed(2), $this->listed(2), $this->listed(3, 'prz_rachunek', '49,99', 0)),
            'calls' => 2,
            'complete' => true,
            'period_from' => '2026-03-10',
            'period_to' => '2026-11-04',
        ), $found);
    }

    public function testTheRowsComeBackAsAListWhateverTheKeysOfTheAnswers()
    {
        $this->queueList($this->answer(array('a' => $this->listed(1), 'b' => $this->listed(2))));
        $this->queueList($this->answer(array('a' => $this->listed(3), 7 => $this->listed(4))));

        $found = $this->collect('KOWALSKI-01', '1234563218');

        $this->assertSame(array($this->listed(1), $this->listed(2), $this->listed(3), $this->listed(4)), $found['docs']);
    }

    public function testACollectionThatFindsNothingAnswersWithAnEmptyList()
    {
        $this->queueEmptyLists();

        $this->assertSame($this->nothing(1), $this->collect());
    }

    // ---- the requests -------------------------------------------------------------------------------

    public function testEveryCallIsASignedGetOfTheListEndpointWithTheInvoiceKey()
    {
        $this->queueEmptyLists();

        $this->collect('KOWALSKI-01', '1234563218');

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

    public function testTheIdentifierAndTheNipAreSentAsGiven()
    {
        $this->queueEmptyLists();

        $this->collect('Jan Kowalski/01 ' . "\xC4\x85\xC4\x99", '012-345-67-89');

        $this->assertSame(array(
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=Jan+Kowalski%2F01+%C4%85%C4%99'),
            $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=012-345-67-89'),
        ), $this->requests());
    }

    public function testWithoutANipOnlyTheContractorIsAskedFor()
    {
        $this->queueEmptyLists();

        $found = $this->collect('KOWALSKI-01', '', '2024-01-15 10:00:00');

        $this->assertSame(array(
            $this->url('2025-11-05', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2024-11-05', '2025-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2024-01-15', '2024-11-04', 'kontrahent=KOWALSKI-01'),
        ), $this->requests());
        $this->assertSame($this->nothing(3, array('period_from' => '2024-01-15')), $found);
    }

    public function testTheWindowsAreYearlyNewestFirstWithoutAGapOrAnOverlapAndEachAsksTheContractorFirst()
    {
        $this->queueEmptyLists();

        $found = $this->collect('KOWALSKI-01', '1234563218', '2024-01-15 10:00:00');

        $this->assertSame(array(
            $this->url('2025-11-05', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2025-11-05', '2026-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-11-05', '2025-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2024-11-05', '2025-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-01-15', '2024-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2024-01-15', '2024-11-04', 'nipKontrahenta=1234563218'),
        ), $this->requests());
        $this->assertSame($this->nothing(6, array('period_from' => '2024-01-15')), $found);
    }

    // ---- today and the registration date ----------------------------------------------------------------

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

    /** @return array label => array(registration date, the windows asked for) */
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
            'a date after other text is no registration date' => array('registered 2026-03-10', $ten),
            'a date with no time' => array('2026-03-10', array(array('2026-03-10', '2026-11-04'))),
        );
    }

    /**
     * @dataProvider registrations
     */
    public function testTheWindowsFollowTheRegistrationDateAndTheTenYearLimit($registeredAt, array $windows)
    {
        $this->queueEmptyLists();

        $found = $this->collect('KOWALSKI-01', '', $registeredAt);

        $expected = array();
        foreach ($windows as $window) {
            $expected[] = $this->url($window[0], $window[1], 'kontrahent=KOWALSKI-01');
        }
        $this->assertSame($expected, $this->requests());
        $last = end($windows);
        $this->assertSame($this->nothing(count($windows), array('period_from' => $last[0])), $found);
    }

    /** @return array label => array(today as a timestamp, registration date, the one window asked for) */
    public static function todays()
    {
        return array(
            'the last second of a day' => array(gmmktime(23, 59, 59, 11, 4, 2026), '2026-03-10', array('2026-03-10', '2026-11-04')),
            'the first second of the next day' => array(gmmktime(0, 0, 0, 11, 5, 2026), '2026-03-10', array('2026-03-10', '2026-11-05')),
            'a day of its own in another year' => array(gmmktime(12, 0, 0, 12, 31, 2027), '2027-12-30 23:00:00', array('2027-12-30', '2027-12-31')),
        );
    }

    /**
     * @dataProvider todays
     */
    public function testTodayIsTheDayOfTheTimestampGivenAndNotTheClockOfTheClient($now, $registeredAt, array $window)
    {
        $this->queueEmptyLists();

        $found = $this->collect('KOWALSKI-01', '', $registeredAt, $now);

        $this->assertSame(array($this->url($window[0], $window[1], 'kontrahent=KOWALSKI-01')), $this->requests());
        $this->assertSame($this->nothing(1, array('period_from' => $window[0], 'period_to' => $window[1])), $found);
    }

    // ---- pages ----------------------------------------------------------------------------------------

    public function testPagesAreAskedForByNumberAfterTheFilterAndUntilAShortPage()
    {
        $this->queueList($this->answer($this->page(1)));
        $this->queueList($this->answer($this->page(2)));
        $this->queueList($this->answer($this->page(3, 3)));
        $this->queueList($this->answer(array()));

        $found = $this->collect('KOWALSKI-01', '1234563218');

        $this->assertSame(array(
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=2'),
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=3'),
            $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=1234563218'),
        ), $this->requests());
        $this->assertSame($this->nothing(4, array('docs' => array_merge($this->page(1), $this->page(2), $this->page(3, 3)))), $found);
    }

    public function testAFullPageIsFollowedByAnEmptyPageThatEndsTheFilter()
    {
        $this->queueList($this->answer($this->page(1)));
        $this->queueList($this->answer(array()));

        $found = $this->collect();

        $this->assertSame($this->nothing(2, array('docs' => $this->page(1))), $found);
        $this->assertSame(array(
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=2'),
        ), $this->requests());
    }

    public function testAPageOfNinetyNineRowsIsTheLastOne()
    {
        $this->queueList($this->answer($this->page(1, 99)));

        $found = $this->collect();

        $this->assertSame(array($this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01')), $this->requests());
        $this->assertSame($this->nothing(1, array('docs' => $this->page(1, 99))), $found);
    }

    public function testAPageOfOneHundredRowsIsFullWhateverTheRowsAre()
    {
        $this->queueList($this->answer(array_merge($this->page(1, 99), array('junk'))));
        $this->queueList($this->answer(array($this->listed(500))));

        $found = $this->collect();

        $this->assertSame(2, $found['calls'], 'the row that is no document still fills the page');
        $this->assertSame(array_merge($this->page(1, 99), array('junk', $this->listed(500))), $found['docs']);
    }

    // ---- the contractor filter and its fallback -------------------------------------------------------

    /** @return array label => array(Kod, Informacja, error of the collection, or null when the filter is dropped) */
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
        $this->queueList($this->refusal($kod, $text));
        $this->queueList($this->answer(array($this->listed(1))));

        $found = $this->collect('KOWALSKI-01', '1234563218');

        if ($error === null) {
            $this->assertSame($this->nothing(2, array('docs' => array($this->listed(1)))), $found);
            $this->assertSame(array(
                $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
                $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=1234563218'),
            ), $this->requests());

            return;
        }
        $this->assertSame(array(
            'ok' => false,
            'error' => $error,
            'docs' => array(),
            'calls' => 1,
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        ), $found);
        $this->assertCount(1, $this->t->calls);
    }

    public function testTheContractorFilterStaysDroppedInTheOlderWindowsOfTheSameCollection()
    {
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));
        $this->queueEmptyLists();

        $found = $this->collect('KOWALSKI-01', '1234563218', '2024-01-15 10:00:00');

        $this->assertSame(array(
            $this->url('2025-11-05', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2025-11-05', '2026-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-11-05', '2025-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2024-01-15', '2024-11-04', 'nipKontrahenta=1234563218'),
        ), $this->requests());
        $this->assertSame($this->nothing(4, array('period_from' => '2024-01-15')), $found);
    }

    public function testAnUnknownContractorWithoutANipCountsNothingAndStillCoversEveryWindow()
    {
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));

        $found = $this->collect('KOWALSKI-01', '', '2024-01-15 10:00:00');

        $this->assertSame(array($this->url('2025-11-05', '2026-11-04', 'kontrahent=KOWALSKI-01')), $this->requests());
        $this->assertSame($this->nothing(1, array('period_from' => '2024-01-15')), $found);
    }

    public function testTheContractorUnknownOnALaterPageIsAFailure()
    {
        $this->queueList($this->answer($this->page(1)));
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));

        $found = $this->collect('KOWALSKI-01', '1234563218');

        $this->assertSame(array(
            'ok' => false,
            'error' => 'Validation error in document data (Kod 201): Nie znaleziono kontrahenta',
            'docs' => array(),
            'calls' => 2,
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        ), $found);
        $this->assertCount(2, $this->t->calls, 'the NIP is not asked for after the failure');
    }

    public function testTheNipQueryIsNeverDroppedForAnUnknownContractor()
    {
        $this->queueList($this->answer(array($this->listed(1))));
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));

        $found = $this->collect('KOWALSKI-01', '1234563218');

        $this->assertFalse($found['ok']);
        $this->assertSame('Validation error in document data (Kod 201): Nie znaleziono kontrahenta', $found['error']);
        $this->assertSame(2, $found['calls']);
    }

    // ---- failures ---------------------------------------------------------------------------------

    public function testAFailureInAnOlderWindowDropsEveryRowReadBeforeIt()
    {
        $this->queueList($this->answer(array($this->listed(1), $this->listed(2, 'prz_faktura_paragon'))));
        $this->queueList(array('status' => 0, 'error' => 'timeout'));

        $found = $this->collect('KOWALSKI-01', '', '2024-01-15 10:00:00');

        $this->assertSame(array(
            'ok' => false,
            'error' => 'cURL error: timeout',
            'docs' => array(),
            'calls' => 2,
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        ), $found);
        $this->assertCount(2, $this->t->calls, 'no older window is asked for after the failure');
    }

    public function testTheLocalRateGuardRefusingTheFirstRequestIsAFailureThatCountsTheAttempt()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':80');

        $found = $this->collect();

        $this->assertSame(array(
            'ok' => false,
            'error' => 'iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.',
            'docs' => array(),
            'calls' => 1,
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        ), $found);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheLocalRateGuardRefusingALaterRequestDropsTheRowsAlreadyRead()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '2');
        $this->queueList($this->answer(array($this->listed(1))));
        $this->queueList($this->answer(array($this->listed(2))));

        $found = $this->collect('KOWALSKI-01', '', '2024-01-15 10:00:00');

        $this->assertSame(array(
            'ok' => false,
            'error' => 'iFirma rate limit guard: 2 requests in the current minute (cap 2). Retry shortly.',
            'docs' => array(),
            'calls' => 3,
            'complete' => false,
            'period_from' => '',
            'period_to' => '',
        ), $found);
        $this->assertCount(2, $this->t->calls);
    }

    public function testAnExceptionOfTheTransportIsNotCaught()
    {
        $this->queueList(function () {
            throw new RuntimeException('line down');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('line down');

        $this->collect();
    }

    // ---- the call budget ----------------------------------------------------------------------------------

    public function testTheBudgetSpentInTheNewestWindowKeepsTheRowsReadAndCoversNothing()
    {
        $this->queueList(function ($method, $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

            return $this->answer($this->page(isset($q['strona']) ? (int) $q['strona'] : 1));
        });

        $found = $this->collect('KOWALSKI-01', '1234563218');

        $requests = $this->requests();
        $this->assertCount(20, $requests);
        $this->assertSame($this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01&strona=20'), end($requests));
        $this->assertTrue($found['ok']);
        $this->assertFalse($found['complete']);
        $this->assertSame(20, $found['calls']);
        $this->assertSame('', $found['period_from']);
        $this->assertSame('', $found['period_to']);
        $this->assertCount(2000, $found['docs'], 'the twenty pages read are kept');
        $this->assertSame($this->listed(1), $found['docs'][0]);
        $this->assertSame($this->listed(2000), $found['docs'][1999]);
    }

    public function testTheBudgetEndingBetweenTwoWindowsCoversTheWindowsBeforeIt()
    {
        $this->queueEmptyLists();

        $found = $this->collect('KOWALSKI-01', '1234563218', '');

        $requests = $this->requests();
        $this->assertCount(20, $requests, 'ten windows of two queries; the eleventh window is not asked for');
        $this->assertSame($this->url('2016-11-07', '2017-11-06', 'nipKontrahenta=1234563218'), end($requests));
        $this->assertSame($this->nothing(20, array('complete' => false, 'period_from' => '2016-11-07')), $found);
    }

    public function testExactlyTheBudgetIsStillComplete()
    {
        $this->queueEmptyLists();

        $found = $this->collect('KOWALSKI-01', '1234563218', '2017-01-01 00:00:00');

        $this->assertSame($this->nothing(20, array('period_from' => '2017-01-01')), $found);
    }

    // ---- one collector, several collections ---------------------------------------------------------------

    public function testACollectorStartsFromNothingForEveryCollection()
    {
        $this->queueList($this->refusal(201, 'Nie znaleziono kontrahenta'));
        $this->queueList($this->answer(array($this->listed(1))));
        $first = $this->collect('KOWALSKI-01', '1234563218');

        $this->queueList($this->answer(array($this->listed(2))));
        $this->queueList($this->answer(array($this->listed(3))));
        $second = $this->collect('KOWALSKI-01', '1234563218');

        $this->assertSame($this->nothing(2, array('docs' => array($this->listed(1)))), $first);
        $this->assertSame($this->nothing(2, array('docs' => array($this->listed(2), $this->listed(3)))), $second, 'calls and rows start again, and the contractor is asked for again');
        $this->assertSame(array(
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=1234563218'),
            $this->url('2026-03-10', '2026-11-04', 'kontrahent=KOWALSKI-01'),
            $this->url('2026-03-10', '2026-11-04', 'nipKontrahenta=1234563218'),
        ), $this->requests());
    }

    public function testACollectionAfterAFailedOneHasNeitherItsCallsNorItsError()
    {
        $this->queueList(array('status' => 0, 'error' => 'timeout'));
        $failed = $this->collect();
        $this->queueList($this->answer(array($this->listed(1))));

        $found = $this->collect();

        $this->assertFalse($failed['ok']);
        $this->assertSame($this->nothing(1, array('docs' => array($this->listed(1)))), $found);
    }

    // ---- what the collector leaves alone ------------------------------------------------------------------

    public function testACollectionReadsNothingButTheListAnswersAndWritesOnlyTheRateWindow()
    {
        $configurationBefore = Configuration::$values;
        $this->queueList($this->answer(array($this->listed(1))));
        $this->queueList($this->answer(array($this->listed(2))));

        $this->collect('KOWALSKI-01', '1234563218');

        $this->assertSame(array('IFIRMA_RATE_WINDOW' => date('YmdHi', $this->now) . ':2'), array_diff_assoc(Configuration::$values, $configurationBefore));
        $this->assertSame(array(), array_diff_assoc($configurationBefore, Configuration::$values));
    }
}
