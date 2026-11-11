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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRateGuard.php';

/**
 * A counter that lets another request change the row between the moment it was read and the moment it is updated,
 * the way a concurrent request would.
 */
class RollingWindowCounterWithARival extends IfirmaRollingWindowCounter
{
    /** @var callable|null runs after a read, while the rival is still to act */
    public $rival;

    /** @var int reads that the rival acts after */
    public $rivalActs = 0;

    protected function storedValue($key)
    {
        $value = parent::storedValue($key);
        if ($this->rivalActs > 0 && $this->rival !== null) {
            $this->rivalActs--;
            call_user_func($this->rival);
        }

        return $value;
    }
}

/**
 * The minute cap holds for every period of 60 seconds, not per calendar minute (spec ifirma-api-compliance
 * "Local rate guard": burst across the turn of a minute, new minute, concurrent requests, counter of an earlier
 * version, counter dated in the future).
 */
class RollingMinuteWindowTest extends TestCase
{
    const KEY = 'IFIRMA_RATE_WINDOW';

    /** @var int */
    public $now;

    /** @var IfirmaRateGuard */
    private $guard;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->clockAt(10, 0, 59);
        $this->guard = $this->guardWith(new IfirmaRollingWindowCounter());
    }

    private function guardWith(IfirmaRollingWindowCounter $rolling)
    {
        $self = $this;

        return new IfirmaRateGuard(function () use ($self) {
            return $self->now;
        }, null, $rolling);
    }

    private function clockAt($hour, $minute, $second)
    {
        $this->now = mktime($hour, $minute, $second, 11, 1, 2026);
    }

    private function admitTimes($times)
    {
        $allowed = 0;
        for ($i = 0; $i < $times; $i++) {
            if ($this->guard->admit()->isAllowed()) {
                $allowed++;
            }
        }

        return $allowed;
    }

    private function usedInTheWindow()
    {
        return (new IfirmaRollingWindowCounter())->count(self::KEY, $this->now);
    }

    public function testABurstAcrossTheTurnOfAMinuteIsRefused()
    {
        $this->assertSame(80, $this->admitTimes(80));
        $this->clockAt(10, 1, 0);

        $this->assertSame(0, $this->admitTimes(80));
        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $this->guard->admit()->getWindow());
    }

    public function testTheRefusalSaysHowManyRequestsTheLastSixtySecondsHold()
    {
        $this->admitTimes(80);
        $this->clockAt(10, 1, 0);

        $this->assertSame(
            'iFirma rate limit guard: 80 requests in the last 60 seconds (cap 80). Retry shortly.',
            $this->guard->admit()->getMessage()
        );
    }

    public function testRequestsAreSentAgainWhenTheOldOnesAreOlderThanSixtySeconds()
    {
        $this->admitTimes(80);

        $this->clockAt(10, 1, 59);
        $this->assertFalse($this->guard->admit()->isAllowed(), 'exactly 60 seconds old is still counted');

        $this->clockAt(10, 2, 0);
        $this->assertTrue($this->guard->admit()->isAllowed());
        $this->assertSame(1, $this->usedInTheWindow());
    }

    public function testTwoRequestsAtTheSameMomentRaiseTheCountByTwo()
    {
        $other = $this->guardWith(new IfirmaRollingWindowCounter());

        $this->assertTrue($this->guard->admit()->isAllowed());
        $this->assertTrue($other->admit()->isAllowed());

        $this->assertSame(2, $this->usedInTheWindow());
        $this->assertSame(date('Ymd', $this->now) . ':2', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testARivalThatWinsTheRowOnceMakesTheRequestReadAgainAndStillCountBoth()
    {
        Configuration::updateValue(self::KEY, $this->now . '=5');
        $counter = new RollingWindowCounterWithARival();
        $counter->rivalActs = 1;
        $counter->rival = function () {
            (new IfirmaRollingWindowCounter())->take(RollingMinuteWindowTest::KEY, 80, $this->now);
        };

        $this->assertNull($counter->take(self::KEY, 80, $this->now));

        $this->assertSame(7, $this->usedInTheWindow());
    }

    public function testOnlyOneOfTwoRequestsOneBelowTheCapGetsTheLastSlot()
    {
        Configuration::updateValue(self::KEY, $this->now . '=79');
        $counter = new RollingWindowCounterWithARival();
        $counter->rivalActs = 1;
        $counter->rival = function () {
            (new IfirmaRollingWindowCounter())->take(RollingMinuteWindowTest::KEY, 80, $this->now);
        };

        $this->assertSame(80, $counter->take(self::KEY, 80, $this->now));

        $this->assertSame(80, $this->usedInTheWindow());
    }

    public function testARequestThatKeepsLosingTheRowIsRefusedAtTheCap()
    {
        Configuration::updateValue(self::KEY, $this->now . '=5');
        $counter = new RollingWindowCounterWithARival();
        $counter->rivalActs = 99;
        $counter->rival = function () {
            (new IfirmaRollingWindowCounter())->take(RollingMinuteWindowTest::KEY, 80, $this->now);
        };

        $this->assertSame(80, $counter->take(self::KEY, 80, $this->now));

        $this->assertSame(5 + IfirmaRollingWindowCounter::MAX_ATTEMPTS, $this->usedInTheWindow(), 'each rival counted once');
    }

    public function testAMinuteCounterOfAnEarlierVersionIsReadAsRequestsOfItsMinute()
    {
        Configuration::updateValue(self::KEY, '202611011000:80');
        $this->clockAt(10, 1, 0);

        $decision = $this->guard->admit();

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $decision->getWindow());
        $this->assertSame('202611011000:80', Configuration::get(self::KEY), 'a refusal writes nothing');
    }

    public function testAMinuteCounterOfAnEarlierVersionIsGoneAfterSixtySecondsAndTheRowTakesTheNewFormat()
    {
        Configuration::updateValue(self::KEY, '202611011000:80');
        $this->clockAt(10, 1, 59);
        $this->assertFalse($this->guard->admit()->isAllowed());

        $this->clockAt(10, 2, 0);

        $this->assertTrue($this->guard->admit()->isAllowed());
        $this->assertSame($this->now . '=1', Configuration::get(self::KEY));
    }

    public function testAMinuteCounterOfAnEarlierVersionAddsToTheNewCount()
    {
        Configuration::updateValue(self::KEY, '202611011000:10');
        $this->clockAt(10, 1, 0);

        $this->assertTrue($this->guard->admit()->isAllowed());

        $this->assertSame(11, $this->usedInTheWindow());
        $this->assertSame(mktime(10, 0, 59, 11, 1, 2026) . '=10,' . $this->now . '=1', Configuration::get(self::KEY));
    }

    public function testRequestsDatedAFarWayAheadAreCountedAsRequestsOfTheCurrentSecond()
    {
        Configuration::updateValue(self::KEY, ($this->now + 3600) . '=80');

        $this->assertFalse($this->guard->admit()->isAllowed());
        $this->assertSame($this->now . '=80', Configuration::get(self::KEY));
    }

    public function testRequestsDatedAFewSecondsAheadAreCountedAndAgeFromTheCurrentSecond()
    {
        Configuration::updateValue(self::KEY, ($this->now + 30) . '=80');

        $this->assertFalse($this->guard->admit()->isAllowed());
        $this->assertSame($this->now . '=80', Configuration::get(self::KEY));
        $this->clockAt(10, 1, 59);
        $this->assertFalse($this->guard->admit()->isAllowed());
        $this->clockAt(10, 2, 0);
        $this->assertTrue($this->guard->admit()->isAllowed());
    }

    public function testAPartlyFullWindowWithFutureRequestsTakesTheSlotAndKeepsTheOldCountAtTheCurrentSecond()
    {
        Configuration::updateValue(self::KEY, ($this->now - 5) . '=10,' . ($this->now + 100) . '=20');

        $this->assertTrue($this->guard->admit()->isAllowed());

        $this->assertSame(($this->now - 5) . '=10,' . $this->now . '=21', Configuration::get(self::KEY));
    }

    public function testTheWindowOfFutureRequestsHasRoomSixtyOneSecondsFromNow()
    {
        Configuration::updateValue(self::KEY, ($this->now + 500) . '=80');

        $this->assertSame($this->now + 61, $this->guard->minuteFreeAt());
        $this->assertSame(($this->now + 500) . '=80', Configuration::get(self::KEY), 'asking writes nothing');
    }

    public function testAnOrdinaryRefusalSendsNoUpdate()
    {
        Configuration::updateValue(self::KEY, ($this->now - 5) . '=80');

        $this->assertFalse($this->guard->admit()->isAllowed());

        $this->assertSame(array(), preg_grep('/^UPDATE/', Db::getInstance()->rateQueries));
    }

    public function testALegacyRowOfTheCurrentMinuteRefusesNowAndHasRoomSixtyOneSecondsAfterTheFirstRefusal()
    {
        $this->clockAt(10, 0, 30);
        Configuration::updateValue(self::KEY, date('YmdHi', $this->now) . ':80');

        $this->assertFalse($this->guard->admit()->isAllowed());
        $this->assertSame($this->now . '=80', Configuration::get(self::KEY));
        $this->clockAt(10, 1, 30);
        $this->assertFalse($this->guard->admit()->isAllowed());
        $this->clockAt(10, 1, 31);
        $this->assertTrue($this->guard->admit()->isAllowed());
    }

    public function testARefusalThatLosesTheWriteBackStillRefuses()
    {
        Configuration::updateValue(self::KEY, ($this->now + 30) . '=80');
        Db::getInstance()->whenExecute('/UPDATE .*IFIRMA_RATE_WINDOW/s', 0);

        $this->assertFalse($this->guard->admit()->isAllowed());
    }

    public function testTwentyRequestsAtTheSameInstantAreAllServed()
    {
        Configuration::updateValue(self::KEY, $this->now . '=5');
        $counter = new RollingWindowCounterWithARival();
        $counter->rivalActs = IfirmaRollingWindowCounter::MAX_ATTEMPTS - 1;
        $counter->rival = function () {
            (new IfirmaRollingWindowCounter())->take(RollingMinuteWindowTest::KEY, 80, $this->now);
        };

        $this->assertNull($counter->take(self::KEY, 80, $this->now));

        $this->assertSame(5 + IfirmaRollingWindowCounter::MAX_ATTEMPTS, $this->usedInTheWindow());
    }

    public function testAnUnreadableCounterIsReadAsEmptyAndThenReplaced()
    {
        Configuration::updateValue(self::KEY, "x=1,12=,abc,5=99999999999999999999,'; DROP TABLE x;--");

        $this->assertTrue($this->guard->admit()->isAllowed());

        $this->assertSame($this->now . '=1', Configuration::get(self::KEY));
    }

    public function testACounterWithMarkupIsReplacedNotLeftToBlockTheGuard()
    {
        Configuration::updateValue(self::KEY, '1<2');

        $this->assertTrue($this->guard->admit()->isAllowed());

        $this->assertSame($this->now . '=1', Configuration::get(self::KEY));
    }

    public function testAnEmptyCounterRowIsCountedFromZero()
    {
        Configuration::updateValue(self::KEY, '');

        $this->assertTrue($this->guard->admit()->isAllowed());

        $this->assertSame($this->now . '=1', Configuration::get(self::KEY));
    }

    public function testAHugeCountIsCutAndStillRefuses()
    {
        Configuration::updateValue(self::KEY, $this->now . '=999999999,' . ($this->now - 1) . '=999999999');

        $this->assertFalse($this->guard->admit()->isAllowed());
    }

    public function testTheRowKeepsOnlySecondsInsideTheWindow()
    {
        Configuration::updateValue(self::KEY, ($this->now - 90) . '=3,' . ($this->now - 60) . '=2,' . ($this->now - 10) . '=1');

        $this->guard->admit();

        $this->assertSame(($this->now - 60) . '=2,' . ($this->now - 10) . '=1,' . $this->now . '=1', Configuration::get(self::KEY));
    }

    public function testTheMinuteBlocksWhenTheLastSixtySecondsReachedTheCap()
    {
        $this->admitTimes(80);
        $this->clockAt(10, 1, 0);

        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $this->guard->blockedBy());
    }

    public function testTheMinuteDoesNotBlockWhenTheOldRequestsAreOlderThanSixtySeconds()
    {
        $this->admitTimes(80);
        $this->clockAt(10, 2, 0);

        $this->assertSame(IfirmaRateDecision::WINDOW_NONE, $this->guard->blockedBy());
    }

    public function testTheMinuteUsageIsTheCountOfTheLastSixtySeconds()
    {
        $this->admitTimes(3);
        $this->clockAt(10, 1, 30);
        $this->admitTimes(2);

        $usage = $this->guard->windowUsage();

        $this->assertSame(array('used' => 5, 'cap' => 80), $usage[IfirmaRateDecision::WINDOW_MINUTE]);

        $this->clockAt(10, 2, 0);
        $usage = $this->guard->windowUsage();
        $this->assertSame(2, $usage[IfirmaRateDecision::WINDOW_MINUTE]['used']);
    }

    public function testAMissingRowIsCreatedWithTheFirstRequest()
    {
        $this->assertFalse(Configuration::hasKey(self::KEY));

        $this->assertTrue($this->guard->admit()->isAllowed());

        $this->assertSame($this->now . '=1', Configuration::get(self::KEY));
    }

    public function testASecondInTheRowIsNeverCountedTwice()
    {
        Configuration::updateValue(self::KEY, $this->now . '=1,' . $this->now . '=1');

        $this->assertSame(1, $this->usedInTheWindow());
    }

    public function testTheWindowHasRoomAtOnceWhenItIsBelowTheCap()
    {
        $this->admitTimes(79);

        $this->assertSame($this->now, $this->guard->minuteFreeAt());
    }

    public function testAFullWindowHasRoomWhenTheOldestRequestsAreSixtyOneSecondsOld()
    {
        $this->clockAt(10, 0, 10);
        $this->admitTimes(80);
        $this->clockAt(10, 0, 40);

        $this->assertSame(mktime(10, 1, 11, 11, 1, 2026), $this->guard->minuteFreeAt());
    }

    public function testTheWaitEndsWhenEnoughOfTheOldestSecondsAreGone()
    {
        $this->clockAt(10, 0, 10);
        $this->admitTimes(30);
        $this->clockAt(10, 0, 20);
        $this->admitTimes(30);
        $this->clockAt(10, 0, 30);
        $this->admitTimes(20);
        $this->clockAt(10, 0, 40);

        $this->assertSame(mktime(10, 1, 11, 11, 1, 2026), $this->guard->minuteFreeAt(), 'losing the 30 oldest leaves 50, below the cap');

        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 80);
        Configuration::updateValue(self::KEY, mktime(10, 0, 10, 11, 1, 2026) . '=1,' . mktime(10, 0, 20, 11, 1, 2026) . '=1,' . mktime(10, 0, 30, 11, 1, 2026) . '=79');
        $this->assertSame(mktime(10, 1, 21, 11, 1, 2026), $this->guard->minuteFreeAt(), 'one oldest request is not enough, the second second must go too');
    }

    public function testAWindowThatFreedBetweenTheRefusalAndTheQuestionHasRoomNow()
    {
        $this->admitTimes(80);
        $this->clockAt(10, 2, 0);

        $this->assertSame($this->now, $this->guard->minuteFreeAt());
    }

    public function testFutureDatedRequestsAreWaitedOutFromTheCurrentSecond()
    {
        Configuration::updateValue(self::KEY, ($this->now + 20) . '=80');

        $this->assertSame($this->now + 61, $this->guard->minuteFreeAt());
    }

    public function testRequestsStoredBeforeAClockWasSetBackAreStillCountedAndThenAge()
    {
        $this->clockAt(12, 0, 0);
        $this->admitTimes(80);
        $this->clockAt(10, 0, 0);

        $this->assertFalse($this->guard->admit()->isAllowed(), 'the 80 requests of the future are not forgotten');
        $this->assertSame($this->now . '=80', Configuration::get(self::KEY), 'the refusal re-dated them to the current second');

        $this->clockAt(10, 1, 0);
        $this->assertFalse($this->guard->admit()->isAllowed());
        $this->clockAt(10, 1, 1);
        $this->assertTrue($this->guard->admit()->isAllowed());
    }
}
