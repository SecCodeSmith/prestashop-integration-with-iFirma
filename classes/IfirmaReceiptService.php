<?php
/**
 * iFirma module – business logic: build payloads from PrestaShop orders,
 * issue documents, download PDFs and register payments ("status update").
 *
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class IfirmaReceiptService
{
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
     * Convenience factory that wires the client from module configuration.
     *
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
     * Issue a receipt/invoice in iFirma for the given order and persist it.
     *
     * @param Order $order
     * @return IfirmaReceipt
     * @throws Exception on API/validation failure
     */
    public function createReceipt(Order $order)
    {
        $existing = IfirmaReceipt::getByOrder((int) $order->id);
        if ($existing && $existing->isIssued()) {
            throw new Exception($this->l('A receipt has already been issued for this order (%s).', $existing->ifirma_number ?: $existing->ifirma_id));
        }

        $docType = $this->getDocumentType();
        $payload = $this->buildInvoicePayload($order);

        $receipt = $existing instanceof IfirmaReceipt ? $existing : new IfirmaReceipt();
        $receipt->id_order = (int) $order->id;
        $receipt->id_customer = (int) $order->id_customer;
        $receipt->id_shop = (int) $order->id_shop;
        $receipt->document_type = $docType;
        $receipt->request_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response = $this->client->post($docType . '.json', 'faktura', $payload);

        if ($response === null) {
            $receipt->status = 'error';
            $receipt->last_error = $this->client->getLastError();
            $receipt->save();
            throw new Exception($this->l('Could not reach iFirma: %s', $this->client->getLastError()));
        }

        $receipt->response_payload = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $node = isset($response['response']) ? $response['response'] : $response;
        $code = isset($node['Kod']) ? (int) $node['Kod'] : -1;

        if ($code !== 0) {
            $message = isset($node['Informacja']) ? $node['Informacja'] : $this->l('Unknown iFirma error.');
            $receipt->status = 'error';
            $receipt->last_error = $message;
            $receipt->save();
            throw new Exception($this->l('iFirma rejected the document: %s', $message));
        }

        $receipt->ifirma_id = isset($node['Identyfikator']) ? (string) $node['Identyfikator'] : '';
        // The iFirma create response returns only the identifier (as a string),
        // not the printable number. Keep it if a variant ever provides it, then
        // fall back to reading it from the invoice list (return connection).
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
        $receipt->status = 'created';
        $receipt->paid_amount = (float) $this->getPaidAmount($order);
        $receipt->last_error = '';

        if (!$receipt->security_token) {
            $receipt->security_token = $this->generateToken();
        }

        $receipt->save();

        return $receipt;
    }

    /**
     * Register a payment against the iFirma document = update its status to paid.
     * (POST iapi/faktury/wplaty/{typ}/{id}.json  body {"Kwota": amount})
     *
     * @param IfirmaReceipt $receipt
     * @param float|null    $amount defaults to the order's paid total
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

        // Documented alternative to the "number with / replaced by _" scheme:
        // the numeric identifier may be used directly in the URL.
        $endpoint = 'faktury/wplaty/' . rawurlencode($typeToken) . '/' . rawurlencode($receipt->ifirma_id) . '.json';

        $response = $this->client->post($endpoint, 'faktura', array('Kwota' => $amount));

        if ($response === null) {
            $receipt->last_error = $this->client->getLastError();
            $receipt->save();
            throw new Exception($this->l('Could not reach iFirma: %s', $this->client->getLastError()));
        }

        // The payment endpoint's success shape is not strictly documented, so we
        // only fail on an explicit non-zero code or an HTTP error status.
        $node = isset($response['response']) ? $response['response'] : $response;
        $hasCode = isset($node['Kod']);
        $isError = ($hasCode && (int) $node['Kod'] !== 0) || $this->client->getLastHttpCode() >= 400;

        if ($isError) {
            $message = isset($node['Informacja']) ? $node['Informacja'] : $this->l('Unknown iFirma error.');
            $receipt->last_error = $message;
            $receipt->save();
            throw new Exception($this->l('iFirma rejected the payment: %s', $message));
        }

        $receipt->status = 'paid';
        $receipt->paid_amount = $amount;
        $receipt->last_error = '';
        $receipt->response_payload = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $receipt->save();

        return $receipt;
    }

    /**
     * Read the printable document number back from iFirma.
     *
     * The create response only returns the numeric identifier, so we query the
     * invoice list (GET iapi/faktury.json) filtered by the issue date and match
     * on FakturaId. Best-effort: returns '' on any failure.
     *
     * @param string $ifirmaId
     * @param string $issueDate Y-m-d used as dataOd/dataDo
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
     * Fetch the document PDF bytes from iFirma (return connection).
     *
     * @param IfirmaReceipt $receipt
     * @param bool          $duplicate original vs duplicate copy
     * @return string raw PDF bytes
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
     * Map a PrestaShop order to the iFirma "faktura do paragonu" / "faktura krajowa" payload.
     *
     * @param Order $order
     * @return array
     */
    public function buildInvoicePayload(Order $order)
    {
        $invoiceAddress = new Address((int) $order->id_address_invoice);
        $customer = new Customer((int) $order->id_customer);
        $country = new Country((int) $invoiceAddress->id_country);
        $countryIso = $country->iso_code ? mb_strtoupper($country->iso_code) : 'PL';

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
                'StawkaVat' => round($taxRate / 100, 4),         // iFirma wants a fraction (0.23)
                'Ilosc' => (float) $row['product_quantity'],
                'CenaJednostkowa' => $grossUnit,                 // gross, because LiczOd = BRT
                'NazwaPelna' => mb_substr($row['product_name'], 0, 500),
                'Jednostka' => $unit,
                'TypStawkiVat' => $taxRate > 0 ? 'PRC' : 'ZW',
            );
        }

        // Shipping as an extra line when charged.
        $shippingIncl = round((float) $order->total_shipping_tax_incl, 2);
        if ($shippingIncl > 0) {
            $shipRate = $this->deriveTaxRate($shippingIncl, (float) $order->total_shipping_tax_excl, isset($order->carrier_tax_rate) ? $order->carrier_tax_rate : null);
            $positions[] = array(
                'StawkaVat' => round($shipRate / 100, 4),
                'Ilosc' => 1,
                'CenaJednostkowa' => $shippingIncl,
                'NazwaPelna' => $this->l('Shipping'),
                'Jednostka' => 'usł.',
                'TypStawkiVat' => $shipRate > 0 ? 'PRC' : 'ZW',
            );
        }

        $vatNumber = trim((string) $invoiceAddress->vat_number . $invoiceAddress->dni);
        $company = trim((string) $invoiceAddress->company);
        $isBusiness = ($company !== '' && $vatNumber !== '');

        $contractorName = $isBusiness
            ? $company
            : trim($invoiceAddress->firstname . ' ' . $invoiceAddress->lastname);

        $contractor = array(
            'Nazwa' => $contractorName,
            'Identyfikator' => mb_substr($customer->email, 0, 40),
            'NIP' => $isBusiness ? preg_replace('/[^0-9A-Za-z]/', '', $vatNumber) : '',
            'Ulica' => trim($invoiceAddress->address1 . ' ' . $invoiceAddress->address2),
            'KodPocztowy' => $invoiceAddress->postcode,
            'Kraj' => $countryIso,
            'Miejscowosc' => $invoiceAddress->city,
            'Email' => $customer->email,
            'OsobaFizyczna' => !$isBusiness,
        );

        $payload = array(
            'Zaplacono' => $paid,
            'ZaplaconoNaDokumencie' => $paid,
            'LiczOd' => 'BRT',
            'DataWystawienia' => $today,
            'DataSprzedazy' => $saleDate,
            'FormatDatySprzedazy' => 'DZN',
            'SposobZaplaty' => Configuration::get('IFIRMA_PAYMENT_METHOD') ?: 'PRZ',
            'RodzajPodpisuOdbiorcy' => 'BRK',
            'WidocznyNumerBdo' => false,
            'Numer' => null,
            'Kontrahent' => $contractor,
            'Pozycje' => $positions,
        );

        return $payload;
    }

    /**
     * Configured iFirma endpoint slug for the document to issue.
     *
     * @return string
     */
    public function getDocumentType()
    {
        $type = Configuration::get('IFIRMA_DOC_TYPE');

        return in_array($type, array('fakturaparagon', 'fakturakraj'), true) ? $type : 'fakturaparagon';
    }

    /**
     * Generate a cryptographically-random, URL-safe download token.
     * (Replaces the removed/deprecated Tools::encrypt(); PHP 7.2+ / 8.x / PS 9 safe.)
     *
     * @return string 40 hexadecimal characters
     */
    private function generateToken()
    {
        try {
            return bin2hex(random_bytes(20));
        } catch (Exception $e) {
            // Extremely unlikely fallback if no CSPRNG is available.
            return substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 40);
        }
    }

    /**
     * Derive the VAT percentage for a line from its gross/net unit prices,
     * falling back to a provided rate column when the net price is unusable.
     *
     * @param float      $gross
     * @param float      $net
     * @param mixed|null $fallbackRate
     * @return float percentage, e.g. 23.0
     */
    private function deriveTaxRate($gross, $net, $fallbackRate = null)
    {
        if ($net > 0 && $gross > 0) {
            return round((($gross / $net) - 1) * 100, 2);
        }

        return $fallbackRate !== null ? (float) $fallbackRate : 0.0;
    }

    /**
     * Paid total to report to iFirma. When the order is not yet paid we still
     * report the grand total for a cash-style receipt unless configured otherwise.
     *
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
     * Translation helper bound to the module domain, with sprintf support.
     *
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
     * @return string last transport-level error from the API client
     */
    public function getLastError()
    {
        return $this->client->getLastError();
    }
}
