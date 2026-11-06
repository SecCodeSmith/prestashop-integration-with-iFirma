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

require_once dirname(__FILE__) . '/IfirmaPaymentMethodResolver.php';
require_once dirname(__FILE__) . '/IfirmaDocumentSettings.php';

/**
 * What the module sends to iFirma for an order. The duplicate guard compares remote documents with values
 * from the static helpers below; the delivery service only uses truncate().
 *
 * Contract:
 *  - buildInvoicePayload(order, override, options) returns the document payload; options:
 *    skip_dodatkowe_uwagi (bool). buildContractor(order, override) returns its Kontrahent block;
 *    override is the approved buyer data. getPaidAmount(order) is the amount the document carries
 *    as paid.
 *  - Static helpers: fieldLimits() and truncate() (the documented field lengths), buildOrderReference()
 *    and orderSaleDate() (the order reference and the sale date stamped on the document),
 *    orderProductLines() and buyerNames() (the lines and the buyer names a remote document is
 *    compared with), allowedVatRates() and snapVatRate().
 *
 * Only the shipping line is translated. Its label stays in the translation domain of the receipt
 * service the code came from, so the translation key and the translation pack do not change.
 */
class IfirmaPayloadBuilder
{
    const CONTRACTOR_ID_PREFIX = 'PS-C';
    const ORDER_REFERENCE_PREFIX = 'PS-ORDER-';

    /** @var Module */
    private $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * @param Module $module
     * @return IfirmaPayloadBuilder
     */
    public static function build(Module $module)
    {
        return new self($module);
    }

    /**
     * Documented iFirma maximum lengths (characters) of the payload fields.
     *
     * @return array
     */
    public static function fieldLimits()
    {
        return array(
            'Nazwa' => 150,
            'Email' => 65,
            'NIP' => 13,
            'Ulica' => 65,
            'Miejscowosc' => 65,
            'KodPocztowy' => 16,
            'Uwagi' => 1000,
            'NazwaPelna' => 500,
            'Identyfikator' => 15,
            'Kraj' => 70,
        );
    }

    /**
     * Trim and cut a value to at most $max characters (never splits a multibyte character).
     *
     * @param mixed $value
     * @param int   $max
     * @return string
     */
    public static function truncate($value, $max)
    {
        $value = trim((string) $value);
        $max = (int) $max;
        if (mb_strlen($value, 'UTF-8') > $max) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        }

