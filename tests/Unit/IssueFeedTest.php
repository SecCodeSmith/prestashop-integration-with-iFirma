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
 * The issue-events feed: which records are listed (the predicate), what kind each is, the unseen counter and the
 * per-employee seen mark.
 */
class IssueFeedTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function row(array $over = array())
    {
        return array_merge(array(
            'id_ifirma_receipt' => 1,
            'id_order' => 42,
            'reference' => 'ABCDEFGHI',
            'status' => 'created',
            'delivery_status' => 'sent',
            'ksef_status' => '',
            'remote_missing' => 0,
            'ifirma_number' => 'FP 7/2026',
            'ifirma_id' => '77',
            'last_error' => '',
            'date_upd' => '2026-11-07 13:40:00',
            'has_pending_request' => 0,
        ), $over);
    }

    public function testThePredicateNamesEveryCondition()
    {
        $sql = IfirmaIssueFeed::issuePredicate();

        $this->assertStringContainsString("r.status IN ('error','unknown','duplicate_suspected')", $sql);
        $this->assertStringContainsString("r.status = 'held' AND EXISTS (SELECT 1 FROM ps_ifirma_data_request d WHERE d.id_order = r.id_order AND d.status = 'pending')", $sql);
        $this->assertStringContainsString("r.delivery_status = 'failed'", $sql);
        $this->assertStringContainsString("r.ksef_status = 'BLAD'", $sql);
        $this->assertStringContainsString('r.remote_missing = 1', $sql);
        $this->assertStringStartsWith('(', $sql);
        $this->assertStringEndsWith(')', $sql);
    }

    public function testThePredicateFollowsTheAlias()
    {
        $this->assertStringContainsString("x.delivery_status = 'failed'", IfirmaIssueFeed::issuePredicate('x'));
        $this->assertStringNotContainsString('r.status', IfirmaIssueFeed::issuePredicate('x'));
    }

    public function testThePredicateAliasIsValidated()
    {
        $this->expectException(InvalidArgumentException::class);
        IfirmaIssueFeed::issuePredicate('r; DROP');
    }

    public function testFailedIssuanceIsListed()
    {
        $this->assertSame(IfirmaIssueFeed::KIND_ERROR, IfirmaIssueFeed::classify($this->row(array('status' => 'error', 'ifirma_id' => ''))));
        $this->assertSame(IfirmaIssueFeed::KIND_UNKNOWN, IfirmaIssueFeed::classify($this->row(array('status' => 'unknown'))));
        $this->assertSame(IfirmaIssueFeed::KIND_SUSPECTED, IfirmaIssueFeed::classify($this->row(array('status' => 'duplicate_suspected'))));
    }

    public function testADeliveredDocumentIsNotListed()
    {
        $this->assertSame('', IfirmaIssueFeed::classify($this->row()));
        $this->assertSame('', IfirmaIssueFeed::classify($this->row(array('status' => 'paid', 'delivery_status' => 'skipped'))));
        $this->assertSame('', IfirmaIssueFeed::classify($this->row(array('delivery_status' => 'none'))));
    }

    public function testARepairedRecordNoLongerMatches()
    {
        // delivery repaired: 'sent' replaces 'failed'; a held order whose request was answered has no pending request
        $this->assertSame(IfirmaIssueFeed::KIND_DELIVERY_FAILED, IfirmaIssueFeed::classify($this->row(array('delivery_status' => 'failed'))));
        $this->assertSame('', IfirmaIssueFeed::classify($this->row(array('delivery_status' => 'sent'))));
        $this->assertSame(IfirmaIssueFeed::KIND_HELD, IfirmaIssueFeed::classify($this->row(array('status' => 'held', 'has_pending_request' => 1))));
        $this->assertSame('', IfirmaIssueFeed::classify($this->row(array('status' => 'held', 'has_pending_request' => 0))));
        $this->assertSame(IfirmaIssueFeed::KIND_MISSING, IfirmaIssueFeed::classify($this->row(array('remote_missing' => 1))));
        $this->assertSame('', IfirmaIssueFeed::classify($this->row(array('remote_missing' => 0))));
    }

    public function testKsefFailureIsAKind()
    {
        $this->assertSame(IfirmaIssueFeed::KIND_KSEF_FAILED, IfirmaIssueFeed::classify($this->row(array('ksef_status' => 'BLAD'))));
        $this->assertSame('', IfirmaIssueFeed::classify($this->row(array('ksef_status' => 'OK'))));
    }

    public function testTheFirstKindThatAppliesWins()
    {
        $this->assertSame(IfirmaIssueFeed::KIND_HELD, IfirmaIssueFeed::classify($this->row(array(
            'status' => 'held', 'has_pending_request' => 1, 'delivery_status' => 'failed',
        ))));
        $this->assertSame(IfirmaIssueFeed::KIND_ERROR, IfirmaIssueFeed::classify($this->row(array(
            'status' => 'error', 'ksef_status' => 'BLAD',
        ))));
        $this->assertSame(IfirmaIssueFeed::KIND_MISSING, IfirmaIssueFeed::classify($this->row(array(
            'remote_missing' => 1, 'ksef_status' => 'BLAD', 'delivery_status' => 'failed',
        ))));
        $this->assertSame(IfirmaIssueFeed::KIND_KSEF_FAILED, IfirmaIssueFeed::classify($this->row(array(
            'ksef_status' => 'BLAD', 'delivery_status' => 'failed',
        ))));
    }

    public function testKindsListTheDecisionOrder()
    {
        $this->assertSame(
            array('held', 'error', 'unknown', 'suspected', 'missing', 'ksef_failed', 'delivery_failed'),
            IfirmaIssueFeed::kinds()
        );
    }

    public function testLatestQueriesTheNewestMatchingRecords()
    {
        $db = Db::getInstance();
        $db->whenExecuteS('/FROM ps_ifirma_receipt r/', array());

        (new IfirmaIssueFeed())->latest(20);

        $sql = $db->queries[0];
        $this->assertStringContainsString('FROM ps_ifirma_receipt r LEFT JOIN ps_orders o ON o.id_order = r.id_order', $sql);
        $this->assertStringContainsString('WHERE ' . IfirmaIssueFeed::issuePredicate('r'), $sql);
        $this->assertStringContainsString('ORDER BY r.date_upd DESC, r.id_ifirma_receipt DESC LIMIT 20', $sql);
        $this->assertStringContainsString("d.status = 'pending')", $sql);
        $this->assertStringContainsString('o.reference', $sql);
    }

    public function testLatestMapsTheRows()
    {
        Db::getInstance()->whenExecuteS('/FROM ps_ifirma_receipt r/', array(
            $this->row(array('id_ifirma_receipt' => '9', 'status' => 'error', 'last_error' => 'boom', 'ifirma_number' => '')),
            $this->row(array('id_ifirma_receipt' => '8', 'delivery_status' => 'failed')),
        ));

        $items = (new IfirmaIssueFeed())->latest();

        $this->assertSame(array(
            'id_ifirma_receipt' => 9,
            'id_order' => 42,
            'reference' => 'ABCDEFGHI',
            'kind' => 'error',
            'number' => '77',            // no number yet: the identifier
            'time' => '2026-11-07 13:40:00',
            'last_error' => 'boom',
        ), $items[0]);
        $this->assertSame('delivery_failed', $items[1]['kind']);
        $this->assertSame('FP 7/2026', $items[1]['number']);
    }

    public function testLatestLimitIsAnInteger()
    {
        $db = Db::getInstance();
        (new IfirmaIssueFeed())->latest('5; DROP TABLE x');
        $this->assertStringEndsWith('LIMIT 5', $db->queries[0]);
    }

    public function testUnseenCountWithoutAMarkHasNoDateCondition()
    {
        $db = Db::getInstance();
        $db->whenGetValue('/SELECT COUNT\(\*\) FROM ps_ifirma_receipt r/', 4);

        $this->assertSame(4, (new IfirmaIssueFeed())->unseenCount(''));
        $this->assertStringNotContainsString('date_upd >', $db->queries[0]);
        $this->assertStringContainsString(IfirmaIssueFeed::issuePredicate('r'), $db->queries[0]);
    }

    public function testUnseenCountCarriesTheMark()
    {
        $db = Db::getInstance();
        $db->whenGetValue('/SELECT COUNT\(\*\) FROM ps_ifirma_receipt r/', 2);

        $this->assertSame(2, (new IfirmaIssueFeed())->unseenCount("2026-11-07 10:00:00"));
        $this->assertStringEndsWith(" AND r.date_upd > '2026-11-07 10:00:00'", $db->queries[0]);
    }

    public function testTheMarkIsEscaped()
    {
        $db = Db::getInstance();
        (new IfirmaIssueFeed())->unseenCount("x' OR 1=1 -- ");

        $this->assertStringContainsString("r.date_upd > 'x\\' OR 1=1 -- '", $db->queries[0]);
    }

    public function testSeenMarksArePerEmployee()
    {
        $feed = new IfirmaIssueFeed();
        $feed->markSeen(3, mktime(10, 0, 0, 11, 7, 2026));
        $feed->markSeen(8, mktime(12, 30, 0, 11, 7, 2026));

        $this->assertSame('2026-11-07 10:00:00', Configuration::get('IFIRMA_SEEN_3'));
        $this->assertSame('2026-11-07 12:30:00', Configuration::get('IFIRMA_SEEN_8'));
        $this->assertSame('2026-11-07 10:00:00', $feed->seenAt(3));
        $this->assertSame('2026-11-07 12:30:00', $feed->seenAt(8));
        $this->assertSame('', $feed->seenAt(99));
    }

    public function testMarkSeenUsesTheClock()
    {
        $feed = new IfirmaIssueFeed(function () {
            return mktime(9, 15, 0, 11, 7, 2026);
        });
        $feed->markSeen('4');

        $this->assertSame('2026-11-07 09:15:00', Configuration::get('IFIRMA_SEEN_4'));
    }
}
