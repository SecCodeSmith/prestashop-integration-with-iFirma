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

require_once dirname(__FILE__) . '/IfirmaRateDecision.php';
require_once dirname(__FILE__) . '/IfirmaLimitCheck.php';
require_once dirname(__FILE__) . '/IfirmaLimitReading.php';

/**
 * The monthly API limit as iFirma itself reports it (the optional "abonent" key).
 *
 * It is the account's limit of documents issued through the API in a calendar month, not the daily request quota, so a
 * reading is only stored: it never changes the module's request counters or caps. The reading is stored as
 * "<day>:<used>:<granted>:<unix time>" (the day is the date of the reading) and reused for REFRESH_SECONDS, so the
 * extra request is made a few times an hour, not on every cron run.
 */
class IfirmaRemoteLimit
{
    const SNAPSHOT_KEY = 'IFIRMA_REMOTE_LIMIT';

    /** How long a reading is reused before iFirma is asked again. */
    const REFRESH_SECONDS = 600;

    /** The largest figure of iFirma's limit answer that is believed; a monthly limit is a few thousand. */
    const MAX_FIGURE = 10000000;
    const MAX_FIGURE_DIGITS = 8;

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
     * Reads the limit, or reuses the stored reading while it is younger than REFRESH_SECONDS.
     *
     * @return array|null used, granted and remaining of the monthly limit; null when iFirma gave no usable answer, in
     *                    which case nothing is changed
     */
    public function refresh()
    {
        $now = $this->now();
        $kept = self::stored();
        if ($kept !== null && $kept->at() <= $now && $now - $kept->at() < self::REFRESH_SECONDS) {
            return self::summary($kept);
        }

        $reading = $this->readAndStore(date('Ymd', $now), $now);

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
     * Asks iFirma, and on a usable answer stores it.
     *
     * @param string $today Ymd
     * @param int    $now
     * @return IfirmaLimitReading|null null when there was no usable answer, in which case nothing is changed
     */
    private function readAndStore($today, $now)
    {
        $answer = call_user_func($this->fetch);
        $used = is_array($answer) && isset($answer['used']) ? self::figure($answer['used']) : null;
        $granted = is_array($answer) && isset($answer['granted']) ? self::figure($answer['granted']) : null;
        if ($used === null || $granted === null || $granted <= 0) {
            return null;
        }

        Configuration::updateValue(self::SNAPSHOT_KEY, $today . ':' . $used . ':' . $granted . ':' . $now);

        return new IfirmaLimitReading($today, $used, $granted, $now);
    }

    /**
     * @param mixed $value a figure of iFirma's answer
     * @return int|null the figure when it is a non-negative whole number (an integer or plain digits) up to MAX_FIGURE;
     *                  null for anything else: a float, a sign, an exponent, text, a value out of bounds
     */
    private static function figure($value)
    {
        if (is_string($value) && ctype_digit($value) && strlen($value) <= self::MAX_FIGURE_DIGITS) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 0 && $value <= self::MAX_FIGURE ? $value : null;
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
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
