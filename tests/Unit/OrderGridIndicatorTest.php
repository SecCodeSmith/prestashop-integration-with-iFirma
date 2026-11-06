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
        $sql = IfirmaOrderGridIndicator::selectSql('o');

        $this->assertStringContainsString('IF(r.ifirma_number <> \'\', r.ifirma_number, r.ifirma_id)', $sql[0]);
        $this->assertStringEndsWith(' AS ifirma_document_number', $sql[0]);
        $this->assertStringEndsWith(' AS ifirma_document_status', $sql[1]);
        foreach ($sql as $expression) {
            $this->assertStringContainsString('FROM `ps_ifirma_receipt` r', $expression);
            $this->assertStringContainsString('WHERE r.id_order = o.id_order', $expression);
            $this->assertStringContainsString('ORDER BY r.id_ifirma_receipt DESC LIMIT 1', $expression);
            $this->assertStringContainsString('r.ifirma_id <> \'\' AND r.status <> \'error\'', $expression);
        }
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
        IfirmaOrderGridIndicator::selectSql('o; DROP');
    }

    public function testTheSelectCountIsTwo()
    {
        $this->assertCount(2, IfirmaOrderGridIndicator::selectSql('o'));
    }
}
