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
 * The rate guard's answer to "may a request start now?": allowed, or refused by a named window
 * with a message that says why and until when.
 */
class IfirmaRateDecision
{
    const WINDOW_NONE = '';
    const WINDOW_MINUTE = 'minute';
    const WINDOW_DAY = 'day';
    /** The stored back-off after an iFirma limit answer is still running. */
    const WINDOW_BACKOFF = 'backoff';
    /** iFirma itself refused the request that was just sent. */
    const WINDOW_SERVER = 'server';

    /** @var bool */
    private $allowed;

    /** @var string */
    private $window;

    /** @var string */
    private $message;

    /**
     * @param bool   $allowed
     * @param string $window  one of the WINDOW_* constants
     * @param string $message
     */
    public function __construct($allowed, $window = self::WINDOW_NONE, $message = '')
    {
        $this->allowed = (bool) $allowed;
        $this->window = (string) $window;
        $this->message = (string) $message;
    }

    /**
     * @return IfirmaRateDecision
     */
    public static function allow()
    {
        return new self(true);
    }

    /**
     * @param string $window
     * @param string $message
     * @return IfirmaRateDecision
     */
    public static function refuse($window, $message)
    {
        return new self(false, $window, $message);
    }

    /**
     * @return bool
     */
    public function isAllowed()
    {
        return $this->allowed;
    }

    /**
     * @return string one of the WINDOW_* constants, WINDOW_NONE when allowed
     */
    public function getWindow()
    {
        return $this->window;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }
}
