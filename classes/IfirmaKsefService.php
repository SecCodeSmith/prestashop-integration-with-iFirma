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
 *  - goesToKsef(receipt, order) -> bool; whether the document belongs in KSeF under the mode (issued, supported
 *    type, business buyer unless the mode is all). It ignores the submitter and the current state.
 *  - submitter() -> module|ifirma from IFIRMA_KSEF_SUBMITTER (default module).
 *  - submit(receipt, order, force) -> array(ok, message, outcome, rate_limited); outcome is sent, failed, refused
 *    (no call was made, the receipt is unchanged), already (iFirma already holds the document in KSeF: its state
 *    is stored, no call) or deferred (the state could not be read: no call, nothing changes, the cron retries).
 *    Before any call it reads the document's KSeF state from iFirma and submits only when that state is empty (a
 *    rejected document only when force is set). It records ksef_status / ksef_sent_at / ksef_error on the
 *    receipt. Never throws. force bypasses the mode, the submitter and eligibility but never "already accepted".
 *    A submission is recorded as sent (WYSLANA) only when iFirma answers Kod 0. Every other outcome, an
 *    error raised during the call included, is BLAD with a non-empty ksef_error, which is "iFirma did not
 *    confirm the KSeF submission" when the failure carries no text of its own.
 *  - describe(receipt, order) -> human text for the admin panel.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaSchema.php';
require_once dirname(__FILE__) . '/IfirmaKsefStatus.php';
require_once dirname(__FILE__) . '/IfirmaKsefStateReader.php';

class IfirmaKsefService
{
    const MODE_OFF = 'off';
    const MODE_B2B = 'b2b';
    const MODE_ALL = 'all';

    /** Who submits the documents that go to KSeF. */
    const SUBMITTER_MODULE = 'module';
    const SUBMITTER_IFIRMA = 'ifirma';

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

    /** @var IfirmaKsefStateReader */
    private $reader;

    /**
     * @param IfirmaApiClient            $client
     * @param Module                     $module
     * @param IfirmaKsefStateReader|null $reader
     */
    public function __construct(IfirmaApiClient $client, Module $module, ?IfirmaKsefStateReader $reader = null)
    {
        $this->client = $client;
        $this->module = $module;
        $this->reader = $reader !== null ? $reader : new IfirmaKsefStateReader($client);
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
     * @param mixed $value
     * @return string module|ifirma; anything else is module
     */
    public static function normalizeSubmitter($value)
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        return $value === self::SUBMITTER_IFIRMA ? self::SUBMITTER_IFIRMA : self::SUBMITTER_MODULE;
    }

