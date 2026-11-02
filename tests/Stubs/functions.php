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

if (!function_exists('pSQL')) {
    function pSQL($string, $htmlOk = false)
    {
        return Db::getInstance()->escape($string, $htmlOk);
    }
}

if (!function_exists('bqSQL')) {
    function bqSQL($string)
    {
        return str_replace('`', '\\`', strip_tags(addslashes((string) $string)));
    }
}
