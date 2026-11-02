<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaPaymentMethodResolver.php';

class IfirmaReceiptService
{
    const CONTRACTOR_ID_PREFIX = 'PS-C';
    const ORDER_REFERENCE_PREFIX = 'PS-ORDER-';

    /** @var IfirmaApiClient */
    private $client;

    /** @var Module */
    private $module;

    public function __construct(IfirmaApiClient $client, Module $module)
    {
        $this->client = $client;
        $this->module = $module;
    }

    /**
     * @param Module $module
     * @return IfirmaReceiptService
     */
    public static function build(Module $module)
    {
        $baseUrl = Configuration::get('IFIRMA_BASE_URL');

        $client = new IfirmaApiClient(
            Configuration::get('IFIRMA_USER'),
            array(
                'faktura' => Configuration::get('IFIRMA_KEY_FAKTURA'),
                'abonent' => Configuration::get('IFIRMA_KEY_ABONENT'),
                'rachunek' => Configuration::get('IFIRMA_KEY_RACHUNEK'),
            ),
            $baseUrl ?: IfirmaApiClient::BASE_URL
        );

        return new self($client, $module);
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
     * @param Order $order
     * @param array $options override (array|null), skip_dodatkowe_uwagi (bool)
     * @return IfirmaReceipt
     * @throws Exception
     */
    public function createReceipt(Order $order, array $options = array())
    {
        $existing = IfirmaReceipt::getByOrder((int) $order->id);
        if ($existing && $existing->isIssued()) {
            throw new Exception($this->l('A receipt has already been issued for this order (%s).', $existing->ifirma_number ?: $existing->ifirma_id));
        }

        $docType = $this->getDocumentType();
        $payload = $this->buildInvoicePayload(
            $order,
            isset($options['override']) ? $options['override'] : null,
            $options
        );

        $receipt = $existing instanceof IfirmaReceipt ? $existing : new IfirmaReceipt();
        $receipt->id_order = (int) $order->id;
        $receipt->id_customer = (int) $order->id_customer;
        $receipt->id_shop = (int) $order->id_shop;
        $receipt->document_type = $docType;
        $receipt->request_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response = $this->client->post($docType . '.json', 'faktura', $payload);

        // Kod 201 naming DodatkoweUwagi: retry exactly once without that object.
        if ($response !== null && empty($options['skip_dodatkowe_uwagi']) && $this->namesDodatkoweUwagi($response)) {
            $options['skip_dodatkowe_uwagi'] = true;
            $payload = $this->buildInvoicePayload(
                $order,
                isset($options['override']) ? $options['override'] : null,
                $options
            );
            $receipt->request_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $response = $this->client->post($docType . '.json', 'faktura', $payload);
        }

        if ($response === null) {
            // Unknown outcome when the request reached the transport: the document may exist.
            $receipt->status = $this->client->lastRequestWasSent() ? IfirmaReceipt::STATUS_UNKNOWN : IfirmaReceipt::STATUS_ERROR;
            $receipt->last_error = $this->client->getLastError();
            $receipt->save();
            throw new Exception($this->l('Could not reach iFirma: %s', $this->client->getLastError()));
        }

        $receipt->response_payload = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $node = isset($response['response']) ? $response['response'] : $response;
        $code = isset($node['Kod']) ? (int) $node['Kod'] : -1;

        if ($code !== 0) {
            $message = isset($node['Informacja']) ? $node['Informacja'] : $this->l('Unknown iFirma error.');
            $receipt->status = IfirmaReceipt::STATUS_ERROR;
            $receipt->last_error = $message;
            $receipt->save();
            throw new Exception($this->l('iFirma rejected the document: %s', $message));
        }

        $receipt->ifirma_id = isset($node['Identyfikator']) ? (string) $node['Identyfikator'] : '';
        if (!empty($node['PelnyNumer'])) {
            $receipt->ifirma_number = (string) $node['PelnyNumer'];
        } elseif (!empty($node['Numer'])) {
            $receipt->ifirma_number = (string) $node['Numer'];
        }
        if (empty($receipt->ifirma_number) && $receipt->ifirma_id !== '') {
            $number = $this->fetchDocumentNumber($receipt->ifirma_id, $payload['DataWystawienia']);
            if ($number !== '') {
                $receipt->ifirma_number = $number;
            }
        }
        $receipt->status = IfirmaReceipt::STATUS_CREATED;
        $receipt->order_reference = self::buildOrderReference($order);
        $receipt->issue_date = $payload['DataWystawienia'];
        $receipt->source = IfirmaReceipt::SOURCE_MODULE;
        $receipt->paid_amount = (float) $this->getPaidAmount($order);
        $receipt->last_error = '';

        if (!$receipt->security_token) {
            $receipt->security_token = $this->generateToken();
        }

        $receipt->save();

        return $receipt;
    }

    /**
     * @param array $response decoded create response
     * @return bool true for Kod 201 whose Informacja names DodatkoweUwagi
     */
    private function namesDodatkoweUwagi(array $response)
    {
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;

        return isset($node['Kod']) && (int) $node['Kod'] === 201
            && isset($node['Informacja'])
            && stripos((string) $node['Informacja'], 'DodatkoweUwagi') !== false;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param float|null    $amount
     * @return IfirmaReceipt
     * @throws Exception
     */
    public function updateStatusPaid(IfirmaReceipt $receipt, $amount = null)
    {
        if (!$receipt->isIssued()) {
            throw new Exception($this->l('This order has no issued iFirma document yet.'));
        }

        if ($amount === null) {
            $order = new Order((int) $receipt->id_order);
            $amount = $this->getPaidAmount($order);
        }
        $amount = round((float) $amount, 2);

        $typeToken = Configuration::get('IFIRMA_PAYMENT_TYPE_TOKEN');
        if (!$typeToken) {
            $typeToken = 'prz_faktura_kraj';
        }

        $endpoint = 'faktury/wplaty/' . rawurlencode($typeToken) . '/' . rawurlencode($receipt->ifirma_id) . '.json';

        $response = $this->client->post($endpoint, 'faktura', array('Kwota' => $amount));

        if ($response === null) {
            $receipt->last_error = $this->client->getLastError();
            $receipt->save();
            throw new Exception($this->l('Could not reach iFirma: %s', $this->client->getLastError()));
        }

        $node = isset($response['response']) ? $response['response'] : $response;
        $hasCode = isset($node['Kod']);
        $isError = ($hasCode && (int) $node['Kod'] !== 0) || $this->client->getLastHttpCode() >= 400;

        if ($isError) {
            $message = isset($node['Informacja']) ? $node['Informacja'] : $this->l('Unknown iFirma error.');
            $receipt->last_error = $message;
            $receipt->save();
            throw new Exception($this->l('iFirma rejected the payment: %s', $message));
        }

        $receipt->status = IfirmaReceipt::STATUS_PAID;
        $receipt->paid_amount = $amount;
        $receipt->last_error = '';
        $receipt->response_payload = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $receipt->save();

        return $receipt;
    }

    /**
     * @param string $ifirmaId
     * @param string $issueDate
     * @return string
     */
    public function fetchDocumentNumber($ifirmaId, $issueDate)
    {
        $endpoint = 'faktury.json?dataOd=' . rawurlencode($issueDate)
            . '&dataDo=' . rawurlencode($issueDate)
            . '&iloscNaStronie=100';

        $response = $this->client->get($endpoint, 'faktura');
        if ($response === null) {
            return '';
        }

        $node = isset($response['response']) ? $response['response'] : $response;
        $rows = (isset($node['Wynik']) && is_array($node['Wynik'])) ? $node['Wynik'] : array();

        foreach ($rows as $row) {
            if (isset($row['FakturaId']) && (string) $row['FakturaId'] === (string) $ifirmaId) {
                return isset($row['PelnyNumer']) ? (string) $row['PelnyNumer'] : '';
            }
        }

        return '';
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param bool          $duplicate
     * @return string
     * @throws Exception
     */
    public function fetchPdf(IfirmaReceipt $receipt, $duplicate = false)
    {
        if (!$receipt->isIssued()) {
            throw new Exception($this->l('This order has no issued iFirma document yet.'));
        }

        $endpoint = $receipt->document_type . '/' . rawurlencode($receipt->ifirma_id) . '.pdf';
        if ($duplicate) {
            $endpoint .= '?typ=dup';
        }

        $pdf = $this->client->getBinary($endpoint, 'faktura');

        if ($pdf === false) {
            throw new Exception($this->l('Could not download the PDF: %s', $this->client->getLastError()));
        }

        return $pdf;
    }

    /**
     * @param Order      $order
     * @param array|null $override Approved buyer data (see buildContractor())
     * @param array      $options  skip_dodatkowe_uwagi (bool)
     * @return array
     */
    public function buildInvoicePayload(Order $order, $override = null, array $options = array())
    {
        $limits = self::fieldLimits();

        $paid = $this->getPaidAmount($order);
        $today = date('Y-m-d');
        $saleDate = $order->date_add ? date('Y-m-d', strtotime($order->date_add)) : $today;

        $unit = Configuration::get('IFIRMA_DEFAULT_UNIT') ?: 'szt.';

        $positions = array();
        foreach ($order->getOrderDetailList() as $row) {
            $grossUnit = round((float) $row['unit_price_tax_incl'], 2);
            $netUnit = (float) $row['unit_price_tax_excl'];
            $taxRate = $this->deriveTaxRate($grossUnit, $netUnit, isset($row['tax_rate']) ? $row['tax_rate'] : null);

            $positions[] = array(
                'StawkaVat' => round($taxRate / 100, 4),
                'Ilosc' => (float) $row['product_quantity'],
                'CenaJednostkowa' => $grossUnit,
                'NazwaPelna' => self::truncate($row['product_name'], $limits['NazwaPelna']),
                'Jednostka' => $unit,
                'TypStawkiVat' => $taxRate > 0 ? 'PRC' : 'ZW',
            );
        }

        $shippingIncl = round((float) $order->total_shipping_tax_incl, 2);
        if ($shippingIncl > 0) {
            $shipRate = $this->deriveTaxRate($shippingIncl, (float) $order->total_shipping_tax_excl, isset($order->carrier_tax_rate) ? $order->carrier_tax_rate : null);
            $positions[] = array(
                'StawkaVat' => round($shipRate / 100, 4),
                'Ilosc' => 1,
                'CenaJednostkowa' => $shippingIncl,
                'NazwaPelna' => self::truncate($this->l('Shipping'), $limits['NazwaPelna']),
                'Jednostka' => 'usł.',
                'TypStawkiVat' => $shipRate > 0 ? 'PRC' : 'ZW',
            );
        }

        $reference = self::buildOrderReference($order);

        $payload = array(
            'Zaplacono' => $paid,
            'ZaplaconoNaDokumencie' => $paid,
            'LiczOd' => 'BRT',
            'DataWystawienia' => $today,
            'DataSprzedazy' => $saleDate,
            'FormatDatySprzedazy' => 'DZN',
            'SposobZaplaty' => IfirmaPaymentMethodResolver::build()->resolve($order),
            'RodzajPodpisuOdbiorcy' => 'BRK',
            'WidocznyNumerBdo' => false,
            'Numer' => null,
            'Uwagi' => self::truncate($reference, $limits['Uwagi']),
            'Kontrahent' => $this->buildContractor($order, $override),
            'Pozycje' => $positions,
        );

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

        $name = $isBusiness ? $company : trim($firstname . ' ' . $lastname);

        return array(
            'Nazwa' => self::truncate($name, $limits['Nazwa']),
            'Identyfikator' => self::truncate(self::CONTRACTOR_ID_PREFIX . (int) $order->id_customer, $limits['Identyfikator']),
            'NIP' => $isBusiness ? self::truncate(preg_replace('/[^0-9A-Za-z]/', '', $vatNumber), $limits['NIP']) : '',
            'Ulica' => self::truncate($street, $limits['Ulica']),
            'KodPocztowy' => self::truncate($postcode, $limits['KodPocztowy']),
            'Kraj' => self::truncate($countryIso, $limits['Kraj']),
            'Miejscowosc' => self::truncate($city, $limits['Miejscowosc']),
            'Email' => self::truncate($customer->email, $limits['Email']),
            'OsobaFizyczna' => !$isBusiness,
        );
    }

    /**
     * @return string
     */
    public function getDocumentType()
    {
        $type = Configuration::get('IFIRMA_DOC_TYPE');

        return in_array($type, array('fakturaparagon', 'fakturakraj'), true) ? $type : 'fakturaparagon';
    }

    /**
     * @return string
     */
    private function generateToken()
    {
        return self::generateSecurityToken();
    }

    /**
     * @return string random 40-character token for public receipt links
     */
    public static function generateSecurityToken()
    {
        try {
            return bin2hex(random_bytes(20));
        } catch (Exception $e) {
            return substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 40);
        }
    }

    /**
     * @param float      $gross
     * @param float      $net
     * @param mixed|null $fallbackRate
     * @return float
     */
    private function deriveTaxRate($gross, $net, $fallbackRate = null)
    {
        if ($net > 0 && $gross > 0) {
            return round((($gross / $net) - 1) * 100, 2);
        }

        return $fallbackRate !== null ? (float) $fallbackRate : 0.0;
    }

    /**
     * @param Order $order
     * @return float
     */
    private function getPaidAmount(Order $order)
    {
        if ((bool) Configuration::get('IFIRMA_MARK_PAID_ON_CREATE')) {
            return round((float) $order->total_paid_tax_incl, 2);
        }

        return round((float) $order->getTotalPaid(), 2);
    }

    /**
     * @return string
     */
    private function l($key)
    {
        $args = func_get_args();
        array_shift($args);
        $text = $this->module->l($key, 'ifirmareceiptservice');

        return $args ? vsprintf($text, $args) : $text;
    }

    /**
     * @return string
     */
    public function getLastError()
    {
        return $this->client->getLastError();
    }
}
