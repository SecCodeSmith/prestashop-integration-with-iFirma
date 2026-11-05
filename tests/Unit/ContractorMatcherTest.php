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
 * IfirmaContractorMatcher on its own. The shop's lookups are written out by hand (or built from rows
 * without a query), so nothing here scripts the Db, and a test fails if the matcher reads it.
 */
class ContractorMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaCustomerIndex.php';
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorMatcher.php';
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorImport.php';
    }

    protected function tearDown(): void
    {
        $this->assertSame(array(), Db::getInstance()->queries, 'the matcher must not read the Db');
    }

    // ---- helpers ----------------------------------------------------------------------------

    /**
     * @param array $lookups any of nip, email, name, existing
     * @return IfirmaCustomerIndex
     */
    private function index(array $lookups = array())
    {
        $index = new IfirmaCustomerIndex();
        foreach ($lookups as $lookup => $entries) {
            $index->$lookup = $entries;
        }

        return $index;
    }

    private function row($identifier, array $over = array())
    {
        return array_merge(array('identifier' => $identifier, 'name' => '', 'nip' => '', 'email' => '', 'postcode' => ''), $over);
    }

    private function link($idCustomer, $identifier, $type, $name = '', $changed = true)
    {
        return array('id_customer' => $idCustomer, 'identifier' => $identifier, 'type' => $type, 'name' => $name, 'changed' => $changed);
    }

    /**
     * The report of match(): the given parts over an empty report, in the order of the real one.
     */
    private function report(array $parts)
    {
        return array_merge(array(
            'linked' => 0,
            'by' => array(),
            'unmatched' => 0,
            'unmatched_rows' => array(),
            'skipped_existing' => 0,
            'unchanged' => 0,
            'links' => array(),
        ), $parts);
    }

    private function match(array $rows, IfirmaCustomerIndex $index)
    {
        return IfirmaContractorMatcher::match($rows, $index);
    }

    // ---- which customers a row belongs to ---------------------------------------------------

    public function testCandidatesAreTriedInTheOrderNipEmailName()
    {
        $index = $this->index(array(
            'nip' => array('5252248481' => array(1 => true)),
            'email' => array('e2@example.test' => array(2 => true)),
            'name' => array('jan nowak|00001' => array(3 => true)),
        ));
        $everything = array('name' => 'Jan Nowak', 'postcode' => '00-001');

        $report = $this->match(array(
            $this->row('A', array_merge($everything, array('nip' => '5252248481', 'email' => 'e2@example.test'))),
            $this->row('B', array_merge($everything, array('nip' => '9999999999', 'email' => 'E2@Example.test'))),
            $this->row('C', array_merge($everything, array('email' => 'nobody@example.test'))),
        ), $index);

        $this->assertSame($this->report(array(
            'linked' => 3,
            'by' => array('import-email' => 1, 'import-name' => 1, 'import-nip' => 1),
            'links' => array(
                $this->link(1, 'A', 'import-nip', 'Jan Nowak'),
                $this->link(2, 'B', 'import-email', 'Jan Nowak'),
                $this->link(3, 'C', 'import-name', 'Jan Nowak'),
            ),
        )), $report);
    }

    public function testTheNipOfARowIsReducedToDigitsAndNeedsTenOfThem()
    {
        $index = $this->index(array('nip' => array('123456789' => array(1 => true), '5252248481' => array(2 => true))));

        $report = $this->match(array(
            $this->row('R-1', array('nip' => '123456789')),
            $this->row('R-2', array('nip' => 'PL 525-224-84-81')),
        ), $index);

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-nip' => 1),
            'unmatched' => 1,
            'unmatched_rows' => array('R-1'),
            'links' => array($this->link(2, 'R-2', 'import-nip')),
        )), $report);
    }

    public function testABlankEmailNeverMatches()
    {
        $index = $this->index(array('email' => array('' => array(1 => true))));

        $report = $this->match(array($this->row('R-1', array('email' => '  '))), $index);

        $this->assertSame($this->report(array('unmatched' => 1, 'unmatched_rows' => array('R-1'))), $report);
    }

    public function testEmailsAndNamesAreNormalisedLikeTheLookups()
    {
        $index = $this->index(array(
            'email' => array("\xC5\x82ukasz@example.test" => array(1 => true)),
            'name' => array('zazolc gensla|30001' => array(2 => true)),
        ));

        $report = $this->match(array(
            $this->row('R-1', array('email' => "  \xC5\x81UKASZ@Example.TEST ")),
            $this->row('R-2', array('name' => "ZA\xC5\xBB\xC3\x93\xC5\x81\xC4\x86  GENSL\xC4\x84", 'postcode' => '30-001')),
        ), $index);

        $this->assertSame($this->report(array(
            'linked' => 2,
            'by' => array('import-email' => 1, 'import-name' => 1),
            'links' => array(
                $this->link(1, 'R-1', 'import-email'),
                $this->link(2, 'R-2', 'import-name', "ZA\xC5\xBB\xC3\x93\xC5\x81\xC4\x86  GENSL\xC4\x84"),
            ),
        )), $report);
    }

    public function testANameNeedsAPostcodeAndAPostcodeNeedsAName()
    {
        $index = $this->index(array('name' => array(
            '|00001' => array(1 => true),
            'acme|' => array(2 => true),
            'acme|00001' => array(3 => true),
        )));

        $report = $this->match(array(
            $this->row('R-1', array('name' => '', 'postcode' => '00-001')),
            $this->row('R-2', array('name' => 'ACME', 'postcode' => '')),
            $this->row('R-3', array('name' => 'ACME', 'postcode' => '00-001')),
        ), $index);

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-name' => 1),
            'unmatched' => 2,
            'unmatched_rows' => array('R-1', 'R-2'),
            'links' => array($this->link(3, 'R-3', 'import-name', 'ACME')),
        )), $report);
    }

    public function testNameAndPostcodeLinkOnlyWhenExactlyOneCustomerHasThem()
    {
        $index = $this->index(array('name' => array(
            'jan kowalski|00001' => array(1 => true, 2 => true),
            'jan nowak|00001' => array(3 => true),
        )));

        $report = $this->match(array(
            $this->row('R-1', array('name' => 'Jan Kowalski', 'postcode' => '00001')),
            $this->row('R-2', array('name' => 'Jan Nowak', 'postcode' => '00001')),
        ), $index);

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-name' => 1),
            'unmatched' => 1,
            'unmatched_rows' => array('R-1'),
            'links' => array($this->link(3, 'R-2', 'import-name', 'Jan Nowak')),
        )), $report);
    }

    // ---- which link wins --------------------------------------------------------------------

    /**
     * A customer every kind of row finds, with the given stored link.
     */
    private function rankedIndex($identifier, $type)
    {
        return $this->index(array(
            'nip' => array('5252248481' => array(1 => true)),
            'email' => array('a@example.test' => array(1 => true)),
            'name' => array('acme|00001' => array(1 => true)),
            'existing' => array(1 => array('identifier' => $identifier, 'type' => $type)),
        ));
    }

    private function rowOfType($type, $identifier = 'NEW')
    {
        $keys = array(
            'import-nip' => array('nip' => '5252248481'),
            'import-email' => array('email' => 'a@example.test'),
            'import-name' => array(),
        );

        return $this->row($identifier, array_merge(array('name' => 'ACME', 'postcode' => '00-001'), $keys[$type]));
    }

    public function strengthPairs()
    {
        // the strength of a stored link type and of an import match type, as documented
        $stored = array(
            'module' => 0, 'name' => 1, 'import-name' => 1, 'email' => 2, 'import-email' => 2,
            'nip' => 3, 'import-nip' => 3, 'manual' => 4, 'written by a later version' => 4,
        );
        $incoming = array('import-name' => 1, 'import-email' => 2, 'import-nip' => 3);
        $pairs = array();
        foreach ($stored as $storedType => $storedStrength) {
            foreach ($incoming as $incomingType => $incomingStrength) {
                $pairs[$incomingType . ' against stored ' . $storedType] = array($storedType, $incomingType, $incomingStrength > $storedStrength);
            }
        }

        return $pairs;
    }

    /**
     * @dataProvider strengthPairs
     */
    public function testAStoredLinkIsReplacedOnlyByAStrictlyStrongerMatch($storedType, $incomingType, $replaced)
    {
        $report = $this->match(array($this->rowOfType($incomingType)), $this->rankedIndex('OLD', $storedType));

        $this->assertSame($this->report($replaced ? array(
            'linked' => 1,
            'by' => array($incomingType => 1),
            'links' => array($this->link(1, 'NEW', $incomingType, 'ACME')),
        ) : array(
            'skipped_existing' => 1,
        )), $report);
    }

    public function importTypes()
    {
        return array(
            'import-nip' => array('import-nip'),
            'import-email' => array('import-email'),
            'import-name' => array('import-name'),
        );
    }

    /**
     * @dataProvider importTypes
     */
    public function testTheSameIdentifierAndTypeIsUnchanged($type)
    {
        $report = $this->match(array($this->rowOfType($type, 'SAME')), $this->rankedIndex('SAME', $type));

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array($type => 1),
            'unchanged' => 1,
            'links' => array($this->link(1, 'SAME', $type, 'ACME', false)),
        )), $report);
    }

    public function testTheSameIdentifierUnderAnotherTypeOfEqualStrengthIsKept()
    {
        $report = $this->match(array($this->rowOfType('import-nip', 'SAME')), $this->rankedIndex('SAME', 'nip'));

        $this->assertSame($this->report(array('skipped_existing' => 1)), $report);
    }

    public function identifierPairs()
    {
        return array(
            'leading zeros' => array('007', '7'),
            'exponent' => array('1e3', '1000'),
            'trailing zero' => array('1.0', '1'),
        );
    }

    /**
     * Identifiers are compared as text: a stored '007' and an incoming '7' (Excel strips the zeros) are
     * two identifiers, so the stored link is kept as it is, not confirmed.
     *
     * @dataProvider identifierPairs
     */
    public function testAStoredIdentifierIsComparedAsTextNotAsANumber($stored, $incoming)
    {
        $index = $this->index(array(
            'email' => array('a@example.test' => array(1 => true)),
            'existing' => array(1 => array('identifier' => $stored, 'type' => 'import-email')),
        ));

        $report = $this->match(array($this->row($incoming, array('email' => 'a@example.test'))), $index);

        $this->assertSame($this->report(array('skipped_existing' => 1)), $report);
    }

    public function testRowsAfterAnUnchangedLinkAreIgnoredUnlessTheyAreStronger()
    {
        $index = $this->index(array(
            'nip' => array('5252248481' => array(1 => true)),
            'email' => array('a@example.test' => array(1 => true)),
            'existing' => array(1 => array('identifier' => 'OLD', 'type' => 'import-email')),
        ));
        $confirm = $this->row('OLD', array('email' => 'a@example.test'));
        $same = $this->row('OTHER', array('email' => 'a@example.test'));
        $stronger = $this->row('STRONG', array('nip' => '5252248481'));
        $late = $this->row('LATE', array('email' => 'a@example.test'));

        $kept = $this->match(array($confirm, $same, $late), $index);
        $replaced = $this->match(array($confirm, $same, $stronger, $late), $index);

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-email' => 1),
            'unchanged' => 1,
            'links' => array($this->link(1, 'OLD', 'import-email', '', false)),
        )), $kept);
        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-nip' => 1),
            'links' => array($this->link(1, 'STRONG', 'import-nip')),
        )), $replaced);
    }

    public function testSkippedLinksAreCountedPerRowAndCustomer()
    {
        $index = $this->index(array(
            'nip' => array('5252248481' => array(1 => true, 2 => true)),
            'existing' => array(1 => array('identifier' => 'M1', 'type' => 'manual')),
        ));

        $report = $this->match(array(
            $this->row('ROW-1', array('nip' => '5252248481')),
            $this->row('ROW-2', array('nip' => '5252248481')),
        ), $index);

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-nip' => 1),
            'skipped_existing' => 2,
            'links' => array($this->link(2, 'ROW-1', 'import-nip')),
        )), $report);
    }

    public function testLinksFollowTheOrderCustomersWereFirstPlannedAndAReplacementKeepsItsPlace()
    {
        $index = $this->index(array(
            'nip' => array('5252248481' => array(2 => true, 1 => true)),
            'email' => array('dup@example.test' => array(3 => true, 1 => true)),
        ));

        $report = $this->match(array(
            $this->row('r1', array('email' => 'dup@example.test')),
            $this->row('r2', array('nip' => '5252248481')),
        ), $index);

        $this->assertSame($this->report(array(
            'linked' => 3,
            'by' => array('import-email' => 1, 'import-nip' => 2),
            'links' => array(
                $this->link(3, 'r1', 'import-email'),
                $this->link(1, 'r2', 'import-nip'),
                $this->link(2, 'r2', 'import-nip'),
            ),
        )), $report);
    }

    // ---- the report -------------------------------------------------------------------------

    public function testNoRowsGiveAnEmptyReport()
    {
        $this->assertSame($this->report(array()), $this->match(array(), $this->index()));
    }

    public function unmatchedCounts()
    {
        return array(
            'nineteen rows' => array(19, 19),
            'twenty rows' => array(20, 20),
            'twenty-one rows' => array(21, 20),
            'twenty-five rows' => array(25, 20),
        );
    }

    /**
     * @dataProvider unmatchedCounts
     */
    public function testTheUnmatchedListHoldsTheFirstTwentyIdentifiers($rows, $listed)
    {
        $input = array();
        $expected = array();
        for ($i = 1; $i <= $rows; $i++) {
            $input[] = $this->row('U-' . $i);
            if ($i <= $listed) {
                $expected[] = 'U-' . $i;
            }
        }

        $report = $this->match($input, $this->index());

        $this->assertSame($rows, $report['unmatched']);
        $this->assertSame($expected, $report['unmatched_rows']);
    }

    public function testTheCapOfTheListIsTheMatchersConstantAndTheImportKeepsItsName()
    {
        $this->assertSame(20, IfirmaContractorMatcher::REPORT_UNMATCHED);
        $this->assertSame(IfirmaContractorMatcher::REPORT_UNMATCHED, IfirmaContractorImport::REPORT_UNMATCHED);
    }

    // ---- the lookups of a shop and the matcher together -------------------------------------

    public function goldenScenarios()
    {
        $golden = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/contractor-import/golden.json'), true);
        $cases = array();
        foreach ($golden['scenarios'] as $scenario) {
            if (isset($scenario['rounds'])) {
                $cases[$scenario['name']] = array($scenario);
            }
        }

        return $cases;
    }

    /**
     * Lookups built from the rows of the golden scenarios (no query) and matched give the reports
     * captured from the method this matcher was split out of. Between rounds the links of a report
     * become the stored ones, as apply() makes them.
     *
     * @dataProvider goldenScenarios
     */
    public function testLookupsBuiltFromRowsReproduceTheCapturedReports(array $scenario)
    {
        $stored = array();
        foreach ($scenario['mappings'] as $mapping) {
            $stored[$mapping['id_customer']] = $mapping;
        }

        foreach ($scenario['rounds'] as $number => $expected) {
            $index = IfirmaCustomerIndex::fromRows($scenario['customers'], $scenario['addresses'], array_values($stored));

            $report = $this->match($scenario['rows'], $index);

            $this->assertSame($expected['report'], $report, 'round ' . ($number + 1));
            foreach ($report['links'] as $link) {
                if ($link['changed']) {
                    $stored[$link['id_customer']] = array(
                        'id_customer' => $link['id_customer'],
                        'ifirma_identifier' => $link['identifier'],
                        'matched_by' => $link['type'],
                    );
                }
            }
        }
    }
}
