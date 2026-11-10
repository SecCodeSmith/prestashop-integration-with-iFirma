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
 * Delivery of issued iFirma documents to the customer.
 *
 * Channels: shop_mail (default, PDF attached through the shop mail system),
 * ifirma_mail (POST {type}/send/{id}.json, documented types only, otherwise
 * automatic fallback to shop mail recorded as shop_mail_fallback) and none.
 *
 * Contract:
 *  - deliver(receipt, order, options) -> IfirmaDeliveryResult (isSent(), getStatus() sent|failed|skipped|
 *    disabled, getChannel(), getMessage()); options: manual (bool), channel (override).
 *    Never throws; records the outcome on the receipt.
 *  - resend(receipt, order) = deliver() with manual => true.
 *  - sendViaIfirma() / sendViaShopMail() -> array(ok, message), and on a failure the failure class (temporary,
 *    permanent, unknown_outcome, refused) that decides whether the cron tries again: see IfirmaDeliveryRetryPolicy.
 *    An automatic failure stores the attempts used and the due time of the next one; a manual resend leaves both.
 *  - describeDelivery(receipt) -> short human string for the admin panel.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaReceiptService.php';
require_once dirname(__FILE__) . '/IfirmaPayloadBuilder.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryResult.php';
require_once dirname(__FILE__) . '/IfirmaShopLink.php';
require_once dirname(__FILE__) . '/IfirmaKsefDeliveryGate.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryRetryPolicy.php';
require_once dirname(__FILE__) . '/IfirmaNamedLock.php';
require_once dirname(__FILE__) . '/IfirmaSchema.php';

class IfirmaDeliveryService
{
    const CHANNEL_SHOP_MAIL = 'shop_mail';
    const CHANNEL_IFIRMA_MAIL = 'ifirma_mail';
    const CHANNEL_NONE = 'none';
    const CHANNEL_SHOP_MAIL_FALLBACK = 'shop_mail_fallback';

    const MAIL_TEMPLATE = 'receipt_issued';
    const TEXT_MAX = 1000;
    const EXTERNAL_REASON = 'external document - not auto-delivered';

    /** Total PDF download attempts in a shop-mail delivery, and the waits (seconds) between them. */
    const PDF_ATTEMPTS = 3;
    const PDF_RETRY_WAITS = array(3, 7);

    /** iFirma answer codes that a retry cannot fix (authentication, configuration). */
    const PDF_PERMANENT_KODS = array(400, 401, 402, 403, 499, 500);

    /** The order's lock, the name the issuance pipeline, the queue job and the delivery retry job take. */
    const ORDER_LOCK_PREFIX = 'ifirma_order_';

    /** Seconds a manual resend waits for a delivery of the same document that is already running. */
    const RESEND_LOCK_WAIT = 5;

    const COPY_NONE = 'none';
    const COPY_SHOP = 'shop';
    const COPY_EMPLOYEE = 'employee';

    /** Document types accepted by the iFirma send endpoint (fakturaparagon is not one). */
    const IFIRMA_SEND_SUPPORTED_TYPES = array(
        'fakturakraj', 'fakturawysylka', 'fakturaproformakraj', 'fakturaeksporttowarow',
        'fakturawdt', 'fakturaeksportuslugue', 'fakturaeksportuslug', 'fakturawaluta',
    );

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaReceiptService */
    private $service;

    /** @var Module */
    private $module;

    /** @var callable|null returns a unix timestamp */
    private $clock;

    /** @var callable|null receives the seconds to wait */
    private $sleeper;

    /** @var IfirmaDeliveryRetryPolicy */
    private $retryPolicy;

    /**
     * @param IfirmaApiClient                $client
     * @param IfirmaReceiptService           $service
     * @param Module                         $module
     * @param callable|null                  $clock
     * @param callable|null                  $sleeper     receives seconds; defaults to sleep()
     * @param IfirmaDeliveryRetryPolicy|null $retryPolicy when the next automatic attempt is due
     */
    public function __construct(
        IfirmaApiClient $client,
        IfirmaReceiptService $service,
        Module $module,
        $clock = null,
        $sleeper = null,
        ?IfirmaDeliveryRetryPolicy $retryPolicy = null
    ) {
        $this->retryPolicy = $retryPolicy !== null ? $retryPolicy : new IfirmaDeliveryRetryPolicy();
        $this->sleeper = $sleeper;
        $this->client = $client;
        $this->service = $service;
        $this->module = $module;
        $this->clock = $clock;
    }

