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

class Currency
{
    use IfirmaTestRegistryTrait;

    public $id;
    public $iso_code;

    public function __construct($id = null)
    {
        $this->loadFromRegistry($id);
    }

    public static function getDefaultCurrency()
    {
        if (isset(self::$instances[1])) {
            return self::$instances[1];
        }
        $c = new self();
        $c->id = 1;
        $c->iso_code = 'PLN';

        return $c;
    }
}
