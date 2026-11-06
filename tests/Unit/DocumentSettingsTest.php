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
 * The document type and signature type settings, pinned table by table and value by value before they
 * move out of IfirmaReceiptService (refactor-srp-splits 5.3). PayloadBuilderTest asserts only the keys of
 * both tables and a few of the values the normalisers accept.
 */
class DocumentSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    public function testTheDomesticInvoiceIsTheOnlyEnabledDocumentType()
    {
        $this->assertSame(
            array('fakturakraj' => 'Domestic VAT invoice (fakturakraj)'),
            IfirmaReceiptService::enabledDocumentTypes()
        );
    }

    public function testTheSignatureTypesAreTheFourCodesTheDocumentEndpointsAccept()
    {
        $this->assertSame(array(
            'BPO' => 'bez podpisu odbiorcy',
            'BWO' => 'bez podpisu odbiorcy i wystawcy',
            'OUP' => 'osoba upoważniona do otrzymania faktury VAT',
            'UPO' => 'upoważnienie',
        ), IfirmaReceiptService::signatureTypes());
    }

    public function documentTypeInputs()
    {
        return array(
            'the enabled type' => array('fakturakraj'),
            'the enabled type, padded' => array(" \tfakturakraj\n"),
            'the disabled receipt invoice' => array('fakturaparagon'),
            'another case' => array('FAKTURAKRAJ'),
            'something else' => array('something-else'),
            'empty' => array(''),
            'null' => array(null),
            'false' => array(false),
            'a number' => array(0),
        );
    }

    /**
     * @dataProvider documentTypeInputs
     */
    public function testAnyDocumentTypeReadsAsAnEnabledOne($input)
    {
        $type = IfirmaReceiptService::normalizeDocumentType($input);

        $this->assertSame('fakturakraj', $type);
        $this->assertArrayHasKey($type, IfirmaReceiptService::enabledDocumentTypes());
    }

    public function signatureTypeInputs()
    {
        return array(
            'BPO' => array('BPO', 'BPO'),
            'BWO' => array('BWO', 'BWO'),
            'OUP' => array('OUP', 'OUP'),
            'UPO' => array('UPO', 'UPO'),
            'lower case' => array('bwo', 'BWO'),
            'mixed case' => array('oUp', 'OUP'),
            'padded' => array(" \tupo\n", 'UPO'),
            'the removed BRK' => array('BRK', 'BPO'),
            'a code and more' => array('BWO BPO', 'BPO'),
            'a longer word' => array('UPOO', 'BPO'),
            'empty' => array('', 'BPO'),
            'blank' => array('   ', 'BPO'),
            'null' => array(null, 'BPO'),
            'false' => array(false, 'BPO'),
            'a number' => array(0, 'BPO'),
        );
    }

    /**
     * @dataProvider signatureTypeInputs
     */
    public function testASignatureTypeIsUpperCasedAndTrimmedOrReadsAsBpo($input, $expected)
    {
        $this->assertSame($expected, IfirmaReceiptService::normalizeSignatureType($input));
    }

    public function testTheConfiguredDocumentTypeIsNormalisedWhenItIsRead()
    {
        $service = new IfirmaReceiptService(new IfirmaApiClient('u', array()), new Module());

        $this->assertSame('fakturakraj', $service->getDocumentType());

        Configuration::updateValue('IFIRMA_DOC_TYPE', " fakturaparagon\n");
        $this->assertSame('fakturakraj', $service->getDocumentType());
    }
}
