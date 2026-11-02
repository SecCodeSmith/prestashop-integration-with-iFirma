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

class Tools
{
    /** @var array request parameters (GET/POST) */
    public static $values = [];

    public static function reset()
    {
        self::$values = [];
    }

    public static function getValue($key, $default = false)
    {
        return isset(self::$values[$key]) ? self::$values[$key] : $default;
    }

    public static function getIsset($key)
    {
        return isset(self::$values[$key]);
    }

    public static function getAllValues()
    {
        return self::$values;
    }

    public static function isSubmit($key)
    {
        return isset(self::$values[$key]);
    }

    public static function getToken($page = true, $context = null)
    {
        return 'front-token';
    }

    public static function getAdminTokenLite($tab, $context = null)
    {
        return 'admin-token-' . $tab;
    }

    public static function redirect($url, $baseUri = null, $link = null, $headers = null)
    {
        throw new IfirmaTestRedirect($url);
    }

    public static function redirectLink($url)
    {
        throw new IfirmaTestRedirect($url);
    }

    public static function getShopDomainSsl($http = false, $entities = false)
    {
        return 'shop.test';
    }

    public static function strlen($str, $encoding = 'UTF-8')
    {
        return mb_strlen((string) $str, $encoding);
    }

    public static function substr($str, $start, $length = false, $encoding = 'utf-8')
    {
        return mb_substr((string) $str, (int) $start, $length === false ? null : (int) $length, $encoding);
    }

    public static function strtoupper($str)
    {
        return mb_strtoupper((string) $str, 'utf-8');
    }

    public static function displayPrice($price, $currency = null)
    {
        return number_format((float) $price, 2, '.', '') . ' PLN';
    }

    public static function passwdGen($length = 8, $flag = 'ALPHANUMERIC')
    {
        return substr(str_repeat('a1B2c3D4', (int) ceil($length / 8)), 0, $length);
    }

    public static function getRemoteAddr()
    {
        return '127.0.0.1';
    }

    public static function jsonEncode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }

    public static function jsonDecode($json, $assoc = false)
    {
        return json_decode($json, $assoc);
    }

    public static function safeOutput($string, $html = false)
    {
        return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
    }

    public static function nl2br($str)
    {
        return nl2br((string) $str);
    }

    public static function ps_round($value, $precision = 0, $roundMode = null)
    {
        return round($value, $precision);
    }

    public static function str2url($str)
    {
        $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $str));

        return trim($s, '-');
    }

    public static function encrypt($passwd)
    {
        return md5($passwd);
    }
}
