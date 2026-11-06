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

class Configuration
{
    /** @var array key => value */
    public static $values = [];

    /** @var array[] every updateValue() call since the last reset(), oldest first, with every argument it was given: [key, value] for a two-argument call */
    public static $writes = [];

    public static function reset()
    {
        self::$values = ['PS_SHOP_EMAIL' => 'shop@example.test', 'PS_SHOP_NAME' => 'Test Shop'];
        self::$writes = [];
    }

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
    }

    public static function updateValue($key, $values, $html = false, $idShopGroup = null, $idShop = null)
    {
        self::$writes[] = func_get_args();
        self::$values[$key] = $values;

        return true;
    }

    public static function deleteByName($key)
    {
        unset(self::$values[$key]);

        return true;
    }

    public static function hasKey($key, $idLang = null, $idShopGroup = null, $idShop = null)
    {
        return array_key_exists($key, self::$values);
    }

    public static function getMultiple($keys, $idLang = null, $idShopGroup = null, $idShop = null)
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }
}
Configuration::reset();
