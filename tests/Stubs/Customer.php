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

    public function isGuest()
    {
        return (bool) $this->is_guest;
    }
}
