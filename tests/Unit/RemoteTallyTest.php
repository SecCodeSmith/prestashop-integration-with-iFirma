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
 * IfirmaCustomerContractorService::tally(): the counting half of a remote count, on its own. It takes the
 * rows of the list answers and needs no API client, Db, Configuration or clock, so no service is built,
 * no transport scripted and no database prepared here, and a test fails if the tally touches any of them.
 */
class RemoteTallyTest extends TestCase
{
    /** @var array Configuration::$values before the test */
    private $configuration;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->configuration = Configuration::$values;
    }

    protected function tearDown(): void
    {
        $this->assertSame(array(), Db::getInstance()->queries, 'the tally must not read the Db');
        $this->assertSame(array(), Customer::$loads, 'the tally must not load a customer');
        $this->assertSame($this->configuration, Configuration::$values, 'the tally must not touch the configuration');
    }

    // ---- helpers ----------------------------------------------------------------------------

    /** A document as iFirma lists it. */
    private static function doc($id, $rodzaj = 'prz_faktura_kraj', $brutto = 100, $zaplacono = 100)
    {
        return array('FakturaId' => $id, 'Rodzaj' => $rodzaj, 'Brutto' => $brutto, 'Zaplacono' => $zaplacono);
    }

    private static function types($receipt, $invoice, $other)
    {
        return array('receipt' => $receipt, 'invoice' => $invoice, 'other' => $other);
    }

    private static function tally(array $docs)
    {
        return IfirmaCustomerContractorService::tally($docs);
    }

    // ---- nothing and everything -------------------------------------------------------------------

    public function testNothingToTallyIsAZeroTallyWithEveryTypeListedInOrder()
    {
        $this->assertSame(
            array('total' => 0, 'by_type' => array('receipt' => 0, 'invoice' => 0, 'other' => 0), 'paid' => 0),
            self::tally(array())
        );
    }

    public function testDocumentsOfEveryTypeAreCountedEachUnderItsType()
    {
        $tally = self::tally(array(
            self::doc(1, 'prz_faktura_paragon', 10, 10),
            self::doc(2, 'prz_rachunek', 20, 0),
            self::doc(3, 'PRZ_RACHUNEK', 30, 30),
            self::doc(4, 'prz_faktura_kraj', 40, 40),
            self::doc(5, 'PRZ_FAKTURAKRAJ', 50, 0),
            self::doc(6, 'prz_faktura_proforma', 60, 60),
            self::doc(7, '', 70, 0),
            array('FakturaId' => 8, 'Brutto' => 80, 'Zaplacono' => 80),
        ));

        $this->assertSame(array(
            'total' => 8,
            'by_type' => self::types(3, 2, 3),
            'paid' => 5,
        ), $tally);
    }

    public function testOnlyThePaidDocumentsOfAMixedListArePaid()
    {
        $tally = self::tally(array(
            self::doc(1, 'prz_faktura_kraj', 100, 100),
            self::doc(2, 'prz_faktura_kraj', 100, 0),
            self::doc(3, 'prz_faktura_paragon', 100, 100),
            self::doc(4, 'prz_faktura_paragon', 100, 50),
        ));

        $this->assertSame(array('total' => 4, 'by_type' => self::types(2, 2, 0), 'paid' => 2), $tally);
    }

    // ---- the same document twice: what both filters return ---------------------------------------------

    public function testADocumentThatBothFiltersReturnedIsCountedOnce()
    {
        $byContractor = array(self::doc(1), self::doc(2, 'prz_rachunek'), self::doc(3));
        $byNip = array(self::doc(2, 'prz_rachunek'), self::doc(3), self::doc(4, 'prz_faktura_proforma'));

        $tally = self::tally(array_merge($byContractor, $byNip));

        $this->assertSame(array('total' => 4, 'by_type' => self::types(1, 2, 1), 'paid' => 4), $tally);
    }

    public function testTheLastRowOfADocumentIsTheOneThatCounts()
    {
        $tally = self::tally(array(
            self::doc(1, 'prz_faktura_kraj', 100, 0),
            self::doc(2),
            self::doc(1, 'prz_faktura_paragon', 100, 100),
        ));

        $this->assertSame(array('total' => 2, 'by_type' => self::types(1, 1, 0), 'paid' => 2), $tally);
    }

    public function testTheSameIdAsANumberAndAsATextIsOneDocument()
    {
        $tally = self::tally(array(self::doc(5), self::doc('5'), self::doc(0), self::doc('0'), self::doc(6)));

        $this->assertSame(3, $tally['total'], '5, 0 and 6');
    }

    /**
     * @return array label => array(rows, documents they make)
     */
    public static function rowsWithoutAUsableId()
    {
        $row = array('Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 10);

        return array(
            'equal rows without an id' => array(array($row, $row, $row), 1),
            'rows that differ in an amount' => array(array($row, array_merge($row, array('Brutto' => 11))), 2),
            'rows that differ in a field only one has' => array(array($row, array_merge($row, array('Numer' => '1/2026'))), 2),
            'an empty id is no id' => array(array(
                array_merge($row, array('FakturaId' => '')),
                array_merge($row, array('FakturaId' => '', 'Brutto' => 11)),
                array_merge($row, array('FakturaId' => '', 'Brutto' => 11)),
            ), 2),
            'an empty id and a missing id are told apart by the row' => array(array($row, array_merge($row, array('FakturaId' => ''))), 2),
            'a null id is no id' => array(array(
                array_merge($row, array('FakturaId' => null)),
                array_merge($row, array('FakturaId' => null, 'Brutto' => 11)),
            ), 2),
            'an id wins over the rest of the row' => array(array(
                array_merge($row, array('FakturaId' => 3)),
                array_merge($row, array('FakturaId' => 3, 'Brutto' => 11)),
            ), 1),
            'a row with an id and the same row without one' => array(array($row, array_merge($row, array('FakturaId' => 3))), 2),
        );
    }

    /**
     * @dataProvider rowsWithoutAUsableId
     */
    public function testRowsAreUnitedByTheirIdOrElseByTheirWholeContent(array $rows, $documents)
    {
        $this->assertSame($documents, self::tally($rows)['total']);
    }

    // ---- what is no document ------------------------------------------------------------------------

    public function testRowsThatAreNoArraysAreNoDocuments()
    {
        $tally = self::tally(array('junk', null, 5, 1.5, true, self::doc(1), 'junk', array('Rodzaj' => 'prz_rachunek')));

        $this->assertSame(array('total' => 2, 'by_type' => self::types(1, 1, 0), 'paid' => 2), $tally);
    }

    public function testAnEmptyRowIsStillARow()
    {
        $tally = self::tally(array(array(), array()));

        $this->assertSame(array('total' => 1, 'by_type' => self::types(0, 0, 1), 'paid' => 1), $tally);
    }

    public function testTheKeysOfTheRowsDoNotMatter()
    {
        $tally = self::tally(array('a' => self::doc(1), 7 => self::doc(2, 'prz_rachunek'), 'a ' => self::doc(3, 'prz_faktura_proforma')));

        $this->assertSame(array('total' => 3, 'by_type' => self::types(1, 1, 1), 'paid' => 3), $tally);
    }

    public function testTheOrderOfDistinctDocumentsDoesNotMatter()
    {
        $docs = array(
            self::doc(1, 'prz_rachunek', 10, 0),
            self::doc(2, 'prz_faktura_kraj', 20, 20),
            self::doc(3, 'prz_faktura_proforma', 30, 15),
            self::doc(4, 'prz_faktura_paragon', 40, 40),
        );

        $this->assertSame(self::tally($docs), self::tally(array_reverse($docs)));
        $this->assertSame(array('total' => 4, 'by_type' => self::types(2, 1, 1), 'paid' => 2), self::tally($docs));
    }

    // ---- the type and the paid test ---------------------------------------------------------------------------

    /**
     * @return array label => array(row, whether it is paid)
     */
    public static function paidDocuments()
    {
        $base = array('FakturaId' => 1, 'Rodzaj' => 'prz_faktura_kraj');

        return array(
            'paid in full' => array(array_merge($base, array('Brutto' => 100, 'Zaplacono' => 100)), 1),
            'paid a grosz short' => array(array_merge($base, array('Brutto' => 100, 'Zaplacono' => 99.99)), 1),
            'paid half a grosz short' => array(array_merge($base, array('Brutto' => 100, 'Zaplacono' => 99.995)), 1),
            'paid two groszy short' => array(array_merge($base, array('Brutto' => 100, 'Zaplacono' => 99.98)), 0),
            'not paid' => array(array_merge($base, array('Brutto' => 100, 'Zaplacono' => 0)), 0),
            'paid too much' => array(array_merge($base, array('Brutto' => 100, 'Zaplacono' => 150)), 1),
            'amounts with a decimal comma, paid' => array(array_merge($base, array('Brutto' => '1000,50', 'Zaplacono' => '1000,50')), 1),
            'amounts with a decimal comma, not paid' => array(array_merge($base, array('Brutto' => '1000,50', 'Zaplacono' => '1000,00')), 0),
            'amounts as texts with a point' => array(array_merge($base, array('Brutto' => '100.00', 'Zaplacono' => '99.99')), 1),
            'a correction of a negative amount' => array(array_merge($base, array('Brutto' => -100, 'Zaplacono' => 0)), 1),
            'no amounts at all' => array($base, 1),
            'no paid amount' => array(array_merge($base, array('Brutto' => 100)), 0),
            'no gross amount' => array(array_merge($base, array('Zaplacono' => 100)), 1),
        );
    }

    /**
     * @dataProvider paidDocuments
     */
    public function testADocumentIsPaidWhenWhatWasPaidReachesTheGrossToAGrosz(array $row, $paid)
    {
        $this->assertSame($paid, self::tally(array($row))['paid']);
    }

    /**
     * @return array label => array(Rodzaj or null for none, the type)
     */
    public static function kinds()
    {
        return array(
            'a receipt' => array('prz_faktura_paragon', 'receipt'),
            'a bill' => array('prz_rachunek', 'receipt'),
            'a bill in capitals' => array('PRZ_RACHUNEK', 'receipt'),
            'a domestic invoice' => array('prz_faktura_kraj', 'invoice'),
            'a domestic invoice without separators' => array('PRZ_FAKTURAKRAJ', 'invoice'),
            'a pro forma' => array('prz_faktura_proforma', 'other'),
            'an empty kind' => array('', 'other'),
            'no kind' => array(null, 'other'),
        );
    }

    /**
     * @dataProvider kinds
     */
    public function testTheTypeFollowsTheKindOfTheDocument($rodzaj, $type)
    {
        $row = array('FakturaId' => 1);
        if ($rodzaj !== null) {
            $row['Rodzaj'] = $rodzaj;
        }
        $expected = array('receipt' => 0, 'invoice' => 0, 'other' => 0);
        $expected[$type] = 1;

        $this->assertSame($expected, self::tally(array($row))['by_type']);
    }

    // ---- purity ----------------------------------------------------------------------------------------------

    public function testTheTallyAnswersWithoutTheDatabaseTheConfigurationOrACustomer()
    {
        $tally = self::tally(array(self::doc(1), self::doc(2, 'prz_rachunek')));

        $this->assertSame(2, $tally['total']);
        $this->assertSame(array(), Db::getInstance()->queries);
        $this->assertSame(array(), Customer::$loads);
    }
}
