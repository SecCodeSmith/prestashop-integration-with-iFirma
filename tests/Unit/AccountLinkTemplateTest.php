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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAccountLinkTemplate.php';

/**
 * Change fix-my-invoices-page-and-native-invoice-link, scenario "Account entry matches the theme": Hummingbird and its
 * child themes get the account-menu markup, every other theme the Classic tile.
 */
class AccountLinkTemplateTest extends TestCase
{
    const CLASSIC = 'views/templates/hook/customer_account.tpl';
    const HUMMINGBIRD = 'views/templates/hook/customer_account_hummingbird.tpl';

    public function themes()
    {
        return array(
            'classic' => array('classic', '', self::CLASSIC),
            'hummingbird' => array('hummingbird', '', self::HUMMINGBIRD),
            'hummingbird in capitals' => array('Hummingbird', '', self::HUMMINGBIRD),
            'child of hummingbird' => array('my-shop-theme', 'hummingbird', self::HUMMINGBIRD),
            'child of classic' => array('my-shop-theme', 'classic', self::CLASSIC),
            'unknown theme' => array('some-theme', '', self::CLASSIC),
            'empty name' => array('', '', self::CLASSIC),
        );
    }

    /**
     * @dataProvider themes
     */
    public function testTheTemplateFollowsTheThemeFamily($name, $parent, $expected)
    {
        $this->assertSame($expected, IfirmaAccountLinkTemplate::forTheme($name, $parent));
    }

    public function testNonStringThemeNamesFallBackToClassic()
    {
        $this->assertSame(self::CLASSIC, IfirmaAccountLinkTemplate::forTheme(null, false));
    }
}
