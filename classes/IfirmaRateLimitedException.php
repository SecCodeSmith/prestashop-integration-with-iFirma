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
 * Thrown when a request was refused by the rate limit (the local minute or day cap, a running back-off, or an
 * iFirma limit answer). A create is queued and no document exists; a PDF download is answered with a 429 that
 * carries the seconds to wait.
 */
class IfirmaRateLimitedException extends Exception
{
    /** @var string one of the IfirmaRateDecision::WINDOW_* constants */
    private $window;

    /** @var int */
    private $retryAfterSeconds;

    /**
     * @param string $message           the refusal message
     * @param string $window            the window that refused
     * @param int    $retryAfterSeconds seconds the caller is told to wait, 0 when the refusal has none
     */
    public function __construct($message, $window = '', $retryAfterSeconds = 0)
    {
        parent::__construct((string) $message);
        $this->window = (string) $window;
        $this->retryAfterSeconds = max(0, (int) $retryAfterSeconds);
    }

    /**
     * @return int seconds to wait before the request can pass, 0 when unknown
     */
    public function getRetryAfterSeconds()
    {
        return $this->retryAfterSeconds;
    }

    /**
     * @return string one of the IfirmaRateDecision::WINDOW_* constants
     */
    public function getWindow()
    {
        return $this->window;
    }
}