    /**
     * @param Module $module
     * @return IfirmaDeliveryService
     */
    public static function build(Module $module)
    {
        // a missing retry column makes every save of the record fail, and a delivery whose outcome cannot be saved is repeated
        IfirmaSchema::ensureReceiptRetryColumns();
        $client = IfirmaApiClient::fromConfiguration();

        return new self($client, new IfirmaReceiptService($client, $module), $module);
    }

    /**
     * Configured channel; invalid or empty values mean shop_mail.
     *
     * @return string
     */
    public function getChannel()
    {
        $value = (string) Configuration::get('IFIRMA_SEND_CHANNEL');

        return $this->isChannel($value) ? $value : self::CHANNEL_SHOP_MAIL;
    }

    /**
     * @return string[]
     */
    public static function copyModes()
    {
        return array(self::COPY_NONE, self::COPY_SHOP, self::COPY_EMPLOYEE);
    }

    /**
     * Configured copy mode; missing or unknown values mean none.
     *
     * @return string
     */
    public function getCopyMode()
    {
        $value = (string) Configuration::get('IFIRMA_SEND_COPY_MODE');

        return in_array($value, self::copyModes(), true) ? $value : self::COPY_NONE;
    }

    /**
     * Delivers the document and records the outcome on the receipt.
     *
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @param array         $options manual (bool), channel (override)
     * @return IfirmaDeliveryResult
     */
    public function deliver(IfirmaReceipt $receipt, ?Order $order = null, array $options = array())
    {
        $manual = !empty($options['manual']);
        $channel = $this->getChannel();
        if (!empty($options['channel']) && $this->isChannel((string) $options['channel'])) {
            $channel = (string) $options['channel'];
        }

        if (!$receipt->isIssued()) {
            return $this->result(false, 'failed', $channel, $this->module->l('The document has not been issued yet.', 'ifirmadeliveryservice'));
        }

        if ($channel === self::CHANNEL_NONE) {
            if ($manual) {
                return $this->result(false, 'disabled', $channel, $this->module->l('Delivery is disabled (channel none)', 'ifirmadeliveryservice'));
            }
            $receipt->recordDelivery(IfirmaReceipt::DELIVERY_SKIPPED, self::CHANNEL_NONE, '', $receipt->getDeliveryAttempts(), null);
            $receipt->delivered_at = null;
            $this->persist($receipt);

            return $this->result(true, 'skipped', self::CHANNEL_NONE, '');
        }

        if (!$manual && $receipt->source === IfirmaReceipt::SOURCE_EXTERNAL) {
            $receipt->recordDelivery(IfirmaReceipt::DELIVERY_SKIPPED, $channel, self::EXTERNAL_REASON, $receipt->getDeliveryAttempts(), null);
            $this->persist($receipt);

            return $this->result(true, 'skipped', $channel, self::EXTERNAL_REASON);
        }

        $effective = $channel;
        try {
            if ($order === null) {
                $order = new Order($receipt->id_order);
            }

            if ($channel === self::CHANNEL_IFIRMA_MAIL && in_array($receipt->document_type, self::IFIRMA_SEND_SUPPORTED_TYPES, true)) {
                $res = $this->sendViaIfirma($receipt, $order);
            } else {
                if ($channel === self::CHANNEL_IFIRMA_MAIL) {
                    $effective = self::CHANNEL_SHOP_MAIL_FALLBACK;
                }
                $res = $this->sendViaShopMail($receipt, $order);
            }
        } catch (Exception $e) {
            $res = $this->failed($e->getMessage(), IfirmaDeliveryRetryPolicy::FAILURE_TEMPORARY);
        }

        if (!empty($res['ok'])) {
            $receipt->recordDelivery(IfirmaReceipt::DELIVERY_SENT, $effective, '', $receipt->getDeliveryAttempts(), null);
            $receipt->delivered_at = date('Y-m-d H:i:s', $this->now());
            $this->persist($receipt);

            return $this->result(true, 'sent', $effective, '');
        }

        $message = isset($res['message']) ? (string) $res['message'] : '';
        if ($manual) {
            // a call the client refused itself (rate guard, credentials cool-down) never reached iFirma: report
            // it and leave the recorded delivery state as it was
            $refusal = $this->localRefusal();
            if ($refusal !== '') {
                return new IfirmaDeliveryResult(false, 'failed', $effective, $message, $refusal);
            }
        }
        if (IfirmaKsefDeliveryGate::isWaitingState($receipt->delivery_status, $receipt->ksef_status, $receipt->ksef_number)) {
            // a failed resend must not open the download of a document KSeF has not accepted: it keeps waiting
            $receipt->delivery_error = $message;
        } else {
            $this->recordFailure($receipt, $effective, $message, $this->failureClassOf($receipt, $res), $manual);
        }
        $this->persist($receipt);

        return $this->result(false, 'failed', $effective, $message);
    }

