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
 * Immutable value object: what one IfirmaDuplicateGuard::check() run has been told to do. The guard
 * resolves it once (IfirmaDuplicateGuard::resolveSettings()) from the options of the call and the
 * configuration they fall back to: an option wins, then the configuration, then the default below.
 *
 *  - isEnabled()          option `enabled`, IFIRMA_DUPLICATE_CHECK; on unless one of them says off
 *  - isBypassed()         option `bypass`: skip the lookup for this call only (the one-time "Issue anyway")
 *  - getTolerance()       option `tolerance`, IFIRMA_DUPLICATE_AMOUNT_TOLERANCE: the largest difference, in the
 *                         currency of the order, between the gross of a document and the gross of the order
 *                         for the document to be a candidate (an adopted document counts as paid when it is
 *                         unpaid by no more than this)
 *  - getWindowDays()      option `window_days`, IFIRMA_DUPLICATE_WINDOW_DAYS: the longest search window, in
 *                         days from the day before the order to today, in which the guard still lists the
 *                         documents unfiltered
 *  - getTimeout()         option `timeout`: seconds for each call to iFirma; null leaves it to the client
 *  - getMaxDetailCalls()  option `max_detail_calls`: how many candidates one run reads the detail of (one
 *                         call to iFirma each); the others stay suspected, unverified. Never negative.
 *  - readsDetailOf()      whether the candidate at a position (newest first, from 0) is within that cap
 */
class IfirmaGuardSettings
{
    /** The guard is on when neither the option nor IFIRMA_DUPLICATE_CHECK says otherwise. */
    const DEFAULT_ENABLED = true;

    /** One grosz: the amount tolerance when neither the option nor IFIRMA_DUPLICATE_AMOUNT_TOLERANCE is set. */
    const DEFAULT_AMOUNT_TOLERANCE = 0.01;

    /** Days: the search window when neither the option nor IFIRMA_DUPLICATE_WINDOW_DAYS is set. */
    const DEFAULT_WINDOW_DAYS = 60;

    /** The cap on candidate details read per run when the option max_detail_calls is not set. */
    const DEFAULT_MAX_DETAIL_CALLS = 10;

    /** @var bool */
    private $enabled;

    /** @var bool */
    private $bypass;

    /** @var float */
    private $tolerance;

    /** @var int */
    private $windowDays;

    /** @var int|null */
    private $timeout;

    /** @var int */
    private $maxDetailCalls;

    /**
     * Takes the values as the options and the configuration hold them (numbers or text) and casts them.
     *
     * @param mixed $enabled
     * @param mixed $bypass
     * @param mixed $tolerance
     * @param mixed $windowDays
     * @param mixed $timeout        null leaves the timeout to the client
     * @param mixed $maxDetailCalls a negative cap counts as 0
     */
    public function __construct($enabled, $bypass, $tolerance, $windowDays, $timeout, $maxDetailCalls)
    {
        $this->enabled = (bool) $enabled;
        $this->bypass = (bool) $bypass;
        $this->tolerance = (float) $tolerance;
        $this->windowDays = (int) $windowDays;
        $this->timeout = $timeout === null ? null : (int) $timeout;
        $this->maxDetailCalls = max(0, (int) $maxDetailCalls);
    }

    /**
     * @return bool false when the guard is switched off
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @return bool true when this call skips the lookup
     */
    public function isBypassed()
    {
        return $this->bypass;
    }

    /**
     * @return float
     */
    public function getTolerance()
    {
        return $this->tolerance;
    }

    /**
     * @return int
     */
    public function getWindowDays()
    {
        return $this->windowDays;
    }

    /**
     * @return int|null seconds, null when the client's own timeout applies
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @return int
     */
    public function getMaxDetailCalls()
    {
        return $this->maxDetailCalls;
    }

    /**
     * @param int $index position of a candidate, newest first, from 0
     * @return bool true when the run reads the detail of that candidate
     */
    public function readsDetailOf($index)
    {
        return (int) $index < $this->maxDetailCalls;
    }
}
