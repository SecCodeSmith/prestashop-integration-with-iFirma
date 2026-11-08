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
        Employee::reset();
        Country::reset();
        Currency::reset();
        Language::reset();
        Shop::reset();
        Module::reset();
        Tab::reset();
        Access::reset();
        Media::reset();
        IfirmaReceiptService::$randomBytes = null;
        Tools::reset();
        Mail::reset();
        PrestaShopLogger::reset();
        HelperForm::reset();
        Context::reset();
        // a request is a POST unless the test says otherwise (the customer forms change state on POST only)
        $_SERVER['REQUEST_METHOD'] = 'POST';
        IfirmaApiClient::setDefaultTransport(null);
        if (class_exists('IfirmaSchema', false)) {
            IfirmaSchema::resetEnsureCache();
        }
        if (class_exists('IfirmaHttpOutput', false)) {
            IfirmaHttpOutput::useHeaderSink(null);
        }

        // Ifirma keeps a private static render guard; clear it if the module is loaded.
        if (class_exists('Ifirma', false)) {
            $p = new ReflectionProperty('Ifirma', 'adminOrderRendered');
            $p->setAccessible(true);
            $p->setValue(null, false);
        }
    }

    /** The form token of the current visitor (the cookie of the Context), created on first use. */
    public static function formToken()
    {
        return IfirmaFormToken::forVisitor(Context::getContext()->cookie, IfirmaFormToken::cookieKey());
    }

    /** In-memory ObjectModel rows of a table (id => field array). */
    public static function objects($table)
    {
        return isset(ObjectModel::$registry[$table]) ? ObjectModel::$registry[$table] : [];
    }

    /**
     * Persisted writes (one per save()) to a table since the last reset, oldest first: op
     * (add|update), table, id and row (the stored field array). The number of entries is the
     * number of database writes of a code path.
     */
    public static function writes($table)
    {
        return array_values(array_filter(ObjectModel::$writeLog, function ($write) use ($table) {
            return $write['table'] === $table;
        }));
    }

    /** Forgets the writes so far, e.g. those of seeding a row, so that writes() counts one code path. */
    public static function clearWrites()
    {
        ObjectModel::$writeLog = [];
    }
}