    /**
     * Records a failed delivery. An automatic attempt applies the retry policy in the same write; a manual resend
     * leaves the automatic attempts and the due time alone, so an employee's click neither burns nor resets them.
     *
     * @param IfirmaReceipt $receipt
     * @param string        $channel
     * @param string        $message
     * @param string        $failure one of the IfirmaDeliveryRetryPolicy::FAILURE_* constants
     * @param bool          $manual
     */
    private function recordFailure(IfirmaReceipt $receipt, $channel, $message, $failure, $manual)
    {
        if ($manual) {
            $receipt->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, $channel, $message);

            return;
        }
        $used = $receipt->getDeliveryAttempts();
        $receipt->recordDelivery(
            IfirmaReceipt::DELIVERY_FAILED,
            $channel,
            $message,
            $this->retryPolicy->attemptsAfter($used, $failure),
            $this->retryPolicy->dueAfter($used, $failure, $this->now())
        );
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param array         $res     the failed result of a send
     * @return string one of the IfirmaDeliveryRetryPolicy::FAILURE_* constants
     */
    private function failureClassOf(IfirmaReceipt $receipt, array $res)
    {
        $failure = isset($res['failure']) ? (string) $res['failure'] : IfirmaDeliveryRetryPolicy::FAILURE_TEMPORARY;
        // a document iFirma no longer has cannot be sent however often it is tried
        if ($failure === IfirmaDeliveryRetryPolicy::FAILURE_TEMPORARY && !empty($receipt->remote_missing)) {
            return IfirmaDeliveryRetryPolicy::FAILURE_PERMANENT;
        }

        return $failure;
    }

    /**
     * @param string $message
     * @param string $failure one of the IfirmaDeliveryRetryPolicy::FAILURE_* constants
     * @return array array('ok' => false, 'message' => string, 'failure' => string)
     */
    private function failed($message, $failure)
    {
        return array('ok' => false, 'message' => $message, 'failure' => $failure);
    }

    /**
     * Why the last client call failed, from what the client reports. A call the client refused itself (rate guard,
     * credentials cool-down) was never sent and uses no attempt; one that was never sent for another reason
     * (a missing key) needs the merchant; a rejected login or an invalid request will not get better; a send that
     * went out and got no answer may have been delivered; anything else (a download that is not ready, HTTP 5xx,
     * a Kod that is not on the permanent list) may pass.
     *
     * @param bool $mailRequest true for the POST that makes iFirma send the e-mail, whose lost answer is unknown
     * @return string one of the IfirmaDeliveryRetryPolicy::FAILURE_* constants
     */
    private function clientFailureClass($mailRequest)
    {
        if ($this->localRefusal() !== '') {
            return IfirmaDeliveryRetryPolicy::FAILURE_REFUSED;
        }
        if (!$this->client->lastRequestWasSent()) {
            return IfirmaDeliveryRetryPolicy::FAILURE_PERMANENT;
        }
        $httpCode = (int) $this->client->getLastHttpCode();
        // a send that went out and was answered by a gateway error or an unreadable 2xx may have been delivered
        if ($mailRequest && ($httpCode === 0 || $httpCode >= 500 || ($httpCode < 400 && $this->client->getLastKod() === null))) {
            return IfirmaDeliveryRetryPolicy::FAILURE_UNKNOWN_OUTCOME;
        }
        if (in_array((int) $this->client->getLastKod(), self::PDF_PERMANENT_KODS, true) || in_array($httpCode, array(401, 403), true)) {
            return IfirmaDeliveryRetryPolicy::FAILURE_PERMANENT;
        }

        return IfirmaDeliveryRetryPolicy::FAILURE_TEMPORARY;
    }

    /**
     * @param mixed $customer
     * @return bool
     */
    private function hasDeliverableAddress($customer)
    {
        return is_object($customer) && Validate::isEmail((string) $customer->email);
    }

