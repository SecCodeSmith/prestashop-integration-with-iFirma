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

class Language
{
    /** @var array */
    public static $languages = [];

    public static function reset()
    {
        self::$languages = [
            ['id_lang' => 1, 'iso_code' => 'en', 'name' => 'English'],
            ['id_lang' => 2, 'iso_code' => 'pl', 'name' => 'Polski'],
        ];
    }

    /**
     * Like core, $active = true leaves out the languages that are switched off (rows with
     * 'active' => 0); a row without an 'active' key counts as active.
     */
    public static function getLanguages($active = true, $idShop = false, $idsOnly = false)
    {
        if (!$active) {
            return self::$languages;
        }

        return array_values(array_filter(self::$languages, function ($l) {
            return !isset($l['active']) || $l['active'];
        }));
    }

    public static function getIsoById($idLang)
    {
        foreach (self::$languages as $l) {
            if ((int) $l['id_lang'] === (int) $idLang) {
                return $l['iso_code'];
            }
        }

        return false;
    }
}
Language::reset();
