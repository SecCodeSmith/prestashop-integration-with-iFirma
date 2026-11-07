<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaContractorService.php';
require_once dirname(__FILE__) . '/IfirmaCreateResponse.php';
require_once dirname(__FILE__) . '/IfirmaDocumentSettings.php';
require_once dirname(__FILE__) . '/IfirmaInput.php';
require_once dirname(__FILE__) . '/IfirmaPayloadBuilder.php';

class IfirmaReceiptService
{
    /** Longest contractor identifier the document endpoints accept in IdentyfikatorKontrahenta. */
    const DOCUMENT_IDENTIFIER_MAX = 15;

    /** @var IfirmaApiClient */
    private $client;

    /** @var Module */
    private $module;

    /** @var IfirmaContractorService|null */
    private $contractors;

    /** @var IfirmaPayloadBuilder */
    private $payloadBuilder;

    public function __construct(IfirmaApiClient $client, Module $module)
    {
        $this->client = $client;
        $this->module = $module;
        $this->payloadBuilder = IfirmaPayloadBuilder::build($module);
    }

    /**
     * @param Module $module
     * @return IfirmaReceiptService
     */
    public static function build(Module $module)
    {
        $client = IfirmaApiClient::fromConfiguration();

        return new self($client, $module);
    }

    /**
     * Issues the document of the order: registers the contractor, posts the payload (postWithRetries()),
     * reads the answer (parseCreateResponse()) and records it on the receipt (applyCreateResponse()).
     *
     * @param Order $order
     * @param array $options override (array|null), skip_dodatkowe_uwagi (bool), skip_contractor_sync (bool)
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
        $payload = $this->payloadBuilder->buildInvoicePayload(
            $order,
            isset($options['override']) ? $options['override'] : null,
            $options
        );

        $receipt = $existing instanceof IfirmaReceipt ? $existing : new IfirmaReceipt();
        $receipt->id_order = (int) $order->id;
        $receipt->id_customer = (int) $order->id_customer;
        $receipt->id_shop = (int) $order->id_shop;
        $receipt->document_type = $docType;

        // Register the contractor first: iFirma rejects an identifier it does not know (Kod 201).
        $contractorInfo = null;
        if (empty($options['skip_contractor_sync']) && !empty($payload['Kontrahent']['Identyfikator'])) {
            $contractorInfo = $this->contractorService()->ensure(
                $payload['Kontrahent'],
                (int) $order->id_customer,
                $this->contractorHints($order, $payload['Kontrahent'], isset($options['override']) ? $options['override'] : null)
            );
        }
        $payload = $this->referenceContractor($payload, $contractorInfo);

        list($response, $payload, $contractorInfo) = $this->postWithRetries($receipt, $order, $options, $payload, $contractorInfo);
        if ($response === null) {
            throw $this->unreachable($receipt);
        }
        $created = $this->parseCreateResponse($response);
        $this->applyCreateResponse($receipt, $order, $created, $payload, $contractorInfo);

        return $receipt;
    }

    /**
     * Posts the document and, while iFirma refuses it for a reason the module can remove, posts it
     * again, once for each reason: DodatkoweUwagi first, then the contractor reference. The receipt
     * keeps the exchange: the request_payload of the last round and the response_payload of the last
     * answer (none when iFirma gave none).
     *
     * @param IfirmaReceipt                   $receipt
     * @param Order                           $order
     * @param array                           $options        see createReceipt()
     * @param array                           $payload        the payload to send first
     * @param IfirmaContractorResolution|null $contractorInfo ensure() result; null when the contractor step was skipped
     * @return array the decoded last response (null when iFirma gave none), the payload sent last, and
     *               the contractor resolution it went out with, which the fallback retry replaces
     */
    private function postWithRetries(IfirmaReceipt $receipt, Order $order, array $options, array $payload, $contractorInfo)
    {
        $response = $this->sendDocument($receipt, $payload, $contractorInfo);

        // Kod 201 naming DodatkoweUwagi: retry exactly once without that object.
        if ($response !== null && empty($options['skip_dodatkowe_uwagi']) && $this->dodatkoweUwagiRejected($response, $payload)) {
            $options['skip_dodatkowe_uwagi'] = true;
            $payload = $this->payloadBuilder->buildInvoicePayload(
                $order,
                isset($options['override']) ? $options['override'] : null,
                $options
            );
            $payload = $this->referenceContractor($payload, $contractorInfo);
            $response = $this->sendDocument($receipt, $payload, $contractorInfo);
        }

        // Kod 201 about the contractor reference: retry exactly once without IdentyfikatorKontrahenta,
        // so iFirma matches by NIP or creates the contractor from the Kontrahent block.
        if ($response !== null && !empty($payload['IdentyfikatorKontrahenta'])
            && $this->contractorReferenceRejected($response, (string) $payload['IdentyfikatorKontrahenta'])) {
            $rejected = $this->extractInformacja($response);
            $contractorInfo = IfirmaContractorResolution::fallback(
                $rejected,
                array(
                    'code' => 'reference_rejected',
                    'params' => array('identifier' => (string) $payload['IdentyfikatorKontrahenta'], 'error' => $rejected),
                )
            );
            $payload = $this->referenceContractor($payload, $contractorInfo);
            $response = $this->sendDocument($receipt, $payload, $contractorInfo);
        }

        if ($response !== null) {
            $receipt->response_payload = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return array($response, $payload, $contractorInfo);
    }

    /**
     * One request that creates the document: stores on the receipt what is about to be sent (the
     * payload plus the private _contractor note), then sends it.
     *
     * @param IfirmaReceipt                   $receipt
     * @param array                           $payload
     * @param IfirmaContractorResolution|null $contractorInfo
     * @return array|null decoded response; null when iFirma gave none
     */
    private function sendDocument(IfirmaReceipt $receipt, array $payload, $contractorInfo)
    {
        $receipt->request_payload = $this->encodeRequest($payload, $contractorInfo);

        return $this->client->post($receipt->document_type . '.json', 'faktura', $this->sendable($payload));
    }

    /**
     * iFirma gave no answer. Records that on the receipt and saves it.
     *
     * @param IfirmaReceipt $receipt
     * @return Exception the one to throw
     */
    private function unreachable(IfirmaReceipt $receipt)
    {
        // Unknown outcome when the request reached the transport: the document may exist.
        if ($this->client->lastRequestWasSent()) {
            $receipt->markUnknown($this->client->getLastError());
        } else {
            $receipt->markError($this->client->getLastError());
        }
        $receipt->save();

        return new Exception($this->l('Could not reach iFirma: %s', $this->client->getLastError()));
    }

    /**
     * @param array $response decoded create response
     * @return IfirmaCreateResponse
     */
    private function parseCreateResponse(array $response)
    {
        $node = isset($response['response']) ? $response['response'] : $response;
        $code = isset($node['Kod']) ? (int) $node['Kod'] : -1;

        if ($code !== 0) {
            return IfirmaCreateResponse::rejected(isset($node['Informacja']) ? $node['Informacja'] : null);
        }

        $number = null;
        if (!empty($node['PelnyNumer'])) {
            $number = $node['PelnyNumer'];
        } elseif (!empty($node['Numer'])) {
            $number = $node['Numer'];
        }

        return IfirmaCreateResponse::accepted(isset($node['Identyfikator']) ? $node['Identyfikator'] : '', $number);
    }

    /**
     * Records the answer on the receipt and saves it. A rejection is recorded as an error and thrown.
     *
     * @param IfirmaReceipt                   $receipt
     * @param Order                           $order
     * @param IfirmaCreateResponse            $created
     * @param array                           $payload        the payload sent last
     * @param IfirmaContractorResolution|null $contractorInfo the resolution it went out with
     * @throws Exception when iFirma rejected the document
     */
    private function applyCreateResponse(IfirmaReceipt $receipt, Order $order, IfirmaCreateResponse $created, array $payload, $contractorInfo)
    {
        if (!$created->isAccepted()) {
            $message = $created->getMessage() !== null ? $created->getMessage() : $this->l('Unknown iFirma error.');
            $receipt->markError($message);
            $receipt->save();
            throw new Exception($this->l('iFirma rejected the document: %s', $message));
        }

        $receipt->ifirma_id = $created->getIdentifier();
        if ($created->getNumber() !== null) {
            $receipt->ifirma_number = $created->getNumber();
        }
        if (empty($receipt->ifirma_number) && $receipt->ifirma_id !== '') {
            $number = $this->fetchDocumentNumber($receipt->ifirma_id, $payload['DataWystawienia']);
            if ($number !== '') {
                $receipt->ifirma_number = $number;
            }
        }
        $receipt->status = IfirmaReceipt::STATUS_CREATED;
        $receipt->order_reference = IfirmaPayloadBuilder::buildOrderReference($order);
        $receipt->issue_date = $payload['DataWystawienia'];
        $receipt->source = IfirmaReceipt::SOURCE_MODULE;
        $receipt->paid_amount = (float) $this->payloadBuilder->getPaidAmount($order);
        $receipt->last_error = '';

        if (!$receipt->security_token) {
            $receipt->security_token = $this->generateToken();
        }

        $this->learnDocumentContractor($receipt, $payload, $contractorInfo);

        $receipt->save();
    }

    /**
     * iFirma looks an existing contractor up through the top-level IdentyfikatorKontrahenta, then
     * NIPKontrahenta, and otherwise creates one from the Kontrahent block. The nested
     * Kontrahent.Identyfikator is never sent: the live API answers Kod 201 "Nie znaleziono
     * kontrahenta" for a value it does not know instead of creating the contractor under it.
     *
     * @param array                           $payload
     * @param IfirmaContractorResolution|null $contractorInfo ensure() result; null when the contractor step was skipped
     * @return array
     */
    private function referenceContractor(array $payload, $contractorInfo)
    {
        $identifier = ($contractorInfo !== null && $contractorInfo->getIdentifier() !== null)
            ? trim((string) $contractorInfo->getIdentifier())
            : '';
        $payload['IdentyfikatorKontrahenta'] = $identifier !== '' ? $identifier : null;
        if (isset($payload['Kontrahent']) && is_array($payload['Kontrahent'])) {
            $payload['Kontrahent']['Identyfikator'] = null;
        }

        return $payload;
    }

    /**
     * iFirma decides which contractor a document gets: the referenced one, a NIP match, or a new
     * one. When the module could not reference a contractor it knows (no identifier, or one longer
     * than the document API accepts), read the issued document back and remember the contractor
     * iFirma attached, so the next order of this customer reuses it instead of adding a duplicate.
     * One extra GET, only in those cases; never blocks issuance.
     *
     * @param IfirmaReceipt                   $receipt        issued receipt (ifirma_id set, not yet saved)
     * @param array                           $payload
     * @param IfirmaContractorResolution|null $contractorInfo
     */
    private function learnDocumentContractor(IfirmaReceipt $receipt, array $payload, $contractorInfo)
    {
        if ($contractorInfo === null || (string) $receipt->ifirma_id === '' || (int) $receipt->id_customer <= 0) {
            return;
        }
        if ($contractorInfo->getAction() === IfirmaContractorService::ACTION_DISABLED) {
            return;
        }
        $referenced = $contractorInfo->getIdentifier() !== null ? trim((string) $contractorInfo->getIdentifier()) : '';
        if ($referenced !== '' && mb_strlen($referenced) <= self::DOCUMENT_IDENTIFIER_MAX) {
            return;
        }

        $detail = $this->client->getDetail($receipt->document_type, (string) $receipt->ifirma_id);
        if ($detail === null || !isset($detail['data']) || !is_array($detail['data'])) {
            return;
        }
        $node = (isset($detail['data']['response']) && is_array($detail['data']['response']))
            ? $detail['data']['response']
            : $detail['data'];
        $used = isset($node['Kontrahent']['Identyfikator']) ? trim((string) $node['Kontrahent']['Identyfikator']) : '';
        if ($used === '') {
            return;
        }

        $receipt->request_payload = $this->encodeRequest($payload, $contractorInfo, $used);
        if ($used !== $referenced) {
            try {
                IfirmaContractorMap::link(
                    (int) $receipt->id_customer,
                    $used,
                    IfirmaContractorMap::BY_DOCUMENT,
                    isset($node['Kontrahent']['Nazwa']) ? (string) $node['Kontrahent']['Nazwa'] : ''
                );
            } catch (Exception $e) {
                // the mapping is bookkeeping; the document is already issued
            }
        }
    }

    /**
     * @return IfirmaContractorService
     */
    private function contractorService()
    {
        if ($this->contractors === null) {
            $this->contractors = new IfirmaContractorService($this->client);
        }

        return $this->contractors;
    }

    /**
     * @param IfirmaContractorService $service
     */
    public function setContractorService(IfirmaContractorService $service)
    {
        $this->contractors = $service;
    }

    /**
     * iFirma creates the contractor from the document when no identifier is given; an identifier
     * it does not know is rejected (Kod 201). A null/empty identifier is therefore dropped from the
     * buyer block instead of being sent as JSON null.
     *
     * @param array $payload
     * @return array
     */
    private function sendable(array $payload)
    {
        foreach (array('IdentyfikatorKontrahenta', 'NIPKontrahenta') as $key) {
            if (array_key_exists($key, $payload) && ($payload[$key] === null || $payload[$key] === '')) {
                unset($payload[$key]);
            }
        }
        if (isset($payload['Kontrahent']) && is_array($payload['Kontrahent'])
            && array_key_exists('Identyfikator', $payload['Kontrahent'])
            && ($payload['Kontrahent']['Identyfikator'] === null || $payload['Kontrahent']['Identyfikator'] === '')) {
            unset($payload['Kontrahent']['Identyfikator']);
        }

        return $payload;
    }
    /**
     * Stored request_payload: what was sent plus the private _contractor note (never sent to iFirma).
     *
     * @param array                           $payload
     * @param IfirmaContractorResolution|null $contractorInfo
     * @param string                          $documentIdentifier the contractor the issued document turned out to carry; '' when not read back
     * @return string
     */
    private function encodeRequest(array $payload, $contractorInfo, $documentIdentifier = '')
    {
        $payload = $this->sendable($payload);
        if ($contractorInfo !== null) {
            $payload['_contractor'] = array(
                'action' => $contractorInfo->getAction(),
                'message' => $contractorInfo->getMessage(),
                'matched_by' => $contractorInfo->getMatchedBy(),
                'identifier' => (string) $contractorInfo->getIdentifier(),
            );
            if (is_array($contractorInfo->getNote())) {
                $payload['_contractor']['note'] = $contractorInfo->getNote();
            }
            if ((string) $contractorInfo->getAssignedId() !== '') {
                $payload['_contractor']['assigned_identifier'] = (string) $contractorInfo->getAssignedId();
            }
            if ((string) $documentIdentifier !== '') {
                $payload['_contractor']['document_identifier'] = (string) $documentIdentifier;
            }
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param array $response
     * @return string
     */
    private function extractInformacja(array $response)
    {
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;

        return isset($node['Informacja']) ? (string) $node['Informacja'] : '';
    }

    /**
     * Kod 201 that is (probably) about the contractor reference: the message mentions the
     * contractor at all ("Nie znaleziono kontrahenta...", "IdentyfikatorKontrahenta...", a length
     * validation naming "Identyfikator kontrahenta"), or the reference exceeds the 15 characters the
     * document API documents, in which case any Kod 201 is attributed to it. The exact wording of
     * iFirma's validation messages is not documented, so this is deliberately broad: a wrong guess
     * costs one extra request and the second rejection is reported with its own message.
     *
     * @param array  $response   decoded create response
     * @param string $identifier the IdentyfikatorKontrahenta that was sent
     * @return bool
     */
    private function contractorReferenceRejected(array $response, $identifier)
    {
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;

        if (!isset($node['Kod']) || (int) $node['Kod'] !== 201) {
            return false;
        }
        if (mb_strlen((string) $identifier) > self::DOCUMENT_IDENTIFIER_MAX) {
            return true;
        }
        $info = isset($node['Informacja']) ? (string) $node['Informacja'] : '';

        return mb_stripos($info, 'kontrahent') !== false;
    }

    /**
     * The optional DodatkoweUwagi object is the only non-mandatory structure the module sends. It is
     * dropped for one retry when iFirma names it in a Kod 201, or answers Kod 200 (the JSON could not
     * be mapped, a message that never names the field) while the object was present.
     *
     * @param array $response decoded create response
     * @param array $payload  what was sent
     * @return bool
     */
    private function dodatkoweUwagiRejected(array $response, array $payload)
    {
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;
        if (!isset($node['Kod'])) {
            return false;
        }
        $kod = (int) $node['Kod'];
        if ($kod === 201) {
            return isset($node['Informacja']) && stripos((string) $node['Informacja'], 'DodatkoweUwagi') !== false;
        }

        return $kod === 200 && isset($payload['DodatkoweUwagi']);
    }

    /**
     * An amount given by the caller: a string (a form value) must be a positive number with at most
     * two decimals, a number computed by the module is rounded; both must not exceed the order total.
     *
     * @param mixed $amount
     * @param int   $idOrder
     * @return float
     * @throws Exception
     */
    private function validatedAmount($amount, $idOrder)
    {
        $invalid = $this->l('The amount must be a positive number with at most two decimals.');
        if (is_int($amount) || is_float($amount)) {
            $value = round((float) $amount, 2);
        } else {
            $value = IfirmaInput::decimal($amount);
        }
        if ($value === null || $value <= 0) {
            throw new Exception($invalid);
        }

        $order = new Order($idOrder);
        if (Validate::isLoadedObject($order)) {
            $total = round((float) $order->total_paid_tax_incl, 2);
            if ($value > $total) {
                throw new Exception($this->l('The amount cannot exceed the order total (%s).', number_format($total, 2, '.', '')));
            }
        }

        return round($value, 2);
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param mixed|null    $amount
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
            $amount = $this->payloadBuilder->getPaidAmount($order);
            $amount = round((float) $amount, 2);
        } else {
            $amount = $this->validatedAmount($amount, (int) $receipt->id_order);
        }

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

        $receipt->markPaid();
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
     * Matching hints for IfirmaContractorService::ensure().
     *
     * @param Order      $order
     * @param array      $contractor buildContractor() result
     * @param array|null $override   approved buyer data
     * @return array nip (digits), email, postcode, name
     */
    public function contractorHints(Order $order, array $contractor, $override = null)
    {
        if (is_array($override)) {
            $vat = isset($override['vat_number']) ? (string) $override['vat_number'] : '';
        } else {
            $address = new Address((int) $order->id_address_invoice);
            $vat = trim((string) $address->vat_number . $address->dni);
        }
        $customer = new Customer((int) $order->id_customer);

        return array(
            'nip' => preg_replace('/\D/', '', $vat),
            'email' => (string) $customer->email,
            'postcode' => isset($contractor['KodPocztowy']) ? (string) $contractor['KodPocztowy'] : '',
            'name' => isset($contractor['Nazwa']) ? (string) $contractor['Nazwa'] : '',
        );
    }

    /**
     * @return string
     */
    public function getDocumentType()
    {
        return IfirmaDocumentSettings::normalizeDocumentType(Configuration::get('IFIRMA_DOC_TYPE'));
    }

    /**
     * @return string
     */
    private function generateToken()
    {
        return self::generateSecurityToken();
    }

    /**
     * Test seam: callable(int $length): string replacing random_bytes() (spec customer-receipt-visibility,
     * "Download token generation").
     *
     * @var callable|null
     */
    public static $randomBytes = null;

    /**
     * The 40-character download token of a receipt, from the secure random generator only. When that
     * generator is unavailable the token is '' (the record is stored, the customer download is not
     * offered) and the failure is logged; a weaker token is never made up.
     *
     * @return string
     */
    public static function generateSecurityToken()
    {
        try {
            $bytes = self::$randomBytes !== null ? call_user_func(self::$randomBytes, 20) : random_bytes(20);

            return bin2hex((string) $bytes);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('iFirma: the secure random generator is unavailable, download token not set: ' . $e->getMessage(), 3);

            return '';
        }
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
