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

    /**
     * @param callable      $fetch
     * @param callable|null $clock
     */
    public function __construct($fetch, $clock = null)
    {
        $this->fetch = $fetch;
        $this->clock = $clock;
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
        if ($kept !== null && $kept['at'] <= $now && $now - $kept['at'] < self::REFRESH_SECONDS) {
            return self::reading($kept['used'], $kept['granted']);
        }

        $answer = call_user_func($this->fetch);
        $used = is_array($answer) && isset($answer['used']) && is_numeric($answer['used']) ? (int) $answer['used'] : -1;
        $granted = is_array($answer) && isset($answer['granted']) && is_numeric($answer['granted']) ? (int) $answer['granted'] : 0;
        if ($used < 0 || $granted <= 0) {
            return null;
        }

        Configuration::updateValue(self::SNAPSHOT_KEY, $today . ':' . $used . ':' . $granted . ':' . $now);
        $this->raiseDayCounterTo($today, $used);

        return self::reading($used, $granted);
    }

    /**
     * @param string $day the day as Ymd
     * @return int the limit iFirma granted according to the stored reading of that day, 0 when there is none
     */
    public static function grantedOn($day)
    {
        $kept = self::snapshot($day);

        return $kept !== null ? $kept['granted'] : 0;
    }

    /**
     * @param string $day
     * @return array|null used, granted, at; null when nothing usable is stored for the day
     */
    private static function snapshot($day)
    {
        $parts = explode(':', (string) Configuration::get(self::SNAPSHOT_KEY));
        if (count($parts) !== 4 || $parts[0] !== (string) $day) {
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

        return array('used' => (int) $parts[1], 'granted' => (int) $parts[2], 'at' => (int) $parts[3]);
    }

    /**
     * @param int $used
     * @param int $granted
     * @return array
     */
    private static function reading($used, $granted)
    {
        return array('used' => $used, 'granted' => $granted, 'remaining' => max(0, $granted - $used));
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
