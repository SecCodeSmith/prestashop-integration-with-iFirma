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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The criteria that approve a data-change request without an employee.
 *
 * Pure: the only PrestaShop call is Configuration::get() in mode(). decide() compares the request
 * (the service's normalised data) with the values of the order's current invoice address, so the
 * decision is testable without a database.
 *
 *  - none: nothing is approved automatically (the default)
 *  - safe: the buyer identity (company or private, NIP, company name) is unchanged and only the
 *          fields in SAFE_FIELDS differ
 *  - all:  every valid request is approved
 */
class IfirmaDataRequestAutoAccept
{
    const CONFIG_KEY = 'IFIRMA_DATA_REQUEST_AUTO_ACCEPT';
    const MODE_NONE = 'none';
    const MODE_SAFE = 'safe';
    const MODE_ALL = 'all';

    /** @var string[] the fields mode "safe" lets through */
    const SAFE_FIELDS = array('firstname', 'lastname', 'address1', 'address2', 'postcode', 'city', 'id_country');

    /**
     * @return string[] the accepted values of the setting
     */
    public static function modes()
    {
        return array(self::MODE_NONE, self::MODE_SAFE, self::MODE_ALL);
    }

    /**
     * @param mixed $value
     * @return string the value when it is a mode, else none
     */
    public static function normalizeMode($value)
    {
        return is_string($value) && in_array($value, self::modes(), true) ? $value : self::MODE_NONE;
    }

    /**
     * @return string none|safe|all
     */
    public function mode()
    {
        return self::normalizeMode(Configuration::get(self::CONFIG_KEY));
    }

    /**
     * @param array $requested the request's normalised data (IfirmaDataRequestService::normalizeData() shape)
     * @param array $current   the current invoice address: company, vat_number (normalised, digits only),
     *                         firstname, lastname, address1, address2, postcode, city, id_country
     * @return string|null the mode that accepted the request (safe or all); null when it waits for an employee
     */
    public function decide(array $requested, array $current)
    {
        $mode = $this->mode();
        if ($mode === self::MODE_ALL) {
            return self::MODE_ALL;
        }
        if ($mode !== self::MODE_SAFE) {
            return null;
        }

        $currentCompany = trim($this->text($current, 'company'));
        $currentNip = $this->digits($this->text($current, 'vat_number'));
        $currentIsCompany = $currentCompany !== '' || $currentNip !== '';
        $wantsCompany = !empty($requested['want_invoice_for_company']);
        if ($wantsCompany !== $currentIsCompany) {
            return null;
        }
        if ($this->digits($this->text($requested, 'vat_number')) !== $currentNip) {
            return null;
        }
        if (trim($this->text($requested, 'company')) !== $currentCompany) {
            return null;
        }

        // Any other field of the address that differs keeps the request for an employee; only
        // SAFE_FIELDS may change.
        foreach (array_keys($current) as $field) {
            if (in_array($field, self::SAFE_FIELDS, true) || $field === 'company' || $field === 'vat_number') {
                continue;
            }
            if (trim($this->text($requested, $field)) !== trim($this->text($current, $field))) {
                return null;
            }
        }

        return self::MODE_SAFE;
    }

    /**
     * @param array  $data
     * @param string $key
     * @return string
     */
    private function text(array $data, $key)
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }

    /**
     * @param string $value
     * @return string digits only, after dropping a leading PL
     */
    private function digits($value)
    {
        return preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', $value));
    }
}
