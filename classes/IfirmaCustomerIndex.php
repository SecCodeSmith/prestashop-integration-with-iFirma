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

require_once dirname(__FILE__) . '/IfirmaContractorService.php';

/**
 * The shop side of the contractor import: the lookups IfirmaContractorMatcher finds the customer of
 * a CSV row in (by NIP, by e-mail, by name and postcode) and the contractor links already stored.
 *
 * load() is the only place that reads the Db, with three queries; fromRows() builds the lookups from
 * the rows of those queries and touches nothing else. Every lookup maps a key to a set of customer
 * ids (id_customer => true) in the order the rows were read, and a row is linked to them in that order.
 */
class IfirmaCustomerIndex
{
    /** @var array NIP digits (at least 10) => id_customer => true, from the VAT number and DNI of addresses */
    public $nip = array();

    /** @var array e-mail (trimmed, lower case) => id_customer => true */
    public $email = array();

    /** @var array normalised name . '|' . postcode digits => id_customer => true */
    public $name = array();

    /** @var array id_customer => array(identifier, type) of the contractor link already stored */
    public $existing = array();

    /**
     * Reads customers, addresses and stored links with three queries.
     *
     * @return IfirmaCustomerIndex
     */
    public static function load()
    {
        $db = Db::getInstance();
        $customers = $db->executeS('SELECT id_customer, email, firstname, lastname FROM `' . _DB_PREFIX_ . 'customer`');
        $addresses = $db->executeS(
            'SELECT id_customer, vat_number, dni, postcode, company, firstname, lastname FROM `' . _DB_PREFIX_ . 'address`
             WHERE id_customer > 0 AND deleted = 0'
        );
        $mappings = $db->executeS(
            'SELECT id_customer, ifirma_identifier, matched_by FROM `' . _DB_PREFIX_ . 'ifirma_contractor_map`'
        );

        return self::fromRows($customers, $addresses, $mappings);
    }

    /**
     * @param mixed $customers rows of the customer query: id_customer, email, firstname, lastname
     * @param mixed $addresses rows of the address query: id_customer, vat_number, dni, postcode, company, firstname, lastname
     * @param mixed $mappings  rows of the stored links query: id_customer, ifirma_identifier, matched_by
     *                         (anything but an array, such as the false of a failed query, counts as no rows)
     * @return IfirmaCustomerIndex
     */
    public static function fromRows($customers, $addresses, $mappings)
    {
        $index = new self();
        $postcodes = array();
        foreach (is_array($addresses) ? $addresses : array() as $a) {
            $index->addAddress($a, $postcodes);
        }
        foreach (is_array($customers) ? $customers : array() as $c) {
            $index->addCustomer($c, $postcodes);
        }
        foreach (is_array($mappings) ? $mappings : array() as $m) {
            $index->existing[(int) $m['id_customer']] = array(
                'identifier' => (string) $m['ifirma_identifier'],
                'type' => (string) $m['matched_by'],
            );
        }

        return $index;
    }

    /**
     * An address adds its NIPs (VAT number and DNI) and, with its postcode, the names on it (the
     * company and the person). It also records the postcode for the customer's own name.
     *
     * @param array $a         address row
     * @param array $postcodes id_customer => postcode digits => true, filled here
     */
    private function addAddress($a, array &$postcodes)
    {
        $id = (int) $a['id_customer'];
        $postcode = preg_replace('/\D/', '', (string) $a['postcode']);
        $postcodes[$id][$postcode] = true;
        foreach (array('vat_number', 'dni') as $field) {
            $nip = preg_replace('/\D/', '', (string) $a[$field]);
            if (strlen($nip) >= 10) {
                $this->nip[$nip][$id] = true;
            }
        }
        $names = array(
            IfirmaContractorService::normalizeName(trim((string) $a['company'])),
            IfirmaContractorService::normalizeName(trim($a['firstname'] . ' ' . $a['lastname'])),
        );
        foreach ($names as $name) {
            if ($name !== '' && $postcode !== '') {
                $this->name[$name . '|' . $postcode][$id] = true;
            }
        }
    }

    /**
     * A customer adds its e-mail and, with every postcode of its addresses, its own name.
     *
     * @param array $c         customer row
     * @param array $postcodes id_customer => postcode digits => true, from the addresses
     */
    private function addCustomer($c, array $postcodes)
    {
        $id = (int) $c['id_customer'];
        $email = mb_strtolower(trim((string) $c['email']), 'UTF-8');
        if ($email !== '') {
            $this->email[$email][$id] = true;
        }
        $name = IfirmaContractorService::normalizeName(trim($c['firstname'] . ' ' . $c['lastname']));
        if ($name !== '' && isset($postcodes[$id])) {
            foreach (array_keys($postcodes[$id]) as $postcode) {
                if ($postcode !== '') {
                    $this->name[$name . '|' . $postcode][$id] = true;
                }
            }
        }
    }
}
