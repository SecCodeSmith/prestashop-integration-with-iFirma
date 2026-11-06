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
        foreach ($sql as $expression) {
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

    public function testTheSelectCountIsTwo()
    {
        $this->assertCount(2, IfirmaOrderGridIndicator::selectSql());
    }
}
