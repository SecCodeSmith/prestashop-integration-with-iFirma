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
 * IfirmaShopLink: the shop address of the e-mails and the prefix of the PDF file names
 * (module-mail-branding, module-endpoint-security "PDF response headers and file names").
 */
class ShopLinkTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        IfirmaShopLink::useFallback(null);
    }

    protected function tearDown(): void
    {
        IfirmaShopLink::useFallback(null);
    }

    public function testTheConfiguredAddressIsTrimmedAndUsed()
    {
        Configuration::$values['IFIRMA_SHOP_URL'] = "  https://www.Hialumed.pl/  \n";

        $this->assertSame('https://www.Hialumed.pl/', IfirmaShopLink::url());
    }

    public function testThePrefixIsTheLowerCasedHostWithoutWww()
    {
        Configuration::$values['IFIRMA_SHOP_URL'] = 'https://www.Hialumed.pl/';

        $this->assertSame('hialumed.pl', IfirmaShopLink::fileNamePrefix());
    }

    public function testAnEmptySettingFallsBackToTheShopOfTheContext()
    {
        Configuration::$values['IFIRMA_SHOP_URL'] = '   ';
        IfirmaShopLink::useFallback(function () {
            return 'https://shop.example.test/';
        });

        $this->assertSame('https://shop.example.test/', IfirmaShopLink::url());
        $this->assertSame('shop.example.test', IfirmaShopLink::fileNamePrefix());
    }

    public function testTheContextFallbackAsksTheShopForItsBaseUrl()
    {
        $shop = new class {
            public $args;

            public function getBaseURL($autoSecureMode = false, $addBaseUri = true)
            {
                $this->args = array($autoSecureMode, $addBaseUri);

                return 'https://www.Context.test/';
            }
        };
        Context::getContext()->shop = $shop;

        $this->assertSame('https://www.Context.test/', IfirmaShopLink::url());
        $this->assertSame(array(true, true), $shop->args);
        $this->assertSame('context.test', IfirmaShopLink::fileNamePrefix());
    }

    public function testWithoutAShopTheFallbackIsEmptyAndThePrefixIsIfirma()
    {
        // the Context stub's shop has no getBaseURL()
        $this->assertSame('', IfirmaShopLink::url());
        $this->assertSame('ifirma', IfirmaShopLink::fileNamePrefix());

        Context::getContext()->shop = null;
        $this->assertSame('', IfirmaShopLink::url());
    }

    public function prefixes()
    {
        return array(
            'a port' => array('http://localhost:8080/shop', 'localhost_8080'),
            'unicode (every byte)' => array('https://sklep-ż.pl/', 'sklep-__.pl'),
            'underscore host is kept' => array('https://my_shop.example/', 'my_shop.example'),
            'no host' => array('http:///', 'ifirma'),
            'no scheme' => array('not a url', 'ifirma'),
            'only www.' => array('https://www./', 'ifirma'),
        );
    }

    /**
     * @dataProvider prefixes
     */
    public function testTheHostIsSanitisedToTheSafeSet($url, $expected)
    {
        Configuration::$values['IFIRMA_SHOP_URL'] = $url;

        $prefix = IfirmaShopLink::fileNamePrefix();
        $this->assertSame($expected, $prefix);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z._-]+$/', $prefix);
    }
}
