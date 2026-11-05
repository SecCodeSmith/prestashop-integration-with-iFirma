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
 *  - isSent() is the old `ok` flag, the one test the callers (the pipeline's deliver step, the admin
 *             resend action) make: true for sent and for skipped (nothing needed sending), false for
 *             failed and disabled. Use getStatus() to tell sent from skipped.
 */
class IfirmaDeliveryResult
{
    /** @var bool */
    private $ok;

    /** @var string */
    private $status;

    /** @var string */
    private $channel;

    /** @var string */
    private $message;

    /**
     * @param bool   $ok
     * @param string $status
     * @param string $channel
     * @param string $message
     */
    public function __construct($ok, $status, $channel, $message)
    {
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
}
