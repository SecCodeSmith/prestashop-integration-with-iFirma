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

class Context
{
    /** @var Context|null */
    protected static $instance;

    public $language;
    public $customer;
    public $shop;
    public $employee;
    public $cookie;
    public $link;
    public $controller;
    public $smarty;
    public $currency;

    public static function reset()
    {
        self::$instance = null;
    }

    public static function getContext()
    {
        if (self::$instance === null) {
            $c = new self();
            $c->language = (object) ['id' => 1, 'iso_code' => 'en', 'locale' => 'en-US'];
            $c->customer = new Customer();
            $c->customer->id = 0;
            $c->customer->logged = false;
            $c->shop = (object) ['id' => 1, 'name' => 'Test Shop', 'email' => Configuration::get('PS_SHOP_EMAIL')];
            $c->employee = new Employee(1);
            $c->cookie = new stdClass();
            $c->link = new Link();
            $c->controller = new IfirmaTestController();
            $c->smarty = new Smarty();
            $c->currency = new Currency();
            $c->currency->id = 1;
            $c->currency->iso_code = 'PLN';
            self::$instance = $c;
        }

        return self::$instance;
    }
}
