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

/**
 * How long a caller refused by the local request limit is told to wait: the `Retry-After` value of a 429.
 */
class IfirmaRetryAfter
{
    /** A day window can end hours away; a caller is asked to look again within the hour. */
    const DAY_WINDOW_CAP_SECONDS = 3600;

    const MINIMUM_SECONDS = 1;

    /**
     * @param string $window       one of the IfirmaRateDecision::WINDOW_* constants
     * @param int    $now          unix time
     * @param int    $backoffUntil unix time the stored back-off ends, 0 when none runs
     * @param int    $minuteFreeAt unix time the last 60 seconds have room again (IfirmaRateGuard::minuteFreeAt())
     * @return int whole seconds, at least one
     */
    public static function seconds($window, $now, $backoffUntil, $minuteFreeAt = 0)
    {
        $now = (int) $now;
        switch ($window) {
            case IfirmaRateDecision::WINDOW_MINUTE:
                $seconds = (int) $minuteFreeAt - $now;
                break;
            case IfirmaRateDecision::WINDOW_BACKOFF:
                $seconds = (int) $backoffUntil - $now;
                break;
            case IfirmaRateDecision::WINDOW_DAY:
                $seconds = min(self::DAY_WINDOW_CAP_SECONDS, self::secondsToNextDay($now));
                break;
            default:
                $seconds = self::MINIMUM_SECONDS;
        }

        return max(self::MINIMUM_SECONDS, $seconds);
    }

    /**
     * @param int $now
     * @return int seconds to the next midnight of the PHP default timezone, which PrestaShop sets to the shop's
     */
    private static function secondsToNextDay($now)
    {
        $nextMidnight = mktime(0, 0, 0, (int) date('n', $now), (int) date('j', $now) + 1, (int) date('Y', $now));

        return $nextMidnight - $now;
    }
}
