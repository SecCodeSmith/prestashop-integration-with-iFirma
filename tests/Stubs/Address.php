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

class Address
{
    use IfirmaTestRegistryTrait {
        reset as protected resetRegistry;
    }

    public $id;
    public $id_customer;
    public $firstname;
    public $lastname;
    public $company;
    public $vat_number;
    public $dni;
    public $address1;
    public $address2;
    public $postcode;
    public $city;
    public $id_country;
    public $phone;
    public $phone_mobile;

    /** @var Address[] every object passed to save()/add()/update() */
    public static $saved = [];

    public static function reset()
    {
        self::resetRegistry();
        self::$saved = [];
    }

    public function __construct($id = null, $idLang = null)
    {
        $this->loadFromRegistry($id);
    }

    public function save($nullValues = false, $autoDate = true)
    {
        self::$saved[] = $this;

        return true;
    }

    public function add($autoDate = true, $nullValues = false)
    {
        return $this->save();
    }

    public function update($nullValues = false)
    {
        return $this->save();
    }
}
