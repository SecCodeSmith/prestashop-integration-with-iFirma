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
    use IfirmaTestRegistryTrait {
        reset as protected resetRegistry;
    }

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

    /**
     * @var callable|null test seam of getCustomerOrders(): it receives the customer id and the rows the
     *      stub would return, and what it returns replaces them. It may throw (a failing query) or
     *      return a non-array, as core's executeS() can. Cleared by reset().
     */
    public static $customerOrdersHook;

    public static function reset()
    {
        self::resetRegistry();
        self::$customerOrdersHook = null;
    }

    public function __construct($id = null, $idLang = null)
    {
        $this->loadFromRegistry($id);
    }

    /**
     * Like core: the customer's orders as rows (id_order, id_customer, reference, date_add,
     * id_address_invoice, id_address_delivery, current_state), newest first (date_add, then id).
     *
     * @param int $idCustomer
     * @return array[]
     */
    public static function getCustomerOrders($idCustomer)
    {
        $rows = [];
        foreach (static::$instances as $o) {
            if ((int) $o->id_customer !== (int) $idCustomer) {
                continue;
            }
            $rows[] = [
                'id_order' => $o->id,
                'id_customer' => $o->id_customer,
                'reference' => $o->reference,
                'date_add' => $o->date_add,
                'id_address_invoice' => $o->id_address_invoice,
                'id_address_delivery' => $o->id_address_delivery,
                'current_state' => $o->current_state,
            ];
        }
        usort($rows, function ($a, $b) {
            $c = strcmp((string) $b['date_add'], (string) $a['date_add']);

            return $c !== 0 ? $c : (int) $b['id_order'] - (int) $a['id_order'];
        });
        if (static::$customerOrdersHook !== null) {
            return call_user_func(static::$customerOrdersHook, $idCustomer, $rows);
        }

        return $rows;
    }

    /**
     * Like core: the orders with the reference (a collection in core, an array of Order here).
     *
     * @param string $reference
     * @return Order[] in registration order, empty for an unknown reference
     */
    public static function getByReference($reference)
    {
        $found = [];
        foreach (static::$instances as $o) {
            if ((string) $o->reference === (string) $reference) {
                $found[] = $o;
            }
        }

        return $found;
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
