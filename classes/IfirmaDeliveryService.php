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
 *  - deliver(receipt, order, options) -> array(ok, status sent|failed|skipped|disabled, channel, message);
 *    options: manual (bool), channel (override). Never throws; records the outcome on the receipt.
 *  - resend(receipt, order) = deliver() with manual => true.
 *  - sendViaIfirma() / sendViaShopMail() -> array(ok, message).
 *  - describeDelivery(receipt) -> short human string for the admin panel.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaReceiptService.php';

class IfirmaDeliveryService
{
    const CHANNEL_SHOP_MAIL = 'shop_mail';
    const CHANNEL_IFIRMA_MAIL = 'ifirma_mail';
    const CHANNEL_NONE = 'none';
    const CHANNEL_SHOP_MAIL_FALLBACK = 'shop_mail_fallback';

    const MAIL_TEMPLATE = 'receipt_issued';
    const TEXT_MAX = 1000;
    const EXTERNAL_REASON = 'external document - not auto-delivered';

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

    /**
     * @param IfirmaApiClient      $client
     * @param IfirmaReceiptService $service
     * @param Module               $module
     * @param callable|null        $clock
     */
    public function __construct(IfirmaApiClient $client, IfirmaReceiptService $service, Module $module, $clock = null)
    {
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
        $client = new IfirmaApiClient(
            Configuration::get('IFIRMA_USER'),
            array(
                'faktura' => Configuration::get('IFIRMA_KEY_FAKTURA'),
                'abonent' => Configuration::get('IFIRMA_KEY_ABONENT'),
                'rachunek' => Configuration::get('IFIRMA_KEY_RACHUNEK'),
            ),
            Configuration::get('IFIRMA_BASE_URL') ?: IfirmaApiClient::BASE_URL
        );

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
     * Delivers the document and records the outcome on the receipt.
     *
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @param array         $options manual (bool), channel (override)
     * @return array array('ok', 'status', 'channel', 'message')
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
            $receipt->delivery_status = IfirmaReceipt::DELIVERY_SKIPPED;
            $receipt->delivery_channel = self::CHANNEL_NONE;
            $receipt->delivered_at = null;
            $receipt->delivery_error = '';
            $this->persist($receipt);

            return $this->result(true, 'skipped', self::CHANNEL_NONE, '');
        }

        if (!$manual && $receipt->source === IfirmaReceipt::SOURCE_EXTERNAL) {
            $receipt->delivery_status = IfirmaReceipt::DELIVERY_SKIPPED;
            $receipt->delivery_channel = $channel;
            $receipt->delivery_error = self::EXTERNAL_REASON;
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
            $res = array('ok' => false, 'message' => $e->getMessage());
        }

        if (!empty($res['ok'])) {
            $receipt->delivery_status = IfirmaReceipt::DELIVERY_SENT;
            $receipt->delivery_channel = $effective;
            $receipt->delivered_at = date('Y-m-d H:i:s', $this->now());
            $receipt->delivery_error = '';
            $this->persist($receipt);

            return $this->result(true, 'sent', $effective, '');
        }

        $message = isset($res['message']) ? (string) $res['message'] : '';
        $receipt->delivery_status = IfirmaReceipt::DELIVERY_FAILED;
        $receipt->delivery_channel = $effective;
        $receipt->delivery_error = $message;
        $this->persist($receipt);

        return $this->result(false, 'failed', $effective, $message);
    }

    /**
     * Manual (admin) delivery through the configured channel.
     *
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return array
     */
    public function resend(IfirmaReceipt $receipt, ?Order $order = null)
    {
        return $this->deliver($receipt, $order, array('manual' => true));
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
        $body = array(
            'Tekst' => IfirmaReceiptService::truncate((string) Configuration::get('IFIRMA_SEND_TEXT'), self::TEXT_MAX),
            'SkrzynkaEmailOdbiorcy' => is_object($customer) ? (string) $customer->email : '',
        );

        $response = $this->client->send($receipt->document_type, $receipt->ifirma_id, $body);
        if (is_array($response)) {
            $node = (isset($response['response']) && is_array($response['response'])) ? $response['response'] : $response;
            if (isset($node['Kod']) && (int) $node['Kod'] === 0) {
                return array('ok' => true, 'message' => '');
            }
        }

        $error = (string) $this->client->getLastError();

        return array(
            'ok' => false,
            'message' => $error !== '' ? $error : $this->module->l('iFirma did not confirm the e-mail', 'ifirmadeliveryservice'),
        );
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
            $pdf = $this->service->fetchPdf($receipt);
        } catch (Exception $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }

        $customer = $order->getCustomer();
        if (!is_object($customer) || (string) $customer->email === '') {
            return array('ok' => false, 'message' => $this->module->l('The customer has no e-mail address.', 'ifirmadeliveryservice'));
        }

        $idLang = $this->resolveLanguage($order);
        $number = (string) $receipt->ifirma_number;
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $label = $receipt->document_type === 'fakturakraj'
            ? $this->module->l('Invoice', 'ifirmadeliveryservice')
            : $this->module->l('Receipt', 'ifirmadeliveryservice');
        $subject = $shopName . ': ' . $label . ' ' . ($number !== '' ? $number : $receipt->ifirma_id);

        $vars = array(
            '{firstname}' => (string) $customer->firstname,
            '{lastname}' => (string) $customer->lastname,
            '{document_number}' => $number,
            '{order_reference}' => (string) $order->reference,
            '{shop_name}' => $shopName,
            '{issue_date}' => (string) $receipt->issue_date,
        );

        $safe = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $number !== '' ? $number : (string) $receipt->ifirma_id), '-');
        $attachment = array(
            'content' => $pdf,
            'name' => 'ifirma-' . $safe . '.pdf',
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
                _PS_MODULE_DIR_ . 'ifirma/mails/'
            );
        } catch (Exception $e) {
            return array('ok' => false, 'message' => $e->getMessage());
        }

        if (!$sent) {
            return array('ok' => false, 'message' => $this->module->l('The shop mail system refused to send the e-mail.', 'ifirmadeliveryservice'));
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
            $receipt->save();
        } catch (Exception $e) {
            // Recording must never break the caller.
            return;
        }
    }

    /**
     * @param bool   $ok
     * @param string $status
     * @param string $channel
     * @param string $message
     * @return array
     */
    private function result($ok, $status, $channel, $message)
    {
        return array('ok' => $ok, 'status' => $status, 'channel' => $channel, 'message' => $message);
    }
}