    /**
     * @return string who submits the documents that go to KSeF: module (default) or ifirma
     */
    public function submitter()
    {
        return self::normalizeSubmitter(Configuration::get('IFIRMA_KSEF_SUBMITTER'));
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
     * Whether the document belongs in KSeF under the configured mode. It does not look at who submits or at the state
     * of the document, so the pipeline, the delivery gate and the cron jobs share this one definition.
     *
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return bool
     */
    public function goesToKsef(IfirmaReceipt $receipt, ?Order $order = null)
    {
        $mode = $this->mode();

        return $mode !== self::MODE_OFF
            && $receipt->isIssued()
            && in_array((string) $receipt->document_type, self::SUPPORTED_TYPES, true)
            && ($mode === self::MODE_ALL || $this->isBusinessBuyer($receipt, $order));
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
        if ($this->submitter() === self::SUBMITTER_IFIRMA) {
            return $this->module->l('sent by iFirma', 'ifirmaksefservice');
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
     * @return array array('ok' => bool, 'message' => string, 'outcome' => string, 'rate_limited' => bool)
     */
    public function submit(IfirmaReceipt $receipt, ?Order $order = null, $force = false)
    {
        if (!$receipt->isIssued()) {
            return $this->answer(false, 'refused', $this->module->l('The document has not been issued yet.', 'ifirmaksefservice'));
        }
        if ($this->isAccepted($receipt)) {
            return $this->answer(false, 'refused', $this->module->l('already in KSeF', 'ifirmaksefservice'));
        }
        if (!$force) {
            $reason = $this->skipReason($receipt, $order);
            if ($reason !== '') {
                return $this->answer(false, 'refused', $reason);
            }
        }

        $remote = $this->reader->read((string) $receipt->document_type, (string) $receipt->ifirma_id);
        if (!$remote->isReadable()) {
            return $this->answer(
                false,
                'deferred',
                sprintf($this->module->l('KSeF state unavailable: %s', 'ifirmaksefservice'), $remote->getError()),
                $remote->isRateLimited()
            );
        }
        $heldAlready = $this->refuseWhenIfirmaHoldsIt($receipt, $remote, $force);
        if ($heldAlready !== null) {
            return $heldAlready;
        }

        $failed = false;
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
                $failed = true;
                $error = trim((string) $this->client->getLastError());
                if ($error === '') {
                    $error = $info;
                }
            }
        } catch (Throwable $e) {
            $failed = true;
            $error = trim($e->getMessage());
        }

        if (!$failed) {
            $receipt->ksef_status = self::STATUS_SENT;
            $receipt->ksef_sent_at = date('Y-m-d H:i:s');
            $receipt->ksef_error = '';
            $this->persist($receipt);

            return $this->answer(true, 'sent', $info);
        }

        if ($error === '') {
            $error = $this->module->l('iFirma did not confirm the KSeF submission', 'ifirmaksefservice');
        }
        $receipt->ksef_status = self::STATUS_ERROR;
        $receipt->ksef_error = $error;
        $this->persist($receipt);

        return $this->answer(false, 'failed', $error);
    }

    /**
     * What iFirma reports decides whether a call may go out: nothing is sent while iFirma holds the document in KSeF
     * (sent, scheduled, accepted, or any status the module does not know), and a rejected document is only sent again
     * on the employee's decision. The reported state is stored on the receipt.
     *
     * @param IfirmaReceipt         $receipt
     * @param IfirmaKsefRemoteState $remote
     * @param bool                  $force
     * @return array|null the answer when no call may go out, null to go on
     */
    private function refuseWhenIfirmaHoldsIt(IfirmaReceipt $receipt, IfirmaKsefRemoteState $remote, $force)
    {
        $class = IfirmaKsefStatus::classify($remote->getStatus(), $remote->getNumber());
        if ($class === IfirmaKsefStatus::NONE) {
            return null;
        }
        if ($class === IfirmaKsefStatus::REJECTED && $force) {
            return null;
        }

        $this->storeReportedState($receipt, $remote);
        if ($class === IfirmaKsefStatus::REJECTED) {
            return $this->answer(false, 'refused', $this->module->l('rejected by KSeF, send it again by hand', 'ifirmaksefservice'));
        }
        if ($force) {
            return $this->answer(false, 'already', sprintf(
                $this->module->l('iFirma has already sent or scheduled this document (status %s).', 'ifirmaksefservice'),
                $remote->getStatus() !== '' ? $remote->getStatus() : $remote->getNumber()
            ));
        }

        return $this->answer(false, 'already', $class === IfirmaKsefStatus::ACCEPTED
            ? $this->module->l('already in KSeF', 'ifirmaksefservice')
            : $this->module->l('already sent by iFirma', 'ifirmaksefservice'));
    }

    /**
     * @param IfirmaReceipt         $receipt
     * @param IfirmaKsefRemoteState $remote
     */
    private function storeReportedState(IfirmaReceipt $receipt, IfirmaKsefRemoteState $remote)
    {
        if ($remote->getStatus() !== '') {
            $receipt->ksef_status = $remote->getStatus();
        }
        if ($remote->getNumber() !== '') {
            $receipt->ksef_number = $remote->getNumber();
        }
        $this->persist($receipt);
    }

    /**
     * @param bool   $ok
     * @param string $outcome     sent | failed | refused | already | deferred
     * @param string $message
     * @param bool   $rateLimited whether the rate limit is why nothing was sent
     * @return array
     */
    private function answer($ok, $outcome, $message, $rateLimited = false)
    {
        return array('ok' => (bool) $ok, 'message' => (string) $message, 'outcome' => $outcome, 'rate_limited' => (bool) $rateLimited);
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
        if (IfirmaKsefStatus::isRejected($status)) {
            return $this->module->l('error', 'ifirmaksefservice') . ': ' . $this->module->l('rejected by KSeF', 'ifirmaksefservice');
        }
        if ($status === self::STATUS_SENT && trim((string) $receipt->ksef_sent_at) !== '') {
            return sprintf(
                $this->module->l('sent %s, awaiting KSeF', 'ifirmaksefservice'),
                substr((string) $receipt->ksef_sent_at, 0, 16)
            );
        }
        if ($status !== '') {
            // reported by iFirma: it sent the document, or scheduled it, itself
            return $this->module->l('sent or scheduled by iFirma', 'ifirmaksefservice') . ': ' . $status;
        }
        if ($receipt->isIssued() && $this->submitter() === self::SUBMITTER_IFIRMA && $this->goesToKsef($receipt, $order)) {
            return $this->module->l('waiting for iFirma to send the document', 'ifirmaksefservice');
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
