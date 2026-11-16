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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Picks the markup of the "My invoices" account entry. Hummingbird (and any child theme of it) renders the entry in
 * its account menu with its own BEM classes; every other theme gets the Classic account tile.
 */
class IfirmaAccountLinkTemplate
{
    const CLASSIC = 'views/templates/hook/customer_account.tpl';
    const HUMMINGBIRD = 'views/templates/hook/customer_account_hummingbird.tpl';

    const HUMMINGBIRD_THEME = 'hummingbird';

    /**
     * @param mixed $themeName   name of the active theme
     * @param mixed $parentName  name of its parent theme, empty when it has none
     *
     * @return string template path relative to the module directory
     */
    public static function forTheme($themeName, $parentName)
    {
        if (self::isHummingbird($themeName) || self::isHummingbird($parentName)) {
            return self::HUMMINGBIRD;
        }

        return self::CLASSIC;
    }

    private static function isHummingbird($name)
    {
        return is_string($name) && strtolower($name) === self::HUMMINGBIRD_THEME;
    }
}
