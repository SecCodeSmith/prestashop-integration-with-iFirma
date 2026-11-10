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

/**
 * Immutable value object returned by IfirmaDeliveryService::deliver() and resend().
 *
 * It replaces the array('ok', 'status', 'channel', 'message') that deliver() used to return:
 *  - status   sent | failed | skipped | disabled
 *  - channel  the channel the outcome concerns (shop_mail, ifirma_mail, shop_mail_fallback, none);
 *             empty when the pipeline had to build the failure itself
 *  - message  the failure or skip reason; empty when there is none
 *  - refusedLocally() is true when a manual resend did not reach iFirma because the API client refused the
 *             call itself (rate guard or credentials cool-down); the record was not changed then.
 *  - isSent() is the old `ok` flag, the one test the callers (the pipeline's deliver step, the admin
 *             resend action) make: true for sent and for skipped (nothing needed sending), false for
 *             failed and disabled. Use getStatus() to tell sent from skipped.
 */
class IfirmaDeliveryResult
{
    /**
     * A manual resend was refused because the document was delivered a moment ago. Nothing was sent and nothing was
     * recorded, but the API client did not refuse anything, so it is not refusedLocally() and a bulk run goes on.
     */
    const REFUSAL_JUST_SENT = 'just_sent';

    /** @var bool */
    private $ok;

    /** @var string */
    private $status;

    /** @var string */
    private $channel;

    /** @var string */
    private $message;

    /** @var string '' | rate_limited | auth_blocked */
    private $refusal;

    /**
     * @param bool   $ok
     * @param string $status
     * @param string $channel
     * @param string $message
     * @param string $refusal '' | rate_limited | auth_blocked: why the API client refused the call locally
     */
    public function __construct($ok, $status, $channel, $message, $refusal = '')
    {
        $this->refusal = (string) $refusal;
        $this->ok = (bool) $ok;
        $this->status = (string) $status;
        $this->channel = (string) $channel;
        $this->message = (string) $message;
    }

    /**
     * @return bool true when the delivery did not fail (status sent or skipped)
     */
    public function isSent()
    {
        return $this->ok;
    }

    /**
     * @return string sent | failed | skipped | disabled
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return bool true when the API client refused the call locally (nothing was sent and nothing recorded)
     */
    public function refusedLocally()
    {
        return $this->refusal !== '' && $this->refusal !== self::REFUSAL_JUST_SENT;
    }

    /**
     * @return bool true when a manual resend was refused because the document was delivered less than a moment ago
     */
    public function isJustSent()
    {
        return $this->refusal === self::REFUSAL_JUST_SENT;
    }

    /**
     * @return string '' | rate_limited | auth_blocked | just_sent
     */
    public function getRefusal()
    {
        return $this->refusal;
    }
}
