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
 * Pins the matching rules of IfirmaContractorImport::match() through its public entry point: the
 * three reads of the shop, how customers, addresses and stored links are indexed, the order in which
 * a row's candidates are tried, which stored link is kept, confirmed or replaced, and how the report
 * is assembled. Written against the unchanged method before it was split into IfirmaCustomerIndex and
 * IfirmaContractorMatcher (refactor-srp-splits 3.2); every test holds on both sides of that split.
 */
class ContractorImportMatchingTest extends TestCase
{
    use IfirmaFailingDbReads;

    /** @var IfirmaContractorImport */
    private $import;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorImport.php';
        $this->import = new IfirmaContractorImport();
    }

    // ---- helpers ----------------------------------------------------------------------------

    /**
     * Scripts what the three reads of match() return.
     */
    private function shop(array $customers, array $addresses, array $stored = array())
    {
        $db = Db::getInstance();
        $db->whenExecuteS('/FROM `ps_customer`/', $customers);
        $db->whenExecuteS('/FROM `ps_address`/', $addresses);
        $db->whenExecuteS('/FROM `ps_ifirma_contractor_map`/', $stored);
    }

    private function customer($id, $email, $first = 'X', $last = 'Y')
    {
        return array('id_customer' => $id, 'email' => $email, 'firstname' => $first, 'lastname' => $last);
    }

    private function address($idCustomer, array $over = array())
    {
        return array_merge(array(
            'id_customer' => $idCustomer,
            'vat_number' => '',
            'dni' => '',
            'postcode' => '',
            'company' => '',
            'firstname' => 'A',
            'lastname' => 'B',
        ), $over);
    }

    private function row($identifier, array $over = array())
    {
        return array_merge(array('identifier' => $identifier, 'name' => '', 'nip' => '', 'email' => '', 'postcode' => ''), $over);
    }

    private function stored($idCustomer, $identifier, $type)
    {
        return array('id_customer' => $idCustomer, 'ifirma_identifier' => $identifier, 'matched_by' => $type);
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

    /**
     * A row that makes customer 1 of rankShop() an import-nip, import-email or import-name
     * candidate: it carries the NIP, the e-mail or neither, next to the name and postcode.
     */
    private function rowOfType($type, $identifier = 'NEW')
    {
        $keys = array(
            'import-nip' => array('nip' => '5252248481'),
            'import-email' => array('email' => 'a@example.test'),
            'import-name' => array(),
        );

        return $this->row($identifier, array_merge(array('name' => 'ACME', 'postcode' => '00-001'), $keys[$type]));
    }

    /**
     * Customer 1 can be found by NIP, e-mail and name with postcode; it has the given stored link.
     */
    private function rankShop($identifier, $type)
    {
        $this->shop(
            array($this->customer(1, 'a@example.test')),
            array($this->address(1, array('vat_number' => '5252248481', 'company' => 'ACME', 'postcode' => '00-001'))),
            array($this->stored(1, $identifier, $type))
        );
    }

    // ---- reads ------------------------------------------------------------------------------

    public function testTheThreeReadsAreTheseQueriesInThisOrder()
    {
        $this->shop(array(), array());

        $this->import->match(array($this->row('R-1')));

        $this->assertSame(array(
            'SELECT id_customer, email, firstname, lastname FROM `ps_customer`',
            'SELECT id_customer, vat_number, dni, postcode, company, firstname, lastname FROM `ps_address` WHERE id_customer > 0 AND deleted = 0',
            'SELECT id_customer, ifirma_identifier, matched_by FROM `ps_ifirma_contractor_map`',
        ), array_map(array($this, 'collapse'), Db::getInstance()->queries));
    }

    /**
     * @param string $sql
     * @return string the statement with every run of whitespace turned into one space
     */
    private function collapse($sql)
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    public function testAnEmptyRowListStillReadsTheShopAndReturnsAnEmptyReport()
    {
        $this->shop(array($this->customer(1, 'a@example.test')), array());

        $report = $this->import->match(array());

        $this->assertSame($this->report(array()), $report);
        $this->assertCount(3, Db::getInstance()->queries);
    }

    public function failingReads()
    {
        // customer 1 is found by e-mail (customers), customer 2 by NIP (addresses), customer 3 by
        // name and postcode (addresses) but holds a manual link (stored links)
        return array(
            'no read fails' => array(null, array(
                'linked' => 2,
                'by' => array('import-email' => 1, 'import-nip' => 1),
                'skipped_existing' => 1,
                'links' => array(
                    array('id_customer' => 1, 'identifier' => 'R-MAIL', 'type' => 'import-email', 'name' => '', 'changed' => true),
                    array('id_customer' => 2, 'identifier' => 'R-NIP', 'type' => 'import-nip', 'name' => '', 'changed' => true),
                ),
            )),
            'customers cannot be read' => array('customer', array(
                'linked' => 1,
                'by' => array('import-nip' => 1),
                'unmatched' => 1,
                'unmatched_rows' => array('R-MAIL'),
                'skipped_existing' => 1,
                'links' => array(
                    array('id_customer' => 2, 'identifier' => 'R-NIP', 'type' => 'import-nip', 'name' => '', 'changed' => true),
                ),
            )),
            'addresses cannot be read' => array('address', array(
                'linked' => 1,
                'by' => array('import-email' => 1),
                'unmatched' => 2,
                'unmatched_rows' => array('R-NIP', 'R-HELD'),
                'links' => array(
                    array('id_customer' => 1, 'identifier' => 'R-MAIL', 'type' => 'import-email', 'name' => '', 'changed' => true),
                ),
            )),
            'stored links cannot be read' => array('ifirma_contractor_map', array(
                'linked' => 3,
                'by' => array('import-email' => 1, 'import-name' => 1, 'import-nip' => 1),
                'links' => array(
                    array('id_customer' => 1, 'identifier' => 'R-MAIL', 'type' => 'import-email', 'name' => '', 'changed' => true),
                    array('id_customer' => 2, 'identifier' => 'R-NIP', 'type' => 'import-nip', 'name' => '', 'changed' => true),
                    array('id_customer' => 3, 'identifier' => 'R-HELD', 'type' => 'import-name', 'name' => 'BETA', 'changed' => true),
                ),
            )),
        );
    }

    /**
     * @dataProvider failingReads
     */
    public function testAReadThatFailsCountsAsNoRows($failingTable, array $expected)
    {
        if ($failingTable !== null) {
            $this->failingRead($failingTable);
        }
        $this->shop(
            array($this->customer(1, 'mail@example.test'), $this->customer(2, 'x@example.test'), $this->customer(3, 'y@example.test')),
            array(
                $this->address(2, array('vat_number' => '5252248481')),
                $this->address(3, array('company' => 'BETA', 'postcode' => '30-002')),
            ),
            array($this->stored(3, 'HAND', 'manual'))
        );

        $report = $this->import->match(array(
            $this->row('R-MAIL', array('email' => 'mail@example.test')),
            $this->row('R-NIP', array('nip' => '5252248481')),
            $this->row('R-HELD', array('name' => 'BETA', 'postcode' => '30-002')),
        ));

        $this->assertSame($this->report($expected), $report);
        $this->assertCount(3, Db::getInstance()->queries);
    }

    // ---- which candidates a row has ---------------------------------------------------------

    public function testCandidatesAreTriedInTheOrderNipEmailName()
    {
        // row A fits customer 1 by NIP, customer 2 by e-mail and customer 3 by name; row B no
        // longer has a NIP the shop knows, row C no e-mail either
        $this->shop(
            array($this->customer(1, 'n1@example.test'), $this->customer(2, 'e2@example.test'), $this->customer(3, 'c3@example.test', 'Jan', 'Nowak')),
            array($this->address(1, array('vat_number' => '5252248481')), $this->address(3, array('postcode' => '00-001')))
        );
        $everything = array('name' => 'Jan Nowak', 'postcode' => '00-001');

        $report = $this->import->match(array(
            $this->row('A', array_merge($everything, array('nip' => '5252248481', 'email' => 'e2@example.test'))),
            $this->row('B', array_merge($everything, array('nip' => '9999999999', 'email' => 'E2@Example.test'))),
            $this->row('C', array_merge($everything, array('email' => 'nobody@example.test'))),
        ));

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

    public function testTheNipOfARowIsReducedToItsDigits()
    {
        $this->shop(array($this->customer(1, 'a@example.test')), array($this->address(1, array('vat_number' => '5252248481'))));

        $report = $this->import->match(array($this->row('R-1', array('nip' => 'PL 525-224-84-81'))));

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-nip' => 1),
            'links' => array($this->link(1, 'R-1', 'import-nip')),
        )), $report);
    }

    public function testNipsOfFewerThanTenDigitsNeverMatch()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test')),
            array($this->address(1, array('vat_number' => '123456789', 'dni' => '12345678')))
        );

        $report = $this->import->match(array($this->row('R-1', array('nip' => '123456789')), $this->row('R-2', array('nip' => '12345678'))));

        $this->assertSame($this->report(array('unmatched' => 2, 'unmatched_rows' => array('R-1', 'R-2'))), $report);
    }

    public function testEmailsAreTrimmedAndLowerCasedAsUnicodeOnBothSides()
    {
        $this->shop(
            array($this->customer(1, "  \xC5\x81ukasz@Example.TEST "), $this->customer(2, 'plain@example.test')),
            array()
        );

        $report = $this->import->match(array(
            $this->row('R-1', array('email' => "\xC5\x81UKASZ@example.test")),
            $this->row('R-2', array('email' => '  PLAIN@Example.Test ')),
            $this->row('R-3', array('email' => 'lukasz@example.test')),
        ));

        $this->assertSame($this->report(array(
            'linked' => 2,
            'by' => array('import-email' => 2),
            'unmatched' => 1,
            'unmatched_rows' => array('R-3'),
            'links' => array($this->link(1, 'R-1', 'import-email'), $this->link(2, 'R-2', 'import-email')),
        )), $report);
    }

    public function testBlankEmailsNeverMatch()
    {
        $this->shop(array($this->customer(1, ''), $this->customer(2, '   ')), array());

        $report = $this->import->match(array($this->row('R-1', array('email' => '')), $this->row('R-2', array('email' => '  '))));

        $this->assertSame($this->report(array('unmatched' => 2, 'unmatched_rows' => array('R-1', 'R-2'))), $report);
    }

    public function testTheNameOnAnAddressLinksThroughThePostcodeOfThatAddress()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test')),
            array($this->address(1, array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'postcode' => '00-001')))
        );

        $report = $this->import->match(array($this->row('R-1', array('name' => 'JAN  KOWALSKI', 'postcode' => '00001'))));

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-name' => 1),
            'links' => array($this->link(1, 'R-1', 'import-name', 'JAN  KOWALSKI')),
        )), $report);
    }

    public function testTheNameOfACustomerLinksThroughEveryPostcodeOfItsAddresses()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test', 'Jan', 'Nowak'), $this->customer(2, 'b@example.test', 'Anna', 'Zielinska')),
            array(
                $this->address(1, array('postcode' => '00-001')),
                $this->address(1, array('postcode' => '30-002')),
                $this->address(2, array('postcode' => '00-001')),
                $this->address(2, array('postcode' => '30-002')),
            )
        );

        $report = $this->import->match(array(
            $this->row('R-1', array('name' => 'Jan Nowak', 'postcode' => '30-002')),
            $this->row('R-2', array('name' => 'Anna Zielinska', 'postcode' => '00-001')),
            $this->row('R-3', array('name' => 'Jan Nowak', 'postcode' => '11-111')),
        ));

        $this->assertSame($this->report(array(
            'linked' => 2,
            'by' => array('import-name' => 2),
            'unmatched' => 1,
            'unmatched_rows' => array('R-3'),
            'links' => array($this->link(1, 'R-1', 'import-name', 'Jan Nowak'), $this->link(2, 'R-2', 'import-name', 'Anna Zielinska')),
        )), $report);
    }

    public function testOneCustomerBehindTheCustomerNameTheAddressNameAndTheCompanyIsNotAmbiguous()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test', 'Jan', 'Kowalski')),
            array($this->address(1, array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => 'Jan Kowalski', 'postcode' => '00-001')))
        );

        $report = $this->import->match(array($this->row('R-1', array('name' => 'Jan Kowalski', 'postcode' => '00-001'))));

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-name' => 1),
            'links' => array($this->link(1, 'R-1', 'import-name', 'Jan Kowalski')),
        )), $report);
    }

    public function testTheSameNameAtAnotherPostcodeIsNotAmbiguous()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test'), $this->customer(2, 'b@example.test')),
            array(
                $this->address(1, array('company' => 'ACME', 'postcode' => '00-001')),
                $this->address(2, array('company' => 'ACME', 'postcode' => '30-002')),
            )
        );

        $report = $this->import->match(array($this->row('R-1', array('name' => 'ACME', 'postcode' => '30-002'))));

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-name' => 1),
            'links' => array($this->link(2, 'R-1', 'import-name', 'ACME')),
        )), $report);
    }

    // ---- which link wins --------------------------------------------------------------------

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
        $this->rankShop('OLD', $storedType);

        $report = $this->import->match(array($this->rowOfType($incomingType)));

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
    public function testTheSameIdentifierAndTypeIsUnchangedAndWritesNothing($type)
    {
        $this->rankShop('SAME', $type);

        $report = $this->import->match(array($this->rowOfType($type, 'SAME')));

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array($type => 1),
            'unchanged' => 1,
            'links' => array($this->link(1, 'SAME', $type, 'ACME', false)),
        )), $report);
        $this->assertSame(0, $this->import->apply($report));
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_contractor_map'));
    }

    public function sameIdentifierOtherType()
    {
        return array(
            'nip against import-nip' => array('nip', 'import-nip'),
            'email against import-email' => array('email', 'import-email'),
            'name against import-name' => array('name', 'import-name'),
        );
    }

    /**
     * @dataProvider sameIdentifierOtherType
     */
    public function testTheSameIdentifierUnderAnotherTypeOfEqualStrengthIsKept($storedType, $incomingType)
    {
        $this->rankShop('SAME', $storedType);

        $report = $this->import->match(array($this->rowOfType($incomingType, 'SAME')));

        $this->assertSame($this->report(array('skipped_existing' => 1)), $report);
    }

    public function testRowsAfterAnUnchangedLinkAreIgnoredUnlessTheyAreStronger()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test')),
            array($this->address(1, array('vat_number' => '5252248481'))),
            array($this->stored(1, 'OLD', 'import-email'))
        );
        $confirm = $this->row('OLD', array('email' => 'a@example.test'));
        $same = $this->row('OTHER', array('email' => 'a@example.test'));
        $stronger = $this->row('STRONG', array('nip' => '5252248481'));
        $late = $this->row('LATE', array('email' => 'a@example.test'));

        $kept = $this->import->match(array($confirm, $same, $late));
        $replaced = $this->import->match(array($confirm, $same, $stronger, $late));

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

    public function sharedNipLinks()
    {
        return array(
            'one of two customers holds a manual link' => array(
                array($this->stored(1, 'M1', 'manual')),
                array(
                    'linked' => 1,
                    'by' => array('import-nip' => 1),
                    'skipped_existing' => 2,
                    'links' => array($this->link(2, 'ROW-1', 'import-nip')),
                ),
            ),
            'both customers hold a manual link' => array(
                array($this->stored(1, 'M1', 'manual'), $this->stored(2, 'M2', 'manual')),
                array('skipped_existing' => 4),
            ),
        );
    }

    /**
     * @dataProvider sharedNipLinks
     */
    public function testSkippedLinksAreCountedPerRowAndCustomer(array $stored, array $expected)
    {
        $this->shop(
            array($this->customer(1, 'a@example.test'), $this->customer(2, 'b@example.test')),
            array($this->address(1, array('vat_number' => '5252248481')), $this->address(2, array('vat_number' => '5252248481'))),
            $stored
        );

        $report = $this->import->match(array(
            $this->row('ROW-1', array('nip' => '5252248481')),
            $this->row('ROW-2', array('nip' => '5252248481')),
        ));

        $this->assertSame($this->report($expected), $report);
    }

    public function testStoredValuesAreComparedAsStrings()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test')),
            array(),
            array(array('id_customer' => '1', 'ifirma_identifier' => 123, 'matched_by' => 'import-email'))
        );

        $report = $this->import->match(array($this->row('123', array('email' => 'a@example.test'))));

        $this->assertSame($this->report(array(
            'linked' => 1,
            'by' => array('import-email' => 1),
            'unchanged' => 1,
            'links' => array($this->link(1, '123', 'import-email', '', false)),
        )), $report);
    }

    // ---- what the report lists --------------------------------------------------------------

    public function testLinksKeepTheOrderInWhichCustomersWereFirstPlanned()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test'), $this->customer(2, 'b@example.test'), $this->customer(3, 'c@example.test')),
            array($this->address(3, array('vat_number' => '1234567890')))
        );

        $report = $this->import->match(array(
            $this->row('r1', array('email' => 'c@example.test')),
            $this->row('r2', array('email' => 'a@example.test')),
            $this->row('r3', array('email' => 'b@example.test')),
            $this->row('r4', array('nip' => '1234567890')),
        ));

        $this->assertSame($this->report(array(
            'linked' => 3,
            'by' => array('import-email' => 2, 'import-nip' => 1),
            'links' => array(
                $this->link(3, 'r4', 'import-nip'),
                $this->link(1, 'r2', 'import-email'),
                $this->link(2, 'r3', 'import-email'),
            ),
        )), $report);
    }

    public function testOneRowLinksEveryCustomerThatSharesTheKeyInIndexOrder()
    {
        // e-mail: customer 3 is read before customer 1; NIP: the address of customer 2 before the one of customer 1
        $this->shop(
            array($this->customer(3, 'dup@example.test'), $this->customer(1, 'DUP@example.test'), $this->customer(2, 'solo@example.test')),
            array($this->address(2, array('vat_number' => '5252248481')), $this->address(1, array('vat_number' => '5252248481')))
        );

        $report = $this->import->match(array(
            $this->row('r1', array('email' => 'dup@example.test')),
            $this->row('r2', array('nip' => '5252248481')),
        ));

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

    public function testTheUnmatchedListHoldsTheFirstTwentyUnmatchedIdentifiersInRowOrder()
    {
        $this->shop(array($this->customer(1, 'a@example.test')), array());
        $rows = array();
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = $this->row('U-' . $i);
        }
        $rows[] = $this->row('HIT', array('email' => 'a@example.test'));
        for ($i = 11; $i <= 22; $i++) {
            $rows[] = $this->row('U-' . $i);
        }

        $report = $this->import->match($rows);

        $expected = array();
        for ($i = 1; $i <= 20; $i++) {
            $expected[] = 'U-' . $i;
        }
        $this->assertSame(22, $report['unmatched']);
        $this->assertSame($expected, $report['unmatched_rows']);
        $this->assertSame(1, $report['linked']);
    }
}
