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
 * Scenario "Unrecognised request" and the kind list of "Sent requests are counted per kind and day"
 * (api-usage-accounting). Every path shape the module sends appears here.
 */
class RequestKindTest extends TestCase
{
    public function paths()
    {
        return array(
            'create a receipt' => array('POST', 'rachunek.json', 'document'),
            'create an invoice' => array('POST', 'fakturakraj.json', 'document'),
            'create a paragon invoice' => array('POST', 'fakturaparagon.json', 'document'),
            'document list' => array('GET', 'faktury.json?dataOd=2026-11-01&dataDo=2026-11-02&iloscNaStronie=100', 'status'),
            'document list from the duplicate guard' => array('GET', 'faktury.json?dataOd=2026-09-01&dataDo=2026-11-02&iloscNaStronie=100&strona=2', 'status'),
            'document number lookup' => array('GET', 'faktury.json?dataOd=2026-11-01&dataDo=2026-11-01&iloscNaStronie=100', 'status'),
            'document detail' => array('GET', 'fakturakraj/12345.json', 'status'),
            'receipt detail' => array('GET', 'rachunek/98.json', 'status'),
            'detail of an encoded id' => array('GET', 'fakturaparagon/12%2F3.json', 'status'),
            'pdf' => array('GET', 'fakturakraj/12345.pdf', 'pdf'),
            'duplicate pdf' => array('GET', 'fakturakraj/12345.pdf?typ=dup', 'pdf'),
            'send by iFirma mail' => array('POST', 'fakturakraj/send/12345.json', 'email'),
            'send a receipt by iFirma mail' => array('POST', 'rachunek/send/9.json', 'email'),
            'submit to KSeF' => array('POST', 'fakturakraj/ksef/send/12345.json', 'ksef'),
            'payment' => array('POST', 'faktury/wplaty/prz_faktura_kraj/12345.json', 'payment'),
            'contractor by id' => array('GET', 'kontrahenci/id/77.json', 'contractor'),
            'contractor create' => array('POST', 'kontrahenci.json', 'contractor'),
            'contractor update' => array('PUT', 'kontrahenci/77.json', 'contractor'),
            'contractor search' => array('GET', 'kontrahenci/1234567890.json', 'contractor'),
            'contractor search by encoded phrase' => array('GET', 'kontrahenci/Jan%20Kowalski.json', 'contractor'),
            'remaining limit' => array('GET', 'abonent/limit.json', 'limit'),
            'unknown path' => array('GET', 'something/else/entirely', 'other'),
            'unknown path with a known suffix' => array('GET', 'a/b/c/d.json', 'other'),
            'empty path' => array('GET', '', 'other'),
            'detail with another verb' => array('POST', 'fakturakraj/12345.json', 'other'),
            'a full url is not a path' => array('GET', 'https://www.ifirma.pl/iapi/abonent/limit.json', 'other'),
        );
    }

    /**
     * @dataProvider paths
     */
    public function testTheKindFollowsTheMethodAndThePath($method, $path, $kind)
    {
        $this->assertSame($kind, IfirmaRequestKind::of($method, $path));
    }

    public function testTheVerbIsNotCaseSensitive()
    {
        $this->assertSame('status', IfirmaRequestKind::of('get', 'fakturakraj/1.json'));
    }

    public function testTheKindListNamesAllNineKindsOnce()
    {
        $this->assertSame(
            array('document', 'status', 'pdf', 'email', 'ksef', 'payment', 'contractor', 'limit', 'other'),
            IfirmaRequestKind::all()
        );
    }

    public function testEveryKindTheClassCanReturnIsInTheList()
    {
        $returned = array();
        foreach ($this->paths() as $case) {
            $returned[IfirmaRequestKind::of($case[0], $case[1])] = true;
        }

        $this->assertSame(array(), array_diff(IfirmaRequestKind::all(), array_keys($returned)));
        $this->assertSame(array(), array_diff(array_keys($returned), IfirmaRequestKind::all()));
    }
}
