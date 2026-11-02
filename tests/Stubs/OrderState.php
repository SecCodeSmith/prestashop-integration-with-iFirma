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

class OrderState
{
    use IfirmaTestRegistryTrait {
        reset as protected resetRegistry;
    }

    public $id;
    public $name;

    /** @var array rows ['id_order_state'=>int,'name'=>string] */
    public static $states = [];

    public static function reset()
    {
        self::resetRegistry();
        self::$states = [];
    }

    public function __construct($id = null, $idLang = null)
    {
        $this->loadFromRegistry($id);
    }

    public static function getOrderStates($idLang, $filterDeleted = true)
    {
        return self::$states;
    }
}
