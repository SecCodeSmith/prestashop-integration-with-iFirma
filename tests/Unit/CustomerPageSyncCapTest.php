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
 * Spec receipt-status-sync: the sync a customer's order page starts is one list call for the first page of the
 * issue date, refreshes all of that customer's documents of the date, and has its own request budget.
 */
class CustomerPageSyncCapTest extends TestCase
{
    use IfirmaSyncAttemptDb;

    const CUSTOMER = 7;
    const DATE = '2026-10-30';

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaStatusSyncService */
    private $svc;

    /** @var int */
    public $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(12, 0, 0, 11, 1, 2026);
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u', array('faktura' => 'abcd'), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $self = $this;
        $clock = function () use ($self) {
            return $self->now;
        };
        $this->client->setClock($clock);
        $this->svc = new IfirmaStatusSyncService($this->client, $clock);
        $this->scriptSyncAttemptDb();
    }

    private function staleReceipt($ifirmaId, $customer = self::CUSTOMER, $date = self::DATE, array $extra = array())
    {
        $r = new IfirmaReceipt();
        $r->id_order = (int) $ifirmaId;
        $r->id_customer = (int) $customer;
        $r->ifirma_id = (string) $ifirmaId;
        $r->status = IfirmaReceipt::STATUS_CREATED;
        $r->issue_date = $date;
        $r->last_sync_at = date('Y-m-d H:i:s', $this->now - 20 * 60);
        foreach ($extra as $field => $value) {
            $r->$field = $value;
        }
        $r->save();

        return (int) $r->id;
    }

    private function listBody(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows))));
    }

    private function row($id, $paid = 0.0)
    {
        return array(
            'FakturaId' => (string) $id, 'PelnyNumer' => $id . '/2026', 'Brutto' => 100.0, 'Zaplacono' => $paid,
            'CzyWyslano' => true, 'CzySzkic' => false, 'KsefStatus' => '', 'KsefNumer' => '',
            'NumerPowiazanegoPrzychodu' => null,
        );
    }

    /** @return array[] the rows of a full first page that holds none of the documents of these tests */
    private function fullPage()
    {
        $page = array();
        for ($i = 1; $i <= IfirmaStatusSyncService::PAGE_SIZE; $i++) {
            $page[] = $this->row($i);
        }

        return $page;
    }

    private function listCalls()
    {
        $out = array();
        foreach ($this->t->calls as $call) {
            if (strpos($call['url'], 'faktury.json') !== false) {
                parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
                $out[] = $query;
            }
        }

        return $out;
    }

    private function open($id)
    {
        return $this->svc->opportunisticSync(new IfirmaReceipt($id));
    }

    private function stored($id)
    {
        return new IfirmaReceipt($id);
    }

    private function staleTime()
    {
        return date('Y-m-d H:i:s', $this->now - 20 * 60);
    }

    private function minuteCounter()
    {
        return (string) Configuration::get('IFIRMA_SYNC_CUSTOMER_MINUTE');
    }

    private function dayCounter()
    {
        return (string) Configuration::get('IFIRMA_SYNC_CUSTOMER_DAY');
    }

    // ---- Several orders of one issue date ---------------------------------------------------------------------

    public function testFortyOrderPagesOfOneCustomerAndDateCostOneListCall()
    {
        $ids = array();
        $rows = array();
        for ($i = 1; $i <= 40; $i++) {
            $ids[] = $this->staleReceipt(100 + $i);
            $rows[] = $this->row(100 + $i);
        }
        $this->t->queue('GET', '#faktury\.json#', function () use ($rows) {
            return $this->listBody($rows);
        });

        foreach ($ids as $id) {
            $this->open($id);
        }

        $this->assertCount(1, $this->listCalls());
        foreach ($ids as $id) {
            $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->stored($id)->last_sync_at);
        }
    }

    public function testTheRowsOfTheOnePageRefreshTheViewedDocumentAndItsSiblingsOfTheDate()
    {
        $viewed = $this->staleReceipt(101);
        $sibling = $this->staleReceipt(102);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101, 100.0), $this->row(102, 40.0))));

        $this->assertTrue($this->open($viewed));

        $this->assertSame(IfirmaReceipt::STATUS_PAID, $this->stored($viewed)->status);
        $this->assertEquals(40.0, $this->stored($sibling)->remote_paid_amount);
        $this->assertSame(IfirmaReceipt::STATUS_CREATED, $this->stored($sibling)->status);
    }

    public function testADocumentOfAnotherIssueDateIsNotRefreshedByTheCall()
    {
        $viewed = $this->staleReceipt(101);
        $otherDate = $this->staleReceipt(102, self::CUSTOMER, '2026-10-29');
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101), $this->row(102))));

        $this->open($viewed);

        $this->assertSame($this->staleTime(), $this->stored($otherDate)->last_sync_at);
        $this->assertNull($this->storedSyncAttempt($otherDate));
    }

    public function testASiblingThatWasSyncedRecentlyIsLeftAlone()
    {
        $viewed = $this->staleReceipt(101);
        $recentSync = date('Y-m-d H:i:s', $this->now - 5 * 60);
        $fresh = $this->staleReceipt(102, self::CUSTOMER, self::DATE, array('last_sync_at' => $recentSync));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101), $this->row(102, 100.0))));

        $this->open($viewed);

        $this->assertSame($recentSync, $this->stored($fresh)->last_sync_at);
        $this->assertSame(IfirmaReceipt::STATUS_CREATED, $this->stored($fresh)->status);
    }

    public function testAnOrderPageOfACustomerWhoseAnchorWasTriedRecentlySendsNothingAndSpendsNoBudget()
    {
        $anchor = $this->staleReceipt(101, self::CUSTOMER, '2026-10-01', array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now - 60)));
        $viewed = $this->staleReceipt(102);

        $this->assertFalse($this->open($viewed));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(date('YmdHi', $this->now) . ':0', $this->minuteCounter());
        $this->assertSame($this->staleTime(), $this->stored($viewed)->last_sync_at);
        $this->assertNull($this->storedSyncAttempt($viewed));
    }

    // ---- Another customer's documents -------------------------------------------------------------------------

    public function testTheCallRefreshesOnlyTheViewingCustomersDocumentsAndTheOtherCustomersPageStillMakesItsOwnCall()
    {
        $mine = $this->staleReceipt(101, self::CUSTOMER);
        $foreign = $this->staleReceipt(102, 8);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101), $this->row(102, 100.0))));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101), $this->row(102, 100.0))));

        $this->open($mine);

        $this->assertSame($this->staleTime(), $this->stored($foreign)->last_sync_at);
        $this->assertSame(IfirmaReceipt::STATUS_CREATED, $this->stored($foreign)->status);
        $this->assertNull($this->storedSyncAttempt($foreign));

        $this->assertTrue($this->open($foreign));

        $this->assertCount(2, $this->listCalls());
        $this->assertSame(IfirmaReceipt::STATUS_PAID, $this->stored($foreign)->status);
    }

    public function testADocumentWithoutACustomerSendsNothingAndSpendsNoBudget()
    {
        $guest = $this->staleReceipt(101, 0);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101))));

        $this->assertFalse($this->open($guest));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame('', $this->minuteCounter());
    }

    // ---- A busy issue date ------------------------------------------------------------------------------------

    // ---- One call per customer and interval -------------------------------------------------------------------

    public function testElevenOrderPagesOfElevenIssueDatesCostOneListCall()
    {
        $ids = array();
        for ($i = 1; $i <= 11; $i++) {
            $ids[] = $this->staleReceipt(100 + $i, self::CUSTOMER, sprintf('2026-10-%02d', $i));
        }
        $this->t->queue('GET', '#faktury\.json#', function () {
            return $this->listBody(array());
        });

        foreach ($ids as $id) {
            $this->open($id);
        }

        $calls = $this->listCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('2026-10-01', $calls[0]['dataOd']);
        foreach (array_slice($ids, 1) as $id) {
            $this->assertSame($this->staleTime(), $this->stored($id)->last_sync_at);
        }
    }

    public function testTenParallelOrderPagesOfOneCustomerCostOneListCall()
    {
        $ids = array();
        for ($i = 1; $i <= 10; $i++) {
            $ids[] = $this->staleReceipt(100 + $i);
        }
        $this->t->queue('GET', '#faktury\.json#', function () {
            return $this->listBody(array());
        });
        // every request loaded its record before any of them wrote; each starts when the one before is looking up the anchor
        $requests = array();
        foreach ($ids as $id) {
            $requests[] = new IfirmaReceipt($id);
        }
        $rivals = array_slice($requests, 1);
        $this->beforeAnchorLookup = function () use (&$rivals) {
            foreach ($rivals as $request) {
                $this->svc->opportunisticSync($request);
            }
        };

        $this->svc->opportunisticSync($requests[0]);

        $this->assertCount(1, $this->listCalls());
    }

    public function testAnOrderPageAfterTheIntervalMakesANewCall()
    {
        $anchor = $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now - 20 * 60)));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101))));

        $this->assertTrue($this->open($anchor));

        $this->assertCount(1, $this->listCalls());
    }

    public function testThePageOfAnotherCustomerDoesNotWaitForTheFirstCustomersAttempt()
    {
        $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now - 30)));
        $foreign = $this->staleReceipt(102, 8);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(102))));

        $this->assertTrue($this->open($foreign));
    }

    public function testBusyIssueDateIsReadAsOnePageAndAMissingDocumentKeepsItsState()
    {
        $id = $this->staleReceipt(5000);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody($this->fullPage()));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array()));

        $this->open($id);

        $calls = $this->listCalls();
        $this->assertCount(1, $calls);
        $this->assertSame('1', $calls[0]['strona']);
        $this->assertSame((string) IfirmaStatusSyncService::PAGE_SIZE, $calls[0]['iloscNaStronie']);
        $stored = $this->stored($id);
        $this->assertSame(0, (int) $stored->remote_missing);
        $this->assertSame($this->staleTime(), $stored->last_sync_at);
    }

    public function testBusyIssueDateIsNotTriedAgainWithinTheInterval()
    {
        $id = $this->staleReceipt(5000);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody($this->fullPage()));

        $this->open($id);
        $this->now += 5 * 60;
        $this->assertFalse($this->open($id));

        $this->assertCount(1, $this->listCalls());
    }

    public function testBusyIssueDateIsTriedAgainAfterTheInterval()
    {
        $id = $this->staleReceipt(5000);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody($this->fullPage()));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody($this->fullPage()));

        $this->open($id);
        $this->now += 16 * 60;
        $this->open($id);

        $this->assertCount(2, $this->listCalls());
    }

    public function testDocumentsOfAFullPageAreRefreshedWhileTheOnesMissingFromItKeepTheirState()
    {
        $found = $this->staleReceipt(1);
        $absent = $this->staleReceipt(5000);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody($this->fullPage()));

        $this->open($found);

        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->stored($found)->last_sync_at);
        $this->assertSame(0, (int) $this->stored($absent)->remote_missing);
        $this->assertSame($this->staleTime(), $this->stored($absent)->last_sync_at);
    }

    // ---- Document absent from a short page --------------------------------------------------------------------

    public function testDocumentAbsentFromAShortPageIsMarkedMissing()
    {
        $id = $this->staleReceipt(300);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(1), $this->row(2))));

        $this->open($id);

        $this->assertSame(1, (int) $this->stored($id)->remote_missing);
    }

    public function testAnotherDocumentAbsentFromAShortPageKeepsItsStoredState()
    {
        $viewed = $this->staleReceipt(300);
        $other = $this->staleReceipt(301);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(300))));

        $this->open($viewed);

        $this->assertSame(0, (int) $this->stored($viewed)->remote_missing);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->stored($viewed)->last_sync_at);
        $this->assertSame(0, (int) $this->stored($other)->remote_missing);
        $this->assertSame($this->staleTime(), $this->stored($other)->last_sync_at);
    }

    // ---- Claim of the attempt ---------------------------------------------------------------------------------

    public function testThePageClaimsTheCustomersAttemptOnTheAnchorRecordOnly()
    {
        $anchor = $this->staleReceipt(101);
        $viewed = $this->staleReceipt(102);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101), $this->row(102))));

        $this->open($viewed);

        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->storedSyncAttempt($anchor));
        $this->assertNull($this->storedSyncAttempt($viewed));
    }

    public function testAPageViewOfARecordWithAFreshAttemptSendsNothingAndSpendsNoBudget()
    {
        $id = $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now - 30)));

        $this->assertFalse($this->open($id));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(date('YmdHi', $this->now) . ':0', $this->minuteCounter());
    }

    public function testARequestThatLosesTheClaimToAParallelRequestSendsNothingAndGivesTheBudgetBack()
    {
        $id = $this->staleReceipt(101);
        $viewing = new IfirmaReceipt($id);
        // the parallel request stamps the record between this request's load of it and its claim
        ObjectModel::$registry['ifirma_receipt'][$id]['sync_attempt_at'] = date('Y-m-d H:i:s', $this->now - 1);

        $this->assertFalse($this->svc->opportunisticSync($viewing));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(date('Y-m-d H:i:s', $this->now - 1), $this->storedSyncAttempt($id));
        $this->assertSame($this->staleTime(), $this->stored($id)->last_sync_at);
        $this->assertSame(date('YmdHi', $this->now) . ':0', $this->minuteCounter());
    }

    public function testTheAttemptTimeOfTheViewedRecordDoesNotMakeItNotDue()
    {
        $id = $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now - 60)));

        $this->assertTrue($this->svc->shouldSyncOpportunistically(new IfirmaReceipt($id)));
    }

    public function testALastSyncInTheFutureLeavesTheRecordDue()
    {
        $id = $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('last_sync_at' => date('Y-m-d H:i:s', $this->now + 2 * 3600)));

        $this->assertTrue($this->svc->shouldSyncOpportunistically(new IfirmaReceipt($id)));
    }

    public function testSavingARecordLoadedEarlierKeepsTheAttemptTimeAnotherRequestClaimed()
    {
        $id = $this->staleReceipt(101);
        $loaded = new IfirmaReceipt($id);
        IfirmaReceipt::claimSyncAttempt($id, date('Y-m-d H:i:s', $this->now), date('Y-m-d H:i:s', $this->now - 900));

        $loaded->sync_error = 'late write';
        $loaded->save();

        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->storedSyncAttempt($id));
    }

    // ---- Timeout and refusals ---------------------------------------------------------------------------------

    public function testATimeoutIsRecordedForTheViewedDocumentOnlyAndTheOtherDocumentsAreLeftAlone()
    {
        $viewed = $this->staleReceipt(101);
        $other = $this->staleReceipt(102);
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'timeout'));

        $this->assertTrue($this->open($viewed));

        $this->assertNotSame('', (string) $this->stored($viewed)->sync_error);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->stored($viewed)->last_sync_at);
        $this->assertSame('', (string) $this->stored($other)->sync_error);
        $this->assertSame($this->staleTime(), $this->stored($other)->last_sync_at);
        $this->assertNull($this->storedSyncAttempt($other));
    }

    public function testACallThatWasNotSentForAnotherReasonThanTheRequestLimitGivesTheUnitAndTheClaimBack()
    {
        $client = new IfirmaApiClient('u', array(), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $svc = new IfirmaStatusSyncService($client, function () {
            return $this->now;
        });
        $id = $this->staleReceipt(101);
        IfirmaTestState::clearWrites();

        $this->assertFalse($svc->opportunisticSync(new IfirmaReceipt($id)));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(date('YmdHi', $this->now) . ':0', $this->minuteCounter());
        $this->assertNull($this->storedSyncAttempt($id));
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    public function testAFailureBeforeAnythingWasSentGivesTheUnitBack()
    {
        $id = $this->staleReceipt(101);
        $this->beforeAnchorLookup = function () {
            throw new RuntimeException('database gone');
        };

        $this->assertFalse($this->open($id));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(date('YmdHi', $this->now) . ':0', $this->minuteCounter());
    }

    public function testABudgetThatCannotBeReadSendsNothing()
    {
        $budget = new CustomerPageSyncCapUnreadableBudget(array());
        $svc = new IfirmaStatusSyncService($this->client, function () {
            return $this->now;
        }, $budget);
        $id = $this->staleReceipt(101);

        $this->assertFalse($svc->opportunisticSync(new IfirmaReceipt($id)));

        $this->assertSame(array(), $this->t->calls);
    }

    public function testAFailureAfterTheRequestWasSentKeepsTheUnitAndTheClaim()
    {
        $id = $this->staleReceipt(101);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101))));
        $this->beforeStaleFinder = function () {
            throw new RuntimeException('database gone');
        };

        $this->assertFalse($this->open($id));

        $this->assertSame(date('YmdHi', $this->now) . ':1', $this->minuteCounter());
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->storedSyncAttempt($id));
    }


    public function testTheCallUsesTheShortTimeout()
    {
        $id = $this->staleReceipt(101);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101))));

        $this->open($id);

        $this->assertSame(IfirmaStatusSyncService::OPPORTUNISTIC_TIMEOUT, $this->t->calls[0]['timeout']);
    }

    public function testARefusalByTheRequestLimitSendsNothingAndLetsTheNextPageViewTryAgain()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now) . ':14000');
        $viewed = $this->staleReceipt(101);
        $sibling = $this->staleReceipt(102);
        IfirmaTestState::clearWrites();

        $this->assertFalse($this->open($viewed));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertNull($this->storedSyncAttempt($viewed));
        $this->assertNull($this->storedSyncAttempt($sibling));
        $this->assertSame($this->staleTime(), $this->stored($viewed)->last_sync_at);

        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', '');
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101), $this->row(102))));
        $this->assertTrue($this->open($viewed));
        $this->assertCount(1, $this->listCalls());
    }

    public function testARefusalByTheRequestLimitSpendsNoBudget()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now) . ':14000');
        $id = $this->staleReceipt(101);

        $this->open($id);

        $this->assertSame(date('YmdHi', $this->now) . ':0', $this->minuteCounter());
        $this->assertSame(date('Ymd', $this->now) . ':0', $this->dayCounter());
    }

    public function testAnAnswerOfIfirmaThatTheLimitIsExceededWritesNothingAndKeepsTheAttempt()
    {
        $id = $this->staleReceipt(101);
        IfirmaTestState::clearWrites();
        $this->t->queue('GET', '#faktury\.json#', array(
            'status' => 429,
            'body' => '{"response":{"Kod":100,"Informacja":"Przekroczono limit zapytan"}}',
            'headers' => array('Retry-After' => '20'),
        ));

        $this->assertFalse($this->open($id));

        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->storedSyncAttempt($id));
        $this->assertSame(date('YmdHi', $this->now) . ':1', $this->minuteCounter());
    }

    // ---- The request budget of the customer page syncs --------------------------------------------------------

    public function testUsedUpMinuteBudgetOfCustomerSyncsSendsNothing()
    {
        $id = $this->staleReceipt(300);
        Configuration::updateValue('IFIRMA_SYNC_CUSTOMER_MINUTE', date('YmdHi', $this->now) . ':10');
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(300))));

        $this->assertFalse($this->open($id));

        $this->assertSame(array(), $this->t->calls);
        $stored = $this->stored($id);
        $this->assertSame($this->staleTime(), $stored->last_sync_at);
        $this->assertSame('', (string) $stored->sync_error);
        $this->assertNull($this->storedSyncAttempt($id));
    }

    public function testASyncRefusedByTheMinuteBudgetCanBeTriedAgainInTheNextMinute()
    {
        $id = $this->staleReceipt(300);
        Configuration::updateValue('IFIRMA_SYNC_CUSTOMER_MINUTE', date('YmdHi', $this->now) . ':10');
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(300))));
        $this->open($id);

        $this->now += 60;

        $this->assertTrue($this->open($id));
        $this->assertCount(1, $this->listCalls());
    }

    public function testTheMinuteBudgetAllowsTenSyncsAndRefusesTheEleventh()
    {
        $ids = array();
        for ($i = 1; $i <= 11; $i++) {
            $ids[] = $this->staleReceipt(200 + $i, 100 + $i);
        }
        $this->t->queue('GET', '#faktury\.json#', function () {
            return $this->listBody(array());
        });

        foreach ($ids as $id) {
            $this->open($id);
        }

        $this->assertCount(10, $this->listCalls());
        $this->assertSame(date('YmdHi', $this->now) . ':10', $this->minuteCounter());
    }

    public function testUsedUpDayBudgetOfCustomerSyncsSendsNothingEvenInANewMinute()
    {
        $id = $this->staleReceipt(300);
        Configuration::updateValue('IFIRMA_SYNC_CUSTOMER_DAY', date('Ymd', $this->now) . ':1000');
        Configuration::updateValue('IFIRMA_SYNC_CUSTOMER_MINUTE', date('YmdHi', $this->now - 60) . ':3');

        $this->assertFalse($this->open($id));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame($this->staleTime(), $this->stored($id)->last_sync_at);
    }

    public function testNewMinuteStartsANewCountWhenTheDayBudgetIsNotUsedUp()
    {
        $id = $this->staleReceipt(300);
        Configuration::updateValue('IFIRMA_SYNC_CUSTOMER_MINUTE', date('YmdHi', $this->now - 60) . ':10');
        Configuration::updateValue('IFIRMA_SYNC_CUSTOMER_DAY', date('Ymd', $this->now) . ':500');
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(300))));

        $this->assertTrue($this->open($id));

        $this->assertCount(1, $this->listCalls());
        $this->assertSame(date('YmdHi', $this->now) . ':1', $this->minuteCounter());
        $this->assertSame(date('Ymd', $this->now) . ':501', $this->dayCounter());
    }

    public function testAnotherCustomersSyncCountsAgainstTheSameBudget()
    {
        $first = $this->staleReceipt(300, 7);
        $second = $this->staleReceipt(301, 8);
        $this->t->queue('GET', '#faktury\.json#', function () {
            return $this->listBody(array());
        });

        $this->open($first);
        $this->open($second);

        $this->assertSame(date('YmdHi', $this->now) . ':2', $this->minuteCounter());
    }

    public function testTheBudgetOfCustomerSyncsDoesNotTouchTheRequestLimitOfIssuance()
    {
        $id = $this->staleReceipt(300);
        Configuration::updateValue('IFIRMA_SYNC_CUSTOMER_MINUTE', date('YmdHi', $this->now) . ':10');
        $this->open($id);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array()));

        $answer = $this->client->listInvoices(array('dataOd' => self::DATE, 'dataDo' => self::DATE, 'iloscNaStronie' => 100, 'strona' => 1), 'faktura');

        $this->assertSame(array(), $answer);
        $this->assertFalse($this->client->isRateLimited());
    }

    public function testPageViewsDuringTheCredentialsCoolDownSendNothingAndSpendNoBudget()
    {
        $id = $this->staleReceipt(101);
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($this->now - 300) . '|Bad hash');

        $this->assertFalse($this->open($id));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame('', $this->minuteCounter());
        $this->assertSame($this->staleTime(), $this->stored($id)->last_sync_at);
    }

    public function testAnAttemptTimeInTheFutureDoesNotBlockTheSync()
    {
        $id = $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now + 2 * 3600)));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(101))));

        $this->assertTrue($this->open($id));

        $this->assertCount(1, $this->listCalls());
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->storedSyncAttempt($id));
    }

    public function testReleasingTheClaimClearsTheAttemptTimeItWasTakenWith()
    {
        $id = $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now)));

        IfirmaReceipt::releaseSyncAttempt($id, date('Y-m-d H:i:s', $this->now));

        $this->assertNull($this->storedSyncAttempt($id));
    }

    public function testReleasingAClaimOfAnotherRequestLeavesItsTime()
    {
        $id = $this->staleReceipt(101, self::CUSTOMER, self::DATE, array('sync_attempt_at' => date('Y-m-d H:i:s', $this->now - 5)));

        IfirmaReceipt::releaseSyncAttempt($id, date('Y-m-d H:i:s', $this->now));

        $this->assertSame(date('Y-m-d H:i:s', $this->now - 5), $this->storedSyncAttempt($id));
    }

    // ---- A shop that has not run the upgrade script -------------------------------------------------------------

    public function testTheClaimAddsTheMissingColumnBeforeItsStatement()
    {
        $id = $this->staleReceipt(101);
        IfirmaSchema::resetEnsureCache();
        Db::getInstance()->executed = array();
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'sync_attempt_at'/", '0');

        IfirmaReceipt::claimSyncAttempt($id, date('Y-m-d H:i:s', $this->now), $this->staleTime());

        $executed = Db::getInstance()->executed;
        $alter = array_keys(preg_grep('/ADD COLUMN `sync_attempt_at`/', $executed));
        $claim = array_keys(preg_grep('/SET sync_attempt_at = \'/', $executed));
        $this->assertCount(1, $alter);
        $this->assertLessThan($claim[0], $alter[0]);
    }

    public function testSavingAReceiptAddsTheMissingColumnFirst()
    {
        IfirmaSchema::resetEnsureCache();
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'sync_attempt_at'/", '0');

        $this->staleReceipt(101);

        $this->assertCount(1, preg_grep('/ADD COLUMN `sync_attempt_at`/', Db::getInstance()->executed));
    }

    public function testTheCustomerSyncBudgetIsTenPerMinuteAndAThousandPerDay()
    {
        $budget = IfirmaCustomerSyncBudget::create(function () {
            return $this->now;
        });
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($budget->take());
        }

        $this->assertFalse($budget->take());
        $this->assertSame(date('YmdHi', $this->now) . ':10', $this->minuteCounter());
        $this->assertSame(date('Ymd', $this->now) . ':10', $this->dayCounter());
    }
}

/** A budget whose counters cannot be read: taking a unit fails. */
class CustomerPageSyncCapUnreadableBudget extends IfirmaRequestBudget
{
    public function take()
    {
        throw new RuntimeException('counter unreadable');
    }
}
