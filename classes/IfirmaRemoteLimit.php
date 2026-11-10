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

require_once dirname(__FILE__) . '/IfirmaRateGuard.php';
require_once dirname(__FILE__) . '/IfirmaRateDecision.php';
require_once dirname(__FILE__) . '/IfirmaLimitCheck.php';
require_once dirname(__FILE__) . '/IfirmaLimitReading.php';

/**
 * The day limit as iFirma itself reports it (the optional "abonent" key), kept next to the local counters.
 *
 * The local day counter only sees this module's requests; other clients of the same account use the same limit. A
 * reading raises the counter to what iFirma counts and stores the granted limit, which the guard then uses as a ceiling
 * for the day cap. The reading is stored as "<day>:<used>:<granted>:<unix time>" and reused for REFRESH_SECONDS, so the
 * extra request is made a few times an hour, not on every cron run.
 */
class IfirmaRemoteLimit
{
    const SNAPSHOT_KEY = 'IFIRMA_REMOTE_LIMIT';

    /** How long a reading is reused before iFirma is asked again. */
    const REFRESH_SECONDS = 600;

    /** @var callable returns array('used' => int, 'granted' => int), or null when the limit cannot be read */
    private $fetch;

    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /** @var callable|null returns array('window' => string, 'detail' => string) about the fetch that just failed */
    private $failure;

    /**
     * @param callable      $fetch
     * @param callable|null $clock
     * @param callable|null $failure says why the last fetch gave nothing: the rate window or IfirmaLimitCheck::WINDOW_AUTH
     *                               that refused it before it was sent ('' when it was sent), and the detail for the log
     */
    public function __construct($fetch, $clock = null, $failure = null)
    {
        $this->fetch = $fetch;
        $this->clock = $clock;
        $this->failure = $failure;
    }

    /**
     * The reader of an API client: the limit is asked through it and a failure is told apart by what the client knows.
     *
     * @param IfirmaApiClient $client
     * @param callable|null   $clock
     * @return IfirmaRemoteLimit
     */
    public static function forClient($client, $clock = null)
    {
        return new self(function () use ($client) {
            return $client->getLimit();
        }, $clock, function () use ($client) {
            return array('window' => self::refusingWindow($client), 'detail' => (string) $client->getLastError());
        });
    }

    /**
     * Reads the limit (or reuses today's fresh reading) and brings the local day counter up to it.
     *
     * @return array|null used, granted and remaining requests of the day; null when iFirma gave no usable answer, in
     *                    which case nothing is changed
     */
    public function refresh()
    {
        $now = $this->now();
        $today = date('Ymd', $now);
        $kept = self::snapshot($today);
        if ($kept !== null && $kept->at() <= $now && $now - $kept->at() < self::REFRESH_SECONDS) {
            return self::summary($kept);
        }

        $reading = $this->readAndStore($today, $now);

        return $reading !== null ? self::summary($reading) : null;
    }

    /**
     * The employee's check: always asks iFirma once (the reuse of a fresh reading is for the cron only) and stores the
     * answer like refresh() does. A refusal, an error, a failing transport or an unusable answer changes nothing.
     *
     * @return IfirmaLimitCheck
     */
    public function check()
    {
        $now = $this->now();
        try {
            $reading = $this->readAndStore(date('Ymd', $now), $now);
        } catch (Throwable $e) {
            // the client lets a transport failure through; the page must still render
            return IfirmaLimitCheck::unavailable(get_class($e) . ': ' . $e->getMessage());
        }
        if ($reading !== null) {
            return IfirmaLimitCheck::checked($reading);
        }

        $failure = $this->failure !== null ? call_user_func($this->failure) : array();
        $detail = isset($failure['detail']) ? (string) $failure['detail'] : '';
        $window = isset($failure['window']) ? (string) $failure['window'] : '';

        return self::refusesLocally($window) ? IfirmaLimitCheck::refused($window, $detail) : IfirmaLimitCheck::unavailable($detail);
    }

