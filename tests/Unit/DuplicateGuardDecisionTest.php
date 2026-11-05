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
 * The pure half of the duplicate guard: IfirmaDuplicateGuard::candidatesFrom() and ::classify(). They need
 * no Db, no transport, no configuration and no clock, so none of that is set up here.
 */
class DuplicateGuardDecisionTest extends TestCase
{
    const REFERENCE = 'PS-ORDER-15-ABCDEFGHI';
    const SALE_DATE = '2026-11-04';

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function settings($cap = 10, $tolerance = 0.01)
    {
        return new IfirmaGuardSettings(true, false, $tolerance, 60, null, $cap);
    }

    /** A list row of the buyer (identifier PS-C5) as iFirma lists it. */
    private function listed($id, array $over = array())
    {
        return array_merge(array(
            'FakturaId' => $id,
            'IdentyfikatorKontrahenta' => 'PS-C5',
            'PelnyNumer' => $id . '/2026',
            'DataWystawienia' => '2026-11-04',
            'Brutto' => 123,
            'Zaplacono' => 0,
            'KontrahentNazwa' => 'Jan K',
            'Rodzaj' => 'prz_faktura_paragon',
            'Waluta' => 'PLN',
            'CzySzkic' => false,
            'CzyWyslano' => false,
        ), $over);
    }

    /** A candidate as candidatesFrom() leaves it: the listed row and how it matched the buyer. */
    private function candidate($id, array $over = array())
    {
        return array_merge($this->listed($id, $over), array('_matched_by' => 'identifier'));
    }

    private function buyer(array $over = array())
    {
        return array_merge(array('identifier' => 'PS-C5', 'nip' => '', 'names' => array()), $over);
    }

    /** The result of IfirmaApiClient::getDetail() for a document node. */
    private function detail(array $node)
    {
        $raw = json_encode(array('response' => $node));

        return array('raw' => $raw, 'data' => json_decode($raw, true));
    }

    /** A read whose detail has the sale date and the lines of the order but not its reference. */
    private function matching(array $over = array())
    {
        return array('detail' => $this->detail(array_merge(array(
            'Uwagi' => 'other',
            'DataSprzedazy' => self::SALE_DATE,
            'FormatDatySprzedazy' => 'DZN',
            'Pozycje' => array(array('NazwaPelna' => 'Produkt A', 'Ilosc' => 1)),
        ), $over)), 'error' => '');
    }

    private function confirmed()
    {
        return array('detail' => $this->detail(array('Uwagi' => self::REFERENCE)), 'error' => '');
    }

    private function unreadable($error)
    {
        return array('detail' => null, 'error' => $error);
    }

    private function scan(array $candidates, array $reads)
    {
        return array(
            'candidates' => $candidates,
            'reads' => $reads,
            'reference' => self::REFERENCE,
            'sale_date' => self::SALE_DATE,
            'lines' => array(array('name' => 'Produkt A', 'quantity' => 1.0)),
        );
    }

    private function withSaleDate(array $candidate, $saleDate)
    {
        return array_merge($candidate, array('_sale_date' => $saleDate));
    }

    private function assertDecision(array $decision, $outcome, $candidate, array $candidates, $error)
    {
        $this->assertSame(
            array('outcome' => $outcome, 'candidate' => $candidate, 'candidates' => $candidates, 'error' => $error),
            $decision
        );
    }

    // ---- classify() ---------------------------------------------------------------------------

