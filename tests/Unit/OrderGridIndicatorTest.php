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
 * The "iFirma" column of the back-office Orders list: the SQL that reads the newest receipt of an order and
 * the icon cell built from it.
 */
class OrderGridIndicatorTest extends TestCase
{
    /** @var array */
    private $labels = array('paid' => 'Paid', 'issued' => 'Issued');

    public function testIssuedReceiptShowsTheIconWithItsNumberAndState()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            IfirmaOrderGridIndicator::FIELD_NUMBER => 'FP 7/2026',
            IfirmaOrderGridIndicator::FIELD_STATUS => 'created',
        ), $this->labels);

        $this->assertStringContainsString('material-icons', $cell);
        $this->assertStringContainsString('receipt_long', $cell);
        // deliberate change (task 7.8): the tooltip names the document kind and state in words
        $this->assertStringContainsString("title=\"Invoice issued: FP 7/2026\nNot sent yet\nUnpaid\"", $cell);
        $this->assertStringContainsString('text-info', $cell);
        $this->assertStringNotContainsString('text-success', $cell);
    }

    public function testPaidDocumentIsMarkedPaid()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            IfirmaOrderGridIndicator::FIELD_NUMBER => 'FP 7/2026',
            IfirmaOrderGridIndicator::FIELD_STATUS => 'paid',
        ), $this->labels);

        $this->assertStringContainsString('title="Invoice paid: FP 7/2026', $cell);
        $this->assertStringContainsString('text-success', $cell);
    }

    public function testNumberFallsBackToTheIdentifierInSql()
    {
        $sql = IfirmaOrderGridIndicator::selectSql();

        $this->assertStringContainsString('IF(ifr.ifirma_number <> \'\', ifr.ifirma_number, ifr.ifirma_id)', $sql[0]);
        $this->assertStringEndsWith(' AS ifirma_document_number', $sql[0]);
        $this->assertStringEndsWith(' AS ifirma_document_status', $sql[1]);
        foreach (array($sql[0], $sql[1]) as $expression) {
            $this->assertStringStartsWith('IF(ifr.ifirma_id <> \'\' AND ifr.status <> \'error\', ', $expression);
            $this->assertStringNotContainsString('SELECT', $expression, 'the values come from the joined row, not from a subquery each');
        }
    }

    /**
     * One LEFT JOIN on the newest record of the order (MAX id through idx_order) replaces the two correlated
     * subselects of the first version, so a listed row costs one lookup instead of two.
     */
    public function testTheJoinReadsTheNewestRecordOfTheOrder()
    {
        $this->assertSame(array(
            'table' => '`ps_ifirma_receipt`',
            'alias' => 'ifr',
            'condition' => 'ifr.id_ifirma_receipt = (SELECT MAX(r.id_ifirma_receipt) FROM `ps_ifirma_receipt` r WHERE r.id_order = o.id_order)',
        ), IfirmaOrderGridIndicator::joinSql('o'));
    }

    public function testOrderWithoutARecordHasAnEmptyCell()
    {
        $this->assertSame('', IfirmaOrderGridIndicator::cell(array(), $this->labels));
        $this->assertSame('', IfirmaOrderGridIndicator::cell(array(
            IfirmaOrderGridIndicator::FIELD_NUMBER => '',
            IfirmaOrderGridIndicator::FIELD_STATUS => '',
        ), $this->labels));
    }

    public function testUnissuedRecordHasAnEmptyCell()
    {
        $this->assertSame('', IfirmaOrderGridIndicator::cell(array(
            IfirmaOrderGridIndicator::FIELD_NUMBER => '',
            IfirmaOrderGridIndicator::FIELD_STATUS => 'error',
        ), $this->labels));
        $this->assertSame('', IfirmaOrderGridIndicator::cell(array(
            IfirmaOrderGridIndicator::FIELD_NUMBER => '',
            IfirmaOrderGridIndicator::FIELD_STATUS => 'held',
        ), $this->labels));
    }

    public function testMarkupInANumberIsEscaped()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            IfirmaOrderGridIndicator::FIELD_NUMBER => '<b>FP 1</b>',
            IfirmaOrderGridIndicator::FIELD_STATUS => 'created',
        ), $this->labels);

        $this->assertStringContainsString('&lt;b&gt;FP 1&lt;/b&gt;', $cell);
        $this->assertStringNotContainsString('<b>', $cell);
    }

    public function testTheAliasIsValidated()
    {
        $this->expectException(InvalidArgumentException::class);
        IfirmaOrderGridIndicator::joinSql('o; DROP');
    }

    public function testTheSelectListHasEveryAliasOnce()
    {
        $aliases = array();
        foreach (IfirmaOrderGridIndicator::selectSql() as $expression) {
            $this->assertSame(1, preg_match('/ AS (\w+)$/', $expression, $m), $expression);
            $aliases[] = $m[1];
        }

        $this->assertSame(array(
            'ifirma_document_number', 'ifirma_document_status', 'ifirma_document_state', 'ifirma_document_type',
            'ifirma_document_delivery', 'ifirma_document_sent_date', 'ifirma_document_ksef_status',
            'ifirma_document_ksef_number', 'ifirma_document_error', 'ifirma_document_rank',
        ), $aliases);
        $this->assertSame($aliases, array_unique($aliases));
    }

    public function testNoSelectIsACorrelatedSubquery()
    {
        foreach (IfirmaOrderGridIndicator::selectSql() as $expression) {
            $this->assertStringNotContainsString('SELECT', $expression);
        }
    }

    public function testTheErrorIsReadOneCharacterLongerThanShown()
    {
        $this->assertSame("LEFT(IFNULL(ifr.last_error, ''), 101) AS ifirma_document_error", IfirmaOrderGridIndicator::selectSql()[8]);
    }

    public function testTheRankConstantsOrderTheStates()
    {
        $this->assertSame(array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10), array(
            IfirmaOrderGridIndicator::RANK_ERROR, IfirmaOrderGridIndicator::RANK_UNKNOWN, IfirmaOrderGridIndicator::RANK_SUSPECTED,
            IfirmaOrderGridIndicator::RANK_HELD, IfirmaOrderGridIndicator::RANK_QUEUED, IfirmaOrderGridIndicator::RANK_NO_RECORD,
            IfirmaOrderGridIndicator::RANK_ISSUED, IfirmaOrderGridIndicator::RANK_AWAITING_KSEF, IfirmaOrderGridIndicator::RANK_SENT,
            IfirmaOrderGridIndicator::RANK_PAID,
        ));
    }

    public function testTheRankExpressionPutsPaidAboveItsDeliveryAndKsefBetweenIssuedAndSent()
    {
        $rank = IfirmaOrderGridIndicator::selectSql()[9];

        $this->assertStringStartsWith('CASE ', $rank);
        $this->assertLessThan(strpos($rank, "ifr.delivery_status = 'awaiting_ksef'"), strpos($rank, "ifr.status = 'paid'"));
        $this->assertLessThan(strpos($rank, "ifr.delivery_status = 'sent'"), strpos($rank, "ifr.delivery_status = 'awaiting_ksef'"));
        $this->assertStringEndsWith(' AS ifirma_document_rank', $rank);
    }

    public function testTheOrderByNamesTheRankAlias()
    {
        $this->assertSame('ifirma_document_rank', IfirmaOrderGridIndicator::SORT_ALIAS);
    }

    public function testTheThirdSelectIsTheRawStateOfTheNewestRecord()
    {
        $sql = IfirmaOrderGridIndicator::selectSql();

        $this->assertSame("IFNULL(ifr.status, '') AS ifirma_document_state", $sql[2]);
        $this->assertSame('ifirma_document_state', IfirmaOrderGridIndicator::FIELD_STATE);
    }

    /** @return array words the module class gives the cell */
    private function issueLabels()
    {
        return array(
            'paid' => 'Paid', 'issued' => 'Issued', 'issue' => 'Issue iFirma document',
            'error' => 'Issuance failed', 'unknown' => 'Unknown outcome',
            'duplicate_suspected' => 'Suspected duplicate', 'held' => 'Held',
        );
    }

    public function testOrderWithoutARecordGetsTheIssueAnchor()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => 42,
            IfirmaOrderGridIndicator::FIELD_NUMBER => '',
            IfirmaOrderGridIndicator::FIELD_STATUS => '',
            IfirmaOrderGridIndicator::FIELD_STATE => '',
        ), $this->issueLabels(), true);

        $this->assertSame(
            '<a href="#" class="js-ifirma-issue" data-id-order="42" title="No document yet – click to issue"><i class="material-icons">add_circle_outline</i></a>',
            $cell
        );
    }

    public function testOrderWithoutARecordViewOnlyHasAnEmptyCell()
    {
        $this->assertSame('', IfirmaOrderGridIndicator::cell(array('id_order' => 42), $this->issueLabels(), false));
        $this->assertSame('', IfirmaOrderGridIndicator::cell(array('id_order' => 42), $this->issueLabels()));
    }

    public function testFailedIssuanceNamesTheStateInTheTitle()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => 42,
            IfirmaOrderGridIndicator::FIELD_NUMBER => '',
            IfirmaOrderGridIndicator::FIELD_STATUS => '',
            IfirmaOrderGridIndicator::FIELD_STATE => 'error',
        ), $this->issueLabels(), true);

        $this->assertStringContainsString('title="Issuance failed – click to issue"', $cell);
        $this->assertStringContainsString('data-id-order="42"', $cell);
    }

    public function testHeldOrderNamesTheStateInTheTitle()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => 7,
            IfirmaOrderGridIndicator::FIELD_STATE => 'held',
        ), $this->issueLabels(), true);

        $this->assertStringContainsString('title="Held (data change request) – click to issue"', $cell);
    }

    public function testAnUnlabelledStateFallsBackToTheRawState()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => 7,
            IfirmaOrderGridIndicator::FIELD_STATE => 'weird',
        ), array('issue' => 'Issue'), true);

        $this->assertStringContainsString('title="weird – click to issue"', $cell);
    }

    public function testMarkupInTheIssueTitleIsEscaped()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => '7"><script>',
            IfirmaOrderGridIndicator::FIELD_STATE => '<b>x</b>',
        ), array('issue' => '"Issue"'), true);

        $this->assertStringContainsString('data-id-order="7"', $cell);
        $this->assertStringNotContainsString('<script>', $cell);
        $this->assertStringNotContainsString('<b>', $cell);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt; – click to issue', $cell);
    }

    public function testAnIssuedRecordKeepsTheIconEvenWhenIssuingIsAllowed()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => 42,
            IfirmaOrderGridIndicator::FIELD_NUMBER => 'FP 7/2026',
            IfirmaOrderGridIndicator::FIELD_STATUS => 'created',
            IfirmaOrderGridIndicator::FIELD_STATE => 'created',
        ), $this->issueLabels(), true);

        $this->assertStringContainsString('receipt_long', $cell);
        $this->assertStringNotContainsString('js-ifirma-issue', $cell);
    }
}