    /**
     * @return IfirmaLimitReading|null the reading stored last, whatever day it belongs to; null when none is usable
     */
    public static function stored()
    {
        $parts = explode(':', (string) Configuration::get(self::SNAPSHOT_KEY));
        if (count($parts) !== 4) {
            return null;
        }
        foreach (array(1, 2, 3) as $i) {
            if (!ctype_digit($parts[$i])) {
                return null;
            }
        }
        if ((int) $parts[2] <= 0) {
            return null;
        }

        return new IfirmaLimitReading($parts[0], (int) $parts[1], (int) $parts[2], (int) $parts[3]);
    }

    /**
     * @param IfirmaApiClient $client
     * @return string what refused the client's last call: IfirmaLimitCheck::WINDOW_AUTH, the rate window, or '' when the
     *                call was not refused
     */
    private static function refusingWindow($client)
    {
        if ($client->lastCallWasAuthBlocked()) {
            return IfirmaLimitCheck::WINDOW_AUTH;
        }

        return $client->isRateLimited() ? $client->rateLimitWindow() : '';
    }

    /**
     * @param string $window
     * @return bool true for a refusal by the module's own limits or the authentication cool-down, nothing was sent;
     *              false for no refusal and for IfirmaRateDecision::WINDOW_SERVER, where iFirma itself refused a request
     *              that was sent (that is no answer, not a local refusal)
     */
    private static function refusesLocally($window)
    {
        return in_array($window, array(
            IfirmaRateDecision::WINDOW_MINUTE,
            IfirmaRateDecision::WINDOW_DAY,
            IfirmaRateDecision::WINDOW_BACKOFF,
            IfirmaLimitCheck::WINDOW_AUTH,
        ), true);
    }

    /**
     * Asks iFirma, and on a usable answer stores it and raises the day counter.
     *
     * @param string $today Ymd
     * @param int    $now
     * @return IfirmaLimitReading|null null when there was no usable answer, in which case nothing is changed
     */
    private function readAndStore($today, $now)
    {
        $answer = call_user_func($this->fetch);
        $used = is_array($answer) && isset($answer['used']) && is_numeric($answer['used']) ? (int) $answer['used'] : -1;
        $granted = is_array($answer) && isset($answer['granted']) && is_numeric($answer['granted']) ? (int) $answer['granted'] : 0;
        if ($used < 0 || $granted <= 0) {
            return null;
        }

        Configuration::updateValue(self::SNAPSHOT_KEY, $today . ':' . $used . ':' . $granted . ':' . $now);
        $this->raiseDayCounterTo($today, $used);

        return new IfirmaLimitReading($today, $used, $granted, $now);
    }

    /**
     * @param string $day the day as Ymd
     * @return int the limit iFirma granted according to the stored reading of that day, 0 when there is none
     */
    public static function grantedOn($day)
    {
        $kept = self::snapshot($day);

        return $kept !== null ? $kept->granted() : 0;
    }

    /**
     * @param string $day
     * @return IfirmaLimitReading|null the stored reading when it belongs to the day
     */
    private static function snapshot($day)
    {
        $stored = self::stored();

        return ($stored !== null && $stored->day() === (string) $day) ? $stored : null;
    }

    /**
     * @param IfirmaLimitReading $reading
     * @return array
     */
    private static function summary(IfirmaLimitReading $reading)
    {
        return array('used' => $reading->used(), 'granted' => $reading->granted(), 'remaining' => $reading->remaining());
    }

    /**
     * The counter only moves up: what iFirma counts includes this module's requests, never fewer.
     *
     * @param string $today
     * @param int    $used
     */
    private function raiseDayCounterTo($today, $used)
    {
        $parts = explode(':', (string) Configuration::get(IfirmaRateGuard::DAY_COUNTER_KEY), 2);
        $local = (count($parts) === 2 && $parts[0] === $today) ? (int) $parts[1] : 0;
        if ($used > $local) {
            Configuration::updateValue(IfirmaRateGuard::DAY_COUNTER_KEY, $today . ':' . $used);
        }
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
