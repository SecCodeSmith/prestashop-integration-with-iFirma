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

class Order
{
    use IfirmaTestRegistryTrait;

    public $id;
    public $id_customer;
    public $id_address_invoice;
    public $id_address_delivery;
    public $id_shop = 1;
    public $id_lang = 1;
    public $id_currency = 1;
    public $id_carrier;
    public $reference;
    public $date_add;
    public $total_paid_tax_incl = 0;
    public $total_paid_tax_excl = 0;
    public $total_shipping_tax_incl = 0;
    public $total_shipping_tax_excl = 0;
    public $carrier_tax_rate = 0;
    public $current_state;
    public $module;
    public $payment;

    /** @var array rows: product_name, product_quantity, unit_price_tax_incl, unit_price_tax_excl, tax_rate */
    public $details = [];
    public $totalPaidReal = 0;
    /** @var array rows with id_order_state */
    public $history = [];

    public function __construct($id = null, $idLang = null)
    {
        $this->loadFromRegistry($id);
    }

    public function getOrderDetailList()
    {
        return $this->details;
    }

    public function getTotalPaid()
    {
        return $this->totalPaidReal;
    }

    public function getHistory($idLang, $idOrderState = false, $noHidden = false, $idEmployee = 0)
    {
        return $this->history;
    }

    public function getCustomer()
    {
        return new Customer($this->id_customer);
    }

    public function getCurrentOrderState()
    {
        return new OrderState($this->current_state);
    }
}