    /**
     * Brings the delivery fields of the caller's object up to date with the stored row, so the delivery that follows
     * neither repeats a delivery that ended meanwhile nor writes an outdated retry schedule back.
     *
     * @param IfirmaReceipt $target
     * @param IfirmaReceipt $stored
     */
    private function adoptDeliveryState(IfirmaReceipt $target, IfirmaReceipt $stored)
    {
        foreach (array('delivery_status', 'delivery_channel', 'delivery_error', 'delivered_at', 'delivery_attempts', 'delivery_retry_at') as $field) {
            $target->$field = $stored->$field;
        }
    }

    /**
     * @return string '' | rate_limited | auth_blocked: why the last client call was refused locally
     */
    private function localRefusal()
    {
        if ($this->client->isRateLimited()) {
            return 'rate_limited';
        }

        return $this->client->lastCallWasAuthBlocked() ? 'auth_blocked' : '';
    }

    /**
     * Manual (admin) delivery through the configured channel.
     *
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return IfirmaDeliveryResult
     */
    public function resend(IfirmaReceipt $receipt, ?Order $order = null)
    {
        // the cron retry delivers under this lock: an employee and the cron never send the same document at once
        $lock = self::ORDER_LOCK_PREFIX . (int) $receipt->id_order;
        if (!IfirmaNamedLock::acquire($lock, self::RESEND_LOCK_WAIT)) {
            return $this->result(
                false,
                'failed',
                $this->getChannel(),
                $this->module->l('Another delivery of this document is in progress. Try again in a moment.', 'ifirmadeliveryservice')
            );
        }
        try {
            // the caller loaded the record before the lock: a delivery that ended meanwhile must not be overwritten
            if (!empty($receipt->id)) {
                $this->adoptDeliveryState($receipt, IfirmaReceipt::loadFresh((int) $receipt->id));
            }

            return $this->deliver($receipt, $order, array('manual' => true));
        } finally {
            IfirmaNamedLock::release($lock);
        }
    }

    /**
     * Sends through the iFirma send endpoint; only Kod 0 is success.
     *
     * @param IfirmaReceipt $receipt
     * @param Order         $order
     * @return array array('ok' => bool, 'message' => string)
     */
    public function sendViaIfirma(IfirmaReceipt $receipt, Order $order)
    {
        $customer = $order->getCustomer();
        if (!$this->hasDeliverableAddress($customer)) {
            return $this->failed($this->noAddressMessage(), IfirmaDeliveryRetryPolicy::FAILURE_PERMANENT);
        }
        $body = array(
            'Tekst' => IfirmaPayloadBuilder::truncate((string) Configuration::get('IFIRMA_SEND_TEXT'), self::TEXT_MAX),
            'SkrzynkaEmailOdbiorcy' => (string) $customer->email,
        );

        $response = $this->client->send($receipt->document_type, $receipt->ifirma_id, $body);
        if (is_array($response)) {
            $node = (isset($response['response']) && is_array($response['response'])) ? $response['response'] : $response;
            if (isset($node['Kod']) && (int) $node['Kod'] === 0) {
                return array('ok' => true, 'message' => '');
            }
        }

        $error = (string) $this->client->getLastError();

        return $this->failed(
            $error !== '' ? $error : $this->module->l('iFirma did not confirm the e-mail', 'ifirmadeliveryservice'),
            $this->clientFailureClass(true)
        );
    }

    /**
     * @return string
     */
    private function noAddressMessage()
    {
        return $this->module->l('The customer has no e-mail address.', 'ifirmadeliveryservice');
    }

