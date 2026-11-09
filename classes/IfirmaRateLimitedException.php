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
 * Thrown when a request that creates a document was refused by the rate limit (the local minute or day
 * cap, a running back-off, or an iFirma limit answer). No document was created; the order is queued.
 */
class IfirmaRateLimitedException extends Exception
{
    /** @var string one of the IfirmaRateDecision::WINDOW_* constants */
    private $window;

    /**
     * @param string $message the refusal message
     * @param string $window  the window that refused
     */
    public function __construct($message, $window = '')
    {
        parent::__construct((string) $message);
        $this->window = (string) $window;
    }

    /**
     * @return string one of the IfirmaRateDecision::WINDOW_* constants
     */
    public function getWindow()
    {
        return $this->window;
    }
}
