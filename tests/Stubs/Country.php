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

class Country
{
    use IfirmaTestRegistryTrait;

    public $id;
    public $iso_code;
    public $name;

    public function __construct($id = null, $idLang = null)
    {
        $this->loadFromRegistry($id);
    }

    public static function getNameById($idLang, $idCountry)
    {
        return isset(self::$instances[$idCountry]) ? self::$instances[$idCountry]->name : false;
    }

    public static function getByIso($iso, $active = false)
    {
        foreach (self::$instances as $c) {
            if (strtoupper($c->iso_code) === strtoupper($iso)) {
                return (int) $c->id;
            }
        }

        return false;
    }
}
