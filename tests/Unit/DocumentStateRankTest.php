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
 * Spec: order-list-receipt-indicator (Filtering by document state), receipt-documents-list (State column sorts by
 * action rank). One state rank for both lists.
 */
class DocumentStateRankTest extends TestCase
{
    public function testTheTenStatesAreListedInRankOrderWithALabelKeyEach()
    {
        $states = IfirmaDocumentStateRank::states();

        $this->assertSame(range(1, 10), array_column($states, 'rank'));
        $this->assertSame(
            array('error', 'unknown', 'duplicate_suspected', 'held', 'queued', 'none', 'issued', 'awaiting_ksef', 'sent', 'paid'),
            array_column($states, 'state')
        );
        $this->assertSame(
            array(
                'state_error', 'state_unknown', 'state_duplicate_suspected', 'state_held', 'state_queued',
                'state_none', 'state_issued', 'state_awaiting_ksef', 'state_sent', 'state_paid',
            ),
            array_column($states, 'label_key')
        );
    }

    public function testTheExpressionNamesTheGivenAlias()
    {
        $sql = IfirmaDocumentStateRank::sql('a');

        $this->assertStringStartsWith('CASE ', $sql);
        $this->assertStringEndsWith(' ELSE 6 END', $sql);
        $this->assertStringContainsString('a.id_ifirma_receipt IS NULL', $sql);
        $this->assertStringNotContainsString('ifr.', $sql);
    }

    /**
     * @return array[] the CASE branch of each state: rank => SQL fragment that must be in the expression
     */
    public function branches()
    {
        $issued = "a.ifirma_id <> '' AND a.status <> 'error'";

        return array(
            'error' => array(1, "WHEN a.status = 'error' THEN 1"),
            'unknown' => array(2, "WHEN a.status = 'unknown' THEN 2"),
            'duplicate_suspected' => array(3, "WHEN a.status = 'duplicate_suspected' THEN 3"),
            'held' => array(4, "WHEN a.status = 'held' THEN 4"),
            'queued' => array(5, "WHEN a.status = 'queued' THEN 5"),
            'no record' => array(6, 'WHEN a.id_ifirma_receipt IS NULL THEN 6'),
            'issued, not sent' => array(7, "WHEN {$issued} THEN 7"),
            'awaiting ksef' => array(8, "WHEN {$issued} AND a.delivery_status = 'awaiting_ksef' THEN 8"),
            'sent' => array(9, "WHEN {$issued} AND a.delivery_status = 'sent' THEN 9"),
            'paid' => array(10, "WHEN {$issued} AND a.status = 'paid' THEN 10"),
        );
    }

    /**
     * @dataProvider branches
     */
    public function testEveryStateHasItsBranchInTheExpression($rank, $branch)
    {
        $this->assertStringContainsString($branch, IfirmaDocumentStateRank::sql('a'));
        $this->assertSame(1, preg_match('/THEN ' . $rank . '\b/', IfirmaDocumentStateRank::sql('a')));
    }

    public function testPaidWinsOverItsDeliveryAndKsefOverSent()
    {
        $sql = IfirmaDocumentStateRank::sql('a');

        $this->assertLessThan(strpos($sql, "a.delivery_status = 'awaiting_ksef'"), strpos($sql, "a.status = 'paid'"));
        $this->assertLessThan(strpos($sql, "a.delivery_status = 'sent'"), strpos($sql, "a.delivery_status = 'awaiting_ksef'"));
    }

    public function testTheIssuedConditionIsTheOneOfTheOrderGrid()
    {
        $this->assertSame("a.ifirma_id <> '' AND a.status <> 'error'", IfirmaDocumentStateRank::issuedSql('a'));
    }

    public function testAnAliasThatIsNotAPlainIdentifierIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);

        IfirmaDocumentStateRank::sql('a; DROP TABLE x');
    }

    /**
     * @return array[]
     */
    public function acceptedValues()
    {
        return array('int 1' => array(1, 1), 'int 10' => array(10, 10), 'string 7' => array('7', 7), 'string 10' => array('10', 10));
    }

    /**
     * @dataProvider acceptedValues
     */
    public function testAnIntegerFromOneToTenIsAcceptedAsARank($value, $expected)
    {
        $this->assertSame($expected, IfirmaDocumentStateRank::parse($value));
    }

    /**
     * @return array[]
     */
    public function refusedValues()
    {
        return array(
            'unknown text' => array('refunded'),
            'or one equals one' => array('1 OR 1=1'),
            'drop table' => array("'; DROP TABLE ps_orders; --"),
            'array' => array(array(1)),
            'empty string' => array(''),
            'zero' => array(0),
            'zero string' => array('0'),
            'eleven' => array(11),
            'eleven string' => array('11'),
            'null' => array(null),
            'negative' => array('-1'),
            'float' => array(3.0),
            'float string' => array('3.5'),
            'leading space' => array(' 3'),
            'trailing newline' => array("3\n"),
            'leading zero' => array('03'),
            'bool' => array(true),
            'object' => array(new stdClass()),
        );
    }

    /**
     * @dataProvider refusedValues
     */
    public function testAnythingElseIsNotARank($value)
    {
        $this->assertNull(IfirmaDocumentStateRank::parse($value));
    }
}