    /**
     * Sends the PDF through the shop mail system.
     *
     * @param IfirmaReceipt $receipt
     * @param Order         $order
     * @return array array('ok' => bool, 'message' => string)
     */
    public function sendViaShopMail(IfirmaReceipt $receipt, Order $order)
    {
        try {
            $pdf = $this->fetchPdfWithRetry($receipt);
        } catch (Exception $e) {
            return $this->failed($e->getMessage(), $this->clientFailureClass(false));
        }

        $customer = $order->getCustomer();
        if (!$this->hasDeliverableAddress($customer)) {
            return $this->failed($this->noAddressMessage(), IfirmaDeliveryRetryPolicy::FAILURE_PERMANENT);
        }

        $copy = $this->copyRecipient($order, $customer);

        $idLang = $this->resolveLanguage($order);
        $number = (string) $receipt->ifirma_number;
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $label = $receipt->document_type === 'fakturakraj'
            ? $this->module->l('Invoice', 'ifirmadeliveryservice')
            : $this->module->l('Receipt', 'ifirmadeliveryservice');
        $subject = $shopName . ': ' . $label . ' ' . ($number !== '' ? $number : $receipt->ifirma_id);

        // Mail::Send() (PrestaShop 8 and 9) puts the values into the HTML and the text template as they are and
        // escapes only {shop_name} and {color}, so the values a customer controls are escaped here; the text
        // version shows an entity for the rare quote or ampersand
        $vars = array(
            '{firstname}' => htmlspecialchars((string) $customer->firstname, ENT_QUOTES, 'UTF-8'),
            '{lastname}' => htmlspecialchars((string) $customer->lastname, ENT_QUOTES, 'UTF-8'),
            '{document_number}' => $number,
            '{order_reference}' => htmlspecialchars((string) $order->reference, ENT_QUOTES, 'UTF-8'),
            '{shop_name}' => $shopName,
            '{issue_date}' => (string) $receipt->issue_date,
            '{ifirma_shop_link}' => IfirmaShopLink::url(),
        );

        $attachment = array(
            'content' => $pdf,
            'name' => IfirmaShopLink::pdfFileName($number !== '' ? $number : (string) $receipt->ifirma_id),
            'mime' => 'application/pdf',
        );

        try {
            $sent = Mail::Send(
                $idLang,
                self::MAIL_TEMPLATE,
                $subject,
                $vars,
                $customer->email,
                trim($customer->firstname . ' ' . $customer->lastname),
                null,
                null,
                $attachment,
                null,
                _PS_MODULE_DIR_ . 'ifirma/mails/',
                false,
                null,
                $copy
            );
        } catch (Exception $e) {
            return $this->failed($e->getMessage(), IfirmaDeliveryRetryPolicy::FAILURE_TEMPORARY);
        }

        if (!$sent) {
            return $this->failed(
                $this->module->l('The shop mail system refused to send the e-mail.', 'ifirmadeliveryservice'),
                IfirmaDeliveryRetryPolicy::FAILURE_TEMPORARY
            );
        }

        return array('ok' => true, 'message' => '');
    }

    /**
     * Short human description of the recorded delivery state.
     *
     * @param IfirmaReceipt $receipt
     * @return string
     */
    public function describeDelivery(IfirmaReceipt $receipt)
    {
        switch ($receipt->delivery_status) {
            case IfirmaReceipt::DELIVERY_SENT:
                return sprintf(
                    $this->module->l('Sent on %1$s via %2$s', 'ifirmadeliveryservice'),
                    (string) $receipt->delivered_at,
                    $this->channelLabel((string) $receipt->delivery_channel)
                );
            case IfirmaReceipt::DELIVERY_FAILED:
                return sprintf($this->module->l('Failed: %s', 'ifirmadeliveryservice'), (string) $receipt->delivery_error);
            case IfirmaReceipt::DELIVERY_AWAITING_KSEF:
                return $this->module->l('Waiting for KSeF acceptance', 'ifirmadeliveryservice');
            case IfirmaReceipt::DELIVERY_SKIPPED:
                if ($receipt->source === IfirmaReceipt::SOURCE_EXTERNAL) {
                    return $this->module->l('Skipped (external)', 'ifirmadeliveryservice');
                }

                return $this->module->l('Skipped', 'ifirmadeliveryservice');
        }

        return $this->module->l('Not sent', 'ifirmadeliveryservice');
    }

    /**
     * @param string $value
     * @return bool
     */
    private function isChannel($value)
    {
        return in_array($value, array(self::CHANNEL_SHOP_MAIL, self::CHANNEL_IFIRMA_MAIL, self::CHANNEL_NONE), true);
    }

    /**
     * @param string $channel
     * @return string
     */
    private function channelLabel($channel)
    {
        switch ($channel) {
            case self::CHANNEL_SHOP_MAIL:
                return $this->module->l('shop e-mail', 'ifirmadeliveryservice');
            case self::CHANNEL_SHOP_MAIL_FALLBACK:
                return $this->module->l('shop e-mail (fallback)', 'ifirmadeliveryservice');
            case self::CHANNEL_IFIRMA_MAIL:
                return $this->module->l('iFirma e-mail', 'ifirmadeliveryservice');
        }

        return $channel;
    }

