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

class DuplicateMatcherTest extends TestCase
{
    private function row(array $over = array())
    {
        return array_merge(array(
            'FakturaId' => 77,
            'PelnyNumer' => '7/2026',
            'DataWystawienia' => '2026-10-01',
            'Brutto' => 123.00,
            'Zaplacono' => 0,
            'KontrahentNazwa' => 'Jan K',
            'Rodzaj' => 'prz_faktura_paragon',
            'Waluta' => 'PLN',
            'CzySzkic' => false,
            'CzyWyslano' => true,
        ), $over);
    }

    public function testAllConditionsMet()
    {
        $this->assertCount(1, IfirmaDuplicateMatcher::selectCandidates(array($this->row()), 123.00, 'PLN', 0.01));
    }

    public function testDraftExcluded()
    {
        $this->assertSame(array(), IfirmaDuplicateMatcher::selectCandidates(array($this->row(array('CzySzkic' => true))), 123.00, 'PLN', 0.01));
    }

    public function testAbsentDraftFlagIsNotDraft()
    {
        $r = $this->row();
        unset($r['CzySzkic']);
        $this->assertCount(1, IfirmaDuplicateMatcher::selectCandidates(array($r), 123.00, 'PLN', 0.01));
    }

    public function testNonProducibleTypeExcluded()
    {
        $this->assertSame(array(), IfirmaDuplicateMatcher::selectCandidates(array($this->row(array('Rodzaj' => 'prz_faktura_korekta'))), 123.00, 'PLN', 0.01));
    }

    public function testAmountOutsideTolerance()
    {
        $this->assertSame(array(), IfirmaDuplicateMatcher::selectCandidates(array($this->row(array('Brutto' => 123.05))), 123.00, 'PLN', 0.01));
        $this->assertCount(1, IfirmaDuplicateMatcher::selectCandidates(array($this->row(array('Brutto' => 123.01))), 123.00, 'PLN', 0.01));
    }

    public function testCurrencyMismatchAndCaseAndDefault()
    {
        $this->assertSame(array(), IfirmaDuplicateMatcher::selectCandidates(array($this->row(array('Waluta' => 'EUR'))), 123.00, 'PLN', 0.01));
        $this->assertCount(1, IfirmaDuplicateMatcher::selectCandidates(array($this->row(array('Waluta' => 'eur'))), 123.00, 'EUR', 0.01));
        $r = $this->row();
        unset($r['Waluta']);
        $this->assertCount(1, IfirmaDuplicateMatcher::selectCandidates(array($r), 123.00, 'PLN', 0.01));
        $this->assertSame(array(), IfirmaDuplicateMatcher::selectCandidates(array($r), 123.00, 'EUR', 0.01));
    }

    public function testConfirm()
    {
        $ref = 'PS-ORDER-15-ABCDEFGHI';
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('{"Uwagi":"x ' . $ref . ' y"}', $ref));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('{"Uwagi":"PS-ORDER-16-ABCDEFGHI"}', $ref));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('{"Uwagi":"ps-order-15-abcdefghi"}', $ref));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('', $ref));
    }

    public function testConfirmEscapedForm()
    {
        $ref = 'PS-ORDER-15-AB/CD';
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('{"Uwagi":"PS-ORDER-15-AB\\/CD"}', $ref));
    }

    public function testSummarize()
    {
        $s = IfirmaDuplicateMatcher::summarize($this->row(array('Zaplacono' => 50)));
        $this->assertSame('77', $s['id']);
        $this->assertSame('7/2026', $s['number']);
        $this->assertSame('2026-10-01', $s['date']);
        $this->assertSame(123.0, $s['gross']);
        $this->assertSame(50.0, $s['paid']);
        $this->assertSame('Jan K', $s['contractor']);
        $this->assertSame('prz_faktura_paragon', $s['rodzaj']);
        $this->assertSame('PLN', $s['currency']);
        $this->assertTrue($s['sent']);
    }

    public function testTypeMapping()
    {
        $this->assertSame(array('prz_faktura_paragon', 'prz_faktura_kraj'), IfirmaDuplicateMatcher::producibleTypes());
        $this->assertSame('fakturaparagon', IfirmaDuplicateMatcher::rodzajToDocumentType('prz_faktura_paragon'));
        $this->assertSame('fakturakraj', IfirmaDuplicateMatcher::rodzajToDocumentType('prz_faktura_kraj'));
        $this->assertNull(IfirmaDuplicateMatcher::rodzajToDocumentType('other'));
        $this->assertSame('prz_faktura_kraj', IfirmaDuplicateMatcher::documentTypeToRodzaj('fakturakraj'));
        $this->assertNull(IfirmaDuplicateMatcher::documentTypeToRodzaj('x'));
    }

    public function testConfirmAgainstRecordedDetailResponse()
    {
        $raw = file_get_contents(__DIR__ . '/../fixtures/detail-response.json');
        $this->assertNotFalse($raw);
        $this->assertTrue(IfirmaDuplicateMatcher::confirm($raw, 'PS-ORDER-15-ABCDEFGHI'));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm($raw, 'PS-ORDER-16-ZZZZZZZZZ'));
    }

    public function testRecordedDetailResponseCarriesReferenceInBothPlaces()
    {
        $decoded = json_decode(file_get_contents(__DIR__ . '/../fixtures/detail-response.json'), true);
        $node = $decoded['response'];
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $node['Uwagi']);
        $this->assertSame('NUMER_ZAMOWIENIA', $node['DodatkoweUwagi']['Typ']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $node['DodatkoweUwagi']['Info']);
    }
}
