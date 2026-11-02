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

class OrderHistory
{
    use IfirmaTestRegistryTrait {
        reset as protected resetRegistry;
    }

    public $id;
    public $id_order;
    public $id_order_state;
    public $date_add;

    /** @var array recorded changeIdOrderState calls */
    public static $changes = [];

    public static function reset()
    {
        self::resetRegistry();
        self::$changes = [];
    }

    public function __construct($id = null, $idLang = null)
    {
        $this->loadFromRegistry($id);
    }

    public function changeIdOrderState($newOrderState, $idOrder, $useExistingPayment = false)
    {
        self::$changes[] = ['id_order_state' => $newOrderState, 'id_order' => is_object($idOrder) ? $idOrder->id : $idOrder];
        $this->id_order_state = $newOrderState;
    }

    public function add($autoDate = true, $templateVars = false)
    {
        $this->id = count(self::$instances) + 1;
        $this->date_add = date('Y-m-d H:i:s');
        self::$instances[$this->id] = $this;

        return true;
    }

    public function addWithemail($autoDate = true, $templateVars = false, $context = null)
    {
        return $this->add($autoDate, $templateVars);
    }
}
