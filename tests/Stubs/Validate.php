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

class Validate
{
    public static function isOrderWay($orderWay)
    {
        return is_string($orderWay) && in_array(Tools::strtoupper($orderWay), ['ASC', 'DESC'], true);
    }

    public static function isLoadedObject($object)
    {
        return is_object($object) && !empty($object->id);
    }

    public static function isUnsignedId($v)
    {
        return (bool) preg_match('/^[0-9]+$/', (string) $v) && (int) $v < 4294967296;
    }

    public static function isGenericName($v)
    {
        return empty($v) || (bool) preg_match('/^[^<>={}]*$/u', (string) $v);
    }

    public static function isName($v)
    {
        return (bool) preg_match('/^[^0-9!<>,;?=+()@#"{}_$%:]*$/u', (string) $v);
    }

    public static function isPrice($v)
    {
        return (bool) preg_match('/^[0-9]{1,10}(\.[0-9]{1,9})?$/', (string) $v);
    }

    public static function isDateFormat($v)
    {
        return (bool) preg_match('/^([0-9]{4})-((?:0[1-9])|(?:1[0-2]))-((?:0[1-9])|(?:[1-2][0-9])|(?:3[01]))( [0-9]{2}:[0-9]{2}:[0-9]{2})?$/', (string) $v);
    }

    public static function isDate($v)
    {
        return (bool) preg_match('/^([0-9]{4})-((?:0[1-9])|(?:1[0-2]))-((?:0[1-9])|(?:[1-2][0-9])|(?:3[01]))( [0-9]{2}:[0-9]{2}:[0-9]{2})?$/', (string) $v);
    }

    public static function isEmail($v)
    {
        return !empty($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isCleanHtml($v, $allowIframe = false)
    {
        return !preg_match('/<\s*(script|iframe|object|embed)/i', (string) $v);
    }

    public static function isPostCode($v)
    {
        return empty($v) || (bool) preg_match('/^[a-zA-Z 0-9-]+$/', (string) $v);
    }

    public static function isCityName($v)
    {
        return (bool) preg_match('/^[^!<>;?=+@#"{}_$%]*$/u', (string) $v);
    }

    public static function isAddress($v)
    {
        return empty($v) || (bool) preg_match('/^[^!<>?=+@{}_$%]*$/u', (string) $v);
    }

    public static function isVatNumber($v)
    {
        return empty($v) || (bool) preg_match('/^[A-Za-z0-9 .-]+$/', (string) $v);
    }

    public static function isInt($v)
    {
        return (string) $v === (string) (int) $v;
    }

    public static function isBool($v)
    {
        return $v === null || is_bool($v) || (bool) preg_match('/^[01]$/', (string) $v);
    }

    public static function isUrl($v)
    {
        return (bool) preg_match('/^[~:#,$%&_=\(\)\.\? \+\-@\/a-zA-Z0-9\pL\pS-]+$/u', (string) $v);
    }

    /** As core: a missing value passes, otherwise an optional scheme then //host/path from a limited character set. */
    public static function isAbsoluteUrl($url)
    {
        if (!empty($url)) {
            return (bool) preg_match('/^(https?:)?\/\/[$~:;#,%&_=\(\)\[\]\.\? \+\-@\/a-zA-Z0-9]+$/', (string) $url);
        }

        return true;
    }

    public static function isMessage($v)
    {
        return !preg_match('/[<>{}]/i', (string) $v);
    }
}
