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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/admin/AdminIfirmaController.php';

/** Exposes the protected sort methods of the controller, as the core list calls them. */
class IfirmaTestSortController extends AdminIfirmaController
{
    public function orderByClause($orderBy, $orderDirection)
    {
        return $this->getOrderByClause($orderBy, $orderDirection);
    }

    public function direction($orderDirection)
    {
        return $this->checkOrderDirection($orderDirection);
    }
}

/**
 * Spec: receipt-documents-list (State column sorts by action rank; Sorting keeps filters, paging and the default
 * order).
 */
class DocumentsListSortTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id_profile = 4;
        Access::reset();
    }

    protected function tearDown(): void
    {
        Tools::$values = array();
    }

    private function controller(array $request = array())
    {
        Tools::$values = $request;

        return new IfirmaTestSortController();
    }

    // ---- the sort expression, run against real rows ----------------------------------------------------------

    /**
     * @param array[] $rows
     * @return int[] the ids in the order the list's ORDER BY gives them
     */
    private function sortedIds(array $rows, $way)
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is needed to run the sort expression');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE r (id_ifirma_receipt INTEGER, ifirma_id TEXT, status TEXT, delivery_status TEXT)');
        $insert = $pdo->prepare('INSERT INTO r VALUES (?, ?, ?, ?)');
        foreach ($rows as $row) {
            $insert->execute(array($row[0], $row[1], $row[2], $row[3]));
        }
        $clause = IfirmaDocumentListSort::withTieBreak(
            ' ORDER BY ' . IfirmaDocumentListSort::RANK_ALIAS . ' ' . $way,
            IfirmaDocumentListSort::RANK_ALIAS,
            $way
        );
        $sql = 'SELECT a.id_ifirma_receipt FROM (SELECT r.*, ' . IfirmaDocumentListSort::rankSelect('r') . ' FROM r) a' . $clause;

        return array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array[] id, ifirma_id, status, delivery_status
     */
    private function mixedRows()
    {
        return array(
            array(1, '77', 'paid', 'sent'),
            array(2, '78', 'created', 'sent'),
            array(3, '', 'queued', 'none'),
            array(4, '', 'error', 'none'),
        );
    }

    public function testAscendingPutsWhatNeedsActionFirst()
    {
        $this->assertSame(array(4, 3, 2, 1), $this->sortedIds($this->mixedRows(), 'ASC'));
    }

    public function testDescendingPutsPaidFirst()
    {
        $this->assertSame(array(1, 2, 3, 4), $this->sortedIds($this->mixedRows(), 'DESC'));
    }

    public function testAnErrorRecordIsListedBeforeAnIssuedAndNotSentOneAscending()
    {
        $rows = array(array(5, '79', 'created', 'none'), array(6, '', 'error', 'none'));

        $this->assertSame(array(6, 5), $this->sortedIds($rows, 'ASC'));
    }

    public function testRecordsInTheSameStateAreListedNewestFirstInBothDirections()
    {
        $rows = array(array(40, '1', 'created', 'sent'), array(41, '2', 'created', 'sent'));

        $this->assertSame(array(41, 40), $this->sortedIds($rows, 'ASC'));
        $this->assertSame(array(41, 40), $this->sortedIds($rows, 'DESC'));
    }

    public function testEachRecordOfAnOrderKeepsItsOwnRank()
    {
        $rows = array(array(7, '', 'error', 'none'), array(8, '9', 'paid', 'sent'), array(9, '', 'queued', 'none'));

        $this->assertSame(array(7, 9, 8), $this->sortedIds($rows, 'ASC'));
    }

    public function testAFailedDeliveryOfAnIssuedRecordRanksAsIssuedAndNotSent()
    {
        $rows = array(array(1, '5', 'created', 'failed'), array(2, '6', 'created', 'sent'), array(3, '', 'held', 'none'));

        $this->assertSame(array(3, 1, 2), $this->sortedIds($rows, 'ASC'));
    }

    // ---- the pure pieces -------------------------------------------------------------------------------------

    public function testTheRankIsSelectedUnderTheSortAlias()
    {
        $select = IfirmaDocumentListSort::rankSelect('a');

        $this->assertSame(IfirmaDocumentStateRank::sql('a') . ' AS ifirma_state_rank', $select);
    }

    public function directions()
    {
        return array(
            'asc' => array('asc', 'ASC'), 'ASC' => array('ASC', 'ASC'), 'desc' => array('desc', 'DESC'),
            'DESC' => array('DESC', 'DESC'), 'mixed case' => array('DeSc', 'DESC'),
            'sideways' => array('sideways; DROP TABLE', 'ASC'), 'empty' => array('', 'ASC'), 'null' => array(null, 'ASC'),
            'array' => array(array('desc'), 'ASC'), 'padded' => array(' desc', 'ASC'), 'int' => array(1, 'ASC'),
        );
    }

    /**
     * @dataProvider directions
     */
    public function testADirectionIsNormalisedToAscOrDesc($value, $expected)
    {
        $this->assertSame($expected, IfirmaDocumentListSort::direction($value));
    }

    public function testTheTieBreakIsAddedOnlyToTheStateSort()
    {
        $head = ' ORDER BY ifirma_state_rank ASC';

        $this->assertSame($head . ', a.`id_ifirma_receipt` DESC', IfirmaDocumentListSort::withTieBreak($head, 'ifirma_state_rank', 'ASC'));
        $this->assertSame(' ORDER BY a.`id_ifirma_receipt` DESC', IfirmaDocumentListSort::withTieBreak(' ORDER BY a.`id_ifirma_receipt` DESC', 'a.`id_ifirma_receipt`', 'DESC'));
    }

    public function testTheTieBreakGoesBeforeTheSuffixTheCoreAppends()
    {
        $clause = ' ORDER BY ifirma_state_rank DESC) tmpTable WHERE 1 AND x';

        $this->assertSame(
            ' ORDER BY ifirma_state_rank DESC, a.`id_ifirma_receipt` DESC) tmpTable WHERE 1 AND x',
            IfirmaDocumentListSort::withTieBreak($clause, 'ifirma_state_rank', 'DESC')
        );
    }

    public function testAClauseThatDoesNotStartWithTheExpectedHeadIsLeftAlone()
    {
        $this->assertSame(' ORDER BY x ASC', IfirmaDocumentListSort::withTieBreak(' ORDER BY x ASC', 'ifirma_state_rank', 'ASC'));
    }

    // ---- the controller --------------------------------------------------------------------------------------

    public function testTheListSelectsTheRankAndTheStatusColumnSortsByIt()
    {
        $c = $this->controller();

        $this->assertStringContainsString(IfirmaDocumentListSort::rankSelect('a'), $c->_select);
        $this->assertSame('ifirma_state_rank', $c->fields_list['status']['order_key']);
        $this->assertSame('a!status', $c->fields_list['status']['filter_key'], 'the filter of the column is unchanged');
    }

    public function testSortingByStateAscendingEndsWithTheNewestFirstTieBreak()
    {
        $c = $this->controller();

        $this->assertSame(
            ' ORDER BY ifirma_state_rank ASC, a.`id_ifirma_receipt` DESC',
            $c->orderByClause('status', 'asc')
        );
    }

    public function testSortingByStateDescendingEndsWithTheNewestFirstTieBreak()
    {
        $c = $this->controller();

        $this->assertSame(
            ' ORDER BY ifirma_state_rank DESC, a.`id_ifirma_receipt` DESC',
            $c->orderByClause('status', 'desc')
        );
    }

    public function testWithoutAChosenSortTheListStaysNewestFirst()
    {
        $c = $this->controller();

        $this->assertSame(' ORDER BY a.`id_ifirma_receipt` DESC', $c->orderByClause(null, null));
    }

    public function testAnotherColumnSortsAsBefore()
    {
        $c = $this->controller();

        $this->assertSame(' ORDER BY a.`ifirma_number` ASC', $c->orderByClause('ifirma_number', 'asc'));
    }

    public function testAnUnexpectedDirectionInTheRequestSortsAscendingAndNeverReachesTheSql()
    {
        $c = $this->controller(array('ifirma_receiptOrderway' => 'sideways; DROP TABLE'));

        $this->assertSame('ASC', $c->direction('sideways; DROP TABLE'));
        $clause = $c->orderByClause('status', 'sideways; DROP TABLE');
        $this->assertSame(' ORDER BY ifirma_state_rank ASC, a.`id_ifirma_receipt` DESC', $clause);
        $this->assertStringNotContainsString('DROP', $clause);
    }

    public function testAnArrayDirectionInTheRequestChangesNothing()
    {
        $c = $this->controller(array('ifirma_receiptOrderway' => array('desc')));

        $this->assertSame('DESC', $c->direction('desc'));
        $this->assertSame('DESC', $c->direction(null), 'the list default');
    }

    public function testNoDirectionInTheRequestKeepsTheDefaultOrderWay()
    {
        $c = $this->controller();

        $this->assertSame('DESC', $c->direction(null));
    }

    public function testTheNeedsAttentionPresetAndTheFiltersStayInTheWhereClause()
    {
        $c = $this->controller(array('ifirma_preset' => 'attention'));

        $this->assertStringContainsString(IfirmaIssueFeed::issuePredicate('a'), $c->_where);
        $this->assertStringNotContainsString('ifirma_state_rank', $c->_where);
        $this->assertSame('a!delivery_status', $c->fields_list['delivery_status']['filter_key']);
    }
}
