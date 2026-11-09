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

    /**
     * @param callable|null $clock
     */
    public function __construct($clock = null)
    {
        $this->clock = $clock;
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
            $count = $this->countIn($window, $now);
            if ($count !== null) {
                return IfirmaRateDecision::refuse($window->getName(), $this->refusalMessage($window, $count));
            }
        }

        return IfirmaRateDecision::allow();
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
                $this->capOf('IFIRMA_RATE_MAX_PER_DAY', self::DEFAULT_DAY_CAP)
            ),
        );
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
     * Takes one slot of the window.
     *
     * @param IfirmaRateWindow $window
     * @param int              $now
     * @return int|null null when the slot was taken, else the count that makes the window full
     */
    private function countIn(IfirmaRateWindow $window, $now)
    {
        $bucket = $window->bucketAt($now);
        $cap = $window->getCap();
        $key = $window->getCounterKey();

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'configuration';
        $b = pSQL($bucket);
        // One atomic statement: reset or increment the counter, but only while the cap is not reached.
        $db->execute(
            "UPDATE `" . $table . "` SET `value` = IF(SUBSTRING_INDEX(`value`, ':', 1) = '" . $b . "', "
            . "CONCAT('" . $b . ":', SUBSTRING_INDEX(`value`, ':', -1) + 1), '" . $b . ":1') "
            . "WHERE `name` = '" . pSQL($key) . "' AND (SUBSTRING_INDEX(`value`, ':', 1) <> '" . $b . "' "
            . "OR SUBSTRING_INDEX(`value`, ':', -1) < " . (int) $cap . ")"
        );
        if ((int) $db->Affected_Rows() >= 1) {
            return null;
        }

        $stored = $db->getValue("SELECT `value` FROM `" . $table . "` WHERE `name` = '" . pSQL($key) . "'", false);
        if ($stored === false || $stored === null) {
            // No row yet (the module was never installed with the default): start the window.
            Configuration::updateValue($key, $bucket . ':1');

            return null;
        }

        $parts = explode(':', (string) $stored, 2);

        return (count($parts) === 2 && $parts[0] === $bucket) ? (int) $parts[1] : $cap;
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
