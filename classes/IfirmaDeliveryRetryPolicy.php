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
 * When a failed delivery is tried again. A document gets MAX_ATTEMPTS automatic attempts, the first one included;
 * the wait before the second is 15 minutes and before the third 2 hours. Only a temporary failure is retried.
 * A permanent failure and an unknown outcome (a send request that reached iFirma without an answer: the e-mail
 * may have left) are never retried, so a customer cannot be e-mailed twice. A refusal by the local rate guard
 * happened before any request: it counts no attempt and is due at once. Stateless and immutable.
 */
final class IfirmaDeliveryRetryPolicy
{
    const MAX_ATTEMPTS = 3;
    const WAITS = array(900, 7200);

    const FAILURE_TEMPORARY = 'temporary';
    const FAILURE_PERMANENT = 'permanent';
    const FAILURE_UNKNOWN_OUTCOME = 'unknown_outcome';
    const FAILURE_REFUSED = 'refused';

    /**
     * @param int    $attemptsUsed attempts counted before this failure
     * @param string $failureClass one of the FAILURE_* constants
     * @return int attempts counted after this failure
     */
    public function attemptsAfter($attemptsUsed, $failureClass)
    {
        $attemptsUsed = max(0, (int) $attemptsUsed);

        return $failureClass === self::FAILURE_REFUSED ? $attemptsUsed : $attemptsUsed + 1;
    }

    /**
     * @param int    $attemptsUsed attempts counted before this failure
     * @param string $failureClass one of the FAILURE_* constants
     * @param int    $now          unix time of the failure
     * @return int|null unix time the next attempt is due, null when none is scheduled
     */
    public function dueAfter($attemptsUsed, $failureClass, $now)
    {
        if ($failureClass === self::FAILURE_REFUSED) {
            return (int) $now;
        }
        if ($failureClass !== self::FAILURE_TEMPORARY) {
            return null;
        }
        $after = $this->attemptsAfter($attemptsUsed, $failureClass);
        if (!$this->hasAttemptLeft($after)) {
            return null;
        }

        return (int) $now + self::WAITS[$after - 1];
    }

    /**
     * @param int $attemptsUsed
     * @return bool
     */
    public function hasAttemptLeft($attemptsUsed)
    {
        return (int) $attemptsUsed < self::MAX_ATTEMPTS;
    }
}
