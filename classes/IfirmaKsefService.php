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

/**
 * Submission of issued iFirma documents to KSeF (Krajowy System e-Faktur).
 *
 * Contract:
 *  - mode() -> off|b2b|all from IFIRMA_KSEF_MODE (default b2b).
 *  - isBusinessBuyer(receipt, order) -> bool; true when the buyer has a NIP (stored request payload
 *    Kontrahent.NIP, else the order's invoice address vat_number / dni).
 *  - skipReason(receipt, order) -> '' when the document should be submitted, otherwise the reason.
 *  - shouldSubmit(receipt, order) -> bool (skipReason() === '').
 *  - submit(receipt, order, force) -> array(ok, message); records ksef_status / ksef_sent_at / ksef_error
 *    on the receipt. Never throws. force bypasses the mode and eligibility but never "already accepted".
 *  - describe(receipt, order) -> human text for the admin panel.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaSchema.php';

class IfirmaKsefService
{
    const MODE_OFF = 'off';
    const MODE_B2B = 'b2b';
    const MODE_ALL = 'all';

    /** Module-side marker: sent, iFirma has not reported a KSeF status yet. */
    const STATUS_SENT = 'WYSLANA';
    const STATUS_ACCEPTED = 'PRZYJETA';
    const STATUS_ERROR = 'BLAD';

    /** Document types the iFirma KSeF endpoint accepts. */
    const SUPPORTED_TYPES = array(
        'fakturakraj', 'fakturakraj/korekta', 'fakturawysylka', 'fakturazaliczka', 'fakturakoncowa',
        'fakturaeksporttowarow', 'fakturawdt', 'fakturaeksportuslugue', 'fakturaeksportuslug',
        'fakturawaluta', 'fakturabudowa', 'fakturaparagon', 'fakturaoss',
    );

    /** @var IfirmaApiClient */
    private $client;

    /** @var Module */
    private $module;

    /**
     * @param IfirmaApiClient $client
     * @param Module          $module
     */
    public function __construct(IfirmaApiClient $client, Module $module)
    {
        $this->client = $client;
        $this->module = $module;
    }

    /**
     * @param Module $module
     * @return IfirmaKsefService
     */
    public static function build(Module $module)
    {
        IfirmaSchema::ensureReceiptKsefColumns();
        $client = IfirmaApiClient::fromConfiguration();

        return new self($client, $module);
    }

    /**
     * @param mixed $value
     * @return string off|b2b|all; anything else is b2b
     */
    public static function normalizeMode($value)
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, array(self::MODE_OFF, self::MODE_B2B, self::MODE_ALL), true) ? $value : self::MODE_B2B;
    }

    /**
     * @return string
     */
    public function mode()
    {
        return self::normalizeMode(Configuration::get('IFIRMA_KSEF_MODE'));
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return bool
     */
    public function isBusinessBuyer(IfirmaReceipt $receipt, ?Order $order = null)
    {
        $payload = json_decode((string) $receipt->request_payload, true);
        if (is_array($payload) && isset($payload['Kontrahent']) && is_array($payload['Kontrahent'])) {
            $nip = isset($payload['Kontrahent']['NIP']) ? (string) $payload['Kontrahent']['NIP'] : '';

            return preg_replace('/\D+/', '', $nip) !== '';
        }

        if ($order === null) {
            return false;
        }
        $address = new Address((int) $order->id_address_invoice);
        foreach (array('vat_number', 'dni') as $field) {
            if (!empty($address->{$field}) && preg_replace('/\D+/', '', (string) $address->{$field}) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @return bool true when KSeF already accepted the document
     */
    public function isAccepted(IfirmaReceipt $receipt)
    {
        return trim((string) $receipt->ksef_number) !== '' || (string) $receipt->ksef_status === self::STATUS_ACCEPTED;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return string '' when the document should be submitted, otherwise the reason it should not
     */
    public function skipReason(IfirmaReceipt $receipt, ?Order $order = null)
    {
        $mode = $this->mode();
        if ($mode === self::MODE_OFF) {
            return $this->module->l('KSeF disabled', 'ifirmaksefservice');
        }
        if (!$receipt->isIssued()) {
            return $this->module->l('document not issued', 'ifirmaksefservice');
        }
        if (!in_array((string) $receipt->document_type, self::SUPPORTED_TYPES, true)) {
            return $this->module->l('type not supported', 'ifirmaksefservice');
        }
        if ($this->isAccepted($receipt) || (string) $receipt->ksef_status === self::STATUS_SENT) {
            return $this->module->l('already in KSeF', 'ifirmaksefservice');
        }
        if ($mode === self::MODE_B2B && !$this->isBusinessBuyer($receipt, $order)) {
            return $this->module->l('private buyer (B2B mode)', 'ifirmaksefservice');
        }

        return '';
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return bool
     */
    public function shouldSubmit(IfirmaReceipt $receipt, ?Order $order = null)
    {
        return $this->skipReason($receipt, $order) === '';
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @param bool          $force bypass the mode and eligibility (never "already accepted")
     * @return array array('ok' => bool, 'message' => string)
     */
    public function submit(IfirmaReceipt $receipt, ?Order $order = null, $force = false)
    {
        if (!$receipt->isIssued()) {
            return array('ok' => false, 'message' => $this->module->l('The document has not been issued yet.', 'ifirmaksefservice'));
        }
        if ($this->isAccepted($receipt)) {
            return array('ok' => false, 'message' => $this->module->l('already in KSeF', 'ifirmaksefservice'));
        }
        if (!$force) {
            $reason = $this->skipReason($receipt, $order);
            if ($reason !== '') {
                return array('ok' => false, 'message' => $reason);
            }
        }

        $error = '';
        $info = '';
        try {
            $response = $this->client->sendToKsef($receipt->document_type, $receipt->ifirma_id);
            $node = array();
            if (is_array($response)) {
                $node = (isset($response['response']) && is_array($response['response'])) ? $response['response'] : $response;
            }
            $info = isset($node['Informacja']) ? trim((string) $node['Informacja']) : '';
            if (!isset($node['Kod']) || (int) $node['Kod'] !== 0) {
                $error = trim((string) $this->client->getLastError());
                if ($error === '') {
                    $error = $info !== '' ? $info : $this->module->l('iFirma did not confirm the KSeF submission', 'ifirmaksefservice');
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error === '') {
            $receipt->ksef_status = self::STATUS_SENT;
            $receipt->ksef_sent_at = date('Y-m-d H:i:s');
            $receipt->ksef_error = '';
            $this->persist($receipt);

            return array('ok' => true, 'message' => $info);
        }

        $receipt->ksef_status = self::STATUS_ERROR;
        $receipt->ksef_error = $error;
        $this->persist($receipt);

        return array('ok' => false, 'message' => $error);
    }

    /**
     * Human description of the KSeF state for the admin panel.
     *
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return string
     */
    public function describe(IfirmaReceipt $receipt, ?Order $order = null)
    {
        $status = (string) $receipt->ksef_status;
        $number = trim((string) $receipt->ksef_number);

        if ($number !== '') {
            return $this->module->l('accepted', 'ifirmaksefservice') . ': ' . $number;
        }
        if ($status === self::STATUS_ACCEPTED) {
            return $this->module->l('accepted', 'ifirmaksefservice');
        }
        if ($status === self::STATUS_ERROR) {
            return $this->module->l('error', 'ifirmaksefservice') . ': ' . (string) $receipt->ksef_error;
        }
        if ($status === self::STATUS_SENT) {
            return sprintf(
                $this->module->l('sent %s, awaiting KSeF', 'ifirmaksefservice'),
                substr((string) $receipt->ksef_sent_at, 0, 16)
            );
        }
        if ($status !== '') {
            return $status;
        }

        $text = $this->module->l('not sent', 'ifirmaksefservice');
        $reason = $receipt->isIssued() ? $this->skipReason($receipt, $order) : '';
        if ($reason !== '') {
            $text .= ' (' . $reason . ')';
        }

        return $text;
    }

    /**
     * @param IfirmaReceipt $receipt
     */
    private function persist(IfirmaReceipt $receipt)
    {
        try {
            $receipt->save();
        } catch (Exception $e) {
            // Recording must never break the caller.
            return;
        }
    }
}
