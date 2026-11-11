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

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/classes/IfirmaDeliveryRetryPolicy.php';

/**
 * Scenarios of the receipt-delivery spec: "Automatic retry of a failed delivery", "Failures that are never
 * retried automatically" and "A limit refusal uses no delivery attempt". One row per line of the failure
 * class table of the design (D6) and per step of the schedule.
 */
class DeliveryRetryPolicyTest extends TestCase
{
    const NOW = 1000000;

    public function schedule()
    {
        return array(
            'temporary, first failure: attempt 1, due in 15 minutes' => array(0, 'temporary', 1, self::NOW + 900),
            'temporary, second failure: attempt 2, due in 2 hours' => array(1, 'temporary', 2, self::NOW + 7200),
            'temporary, third failure: attempt 3, none left' => array(2, 'temporary', 3, null),
            'temporary beyond the cap stays without a due time' => array(3, 'temporary', 4, null),
            'permanent counts an attempt and never retries' => array(0, 'permanent', 1, null),
            'unknown outcome counts an attempt and never retries' => array(0, 'unknown_outcome', 1, null),
            'refused counts nothing and is due at once' => array(0, 'refused', 0, self::NOW),
            'refused keeps the attempts already used' => array(1, 'refused', 1, self::NOW),
            'an unrecognised class is never retried' => array(0, 'surprise', 1, null),
        );
    }

    /**
     * @dataProvider schedule
     */
    public function testTheFailureClassDecidesAttemptsAndDueTime($used, $class, $attempts, $due)
    {
        $policy = new IfirmaDeliveryRetryPolicy();

        $this->assertSame($attempts, $policy->attemptsAfter($used, $class));
        $this->assertSame($due, $policy->dueAfter($used, $class, self::NOW));
    }

    public function testThePolicyHoldsTheOwnersFixedValues()
    {
        $this->assertSame(3, IfirmaDeliveryRetryPolicy::MAX_ATTEMPTS);
        $this->assertSame(array(900, 7200), IfirmaDeliveryRetryPolicy::WAITS);
    }

    public function testAnAttemptRemainsOnlyBelowTheCap()
    {
        $policy = new IfirmaDeliveryRetryPolicy();

        $this->assertTrue($policy->hasAttemptLeft(2));
        $this->assertFalse($policy->hasAttemptLeft(3));
    }
}
