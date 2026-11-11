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
require_once dirname(__FILE__) . '/IfirmaRemoteLimit.php';

/**
 * Admission control for requests to iFirma: a window of the last 60 seconds and a per-day window, checked in that
 * order (iFirma allows 100 requests per minute and 15,000 per day per account).
 *
 * The 60 seconds are rolling, not a calendar minute, so a burst around the turn of a minute cannot double the cap.
 * The day window changes its Configuration row in one atomic statement, the minute window by
 * compare-and-set, so concurrent requests cannot lose increments and exceed a cap. A request refused by the day
 * window has already used a minute slot; that only makes the guard more conservative, so it is not given back (a
 * second write could race).
 */
class IfirmaRateGuard
{
    const DEFAULT_MINUTE_CAP = 80;
    const DEFAULT_DAY_CAP = 14000;

    const MINUTE_COUNTER_KEY = 'IFIRMA_RATE_WINDOW';
    const DAY_COUNTER_KEY = 'IFIRMA_RATE_DAY_WINDOW';

    /** Configuration row holding the unix time until which iFirma asked the module to wait ('' = none). */
    const BACKOFF_KEY = 'IFIRMA_RATE_BACKOFF_UNTIL';

    /** Longest back-off accepted, in seconds; a Retry-After above it is cut, a stored end further away is ignored. */
    const MAX_BACKOFF_SECONDS = 86400;

    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /** @var IfirmaWindowCounter */
    private $counter;

    /** @var IfirmaRollingWindowCounter */
    private $rolling;

    /**
     * @param callable|null                   $clock
     * @param IfirmaWindowCounter|null        $counter
     * @param IfirmaRollingWindowCounter|null $rolling
     */
    public function __construct($clock = null, IfirmaWindowCounter $counter = null, IfirmaRollingWindowCounter $rolling = null)
    {
        $this->clock = $clock;
        $this->counter = $counter !== null ? $counter : new IfirmaWindowCounter();
        $this->rolling = $rolling !== null ? $rolling : new IfirmaRollingWindowCounter();
    }

    /**
     * Says whether a request may be sent and counts it in every window. A running back-off is checked first and
     * writes nothing.
     *
     * @return IfirmaRateDecision
     */
    public function admit()
    {
        $now = $this->now();
        $backoffEnd = $this->backoffEnd($now);
        if ($backoffEnd > 0) {
            return IfirmaRateDecision::refuse(
                IfirmaRateDecision::WINDOW_BACKOFF,
                sprintf('iFirma asked the module to wait: no requests until %s.', date('Y-m-d H:i:s', $backoffEnd))
            );
        }

        $minuteCap = $this->minuteCap();
        $minuteCount = $this->rolling->take(self::MINUTE_COUNTER_KEY, $minuteCap, $now);
        if ($minuteCount !== null) {
            return IfirmaRateDecision::refuse(
                IfirmaRateDecision::WINDOW_MINUTE,
                sprintf(
                    'iFirma rate limit guard: %d requests in the last 60 seconds (cap %d). Retry shortly.',
                    $minuteCount,
                    $minuteCap
                )
            );
        }

        $day = $this->dayWindow();
        $dayCount = $this->counter->take($day, $now);
        if ($dayCount !== null) {
            return IfirmaRateDecision::refuse($day->getName(), $this->dayRefusalMessage($day, $dayCount));
        }

        return IfirmaRateDecision::allow();
    }

    /**
     * What keeps requests from starting right now, without counting a request: a running back-off, else a window
     * whose counter has reached its cap.
     *
     * @return string one of the IfirmaRateDecision::WINDOW_* constants, WINDOW_NONE when nothing blocks
     */
    public function blockedBy()
    {
        $now = $this->now();
        if ($this->backoffEnd($now) > 0) {
            return IfirmaRateDecision::WINDOW_BACKOFF;
        }
        if ($this->rolling->count(self::MINUTE_COUNTER_KEY, $now) >= $this->minuteCap()) {
            return IfirmaRateDecision::WINDOW_MINUTE;
        }
        $day = $this->dayWindow();
        $parts = explode(':', (string) Configuration::get($day->getCounterKey()), 2);
        if (count($parts) === 2 && $parts[0] === $day->bucketAt($now) && (int) $parts[1] >= $day->getCap()) {
            return $day->getName();
        }

        return IfirmaRateDecision::WINDOW_NONE;
    }

