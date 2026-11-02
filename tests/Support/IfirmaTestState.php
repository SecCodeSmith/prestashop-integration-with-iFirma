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

/** Resets every stub. Call IfirmaTestState::resetAll() in setUp(). */
class IfirmaTestState
{
    public static function resetAll()
    {
        Configuration::reset();
        Db::reset();
        ObjectModel::reset();
        Order::reset();
        OrderState::reset();
        OrderHistory::reset();
        Address::reset();
        Customer::reset();
        Country::reset();
        Currency::reset();
        Language::reset();
        Shop::reset();
        Module::reset();
        Tab::reset();
        Tools::reset();
        Mail::reset();
        PrestaShopLogger::reset();
        HelperForm::reset();
        Context::reset();
        IfirmaApiClient::setDefaultTransport(null);

        // Ifirma keeps a private static render guard; clear it if the module is loaded.
        if (class_exists('Ifirma', false)) {
            $p = new ReflectionProperty('Ifirma', 'adminOrderRendered');
            $p->setAccessible(true);
            $p->setValue(null, false);
        }
    }

    /** In-memory ObjectModel rows of a table (id => field array). */
    public static function objects($table)
    {
        return isset(ObjectModel::$registry[$table]) ? ObjectModel::$registry[$table] : [];
    }
}
