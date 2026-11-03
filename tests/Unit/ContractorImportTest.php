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

class ContractorImportTest extends TestCase
{
    use IfirmaContractorMapDb;

    /** @var IfirmaContractorImport */
    private $import;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorImport.php';
        $this->import = new IfirmaContractorImport();
        $this->scriptContractorMapDb();
    }

    private function fixture()
    {
        return file_get_contents(dirname(__DIR__) . '/fixtures/contractors-export.csv');
    }

    private function shop(array $customers, array $addresses)
    {
        $db = Db::getInstance();
        $db->whenExecuteS('/FROM `ps_customer`/', $customers);
        $db->whenExecuteS('/FROM `ps_address`/', $addresses);
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

    private function types()
    {
        $out = array();
        foreach ($this->mapRows() as $row) {
            $out[$row['id_customer']] = $row['ifirma_identifier'] . ':' . $row['matched_by'];
        }
        ksort($out);

        return $out;
    }

    // ---- parse ------------------------------------------------------------------------------

    public function testParsesTheFixtureWithBomAndSemicolons()
    {
        $parsed = $this->import->parse($this->fixture());

        $this->assertSame(array(), $parsed['errors']);
        $this->assertCount(4, $parsed['rows']);
        $this->assertSame(array('identifier', 'name', 'nip', 'email', 'postcode'), array_keys($parsed['rows'][0]));
        $this->assertSame('KOWALSKI-01', $parsed['rows'][0]['identifier']);
        $this->assertSame('525-224-84-81', $parsed['rows'][1]['nip']);
        $this->assertSame("Za\xC5\xBC\xC3\xB3\xC5\x82\xC4\x87 Gensl\xC4\x85", $parsed['rows'][2]['name']);
        $this->assertSame('99-999', $parsed['rows'][3]['postcode']);
    }

    public function testBomIsStrippedFromTheFirstHeader()
    {
        $parsed = $this->import->parse("\xEF\xBB\xBFIdentyfikator;Nazwa\nA-1;Jan\n");

        $this->assertSame(array(), $parsed['errors']);
        $this->assertSame('A-1', $parsed['rows'][0]['identifier']);
    }

    public function testDetectsCommaAndTabDelimiters()
    {
        $comma = $this->import->parse("Identyfikator,Nazwa,NIP\nA-1,\"Kowalski, Jan\",5252248481\n");
        $this->assertSame('Kowalski, Jan', $comma['rows'][0]['name']);
        $this->assertSame('5252248481', $comma['rows'][0]['nip']);

        $tab = $this->import->parse("Identyfikator\tNazwa\tE-mail\nA-1\tJan\tjan@example.test\n");
        $this->assertSame('jan@example.test', $tab['rows'][0]['email']);
    }

    public function testConvertsWindows1250()
    {
        $utf8 = "Identyfikator;Nazwa;Kod pocztowy\nZ-1;Za\xC5\xBC\xC3\xB3\xC5\x82\xC4\x87 G\xC4\x99\xC5\x9Bl\xC4\x85;30-001\n";
        $cp1250 = iconv('UTF-8', 'Windows-1250', $utf8);
        $this->assertFalse(mb_check_encoding($cp1250, 'UTF-8'));

        $parsed = $this->import->parse($cp1250);

        $this->assertSame(array(), $parsed['errors']);
        $this->assertSame("Za\xC5\xBC\xC3\xB3\xC5\x82\xC4\x87 G\xC4\x99\xC5\x9Bl\xC4\x85", $parsed['rows'][0]['name']);
    }

    public function testWindows1250FallbackTableCoversThePolishLetters()
    {
        $bytes = pack('C*', 0xB9, 0xE6, 0xEA, 0xB3, 0xF1, 0xF3, 0x9C, 0x9F, 0xBF, 0xA5, 0xC6, 0xCA, 0xA3, 0xD1, 0xD3, 0x8C, 0x8F, 0xAF, 0x20, 0x41, 0x80);

        $this->assertSame(
            'ąćęłńóśźżĄĆĘŁŃÓŚŹŻ A?',
            IfirmaContractorImport::convertWindows1250Fallback($bytes)
        );
    }

    public function testHeaderAliasesAreCaseAndDiacriticsInsensitive()
    {
        $a = $this->import->parse("ID,NAME,vat,Adres e-mail,Kod\nA-1,Jan,5252248481,jan@example.test,00-001\n");
        $this->assertSame(array('identifier' => 'A-1', 'name' => 'Jan', 'nip' => '5252248481', 'email' => 'jan@example.test', 'postcode' => '00-001'), $a['rows'][0]);

        $b = $this->import->parse("identifier;Nazwa kontrahenta;NIP;Email;KodPocztowy\nB-1;Ola;;o@example.test;30001\n");
        $this->assertSame(array('identifier' => 'B-1', 'name' => 'Ola', 'nip' => '', 'email' => 'o@example.test', 'postcode' => '30001'), $b['rows'][0]);

        $c = $this->import->parse("IDENTYFIKATOR;Nazwa;Postal code\nC-1;Ala;40-001\n");
        $this->assertSame('40-001', $c['rows'][0]['postcode']);
    }

    public function testMissingIdentifierColumnIsAnErrorWithoutRows()
    {
        $parsed = $this->import->parse("Nazwa;NIP\nJan;5252248481\n");

        $this->assertSame(array(), $parsed['rows']);
        $this->assertSame(array('missing column: Identyfikator'), $parsed['errors']);
    }

    public function testEmptyFileIsAnError()
    {
        $parsed = $this->import->parse('');

        $this->assertSame(array(), $parsed['rows']);
        $this->assertNotSame(array(), $parsed['errors']);
    }

    public function testBlankLinesAndRowsWithoutIdentifierAreSkippedAndLongIdentifiersReported()
    {
        $parsed = $this->import->parse("Identyfikator;Nazwa\nA-1;Jan\n\n;Bez\nTHIS-IS-WAY-TOO-LONG-XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX;Dlugi\n");

        $this->assertCount(1, $parsed['rows']);
        $this->assertCount(1, $parsed['errors']);
        $this->assertStringContainsString('line 5', $parsed['errors'][0]);
    }

    // ---- match ------------------------------------------------------------------------------

    public function testMatchesByNipThenEmail()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test'), $this->customer(2, 'b@example.test'), $this->customer(3, 'kowalczyk@example.test')),
            array(
                $this->address(1, array('vat_number' => '525-224-84-81')),
                $this->address(2, array('dni' => 'PL 1234567890')),
            )
        );
        $rows = array(
            $this->row('R-1', array('nip' => '5252248481')),
            $this->row('R-2', array('nip' => '123 456 78 90')),
            $this->row('R-3', array('email' => 'Kowalczyk@Example.test')),
        );

        $report = $this->import->match($rows);

        $this->assertSame(3, $report['linked']);
        $this->assertSame(array('import-email' => 1, 'import-nip' => 2), $report['by']);
        $this->assertSame(0, $report['unmatched']);
        $this->assertSame(array(), $report['unmatched_rows']);
        $this->assertSame(0, $report['skipped_existing']);
        $this->assertSame(array(), $this->mapRows());
    }

    public function testMatchesByNameAndPostcode()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test', "Za\xC5\xBC\xC3\xB3\xC5\x82\xC4\x87", "Gensl\xC4\x85"), $this->customer(2, 'b@example.test')),
            array(
                $this->address(1, array('postcode' => '30-001')),
                $this->address(2, array('company' => 'ACME Sp. z o.o.', 'postcode' => '00-950')),
            )
        );
        $rows = array(
            $this->row('R-1', array('name' => 'ZAZOLC  GENSLA', 'postcode' => '30001')),
            $this->row('R-2', array('name' => 'acme sp z o o', 'postcode' => '00-950')),
            $this->row('R-3', array('name' => 'acme sp z o o', 'postcode' => '11-111')),
            $this->row('R-4', array('name' => 'acme sp z o o')),
        );

        $report = $this->import->match($rows);

        $this->assertSame(2, $report['linked']);
        $this->assertSame(array('import-name' => 2), $report['by']);
        $this->assertSame(2, $report['unmatched']);
        $this->assertSame(array('R-3', 'R-4'), $report['unmatched_rows']);
    }

    public function testAmbiguousNameAndPostcodeIsNotLinked()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test'), $this->customer(2, 'b@example.test')),
            array(
                $this->address(1, array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'postcode' => '00-001')),
                $this->address(2, array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'postcode' => '00-001')),
            )
        );

        $report = $this->import->match(array($this->row('R-1', array('name' => 'Jan Kowalski', 'postcode' => '00-001'))));

        $this->assertSame(0, $report['linked']);
        $this->assertSame(1, $report['unmatched']);
    }

    public function testNipOutranksEmailAndFirstEqualMatchWins()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test')),
            array($this->address(1, array('vat_number' => '5252248481')))
        );
        $rows = array(
            $this->row('FIRST', array('email' => 'a@example.test')),
            $this->row('SECOND', array('email' => 'a@example.test')),
            $this->row('NIP', array('nip' => '5252248481')),
            $this->row('NIP-2', array('nip' => '5252248481')),
        );

        $report = $this->import->match($rows);
        $this->import->apply($report);

        $this->assertSame(1, $report['linked']);
        $this->assertSame(array('import-nip' => 1), $report['by']);
        $this->assertSame(array(1 => 'NIP:import-nip'), $this->types());
    }

    public function testFirstRowWinsAtEqualStrength()
    {
        $this->shop(array($this->customer(1, 'a@example.test')), array());

        $report = $this->import->match(array(
            $this->row('FIRST', array('email' => 'a@example.test')),
            $this->row('SECOND', array('email' => 'a@example.test')),
        ));
        $this->import->apply($report);

        $this->assertSame(array(1 => 'FIRST:import-email'), $this->types());
    }

    public function testStrongerMatchReplacesAnExistingWeakerMapping()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test'), $this->customer(2, 'b@example.test'), $this->customer(3, 'c@example.test')),
            array($this->address(1, array('vat_number' => '5252248481')), $this->address(3, array('vat_number' => '1234567890')))
        );
        IfirmaContractorMap::link(1, 'WEAK-1', 'import-name');
        IfirmaContractorMap::link(2, 'PS-C2', 'module');
        IfirmaContractorMap::link(3, 'HAND-3', 'manual');

        $report = $this->import->match(array(
            $this->row('NIP-1', array('nip' => '5252248481')),
            $this->row('MAIL-2', array('email' => 'b@example.test')),
            $this->row('NIP-3', array('nip' => '1234567890')),
        ));
        $this->import->apply($report);

        $this->assertSame(array(1 => 'NIP-1:import-nip', 2 => 'MAIL-2:import-email', 3 => 'HAND-3:manual'), $this->types());
        $this->assertSame(2, $report['linked']);
        $this->assertSame(1, $report['skipped_existing']);
    }

    public function testEqualOrWeakerMatchKeepsTheExistingMapping()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test')),
            array($this->address(1, array('vat_number' => '5252248481')))
        );
        IfirmaContractorMap::link(1, 'OLD-1', 'import-nip');

        $report = $this->import->match(array(
            $this->row('OTHER', array('nip' => '5252248481')),
            $this->row('WEAKER', array('email' => 'a@example.test')),
        ));
        $this->import->apply($report);

        $this->assertSame(array(1 => 'OLD-1:import-nip'), $this->types());
        $this->assertSame(0, $report['linked']);
        $this->assertSame(2, $report['skipped_existing']);
    }

    public function testReimportIsIdempotent()
    {
        $this->shop(
            array($this->customer(1, 'a@example.test'), $this->customer(2, 'b@example.test'), $this->customer(3, 'c@example.test')),
            array($this->address(1, array('vat_number' => '5252248481')), $this->address(2, array('vat_number' => '1234567890')))
        );
        $rows = array(
            $this->row('R-1', array('nip' => '5252248481')),
            $this->row('R-2', array('nip' => '1234567890')),
            $this->row('R-3', array('email' => 'c@example.test')),
        );

        $first = $this->import->match($rows);
        $this->assertSame(3, $first['linked']);
        $this->assertSame(3, $this->import->apply($first));
        $before = $this->mapRows();

        $second = $this->import->match($rows);

        $this->assertSame(3, $second['linked']);
        $this->assertSame(array('import-email' => 1, 'import-nip' => 2), $second['by']);
        $this->assertSame(0, $second['unmatched']);
        $this->assertSame(3, $second['unchanged']);
        $this->assertSame(0, $this->import->apply($second));
        $this->assertSame($before, $this->mapRows());
    }

    public function testMatchRunsExactlyThreeQueries()
    {
        $this->shop(array(), array());

        $this->import->match(array($this->row('R-1', array('nip' => '5252248481'))));

        $this->assertCount(3, Db::getInstance()->queries);
        $this->assertStringContainsString('ps_customer', Db::getInstance()->queries[0]);
        $this->assertStringContainsString('ps_address', Db::getInstance()->queries[1]);
        $this->assertStringContainsString('ps_ifirma_contractor_map', Db::getInstance()->queries[2]);
    }

    public function testUnmatchedListIsCappedAtTwenty()
    {
        $this->shop(array(), array());
        $rows = array();
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->row('U-' . $i);
        }

        $report = $this->import->match($rows);

        $this->assertSame(25, $report['unmatched']);
        $this->assertCount(20, $report['unmatched_rows']);
        $this->assertSame('U-1', $report['unmatched_rows'][0]);
    }

    // ---- apply / import ----------------------------------------------------------------------

    public function testApplyWritesMappingsWithTheContractorName()
    {
        $this->shop(array($this->customer(1, 'a@example.test')), array());
        $report = $this->import->match(array($this->row('R-1', array('email' => 'a@example.test', 'name' => 'Jan Kowalski'))));

        $this->assertSame(1, $this->import->apply($report));

        $map = IfirmaContractorMap::getByCustomer(1);
        $this->assertSame('R-1', $map->ifirma_identifier);
        $this->assertSame('import-email', $map->matched_by);
        $this->assertSame('Jan Kowalski', $map->ifirma_name);
    }

    public function testImportOfTheFixtureEndToEnd()
    {
        $this->shop(
            array($this->customer(1, 'jan@example.test'), $this->customer(2, 'x@example.test', "Za\xC5\xBC\xC3\xB3\xC5\x82\xC4\x87", "Gensl\xC4\x85")),
            array(
                $this->address(1, array('postcode' => '00-001')),
                $this->address(2, array('postcode' => '30-001')),
                $this->address(3, array('vat_number' => 'PL5252248481', 'company' => 'ACME', 'postcode' => '00-950')),
            )
        );

        $report = $this->import->import($this->fixture());

        $this->assertSame(4, $report['rows']);
        $this->assertSame(3, $report['linked']);
        $this->assertSame(3, $report['written']);
        $this->assertSame(1, $report['unmatched']);
        $this->assertSame(array('NIEZNANY-04'), $report['unmatched_rows']);
        $this->assertSame(array('import-email' => 1, 'import-name' => 1, 'import-nip' => 1), $report['by']);
    }

    public function testImportRefusesAFileWithoutIdentifierColumn()
    {
        $this->shop(array($this->customer(1, 'a@example.test')), array());

        $report = $this->import->import("Nazwa;E-mail\nJan;a@example.test\n");

        $this->assertSame(array('missing column: Identyfikator'), $report['errors']);
        $this->assertSame(0, $report['rows']);
        $this->assertSame(array(), $this->mapRows());
        $this->assertSame(array(), Db::getInstance()->queries);
    }
}
