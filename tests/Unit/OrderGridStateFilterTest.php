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
 * Spec: order-list-receipt-indicator (Filtering by document state, Filter and paging agree, Unexpected filter values
 * are ignored).
 */
class OrderGridStateFilterTest extends TestCase
{
    private function criteria($filters)
    {
        return new class($filters) {
            private $filters;

            public function __construct($filters)
            {
                $this->filters = $filters;
            }

            public function getFilters()
            {
                return $this->filters;
            }
        };
    }

    private function builder()
    {
        return new class {
            public $joins = array();
            public $wheres = array();
            public $parameters = array();

            public function leftJoin($from, $join, $alias, $condition = null)
            {
                $this->joins[] = array($from, $join, $alias, $condition);

                return $this;
            }

            public function andWhere($where)
            {
                $this->wheres[] = $where;

                return $this;
            }

            public function setParameter($key, $value, $type = null)
            {
                $this->parameters[] = array($key, $value, $type);

                return $this;
            }
        };
    }

    public function testTheFilterFieldIsTheColumnId()
    {
        $this->assertSame('ifirma_document', IfirmaOrderGridStateFilter::FIELD);
        $this->assertSame(IfirmaOrderGridIndicator::FIELD, IfirmaOrderGridStateFilter::FIELD);
    }

    public function testTheChoicesMapEachLabelToItsRank()
    {
        $labels = array();
        foreach (IfirmaDocumentStateRank::states() as $state) {
            $labels[$state['label_key']] = 'Label ' . $state['state'];
        }

        $choices = IfirmaOrderGridStateFilter::choices($labels);

        $this->assertCount(10, $choices);
        $this->assertSame(1, $choices['Label error']);
        $this->assertSame(6, $choices['Label none']);
        $this->assertSame(10, $choices['Label paid']);
        $this->assertSame(range(1, 10), array_values($choices));
    }

    public function testAChosenRankIsReadFromTheCriteria()
    {
        $this->assertSame(1, IfirmaOrderGridStateFilter::rankFrom($this->criteria(array('ifirma_document' => '1'))));
        $this->assertSame(10, IfirmaOrderGridStateFilter::rankFrom($this->criteria(array('ifirma_document' => 10, 'reference' => 'X'))));
    }

    public function refusedCriteria()
    {
        return array(
            'unknown state' => array(array('ifirma_document' => 'refunded')),
            'or one equals one' => array(array('ifirma_document' => '1 OR 1=1')),
            'drop table' => array(array('ifirma_document' => "'; DROP TABLE ps_orders; --")),
            'array value' => array(array('ifirma_document' => array('1', '2'))),
            'empty string' => array(array('ifirma_document' => '')),
            'zero' => array(array('ifirma_document' => '0')),
            'zero int' => array(array('ifirma_document' => 0)),
            'eleven' => array(array('ifirma_document' => '11')),
            'null' => array(array('ifirma_document' => null)),
            'absent' => array(array('reference' => 'X')),
            'no filters' => array(array()),
        );
    }

    /**
     * @dataProvider refusedCriteria
     */
    public function testAnyOtherValueIsNoRank($filters)
    {
        $this->assertNull(IfirmaOrderGridStateFilter::rankFrom($this->criteria($filters)));
    }

    public function testCriteriaWithoutFiltersOrNotAnObjectGiveNoRank()
    {
        $this->assertNull(IfirmaOrderGridStateFilter::rankFrom(null));
        $this->assertNull(IfirmaOrderGridStateFilter::rankFrom('not criteria'));
        $this->assertNull(IfirmaOrderGridStateFilter::rankFrom(new stdClass()));
        $this->assertNull(IfirmaOrderGridStateFilter::rankFrom(new class {
            public function getFilters()
            {
                return 'not an array';
            }
        }));
    }

    /**
     * Runs the join of the newest record and the filter's own condition on real rows.
     *
     * @param array[] $records id_ifirma_receipt, id_order, ifirma_id, status, delivery_status
     * @param int     $rank
     * @return int[] the orders the filter lists
     */
    private function ordersListedFor(array $records, $rank)
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is needed to run the filter condition');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE o (id_order INTEGER)');
        $pdo->exec('CREATE TABLE `' . _DB_PREFIX_ . 'ifirma_receipt` (id_ifirma_receipt INTEGER, id_order INTEGER, ifirma_id TEXT, status TEXT, delivery_status TEXT)');
        foreach (array(1, 2) as $idOrder) {
            $pdo->exec('INSERT INTO o VALUES (' . $idOrder . ')');
        }
        $insert = $pdo->prepare('INSERT INTO `' . _DB_PREFIX_ . 'ifirma_receipt` VALUES (?, ?, ?, ?, ?)');
        foreach ($records as $record) {
            $insert->execute($record);
        }
        $builder = $this->builder();
        IfirmaOrderGridStateFilter::apply($builder, $rank);
        $join = IfirmaOrderGridIndicator::joinSql('o');
        $statement = $pdo->prepare(
            'SELECT o.id_order FROM o LEFT JOIN ' . $join['table'] . ' ' . $join['alias'] . ' ON ' . $join['condition']
            . ' WHERE ' . $builder->wheres[0] . ' ORDER BY o.id_order'
        );
        $statement->bindValue(':' . IfirmaOrderGridStateFilter::PARAMETER, $builder->parameters[0][1], PDO::PARAM_INT);
        $statement->execute();

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAnOrderWhoseNewestRecordIsPaidIsNotListedForIssuanceFailedWhateverItsOlderRecordSays()
    {
        $records = array(
            array(10, 1, '', 'error', 'none'),
            array(11, 1, '77', 'paid', 'sent'),
            array(12, 2, '', 'error', 'none'),
        );

        $listed = $this->ordersListedFor($records, IfirmaDocumentStateRank::ERROR);

        $this->assertSame(array(2), $listed);
    }

    public function testAnOrderWhoseNewestRecordIsFailedIsListedForIssuanceFailedAfterAnOlderOneSucceeded()
    {
        $records = array(
            array(10, 1, '77', 'paid', 'sent'),
            array(11, 1, '', 'error', 'none'),
        );

        $listed = $this->ordersListedFor($records, IfirmaDocumentStateRank::ERROR);

        $this->assertSame(array(1), $listed);
    }

    public function testApplyComparesTheRankToABoundInteger()
    {
        $builder = $this->builder();

        IfirmaOrderGridStateFilter::apply($builder, 3);

        $this->assertSame(array('(' . IfirmaDocumentStateRank::sql('ifr') . ') = :ifirma_rank'), $builder->wheres);
        $this->assertSame(array(array('ifirma_rank', 3, PDO::PARAM_INT)), $builder->parameters);
    }
}