    /**
     * Language id of the order; falls back to English when the template folder is missing.
     *
     * @param Order $order
     * @return int
     */
    private function resolveLanguage(Order $order)
    {
        $idLang = (int) $order->id_lang;
        $iso = Language::getIsoById($idLang);
        if ($iso && is_file(_PS_MODULE_DIR_ . 'ifirma/mails/' . $iso . '/' . self::MAIL_TEMPLATE . '.html')) {
            return $idLang;
        }

        foreach ((array) Language::getLanguages(false) as $lang) {
            if (isset($lang['iso_code']) && $lang['iso_code'] === 'en') {
                return (int) $lang['id_lang'];
            }
        }

        return 1;
    }

    /**
     * Downloads the PDF, retrying transient failures (document not ready yet).
     *
     * @param IfirmaReceipt $receipt
     * @return string
     * @throws Exception
     */
    private function fetchPdfWithRetry(IfirmaReceipt $receipt)
    {
        $attempt = 0;
        while (true) {
            ++$attempt;
            try {
                return $this->service->fetchPdf($receipt);
            } catch (Exception $e) {
                // A call the client refused locally (rate guard, credentials cool-down) was never sent, and a
                // rejected login (HTTP 401/403, with or without a Kod) will not get better in a few seconds.
                $retryable = $this->client->lastRequestWasSent()
                    && !in_array((int) $this->client->getLastKod(), self::PDF_PERMANENT_KODS, true)
                    && !in_array((int) $this->client->getLastHttpCode(), array(401, 403), true);
                if (!$retryable && $attempt === 1) {
                    throw $e;
                }
                if (!$retryable || $attempt >= self::PDF_ATTEMPTS) {
                    throw new Exception(sprintf(
                        $this->module->l('Could not download the PDF after %1$d attempts: %2$s', 'ifirmadeliveryservice'),
                        $attempt,
                        $e->getMessage()
                    ));
                }
                $waits = self::PDF_RETRY_WAITS;
                $this->wait(isset($waits[$attempt - 1]) ? $waits[$attempt - 1] : end($waits));
            }
        }
    }

    /**
     * @param int $seconds
     */
    private function wait($seconds)
    {
        if ($this->sleeper !== null) {
            call_user_func($this->sleeper, $seconds);

            return;
        }
        sleep((int) $seconds);
    }

    /**
     * Blind-copy address for the shop-mail delivery, or null (skips are logged).
     *
     * @param Order $order
     * @param mixed $customer
     * @return string|null
     */
    private function copyRecipient(Order $order, $customer)
    {
        $mode = $this->getCopyMode();
        if ($mode === self::COPY_NONE) {
            return null;
        }

        if ($mode === self::COPY_SHOP) {
            $address = (string) Configuration::get('PS_SHOP_EMAIL');
        } else {
            $employee = new Employee((int) Configuration::get('IFIRMA_SEND_COPY_EMPLOYEE'));
            if (!Validate::isLoadedObject($employee)) {
                return $this->skipCopy($order, 'employee not found');
            }
            if ((int) $employee->active !== 1) {
                return $this->skipCopy($order, 'employee inactive');
            }
            if (!Validate::isEmail($employee->email)) {
                return $this->skipCopy($order, 'invalid e-mail');
            }
            $address = (string) $employee->email;
        }

        if (!Validate::isEmail($address)) {
            return $this->skipCopy($order, 'invalid e-mail');
        }
        if (mb_strtolower($address) === mb_strtolower((string) $customer->email)) {
            return $this->skipCopy($order, 'same as customer');
        }

        return $address;
    }

    /**
     * @param Order  $order
     * @param string $reason
     * @return null
     */
    private function skipCopy(Order $order, $reason)
    {
        PrestaShopLogger::addLog(sprintf('iFirma: delivery copy skipped for order #%d (%s)', (int) $order->id, $reason), 2);

        return null;
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }

    /**
     * @param IfirmaReceipt $receipt
     */
    private function persist(IfirmaReceipt $receipt)
    {
        try {
            if (!$receipt->save()) {
                PrestaShopLogger::addLog(sprintf('iFirma: the delivery outcome of order #%d could not be saved', (int) $receipt->id_order), 3);
            }
        } catch (Exception $e) {
            // Recording must never break the caller.
            PrestaShopLogger::addLog(sprintf('iFirma: the delivery outcome of order #%d could not be saved', (int) $receipt->id_order), 3);
        }
    }

    /**
     * @param bool   $ok
     * @param string $status
     * @param string $channel
     * @param string $message
     * @return IfirmaDeliveryResult
     */
    private function result($ok, $status, $channel, $message)
    {
        return new IfirmaDeliveryResult($ok, $status, $channel, $message);
    }
}
