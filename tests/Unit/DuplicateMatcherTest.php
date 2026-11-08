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

    // ---- the stamp is searched as a whole token outside the buyer block (red team 1.6.0 F6) ----------

    private function detailJson(array $node)
    {
        return json_encode(array('response' => $node + array('Kod' => 0)));
    }

    public function testAStampTypedIntoTheBuyerBlockIsNotConfirmed()
    {
        $stamp = 'PS-ORDER-15-ABCDEFGHI';
        foreach (array('Ulica', 'Nazwa', 'Miejscowosc', 'Email') as $field) {
            $raw = $this->detailJson(array(
                'Uwagi' => 'something else',
                'Kontrahent' => array($field => 'Dom ' . $stamp),
            ));
            $this->assertFalse(IfirmaDuplicateMatcher::confirm($raw, $stamp), $field);
        }
        // the buyer block at the top level of the detail is left out as well
        $this->assertFalse(IfirmaDuplicateMatcher::confirm(json_encode(array('Kontrahent' => array('Ulica' => $stamp))), $stamp));
    }

    public function testTheStampIsConfirmedInUwagiAndInDodatkoweUwagiWhateverTheBuyerBlockHolds()
    {
        $stamp = 'PS-ORDER-15-ABCDEFGHI';
        $buyer = array('Ulica' => 'Testowa 1');

        $this->assertTrue(IfirmaDuplicateMatcher::confirm(
            $this->detailJson(array('Uwagi' => $stamp . ' https://shop.example', 'Kontrahent' => $buyer)),
            $stamp
        ));
        $this->assertTrue(IfirmaDuplicateMatcher::confirm(
            $this->detailJson(array('DodatkoweUwagi' => array('Typ' => 'NUMER_ZAMOWIENIA', 'Info' => $stamp), 'Kontrahent' => $buyer)),
            $stamp
        ));
        // the stamp in the buyer block as well changes nothing
        $this->assertTrue(IfirmaDuplicateMatcher::confirm(
            $this->detailJson(array('Uwagi' => $stamp, 'Kontrahent' => array('Ulica' => $stamp))),
            $stamp
        ));
    }

    public function testAStampThatIsOnlyThePrefixOfAnotherStampIsNotConfirmed()
    {
        $raw = $this->detailJson(array('Uwagi' => 'PS-ORDER-15-ABCDEFGHIJ', 'DodatkoweUwagi' => array('Info' => 'PS-ORDER-15-ABCDEFGHIJ')));

        $this->assertFalse(IfirmaDuplicateMatcher::confirm($raw, 'PS-ORDER-15-ABCDEFGHI'));
        $this->assertTrue(IfirmaDuplicateMatcher::confirm($raw, 'PS-ORDER-15-ABCDEFGHIJ'));
        // a stamp that continues a longer word on the left is another token too
        $this->assertFalse(IfirmaDuplicateMatcher::confirm($this->detailJson(array('Uwagi' => 'XPS-ORDER-15-ABCDEFGHI')), 'PS-ORDER-15-ABCDEFGHI'));
    }

    /** @return array[] label => array(text around the stamp, confirmed) */
    public function boundaries()
    {
        return array(
            'whole text' => array('%s', true),
            'space and url' => array('%s https://shop.example/', true),
            'sentence end' => array('Order: %s.', true),
            'colon and comma' => array('(%s), ok', true),
            'digit continues' => array('%s0', false),
            'letter continues' => array('%sX', false),
            'hyphen continues' => array('%s-2', false),
            'dot and digit continue' => array('%s.5', false),
            'letter before' => array('X%s', false),
            'underscore before' => array('_%s', false),
        );
    }

    /**
     * @dataProvider boundaries
     */
    public function testTheStampMustStandAloneInTheText($pattern, $confirmed)
    {
        $stamp = 'PS-ORDER-15-ABCDEFGHI';
        $raw = $this->detailJson(array('Uwagi' => sprintf($pattern, $stamp)));

        $this->assertSame($confirmed, IfirmaDuplicateMatcher::confirm($raw, $stamp));
    }

    public function testAListOfStampsIsSearchedOutsideTheBuyerBlockToo()
    {
        $stamps = array('scmedical-15-ABCDEFGHI', 'PS-ORDER-15-ABCDEFGHI');

        $this->assertTrue(IfirmaDuplicateMatcher::confirm($this->detailJson(array('Uwagi' => 'PS-ORDER-15-ABCDEFGHI')), $stamps));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm(
            $this->detailJson(array('Kontrahent' => array('Ulica' => 'scmedical-15-ABCDEFGHI'))),
            $stamps
        ));
    }

    public function testADetailThatIsNotJsonIsSearchedAsRawTextByWholeToken()
    {
        $stamp = 'PS-ORDER-15-ABCDEFGHI';

        $this->assertTrue(IfirmaDuplicateMatcher::confirm('plain text: ' . $stamp . ' end', $stamp));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('plain text: ' . $stamp . 'J end', $stamp));
        // the JSON-escaped form is looked for in the raw text only
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('broken {"Uwagi":"PS-ORDER-15-AB\/CD"', 'PS-ORDER-15-AB/CD'));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('broken {"Uwagi":"PS-ORDER-15-AB\/CDX"', 'PS-ORDER-15-AB/CD'));
        // a scalar JSON value is not a document: raw text
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('"' . $stamp . '"', $stamp));
    }

    public function testAnEscapedStampInsideADecodedStringIsConfirmed()
    {
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('{"response":{"Uwagi":"x PS-ORDER-15-AB\/CD y","Kontrahent":{}}}', 'PS-ORDER-15-AB/CD'));
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('{"Uwagi":"sklep łódź PS-ORDER-15-ŁODZ"}', 'PS-ORDER-15-ŁODZ'));
    }
    public function testConfirmConfiguredStampFoundInList()
    {
        $stamps = array('scmedical-15-ABCDEFGHI', 'PS-ORDER-15-ABCDEFGHI');
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('{"Uwagi":"scmedical-15-ABCDEFGHI"}', $stamps));
    }

    public function testConfirmLegacyStampFoundAfterPrefixChange()
    {
        $stamps = array('scmedical-15-ABCDEFGHI', 'PS-ORDER-15-ABCDEFGHI');
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}', $stamps));
    }

    public function testConfirmAnotherShopsStampIsNotFound()
    {
        $stamps = array('scmedical-15-ABCDEFGHI', 'PS-ORDER-15-ABCDEFGHI');
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('{"Uwagi":"hialumed-15-ABCDEFGHI"}', $stamps));
    }

    public function testConfirmListWithEscapedVariantAndEmptyEntries()
    {
        $stamps = array('', 'scmedical-15-AB/CD');
        $this->assertTrue(IfirmaDuplicateMatcher::confirm('{"Uwagi":"scmedical-15-AB\/CD"}', $stamps));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('{"Uwagi":"x"}', array('')));
        $this->assertFalse(IfirmaDuplicateMatcher::confirm('{"Uwagi":"x"}', array()));
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

    public function testNormalizeText()
    {
        $this->assertSame('jan kowalski', IfirmaDuplicateMatcher::normalizeText("  Jan \t\n KOWALSKI "));
        $this->assertSame('zażółć gęślą', IfirmaDuplicateMatcher::normalizeText('ZAŻÓŁĆ  GĘŚLĄ'));
        $this->assertSame('', IfirmaDuplicateMatcher::normalizeText(null));
    }

    private function buyer(array $over = array())
    {
        return array_merge(array('identifier' => 'PS-C5', 'nip' => '', 'names' => array('Jan Kowalski')), $over);
    }

    public function testFilterByBuyerIdentifier()
    {
        $rows = IfirmaDuplicateMatcher::filterByBuyer(array($this->row(array('IdentyfikatorKontrahenta' => 'PS-C5', 'KontrahentNazwa' => 'Other'))), $this->buyer());
        $this->assertCount(1, $rows);
        $this->assertSame('identifier', $rows[0]['_matched_by']);
        $rows = IfirmaDuplicateMatcher::filterByBuyer(array($this->row(array('IdentyfikatorKontrahenta' => 'ps-c5', 'KontrahentNazwa' => 'Other'))), $this->buyer());
        $this->assertCount(1, $rows);
    }

    public function testFilterByBuyerLinkedIdentifier()
    {
        $rows = IfirmaDuplicateMatcher::filterByBuyer(
            array($this->row(array('IdentyfikatorKontrahenta' => 'KOWALSKI-01', 'KontrahentNazwa' => 'Other'))),
            $this->buyer(array('identifier' => 'KOWALSKI-01'))
        );
        $this->assertCount(1, $rows);
        $this->assertSame('identifier', $rows[0]['_matched_by']);
        $this->assertSame(array(), IfirmaDuplicateMatcher::filterByBuyer(
            array($this->row(array('IdentyfikatorKontrahenta' => 'PS-C5', 'KontrahentNazwa' => 'Other'))),
            $this->buyer(array('identifier' => 'KOWALSKI-01'))
        ));
    }

    public function testFilterByBuyerNip()
    {
        $row = $this->row(array('IdentyfikatorKontrahenta' => 'EC_KOWALSKI', 'NIPKontrahenta' => '123-456-78-90', 'KontrahentNazwa' => 'Other'));
        $rows = IfirmaDuplicateMatcher::filterByBuyer(array($row), $this->buyer(array('nip' => 'PL1234567890')));
        $this->assertCount(1, $rows);
        $this->assertSame('nip', $rows[0]['_matched_by']);
        $this->assertSame(array(), IfirmaDuplicateMatcher::filterByBuyer(array($row), $this->buyer(array('nip' => '9999999999'))));
        // an empty order NIP never matches, not even an empty row NIP
        $this->assertSame(array(), IfirmaDuplicateMatcher::filterByBuyer(
            array($this->row(array('IdentyfikatorKontrahenta' => 'X', 'NIPKontrahenta' => '', 'KontrahentNazwa' => 'Other'))),
            $this->buyer(array('nip' => ''))
        ));
    }

    public function testFilterByBuyerName()
    {
        $rows = IfirmaDuplicateMatcher::filterByBuyer(array($this->row(array('IdentyfikatorKontrahenta' => 'X', 'KontrahentNazwa' => 'jan   KOWALSKI'))), $this->buyer());
        $this->assertCount(1, $rows);
        $this->assertSame('name', $rows[0]['_matched_by']);
        $rows = IfirmaDuplicateMatcher::filterByBuyer(
            array($this->row(array('IdentyfikatorKontrahenta' => 'X', 'KontrahentNazwa' => 'ACME Sp. z o.o.'))),
            $this->buyer(array('names' => array('ACME Sp. z o.o.', 'Jan Kowalski')))
        );
        $this->assertCount(1, $rows);
        $this->assertSame(array(), IfirmaDuplicateMatcher::filterByBuyer(
            array($this->row(array('IdentyfikatorKontrahenta' => 'X', 'KontrahentNazwa' => ''))),
            $this->buyer(array('names' => array('')))
        ));
    }

    public function testFilterByBuyerDropsOtherBuyers()
    {
        $rows = array(
            $this->row(array('FakturaId' => 1, 'IdentyfikatorKontrahenta' => 'PS-C9', 'NIPKontrahenta' => '5555555555', 'KontrahentNazwa' => 'Anna Nowak')),
            $this->row(array('FakturaId' => 2)),
            $this->row(array('FakturaId' => 3, 'IdentyfikatorKontrahenta' => 'PS-C5')),
            'not-a-row',
        );
        $kept = IfirmaDuplicateMatcher::filterByBuyer($rows, $this->buyer());
        $this->assertCount(1, $kept);
        $this->assertSame(3, $kept[0]['FakturaId']);
    }

    private function detail(array $over = array())
    {
        return array_merge(array(
            'DataSprzedazy' => '2026-10-01',
            'FormatDatySprzedazy' => 'DZN',
            'Pozycje' => array(
                array('NazwaPelna' => 'Produkt A', 'Ilosc' => 1, 'CenaJednostkowa' => 123.0),
            ),
        ), $over);
    }

    private function lines()
    {
        return array(array('name' => 'Produkt A', 'quantity' => 1.0));
    }

    public function testSaleDateMatches()
    {
        $this->assertTrue(IfirmaDuplicateMatcher::saleDateMatches($this->detail(), '2026-10-01'));
        $this->assertFalse(IfirmaDuplicateMatcher::saleDateMatches($this->detail(), '2026-09-30'));
        $this->assertTrue(IfirmaDuplicateMatcher::saleDateMatches($this->detail(array('FormatDatySprzedazy' => 'MSC', 'DataSprzedazy' => '2026-10-15')), '2026-10-01'));
        $this->assertFalse(IfirmaDuplicateMatcher::saleDateMatches($this->detail(array('FormatDatySprzedazy' => 'MSC', 'DataSprzedazy' => '2026-09-15')), '2026-10-01'));
        $this->assertNull(IfirmaDuplicateMatcher::saleDateMatches($this->detail(array('DataSprzedazy' => '')), '2026-10-01'));
        $this->assertNull(IfirmaDuplicateMatcher::saleDateMatches(array(), '2026-10-01'));
        $this->assertTrue(IfirmaDuplicateMatcher::saleDateMatches(array('DataSprzedazy' => '2026-10-01 00:00:00'), '2026-10-01'));
    }

    public function testPositionsCover()
    {
        $positions = $this->detail()['Pozycje'];
        $this->assertTrue(IfirmaDuplicateMatcher::positionsCover($positions, $this->lines()));
        $this->assertFalse(IfirmaDuplicateMatcher::positionsCover($positions, array(array('name' => 'Produkt B', 'quantity' => 1.0))));
        $this->assertFalse(IfirmaDuplicateMatcher::positionsCover($positions, array(array('name' => 'Produkt A', 'quantity' => 2.0))));
        $extra = array_merge($positions, array(array('NazwaPelna' => 'Koszt dostawy', 'Ilosc' => 1)));
        $this->assertTrue(IfirmaDuplicateMatcher::positionsCover($extra, $this->lines()));
        $this->assertTrue(IfirmaDuplicateMatcher::positionsCover(array(array('NazwaPelna' => '  produkt   a ', 'Ilosc' => '1')), $this->lines()));
        $two = array($this->lines()[0], $this->lines()[0]);
        $this->assertFalse(IfirmaDuplicateMatcher::positionsCover($positions, $two));
        $this->assertTrue(IfirmaDuplicateMatcher::positionsCover(array_merge($positions, $positions), $two));
        $this->assertTrue(IfirmaDuplicateMatcher::positionsCover(array(), array()));
        $this->assertFalse(IfirmaDuplicateMatcher::positionsCover(array(), $this->lines()));
    }

    public function testVerifyDetail()
    {
        $r = IfirmaDuplicateMatcher::verifyDetail($this->detail(), '2026-10-01', $this->lines());
        $this->assertTrue($r['matched']);
        $this->assertSame('2026-10-01', $r['sale_date']);

        $r = IfirmaDuplicateMatcher::verifyDetail($this->detail(array('DataSprzedazy' => '2026-09-30')), '2026-10-01', $this->lines());
        $this->assertFalse($r['matched']);
        $this->assertSame('2026-09-30', $r['sale_date']);

        $r = IfirmaDuplicateMatcher::verifyDetail($this->detail(array('Pozycje' => array(array('NazwaPelna' => 'Produkt B', 'Ilosc' => 1)))), '2026-10-01', $this->lines());
        $this->assertFalse($r['matched']);

        $noPositions = $this->detail();
        unset($noPositions['Pozycje']);
        $r = IfirmaDuplicateMatcher::verifyDetail($noPositions, '2026-10-01', $this->lines());
        $this->assertNull($r['matched']);
        $this->assertSame('2026-10-01', $r['sale_date']);

        $r = IfirmaDuplicateMatcher::verifyDetail(array('Pozycje' => $this->detail()['Pozycje']), '2026-10-01', $this->lines());
        $this->assertNull($r['matched']);
        $this->assertSame('', $r['sale_date']);

        // a known mismatch is conclusive even when the other check is unknown
        $this->assertFalse(IfirmaDuplicateMatcher::verifyDetail(array('DataSprzedazy' => '2026-09-30'), '2026-10-01', $this->lines())['matched']);
        $this->assertFalse(IfirmaDuplicateMatcher::verifyDetail(array('Pozycje' => array()), '2026-10-01', $this->lines())['matched']);
    }

    public function testVerifyDetailAgainstRecordedDetailResponse()
    {
        $decoded = json_decode(file_get_contents(__DIR__ . '/../fixtures/detail-response.json'), true);
        $lines = array(array('name' => 'Produkt A', 'quantity' => 1));
        $r = IfirmaDuplicateMatcher::verifyDetail($decoded['response'], '2026-10-01', $lines);
        $this->assertTrue($r['matched']);
        $this->assertSame('2026-10-01', $r['sale_date']);
        $this->assertFalse(IfirmaDuplicateMatcher::verifyDetail($decoded['response'], '2026-10-02', $lines)['matched']);
    }

    public function testSummarizeCarriesSaleDateAndMatchReason()
    {
        $s = IfirmaDuplicateMatcher::summarize($this->row());
        $this->assertSame('', $s['sale_date']);
        $this->assertSame('', $s['matched_by']);
        $s = IfirmaDuplicateMatcher::summarize($this->row(array('_sale_date' => '2026-10-01', '_matched_by' => 'nip')));
        $this->assertSame('2026-10-01', $s['sale_date']);
        $this->assertSame('nip', $s['matched_by']);
    }
}
