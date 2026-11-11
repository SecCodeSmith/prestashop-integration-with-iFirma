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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRetryAfter.php';

/**
 * Seconds a refused caller is told to wait (design D4 of the api-usage change, requirement "A refused request is
 * reported and not retried in place").
 */
class RetryAfterTest extends TestCase
{
    public function testTheMinuteWindowWaitsUntilTheNextCalendarMinute()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);

        $this->assertSame(40, IfirmaRetryAfter::seconds(IfirmaRateDecision::WINDOW_MINUTE, $now, 0));
    }

    public function testTheFirstSecondOfAMinuteWaitsAWholeMinute()
    {
        $now = mktime(10, 15, 0, 11, 1, 2026);

        $this->assertSame(60, IfirmaRetryAfter::seconds(IfirmaRateDecision::WINDOW_MINUTE, $now, 0));
    }

    public function testTheBackoffWaitsUntilItsStoredEnd()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);

        $this->assertSame(40, IfirmaRetryAfter::seconds(IfirmaRateDecision::WINDOW_BACKOFF, $now, $now + 40));
    }

    public function testTheBackoffEndsNoLaterThanItsStoredEndEvenWhenFarAway()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);

        $this->assertSame(3000, IfirmaRetryAfter::seconds(IfirmaRateDecision::WINDOW_BACKOFF, $now, $now + 3000));
    }

    public function testADayWindowThatEndsSoonWaitsUntilMidnightOfTheShopTimezone()
    {
        $now = mktime(23, 50, 0, 11, 1, 2026);

        $this->assertSame(600, IfirmaRetryAfter::seconds(IfirmaRateDecision::WINDOW_DAY, $now, 0));
    }

    public function testADayWindowIsCappedAtAnHour()
    {
        $now = mktime(10, 0, 0, 11, 1, 2026);

        $this->assertSame(3600, IfirmaRetryAfter::seconds(IfirmaRateDecision::WINDOW_DAY, $now, 0));
    }

    public function testTheWaitIsNeverBelowOneSecond()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);

        $this->assertSame(1, IfirmaRetryAfter::seconds(IfirmaRateDecision::WINDOW_BACKOFF, $now, $now));
    }

    public function testAWindowThatIsNotARateLimitWaitsTheShortestTime()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);

        $this->assertSame(1, IfirmaRetryAfter::seconds('unknown', $now, 0));
    }
}
