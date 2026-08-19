<?php
/**
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
     * @param Order $order
     * @return IfirmaReceipt
     * @throws Exception
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

        $receipt->status = 'paid';
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
                'StawkaVat' => round($taxRate / 100, 4),
                'Ilosc' => (float) $row['product_quantity'],
                'CenaJednostkowa' => $grossUnit,
                'NazwaPelna' => mb_substr($row['product_name'], 0, 500),
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
