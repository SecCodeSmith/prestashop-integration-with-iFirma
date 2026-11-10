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
require_once dirname(__FILE__) . '/IfirmaRemoteLimit.php';

/**
 * Admission control for requests to iFirma: a per-minute and a per-day window, checked in that order
 * (iFirma allows 100 requests per minute and 15,000 per day per account).
 *
 * Each window is one atomic UPDATE on its Configuration row, so concurrent requests cannot lose
 * increments and exceed a cap. A request refused by the day window has already used a minute slot;
 * that only makes the guard more conservative, so it is not given back (a second write could race).
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

    /**
     * @param callable|null            $clock
     * @param IfirmaWindowCounter|null $counter
     */
    public function __construct($clock = null, IfirmaWindowCounter $counter = null)
    {
        $this->clock = $clock;
        $this->counter = $counter !== null ? $counter : new IfirmaWindowCounter();
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

        foreach ($this->windows() as $window) {
            $count = $this->counter->take($window, $now);
            if ($count !== null) {
                return IfirmaRateDecision::refuse($window->getName(), $this->refusalMessage($window, $count));
            }
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
        foreach ($this->windows() as $window) {
            $parts = explode(':', (string) Configuration::get($window->getCounterKey()), 2);
            if (count($parts) === 2 && $parts[0] === $window->bucketAt($now) && (int) $parts[1] >= $window->getCap()) {
                return $window->getName();
            }
        }

        return IfirmaRateDecision::WINDOW_NONE;
    }

    /**
     * What the windows count right now, read from the database (a counter raised by another statement in this
     * request is seen), without counting a request.
     *
     * @return array window name => array('used' => int, 'cap' => int); a window of an earlier minute or day reads 0
     */
    public function windowUsage()
    {
        $now = $this->now();
        $usage = array();
        foreach ($this->windows() as $window) {
            $usage[$window->getName()] = array('used' => $this->storedCount($window, $now), 'cap' => $window->getCap());
        }

        return $usage;
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
     * @return IfirmaRateWindow[] in the order they are checked
     */
    private function windows()
    {
        return array(
            new IfirmaRateWindow(
                IfirmaRateDecision::WINDOW_MINUTE,
                self::MINUTE_COUNTER_KEY,
                'YmdHi',
                $this->capOf('IFIRMA_RATE_MAX_PER_MIN', self::DEFAULT_MINUTE_CAP)
            ),
            new IfirmaRateWindow(
                IfirmaRateDecision::WINDOW_DAY,
                self::DAY_COUNTER_KEY,
                'Ymd',
                $this->dayCap()
            ),
        );
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
    private function refusalMessage(IfirmaRateWindow $window, $count)
    {
        if ($window->getName() === IfirmaRateDecision::WINDOW_DAY) {
            return sprintf(
                'iFirma rate limit guard: %d requests today (cap %d). Retry tomorrow.',
                $count,
                $window->getCap()
            );
        }

        return sprintf(
            'iFirma rate limit guard: %d requests in the current minute (cap %d). Retry shortly.',
            $count,
            $window->getCap()
        );
    }
}
