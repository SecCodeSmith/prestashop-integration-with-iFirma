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

class DataRequestAutoAcceptTest extends TestCase
{
    /** @var IfirmaDataRequestAutoAccept */
    private $criteria;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->criteria = new IfirmaDataRequestAutoAccept();
    }

    private function mode($mode)
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', $mode);
    }

    /** @return array a private person's current invoice address, as the service's currentValues() gives it */
    private function privateAddress(array $override = array())
    {
        return array_merge(array(
            'company' => '',
            'vat_number' => '',
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Stara 1',
            'address2' => '',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'id_country' => '14',
        ), $override);
    }

    /** @return array a company's current invoice address */
    private function companyAddress(array $override = array())
    {
        return $this->privateAddress(array_merge(array('company' => 'ACME Sp. z o.o.', 'vat_number' => '5250001234'), $override));
    }

    /** @return array the request data as the service normalises it */
    private function requestFor(array $address, array $override = array())
    {
        $isCompany = $address['company'] !== '' || $address['vat_number'] !== '';
        $data = $address;
        $data['id_country'] = (int) $address['id_country'];
        $data['want_invoice_for_company'] = $isCompany;

        return array_merge($data, $override);
    }

    public function testPostcodeAndStreetChangeIsAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->privateAddress();

        $this->assertSame('safe', $this->criteria->decide($this->requestFor($current, array('address1' => 'Nowa 5', 'postcode' => '00-950')), $current));
    }

    public function testCountryOnlyChangeIsAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->privateAddress();

        $this->assertSame('safe', $this->criteria->decide($this->requestFor($current, array('id_country' => 15)), $current));
    }

    public function testANameChangeOfACompanyBuyerIsAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->companyAddress();

        $this->assertSame('safe', $this->criteria->decide($this->requestFor($current, array('firstname' => 'Jan', 'city' => 'Krakow')), $current));
    }

    public function testAddingANipIsNotAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->privateAddress();
        $requested = $this->requestFor($current, array('want_invoice_for_company' => true, 'company' => 'ACME', 'vat_number' => '5250001234'));

        $this->assertNull($this->criteria->decide($requested, $current));
    }

    public function testChangingTheNipIsNotAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->companyAddress();

        $this->assertNull($this->criteria->decide($this->requestFor($current, array('vat_number' => '1234563218')), $current));
    }

    public function testChangingTheCompanyNameIsNotAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->companyAddress();

        $this->assertNull($this->criteria->decide($this->requestFor($current, array('company' => 'Other Sp. z o.o.')), $current));
    }

    public function testCompanyToPrivateIsNotAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->companyAddress();
        $requested = $this->requestFor($current, array('want_invoice_for_company' => false, 'company' => '', 'vat_number' => ''));

        $this->assertNull($this->criteria->decide($requested, $current));
    }

    public function testPrivateToCompanyWithoutNipChangeIsNotAcceptedInSafeMode()
    {
        $this->mode('safe');
        $current = $this->privateAddress();

        $this->assertNull($this->criteria->decide($this->requestFor($current, array('want_invoice_for_company' => true)), $current));
    }

    public function testASeparatorOnlyNipDifferenceCountsAsUnchanged()
    {
        $this->mode('safe');
        // the service normalises the current VAT number to digits, so a stored "525-000-12-34" arrives as 5250001234
        $current = $this->companyAddress(array('vat_number' => '5250001234'));
        $requested = $this->requestFor($current, array('vat_number' => '525-000-12-34', 'city' => 'Gdansk'));

        $this->assertSame('safe', $this->criteria->decide($requested, $current));
        $this->assertSame('safe', $this->criteria->decide($this->requestFor($current, array('vat_number' => 'PL 5250001234')), $current));
    }

    public function testACurrentAddressWithOnlyAVatNumberCountsAsACompany()
    {
        $this->mode('safe');
        $current = $this->privateAddress(array('vat_number' => '5250001234'));

        $this->assertSame('safe', $this->criteria->decide($this->requestFor($current, array('want_invoice_for_company' => true)), $current));
        $this->assertNull($this->criteria->decide($this->requestFor($current, array('want_invoice_for_company' => false)), $current));
    }

    public function testCompanyNamesAreComparedTrimmed()
    {
        $this->mode('safe');
        $current = $this->companyAddress();

        $this->assertSame('safe', $this->criteria->decide($this->requestFor($current, array('company' => '  ACME Sp. z o.o. ')), $current));
    }

    public function testAnyOtherFieldOfTheAddressThatDiffersKeepsTheRequestPending()
    {
        $this->mode('safe');
        $current = $this->privateAddress(array('phone' => '111'));
        $same = $this->requestFor($current, array('city' => 'Gdansk'));

        $this->assertSame('safe', $this->criteria->decide($same, $current));
        $this->assertNull($this->criteria->decide(array_merge($same, array('phone' => '222')), $current));
    }

    public function testModeAllAcceptsANipChange()
    {
        $this->mode('all');
        $current = $this->companyAddress();

        $this->assertSame('all', $this->criteria->decide($this->requestFor($current, array('vat_number' => '1234563218')), $current));
    }

    public function testModeNoneAcceptsNothing()
    {
        $this->mode('none');
        $current = $this->privateAddress();

        $this->assertNull($this->criteria->decide($this->requestFor($current, array('city' => 'Gdansk')), $current));
        $this->assertSame('none', $this->criteria->mode());
    }

    public function testNoStoredModeIsNone()
    {
        $this->assertSame('none', $this->criteria->mode());
    }

    /** @dataProvider unknownModes */
    public function testAnUnknownModeIsNone($value)
    {
        $this->mode($value);
        $current = $this->privateAddress();

        $this->assertSame('none', $this->criteria->mode());
        $this->assertNull($this->criteria->decide($this->requestFor($current, array('city' => 'Gdansk')), $current));
    }

    public function unknownModes()
    {
        return array(
            'other word' => array('everything'),
            'wrong case' => array('SAFE'),
            'with spaces' => array(' safe '),
            'zero' => array('0'),
            'empty' => array(''),
        );
    }

    public function testTheSafeFieldsAreTheIdentityFreeOnes()
    {
        $this->assertSame(
            array('firstname', 'lastname', 'address1', 'address2', 'postcode', 'city', 'id_country'),
            IfirmaDataRequestAutoAccept::SAFE_FIELDS
        );
        $this->assertSame(array('none', 'safe', 'all'), IfirmaDataRequestAutoAccept::modes());
        $this->assertSame('none', IfirmaDataRequestAutoAccept::normalizeMode(array('safe')));
    }
}