        return $value;
    }

    /**
     * @param Order $order
     * @return string
     */
    public static function buildOrderReference(Order $order)
    {
        return self::ORDER_REFERENCE_PREFIX . (int) $order->id . '-' . $order->reference;
    }

    /**
     * Sale date the module stamps on the document (DataSprzedazy): the order date, today when the
     * order has none. Shared with the duplicate guard so both sides compare the same value.
     *
     * @param Order $order
     * @return string Y-m-d
     */
    public static function orderSaleDate(Order $order)
    {
        $ts = $order->date_add ? strtotime((string) $order->date_add) : false;

        return $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    }

    /**
     * Product lines of the order as the module sends them (name cut to the NazwaPelna limit,
     * quantity as float) plus the price fields the payload builder needs. Shipping is not a line.
     *
     * @param Order $order
     * @return array of name, quantity, unit_price_tax_incl, unit_price_tax_excl, tax_rate
     */
    public static function orderProductLines(Order $order)
    {
        $limits = self::fieldLimits();
        $lines = array();
        foreach ($order->getOrderDetailList() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lines[] = array(
                'name' => self::truncate(isset($row['product_name']) ? $row['product_name'] : '', $limits['NazwaPelna']),
                'quantity' => isset($row['product_quantity']) ? (float) $row['product_quantity'] : 0.0,
                'unit_price_tax_incl' => isset($row['unit_price_tax_incl']) ? (float) $row['unit_price_tax_incl'] : 0.0,
                'unit_price_tax_excl' => isset($row['unit_price_tax_excl']) ? (float) $row['unit_price_tax_excl'] : 0.0,
                'tax_rate' => isset($row['tax_rate']) ? $row['tax_rate'] : null,
            );
        }

        return $lines;
    }

    /**
     * Names under which the buyer of the order may appear as an iFirma contractor: the company of
     * the invoice address when it has one, and the person name. Both cut to the Nazwa limit, raw
     * (not normalised) so callers decide how to compare.
     *
     * @param Order $order
     * @return string[]
     */
    public static function buyerNames(Order $order)
    {
        $limits = self::fieldLimits();
        $address = new Address((int) $order->id_address_invoice);
        $names = array();
        $company = trim((string) $address->company);
        if ($company !== '') {
            $names[] = self::truncate($company, $limits['Nazwa']);
        }
        $person = trim(trim((string) $address->firstname) . ' ' . trim((string) $address->lastname));
        if ($person !== '') {
            $names[] = self::truncate($person, $limits['Nazwa']);
        }

        return $names;
    }

    /**
     * @param Order      $order
     * @param array|null $override Approved buyer data (see buildContractor())
     * @param array      $options  skip_dodatkowe_uwagi (bool)
     * @return array
     */
    public function buildInvoicePayload(Order $order, $override = null, array $options = array())
    {
        $positions = $this->productPositions($order);
        $shipping = $this->shippingPosition($order);
        if ($shipping !== null) {
            $positions[] = $shipping;
        }

        $payload = $this->documentHeader($order, $this->buildContractor($order, $override), $positions);
        $payload = $this->withBuyerNip($payload);

        return $this->withOrderNumberNote($payload, $options, self::buildOrderReference($order));
    }

    /**
     * One position per product line of the order, priced gross, with the configured default unit.
     *
     * @param Order $order
     * @return array
     */
    private function productPositions(Order $order)
    {
        $unit = Configuration::get('IFIRMA_DEFAULT_UNIT') ?: 'szt.';

        $positions = array();
        foreach (self::orderProductLines($order) as $line) {
            $grossUnit = round($line['unit_price_tax_incl'], 2);
            $netUnit = $line['unit_price_tax_excl'];
            $taxRate = $this->deriveTaxRate($grossUnit, $netUnit, $line['tax_rate']);

            $positions[] = array(
                'StawkaVat' => round($taxRate / 100, 4),
                'Ilosc' => $line['quantity'],
                'CenaJednostkowa' => $grossUnit,
                'NazwaPelna' => $line['name'],
                'Jednostka' => $unit,
                // Always a percentage rate (0% included): "ZW" (exempt) would require a PKWiU code per position.
                'TypStawkiVat' => 'PRC',
            );
        }

        return $positions;
    }

    /**
     * The shipping position of the order, or null when the order has no shipping to charge.
     *
     * @param Order $order
     * @return array|null
     */
    private function shippingPosition(Order $order)
    {
        $shippingIncl = round((float) $order->total_shipping_tax_incl, 2);
        if ($shippingIncl <= 0) {
            return null;
        }

        $limits = self::fieldLimits();
        $shipRate = $this->deriveTaxRate($shippingIncl, (float) $order->total_shipping_tax_excl, isset($order->carrier_tax_rate) ? $order->carrier_tax_rate : null);

        return array(
            'StawkaVat' => round($shipRate / 100, 4),
            'Ilosc' => 1,
            'CenaJednostkowa' => $shippingIncl,
            // The label keeps the translation domain of the receipt service this code came from.
            'NazwaPelna' => self::truncate($this->module->l('Shipping', 'ifirmareceiptservice'), $limits['NazwaPelna']),
            'Jednostka' => 'usł.',
            'TypStawkiVat' => 'PRC',
        );
    }

    /**
     * The document header: payment, dates, notes, the contractor and the positions.
     *
     * @param Order $order
     * @param array $contractor The Kontrahent node (see buildContractor())
     * @param array $positions  The Pozycje node
     * @return array
     */
    private function documentHeader(Order $order, array $contractor, array $positions)
    {
        $limits = self::fieldLimits();
        $paid = $this->getPaidAmount($order);

        return array(
            'Zaplacono' => $paid,
            'ZaplaconoNaDokumencie' => $paid,
            'LiczOd' => 'BRT',
            'DataWystawienia' => date('Y-m-d'),
            'DataSprzedazy' => self::orderSaleDate($order),
            'FormatDatySprzedazy' => 'DZN',
            'SposobZaplaty' => IfirmaPaymentMethodResolver::build()->resolve($order),
            'RodzajPodpisuOdbiorcy' => IfirmaDocumentSettings::normalizeSignatureType(Configuration::get('IFIRMA_SIGNATURE_TYPE')),
            'WidocznyNumerBdo' => false,
            'Numer' => null,
            'Uwagi' => self::truncate(self::buildOrderReference($order), $limits['Uwagi']),
            'IdentyfikatorKontrahenta' => null,
            'NIPKontrahenta' => null,
            'Kontrahent' => $contractor,
            'Pozycje' => $positions,
        );
    }

    /**
     * Documented second lookup step: an existing contractor with this (Polish) NIP is reused.
     *
     * @param array $payload The document with its Kontrahent node
     * @return array
     */
    private function withBuyerNip(array $payload)
    {
        $nip = preg_replace('/\D/', '', (string) $payload['Kontrahent']['NIP']);
        if (strlen($nip) === 10 && strlen($nip) === strlen((string) $payload['Kontrahent']['NIP'])) {
            $payload['NIPKontrahenta'] = $nip;
        }

        return $payload;
    }

    /**
     * Add the DodatkoweUwagi note carrying the order reference, unless skip_dodatkowe_uwagi is set.
     *
     * @param array  $payload
     * @param array  $options   skip_dodatkowe_uwagi (bool)
     * @param string $reference The full (untruncated) order reference
     * @return array
     */
    private function withOrderNumberNote(array $payload, array $options, $reference)
    {
        if (empty($options['skip_dodatkowe_uwagi'])) {
            $payload['DodatkoweUwagi'] = array(
                'Typ' => 'NUMER_ZAMOWIENIA',
                'Info' => $reference,
            );
        }

        return $payload;
    }

    /**
     * Build the Kontrahent node. $override (approved buyer data) replaces the invoice-address data.
     *
     * @param Order      $order
     * @param array|null $override want_invoice_for_company, company, vat_number, firstname,
     *                             lastname, address1, address2, postcode, city, id_country
     * @return array
     */
    public function buildContractor(Order $order, $override = null)
    {
        $limits = self::fieldLimits();
        $customer = new Customer((int) $order->id_customer);

        if (is_array($override)) {
            $get = function ($key) use ($override) {
                return isset($override[$key]) ? (string) $override[$key] : '';
            };
            $idCountry = isset($override['id_country']) ? (int) $override['id_country'] : 0;
            $isBusiness = !empty($override['want_invoice_for_company']);
            $company = trim($get('company'));
            $vatNumber = trim($get('vat_number'));
            $firstname = $get('firstname');
            $lastname = $get('lastname');
            $street = trim($get('address1') . ' ' . $get('address2'));
            $postcode = $get('postcode');
            $city = $get('city');
        } else {
            $invoiceAddress = new Address((int) $order->id_address_invoice);
            $idCountry = (int) $invoiceAddress->id_country;
            $vatNumber = trim((string) $invoiceAddress->vat_number . $invoiceAddress->dni);
            $company = trim((string) $invoiceAddress->company);
            $isBusiness = ($company !== '' && $vatNumber !== '');
            $firstname = (string) $invoiceAddress->firstname;
            $lastname = (string) $invoiceAddress->lastname;
            $street = trim($invoiceAddress->address1 . ' ' . $invoiceAddress->address2);
            $postcode = (string) $invoiceAddress->postcode;
            $city = (string) $invoiceAddress->city;
        }

        $country = new Country($idCountry);
        $countryIso = $country->iso_code ? mb_strtoupper($country->iso_code) : 'PL';
        $countryName = trim((string) Country::getNameById((int) $order->id_lang, $idCountry));
        if ($countryName === '') {
            $countryName = $countryIso;
        }

        $name = $isBusiness ? $company : trim($firstname . ' ' . $lastname);

        return array(
            'Nazwa' => self::truncate($name, $limits['Nazwa']),
            'Identyfikator' => self::truncate(self::CONTRACTOR_ID_PREFIX . (int) $order->id_customer, $limits['Identyfikator']),
            'NIP' => $isBusiness ? self::truncate(preg_replace('/[^0-9A-Za-z]/', '', $vatNumber), $limits['NIP']) : '',
            'Ulica' => self::truncate($street, $limits['Ulica']),
            'KodPocztowy' => self::truncate($postcode, $limits['KodPocztowy']),
            'Kraj' => self::truncate($countryName, $limits['Kraj']),
            'KodKraju' => self::truncate($countryIso, 2),
            'Miejscowosc' => self::truncate($city, $limits['Miejscowosc']),
            'Email' => self::truncate($customer->email, $limits['Email']),
            'OsobaFizyczna' => !$isBusiness,
        );
    }

    /**
     * The amount the document carries as paid: the order total when the document is marked paid on
     * creation, what the customer has paid so far otherwise. The receipt service records it as well.
     *
     * @param Order $order
     * @return float
     */
    public function getPaidAmount(Order $order)
    {
        if ((bool) Configuration::get('IFIRMA_MARK_PAID_ON_CREATE')) {
            return round((float) $order->total_paid_tax_incl, 2);
        }

        return round((float) $order->getTotalPaid(), 2);
    }

    /**
     * @param float      $gross
     * @param float      $net
     * @param mixed|null $fallbackRate
     * @return float
     */
    private function deriveTaxRate($gross, $net, $fallbackRate = null)
    {
        // PrestaShop stores the applied rate on the order line; it beats a ratio of rounded prices.
        if ($fallbackRate !== null && is_numeric($fallbackRate)) {
            return self::snapVatRate((float) $fallbackRate);
        }
        if ($net > 0 && $gross > 0) {
            return self::snapVatRate(round((($gross / $net) - 1) * 100, 2));
        }

        return 0.0;
    }

    /**
     * VAT rates (percent) the document endpoints accept in StawkaVat: 0, 5, 8 and 23.
     *
     * @return float[]
     */
    public static function allowedVatRates()
    {
        return array(0.0, 5.0, 8.0, 23.0);
    }

    /**
     * Rounding noise (7.99 from gross/net of rounded prices) becomes the documented rate; a rate
     * further than half a point from every documented one is returned unchanged so iFirma reports it
     * instead of the module silently changing the tax.
     *
     * @param float $rate percent
     * @return float
     */
    public static function snapVatRate($rate)
    {
        $rate = (float) $rate;
        foreach (self::allowedVatRates() as $allowed) {
            if (abs($rate - $allowed) <= 0.5) {
                return $allowed;
            }
        }

        return $rate;
    }
}
