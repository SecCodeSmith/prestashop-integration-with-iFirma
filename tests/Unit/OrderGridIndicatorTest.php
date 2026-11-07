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
        $this->assertStringContainsString('title="iFirma: FP 7/2026 (Issued)"', $cell);
        $this->assertStringContainsString('text-info', $cell);
        $this->assertStringNotContainsString('text-success', $cell);
    }

    public function testPaidDocumentIsMarkedPaid()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            IfirmaOrderGridIndicator::FIELD_NUMBER => 'FP 7/2026',
            IfirmaOrderGridIndicator::FIELD_STATUS => 'paid',
        ), $this->labels);

        $this->assertStringContainsString('(Paid)', $cell);
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

    public function testTheSelectCountIsThree()
    {
        $this->assertCount(3, IfirmaOrderGridIndicator::selectSql());
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
            '<a href="#" class="js-ifirma-issue" data-id-order="42" title="Issue iFirma document"><i class="material-icons">add_circle_outline</i></a>',
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

        $this->assertStringContainsString('title="Issue iFirma document (Issuance failed)"', $cell);
        $this->assertStringContainsString('data-id-order="42"', $cell);
    }

    public function testHeldOrderNamesTheStateInTheTitle()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => 7,
            IfirmaOrderGridIndicator::FIELD_STATE => 'held',
        ), $this->issueLabels(), true);

        $this->assertStringContainsString('title="Issue iFirma document (Held)"', $cell);
    }

    public function testAnUnlabelledStateFallsBackToTheRawState()
    {
        $cell = IfirmaOrderGridIndicator::cell(array(
            'id_order' => 7,
            IfirmaOrderGridIndicator::FIELD_STATE => 'weird',
        ), array('issue' => 'Issue'), true);

        $this->assertStringContainsString('title="Issue (weird)"', $cell);
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
        $this->assertStringContainsString('&quot;Issue&quot; (&lt;b&gt;x&lt;/b&gt;)', $cell);
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
