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
    /** @var bool|int a country that is switched off is left out by getCountries($idLang, true) */
    public $active = true;

    public function __construct($id = null, $idLang = null)
    {
        $this->loadFromRegistry($id);
    }

    /**
     * Like core: the countries as rows keyed by id_country (id_country, iso_code, name, active),
     * in the order they were registered; $active = true leaves out the ones that are switched
     * off. The language does not change the rows.
     */
    public static function getCountries($idLang, $active = false, $containStates = false, $listStates = true)
    {
        $rows = [];
        foreach (self::$instances as $id => $c) {
            if (!$active || $c->active) {
                $rows[$id] = ['id_country' => $id, 'iso_code' => $c->iso_code, 'name' => $c->name, 'active' => (int) $c->active];
            }
        }

        return $rows;
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
