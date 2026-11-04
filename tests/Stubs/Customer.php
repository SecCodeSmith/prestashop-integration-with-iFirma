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

class Customer
{
    use IfirmaTestRegistryTrait;

    public $id;
    public $email;
    public $firstname;
    public $lastname;
    public $id_lang = 1;
    public $is_guest = 0;
    public $company;
    public $date_add;
    /** @var bool value returned by isLogged() */
    public $logged = false;

    public function __construct($id = null)
    {
        $this->loadFromRegistry($id);
    }

    public function isLogged($withGuest = false)
    {
        return (bool) $this->logged;
    }

    /**
     * Like core: rows of the customer's non-deleted addresses (id_address, id_customer, firstname,
     * lastname, company, vat_number, address1, postcode, city, id_country, phone, phone_mobile),
     * from the Address registry, lowest id first.
     *
     * @param int $idLang
     * @return array[]
     */
    public function getAddresses($idLang)
    {
        $rows = [];
        $ids = array_keys(Address::$instances);
        sort($ids);
        foreach ($ids as $id) {
            $a = Address::$instances[$id];
            if ((int) $a->id_customer !== (int) $this->id) {
                continue;
            }
            $row = get_object_vars($a);
            $row['id_address'] = $a->id;
            $rows[] = $row;
        }

        return $rows;
    }

    public function isGuest()
    {
        return (bool) $this->is_guest;
    }
}
