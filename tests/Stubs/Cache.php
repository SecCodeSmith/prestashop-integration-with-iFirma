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

/** The per-request cache of PrestaShop: only clean() with the trailing * wildcard, as the core Cache::clean() does. */
class Cache
{
    /** @var array key => value */
    public static $local = [];

    public static function clean($key)
    {
        if (strpos($key, '*') !== false) {
            $regexp = str_replace('\*', '.*', preg_quote($key, '#'));
            foreach (array_keys(self::$local) as $stored) {
                if (preg_match('#^' . $regexp . '$#', $stored)) {
                    unset(self::$local[$stored]);
                }
            }
        } else {
            unset(self::$local[$key]);
        }
    }
}
