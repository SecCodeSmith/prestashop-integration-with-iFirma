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

require_once dirname(__FILE__) . '/IfirmaRateWindow.php';
require_once dirname(__FILE__) . '/IfirmaRateDecision.php';
require_once dirname(__FILE__) . '/IfirmaWindowCounter.php';
require_once dirname(__FILE__) . '/IfirmaRollingWindowCounter.php';
require_once dirname(__FILE__) . '/IfirmaRetryAfter.php';

/**
 * A named budget of iFirma requests in any 60 seconds and, optionally, per calendar day. It is independent of the
 * rate guard: the requests a budget covers count here first, so they cannot use up the request limit that
 * issuance, the status sync and the cron share. The 60 seconds roll, like the guard's, so a burst around the turn of
 * a calendar minute cannot take more than the cap.
 *
 * Counters are Configuration rows (see IfirmaRollingWindowCounter and IfirmaWindowCounter). A unit is taken before
 * the request is sent and given back when the request was refused locally, so the count equals the requests that
 * reached iFirma.
 */
class IfirmaRequestBudget
{
    const DAY_BUCKET_FORMAT = 'Ymd';

    /** @var string */
    private $minuteKey;

    /** @var int */
    private $minuteCap;

    /** @var IfirmaRateWindow|null */
    private $dayWindow;

    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /** @var IfirmaWindowCounter */
    private $counter;

    /** @var IfirmaRollingWindowCounter */
    private $rolling;

    /** @var int|null the second the last take() spent its unit of the minute window in, for give-back */
    private $takenAtSecond;

    /** @var string|null the day bucket the last take() spent its unit in, for give-back */
    private $takenInDay;

    /** @var string the IfirmaRateDecision::WINDOW_* that refused the last take(), '' when none did */
    private $refusedBy = '';

    /**
     * @param string                          $minuteKey Configuration row of the 60-second counter
     * @param int                             $minuteCap most units in any 60 seconds
     * @param IfirmaRateWindow|null           $dayWindow the per-day window, null for a budget without one
     * @param callable|null                   $clock
     * @param IfirmaWindowCounter|null        $counter
     * @param IfirmaRollingWindowCounter|null $rolling
     */
    public function __construct(
        $minuteKey,
        $minuteCap,
        IfirmaRateWindow $dayWindow = null,
        $clock = null,
        IfirmaWindowCounter $counter = null,
        IfirmaRollingWindowCounter $rolling = null
    ) {
        $this->minuteKey = (string) $minuteKey;
        $this->minuteCap = (int) $minuteCap;
        $this->dayWindow = $dayWindow;
        $this->clock = $clock;
        $this->counter = $counter !== null ? $counter : new IfirmaWindowCounter();
        $this->rolling = $rolling !== null ? $rolling : new IfirmaRollingWindowCounter();
    }

    /**
     * Takes one unit in the 60-second window, then in the day window. A refusal by the day window gives the minute
     * unit back.
     *
     * @return bool false when a window is full (nothing is spent then)
     */
    public function take()
    {
        $now = $this->now();
        $this->takenAtSecond = null;
        $this->takenInDay = null;
        $this->refusedBy = '';
        if ($this->rolling->take($this->minuteKey, $this->minuteCap, $now) !== null) {
            $this->refusedBy = IfirmaRateDecision::WINDOW_MINUTE;

            return false;
        }
        $this->takenAtSecond = $now;
        if ($this->dayWindow === null) {
            return true;
        }
        if ($this->counter->take($this->dayWindow, $now) !== null) {
            $this->refusedBy = IfirmaRateDecision::WINDOW_DAY;
            $this->release();

            return false;
        }
        $this->takenInDay = $this->dayWindow->bucketAt($now);

        return true;
    }

    /**
     * Gives back the unit the last take() spent; a no-op when it took none or the window has moved on.
     */
    public function release()
    {
        $atSecond = $this->takenAtSecond;
        $inDay = $this->takenInDay;
        // Forgotten first: when a give-back fails, a caller that releases again must not give a unit back twice.
        $this->takenAtSecond = null;
        $this->takenInDay = null;
        if ($atSecond !== null) {
            $this->rolling->giveBack($this->minuteKey, $atSecond, $this->now());
        }
        if ($inDay !== null) {
            $this->counter->giveBack($this->dayWindow, $inDay);
        }
    }

    /**
     * @return int seconds to wait after the last take() was refused, by the rules of IfirmaRetryAfter: until the
     *             60-second window has room for a unit again, or the day rule; 1 when nothing was refused
     */
    public function secondsUntilRoom()
    {
        $now = $this->now();
        $minuteFreeAt = $this->refusedBy === IfirmaRateDecision::WINDOW_MINUTE
            ? $this->rolling->freeAt($this->minuteKey, $this->minuteCap, $now)
            : 0;

        return IfirmaRetryAfter::seconds($this->refusedBy, $now, 0, $minuteFreeAt);
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