    public function testWithoutCandidatesTheDecisionIsProceed()
    {
        $scan = array('candidates' => array(), 'reads' => array(), 'reference' => '', 'sale_date' => '', 'lines' => array());

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $scan);

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_PROCEED, null, array(), '');
    }

    public function testACandidateWhoseDetailCarriesTheReferenceIsAdoptedAsItWasListed()
    {
        $row = $this->candidate(77);

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan(array($row), array($this->confirmed())));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_ADOPT, $row, array(), '');
        $this->assertArrayNotHasKey('_sale_date', $decision['candidate']);
    }

    public function testTheReferenceBeatsASaleDateAndProductsThatDiffer()
    {
        $row = $this->candidate(77);
        $read = array('detail' => $this->detail(array(
            'Uwagi' => self::REFERENCE,
            'DataSprzedazy' => '2020-01-01',
            'Pozycje' => array(array('NazwaPelna' => 'Inny', 'Ilosc' => 3)),
        )), 'error' => '');

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan(array($row), array($read)));

        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $decision['outcome']);
        $this->assertSame($row, $decision['candidate']);
    }

    public function testTheFirstConfirmedCandidateIsAdopted()
    {
        $rows = array($this->candidate(90), $this->candidate(80), $this->candidate(70));

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan($rows, array($this->matching(), $this->confirmed(), $this->confirmed())));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_ADOPT, $rows[1], array(), '');
    }

    public function testAnAdoptionForgetsTheCandidatesKeptAndTheErrorsBeforeIt()
    {
        $rows = array($this->candidate(90), $this->candidate(85), $this->candidate(80));
        $reads = array($this->unreadable('HTTP 500 returned by iFirma.'), $this->matching(), $this->confirmed());

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan($rows, $reads));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_ADOPT, $rows[2], array(), '');
    }

    public function testACandidateWhoseDetailCouldNotBeReadStaysSuspectedUnverifiedWithTheError()
    {
        $row = $this->candidate(77);

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan(array($row), array($this->unreadable('cURL error: timeout'))));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_SUSPECTED, null, array($this->withSaleDate($row, '')), 'cURL error: timeout');
    }

    public static function errorsOfTwoReads()
    {
        // newer candidate, older candidate, error of the decision
        return array(
            'two failures: the last one is reported' => array('first', 'second', 'second'),
            'a failure and a good read: the failure stays' => array('first', null, 'first'),
            'a good read and a failure' => array(null, 'second', 'second'),
            'two good reads: no error' => array(null, null, ''),
        );
    }

    /**
     * @dataProvider errorsOfTwoReads
     */
    public function testTheErrorOfTheLastUnreadableDetailIsReported($newer, $older, $error)
    {
        $rows = array($this->candidate(90), $this->candidate(80));
        $reads = array(
            $newer === null ? $this->matching() : $this->unreadable($newer),
            $older === null ? $this->matching() : $this->unreadable($older),
        );

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan($rows, $reads));

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $decision['outcome']);
        $this->assertSame($error, $decision['error']);
        $this->assertSame(array(90, 80), array_column($decision['candidates'], 'FakturaId'));
    }

    public function testAMatchingDetailKeepsTheCandidateWithTheSaleDateItRecords()
    {
        $row = $this->candidate(77);

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan(array($row), array($this->matching())));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_SUSPECTED, null, array($this->withSaleDate($row, self::SALE_DATE)), '');
    }

    public static function incompleteDetails()
    {
        return array(
            'no positions' => array(array('Pozycje' => null), self::SALE_DATE),
            'no sale date' => array(array('DataSprzedazy' => null), ''),
            'extra positions (shipping)' => array(array('Pozycje' => array(
                array('NazwaPelna' => 'Produkt A', 'Ilosc' => 1),
                array('NazwaPelna' => 'Koszt dostawy', 'Ilosc' => 1),
            )), self::SALE_DATE),
            'only the month of the sale date is recorded' => array(array('FormatDatySprzedazy' => 'MSC', 'DataSprzedazy' => '2026-11-15'), '2026-11-15'),
        );
    }

    /**
     * @dataProvider incompleteDetails
     */
    public function testADetailThatDoesNotContradictTheOrderKeepsTheCandidate(array $over, $saleDate)
    {
        $row = $this->candidate(77);
        $read = $this->matching();
        $node = $read['detail']['data']['response'];
        foreach ($over as $key => $value) {
            if ($value === null) {
                unset($node[$key]);
            } else {
                $node[$key] = $value;
            }
        }
        $read = array('detail' => $this->detail($node), 'error' => '');

        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan(array($row), array($read)));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_SUSPECTED, null, array($this->withSaleDate($row, $saleDate)), '');
    }

    public static function contradictingDetails()
    {
        return array(
            'another sale date' => array(array('DataSprzedazy' => '2020-01-01')),
            'another product' => array(array('Pozycje' => array(array('NazwaPelna' => 'Produkt B', 'Ilosc' => 1)))),
            'another quantity' => array(array('Pozycje' => array(array('NazwaPelna' => 'Produkt A', 'Ilosc' => 2)))),
            'the month of another sale' => array(array('FormatDatySprzedazy' => 'MSC', 'DataSprzedazy' => '2026-10-15')),
        );
    }

    /**
     * @dataProvider contradictingDetails
     */
    public function testADetailThatContradictsTheOrderDropsTheCandidate(array $over)
    {
        $decision = IfirmaDuplicateGuard::classify($this->settings(), $this->scan(array($this->candidate(77)), array($this->matching($over))));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_PROCEED, null, array(), '');
    }

    public function testDroppedKeptAndUnverifiedCandidatesAreDecidedInOrder()
    {
        $rows = array($this->candidate(90), $this->candidate(85), $this->candidate(80), $this->candidate(70));
        $reads = array($this->matching(array('DataSprzedazy' => '2020-01-01')), $this->unreadable('boom'), $this->matching());

        $decision = IfirmaDuplicateGuard::classify($this->settings(3), $this->scan($rows, $reads));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_SUSPECTED, null, array(
            $this->withSaleDate($rows[1], ''),
            $this->withSaleDate($rows[2], self::SALE_DATE),
            $this->withSaleDate($rows[3], ''),
        ), 'boom');
    }

    public function testCandidatesBeyondTheCapStayUnverifiedWhateverTheScanHoldsForThem()
    {
        $rows = array($this->candidate(90), $this->candidate(80));

        $decision = IfirmaDuplicateGuard::classify($this->settings(1), $this->scan($rows, array($this->matching(), $this->confirmed())));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_SUSPECTED, null, array(
            $this->withSaleDate($rows[0], self::SALE_DATE),
            $this->withSaleDate($rows[1], ''),
        ), '');
    }

    public function testACapOfZeroLeavesEveryCandidateUnverified()
    {
        $rows = array($this->candidate(90), $this->candidate(80));

        $decision = IfirmaDuplicateGuard::classify($this->settings(0), $this->scan($rows, array()));

        $this->assertDecision($decision, IfirmaGuardResult::OUTCOME_SUSPECTED, null, array(
            $this->withSaleDate($rows[0], ''),
            $this->withSaleDate($rows[1], ''),
        ), '');
    }

    public function testTheDecisionIsMadeWithoutTheDatabase()
    {
        IfirmaDuplicateGuard::classify($this->settings(), $this->scan(array($this->candidate(77)), array($this->matching())));
        IfirmaDuplicateGuard::candidatesFrom($this->settings(), array($this->listed(77)), $this->buyer(), 123.0, 'PLN');

        $this->assertSame(array(), Db::getInstance()->queries);
    }

    // ---- candidatesFrom() ---------------------------------------------------------------------

    public function testOnlyRowsOfTheBuyerAreCandidatesAndEachSaysHowItMatched()
    {
        $rows = array(
            $this->listed(1, array('DataWystawienia' => '2026-11-01')),
            $this->listed(2, array('DataWystawienia' => '2026-11-02', 'IdentyfikatorKontrahenta' => 'EC_X', 'NIPKontrahenta' => '123-456-78-90')),
            $this->listed(3, array('DataWystawienia' => '2026-11-03', 'IdentyfikatorKontrahenta' => 'EC_Y', 'KontrahentNazwa' => 'jan   KOWALSKI')),
            $this->listed(4, array('DataWystawienia' => '2026-11-04', 'IdentyfikatorKontrahenta' => 'OTHER', 'KontrahentNazwa' => 'Anna')),
        );
        $buyer = $this->buyer(array('nip' => '1234567890', 'names' => array('Jan Kowalski')));

        $candidates = IfirmaDuplicateGuard::candidatesFrom($this->settings(), $rows, $buyer, 123.0, 'PLN');

        $this->assertSame(array(3, 2, 1), array_column($candidates, 'FakturaId'));
        $this->assertSame(array('name', 'nip', 'identifier'), array_column($candidates, '_matched_by'));
    }

    public function testTheToleranceOfTheSettingsDecidesWhichGrossIsClose()
    {
        $rows = array($this->listed(1, array('Brutto' => 123.01)), $this->listed(2, array('Brutto' => 128.0)), $this->listed(3, array('Brutto' => 123.02)));

        $strict = IfirmaDuplicateGuard::candidatesFrom($this->settings(10, 0.01), $rows, $this->buyer(), 123.0, 'PLN');
        $wide = IfirmaDuplicateGuard::candidatesFrom($this->settings(10, 5.0), $rows, $this->buyer(), 123.0, 'PLN');
        $exact = IfirmaDuplicateGuard::candidatesFrom($this->settings(10, 0.0), $rows, $this->buyer(), 123.01, 'PLN');

        $this->assertSame(array(1), array_column($strict, 'FakturaId'));
        $this->assertSame(array(3, 2, 1), array_column($wide, 'FakturaId'));
        $this->assertSame(array(1), array_column($exact, 'FakturaId'));
    }

    public function testOnlyDocumentsInTheCurrencyOfTheOrderAreCandidates()
    {
        $rows = array($this->listed(1, array('Waluta' => 'PLN')), $this->listed(2, array('Waluta' => 'EUR')), $this->listed(3, array('Waluta' => 'eur')));

        $candidates = IfirmaDuplicateGuard::candidatesFrom($this->settings(), $rows, $this->buyer(), 123.0, 'EUR');

        $this->assertSame(array(3, 2), array_column($candidates, 'FakturaId'));
    }

    public function testDraftsAndDocumentsTheModuleDoesNotProduceAreNoCandidates()
    {
        $rows = array(
            $this->listed(1, array('CzySzkic' => true)),
            $this->listed(2, array('Rodzaj' => 'prz_faktura_zaliczka')),
            $this->listed(3),
        );

        $candidates = IfirmaDuplicateGuard::candidatesFrom($this->settings(), $rows, $this->buyer(), 123.0, 'PLN');

        $this->assertSame(array(3), array_column($candidates, 'FakturaId'));
    }

    public function testCandidatesComeNewestIssueDateFirstAndThenHighestIdFirst()
    {
        $rows = array(
            $this->listed(70, array('DataWystawienia' => '2026-01-03')),
            $this->listed(90, array('DataWystawienia' => '2026-01-03')),
            $this->listed(60, array('DataWystawienia' => '2026-01-04')),
            $this->listed(80, array('DataWystawienia' => '2026-01-03')),
            $this->listed(50, array('DataWystawienia' => '2026-01-01')),
        );

        $candidates = IfirmaDuplicateGuard::candidatesFrom($this->settings(), $rows, $this->buyer(), 123.0, 'PLN');

        $this->assertSame(array(60, 90, 80, 70, 50), array_column($candidates, 'FakturaId'));
        $this->assertSame(array(0, 1, 2, 3, 4), array_keys($candidates));
    }

    public function testRowsWithoutAnIssueDateComeLastAndRowsWithoutAnIdCountAsIdZero()
    {
        $undated = $this->listed(5);
        unset($undated['DataWystawienia']);
        $anonymous = $this->listed(0, array('DataWystawienia' => '2026-01-01'));
        unset($anonymous['FakturaId']);
        $rows = array($undated, $anonymous, $this->listed(3, array('DataWystawienia' => '2026-01-01')));

        $candidates = IfirmaDuplicateGuard::candidatesFrom($this->settings(), $rows, $this->buyer(), 123.0, 'PLN');

        $this->assertSame(array(3, null, 5), array_map(function ($row) {
            return isset($row['FakturaId']) ? $row['FakturaId'] : null;
        }, $candidates));
    }

    public function testNothingListedIsNoCandidate()
    {
        $this->assertSame(array(), IfirmaDuplicateGuard::candidatesFrom($this->settings(), array(), $this->buyer(), 123.0, 'PLN'));
    }
}