    /**
     * What the windows count right now, read from the database (a counter raised by another statement in this
     * request is seen), without counting a request.
     *
     * @return array window name => array('used' => int, 'cap' => int); the minute window counts the last 60
     *               seconds, a day window of an earlier day reads 0
     */
    public function windowUsage()
    {
        $now = $this->now();
        $day = $this->dayWindow();

        return array(
            IfirmaRateDecision::WINDOW_MINUTE => array(
                'used' => $this->rolling->count(self::MINUTE_COUNTER_KEY, $now),
                'cap' => $this->minuteCap(),
            ),
            $day->getName() => array('used' => $this->storedCount($day, $now), 'cap' => $day->getCap()),
        );
    }

    /**
     * @return int the unix time at which the last 60 seconds have room for a request again (now when they have)
     */
    public function minuteFreeAt()
    {
        return $this->rolling->freeAt(self::MINUTE_COUNTER_KEY, $this->minuteCap(), $this->now());
    }

    /**
     * @return int the unix time a running back-off ends, 0 when none runs
     */
    public function backoffUntil()
    {
        return $this->backoffEnd($this->now());
    }

    /**
     * @param IfirmaRateWindow $window
     * @param int              $now
     * @return int requests counted in the window's current bucket
     */
    private function storedCount(IfirmaRateWindow $window, $now)
    {
        $stored = Db::getInstance()->getValue(
            "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL($window->getCounterKey()) . "'",
            false
        );
        $parts = explode(':', (string) $stored, 2);

        return (count($parts) === 2 && $parts[0] === $window->bucketAt($now)) ? (int) $parts[1] : 0;
    }

    /**
     * Starts the back-off after iFirma refused a request for exceeding its limit.
     *
     * @param string $retryAfter the Retry-After header of the answer, '' when there was none
     * @return int the unix time the back-off ends: now plus a positive whole number of seconds (at most a day),
     *             else the start of the next minute
     */
    public function startBackoff($retryAfter)
    {
        $now = $this->now();
        $seconds = ctype_digit((string) $retryAfter) ? (int) $retryAfter : 0;
        $until = $seconds > 0
            ? $now + min($seconds, self::MAX_BACKOFF_SECONDS)
            : $now - ($now % 60) + 60;
        Configuration::updateValue(self::BACKOFF_KEY, (string) $until);

        return $until;
    }

    /**
     * @param int $now
     * @return int the unix time a running back-off ends, 0 when none runs (unset, ended, unreadable, or dated more
     *             than a day ahead, which can only be a clock that was stepped back)
     */
    private function backoffEnd($now)
    {
        $stored = (string) Configuration::get(self::BACKOFF_KEY);
        if ($stored === '' || !ctype_digit($stored)) {
            return 0;
        }
        $until = (int) $stored;

        return ($until > $now && $until - $now <= self::MAX_BACKOFF_SECONDS) ? $until : 0;
    }

    /**
     * @return IfirmaRateWindow the calendar-day window
     */
    private function dayWindow()
    {
        return new IfirmaRateWindow(IfirmaRateDecision::WINDOW_DAY, self::DAY_COUNTER_KEY, 'Ymd', $this->dayCap());
    }

    /**
     * @return int
     */
    private function minuteCap()
    {
        return $this->capOf('IFIRMA_RATE_MAX_PER_MIN', self::DEFAULT_MINUTE_CAP);
    }

    /**
     * The configured day cap, lowered to the limit iFirma granted when a reading of today says it is less.
     *
     * @return int
     */
    private function dayCap()
    {
        $cap = $this->capOf('IFIRMA_RATE_MAX_PER_DAY', self::DEFAULT_DAY_CAP);
        $granted = IfirmaRemoteLimit::grantedOn(date('Ymd', $this->now()));

        return $granted > 0 ? min($cap, $granted) : $cap;
    }

    /**
     * @param string $configurationKey
     * @param int    $default
     * @return int
     */
    private function capOf($configurationKey, $default)
    {
        $cap = (int) Configuration::get($configurationKey);

        return $cap > 0 ? $cap : $default;
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }

    /**
     * @param IfirmaRateWindow $window
     * @param int              $count
     * @return string
     */
    private function dayRefusalMessage(IfirmaRateWindow $window, $count)
    {
        return sprintf(
            'iFirma rate limit guard: %d requests today (cap %d). Retry tomorrow.',
            $count,
            $window->getCap()
        );
    }
}
