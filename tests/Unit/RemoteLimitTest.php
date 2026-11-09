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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRemoteLimit.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRemoteLimitJob.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRateGuard.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCronRunner.php';

/**
 * Given the limit iFirma reports for the account, when the module reads it, then the local day counter and the day cap
 * follow it (spec ifirma-api-compliance "Reading the current limit from iFirma").
 */
class RemoteLimitTest extends TestCase
{
    /** @var int */
    public $now;

    /** @var array[] the readings the fake API answers, oldest first; null entries are failed reads */
    private $readings = array();

    /** @var int how many times the API was asked */
    private $asked = 0;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 1, 2026);
        $this->readings = array();
        $this->asked = 0;
    }

    private function remoteLimit()
    {
        $self = $this;

        return new IfirmaRemoteLimit(function () use ($self) {
            $self->asked++;

            return $self->readings ? array_shift($self->readings) : null;
        }, function () use ($self) {
            return $self->now;
        });
    }

    private function day()
    {
        return date('Ymd', $this->now);
    }

    private function guard()
    {
        $self = $this;

        return new IfirmaRateGuard(function () use ($self) {
            return $self->now;
        });
    }

    public function testAReadingGivesTheUsedGrantedAndRemainingRequests()
    {
        $this->readings[] = array('used' => 1200, 'granted' => 15000);

        $reading = $this->remoteLimit()->refresh();

        $this->assertSame(array('used' => 1200, 'granted' => 15000, 'remaining' => 13800), $reading);
    }

    public function testTheDayCounterIsRaisedToWhatIFirmaCountsWhenOtherClientsUsedTheAccount()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':300');
        $this->readings[] = array('used' => 1200, 'granted' => 15000);

        $this->remoteLimit()->refresh();

        $this->assertSame($this->day() . ':1200', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testTheDayCounterIsNotLoweredWhenTheModuleCountedMore()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':1500');
        $this->readings[] = array('used' => 1200, 'granted' => 15000);

        $this->remoteLimit()->refresh();

        $this->assertSame($this->day() . ':1500', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testAnOldDaysCounterIsReplacedByTodaysReading()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now - 86400) . ':9000');
        $this->readings[] = array('used' => 40, 'granted' => 15000);

        $this->remoteLimit()->refresh();

        $this->assertSame($this->day() . ':40', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testAFreshReadingIsReusedWithoutAskingIFirmaAgain()
    {
        $this->readings[] = array('used' => 1200, 'granted' => 15000);
        $remote = $this->remoteLimit();
        $remote->refresh();
        $this->now += IfirmaRemoteLimit::REFRESH_SECONDS - 1;

        $reading = $remote->refresh();

        $this->assertSame(1, $this->asked);
        $this->assertSame(array('used' => 1200, 'granted' => 15000, 'remaining' => 13800), $reading);
    }

    public function testAnOldReadingIsReadAgain()
    {
        $this->readings[] = array('used' => 1200, 'granted' => 15000);
        $this->readings[] = array('used' => 1300, 'granted' => 15000);
        $remote = $this->remoteLimit();
        $remote->refresh();
        $this->now += IfirmaRemoteLimit::REFRESH_SECONDS;

        $reading = $remote->refresh();

        $this->assertSame(2, $this->asked);
        $this->assertSame(1300, $reading['used']);
    }

    public function testAReadingOfAnotherDayIsReadAgainAtOnce()
    {
        $this->readings[] = array('used' => 1200, 'granted' => 15000);
        $this->readings[] = array('used' => 5, 'granted' => 15000);
        $remote = $this->remoteLimit();
        $remote->refresh();
        $this->now = mktime(0, 0, 10, 11, 2, 2026);

        $reading = $remote->refresh();

        $this->assertSame(2, $this->asked);
        $this->assertSame(5, $reading['used']);
    }

    public function testAFailedReadKeepsTheEarlierSnapshotAndReturnsNull()
    {
        $this->readings[] = array('used' => 1200, 'granted' => 15000);
        $this->readings[] = null;
        $remote = $this->remoteLimit();
        $remote->refresh();
        $this->now += IfirmaRemoteLimit::REFRESH_SECONDS;
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':1250');

        $this->assertNull($remote->refresh());

        $this->assertSame($this->day() . ':1250', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
        $this->assertSame(15000, IfirmaRemoteLimit::grantedOn($this->day()));
    }

    public function testWithoutAnAnswerNothingIsStored()
    {
        $this->assertNull($this->remoteLimit()->refresh());

        $this->assertSame(0, IfirmaRemoteLimit::grantedOn($this->day()));
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_DAY_WINDOW'));
    }

    /**
     * @return array[] reading
     */
    public function unusableReadings()
    {
        return array(
            'no granted' => array(array('used' => 5, 'granted' => 0)),
            'negative used' => array(array('used' => -1, 'granted' => 15000)),
            'not numbers' => array(array('used' => 'x', 'granted' => 'y')),
            'missing keys' => array(array()),
        );
    }

    /**
     * @dataProvider unusableReadings
     */
    public function testAnUnusableReadingIsTreatedAsNoAnswer(array $reading)
    {
        $this->readings[] = $reading;

        $this->assertNull($this->remoteLimit()->refresh());
        $this->assertSame(0, IfirmaRemoteLimit::grantedOn($this->day()));
    }

    public function testAGrantedLimitBelowTheConfiguredDayCapLowersTheCap()
    {
        $this->readings[] = array('used' => 5000, 'granted' => 5000);
        $this->remoteLimit()->refresh();

        $decision = $this->guard()->admit();

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $decision->getWindow());
        $this->assertSame('iFirma rate limit guard: 5000 requests today (cap 5000). Retry tomorrow.', $decision->getMessage());
    }

    public function testAGrantedLimitAboveTheConfiguredDayCapChangesNothing()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 500);
        $this->readings[] = array('used' => 500, 'granted' => 15000);
        $this->remoteLimit()->refresh();

        $decision = $this->guard()->admit();

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('iFirma rate limit guard: 500 requests today (cap 500). Retry tomorrow.', $decision->getMessage());
    }

    public function testRoomLeftUnderTheGrantedLimitAdmitsTheRequest()
    {
        $this->readings[] = array('used' => 100, 'granted' => 5000);
        $this->remoteLimit()->refresh();

        $this->assertTrue($this->guard()->admit()->isAllowed());
    }

    public function testYesterdaysGrantedLimitDoesNotCapToday()
    {
        Configuration::updateValue('IFIRMA_REMOTE_LIMIT', date('Ymd', $this->now - 86400) . ':5000:5000:' . ($this->now - 90000));
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':5000');

        $this->assertTrue($this->guard()->admit()->isAllowed());
    }

    public function testAMangledSnapshotIsIgnored()
    {
        foreach (array('', 'garbage', $this->day() . ':a:b:c', $this->day() . ':1:2', $this->day() . ':1:0:5') as $stored) {
            Configuration::updateValue('IFIRMA_REMOTE_LIMIT', $stored);

            $this->assertSame(0, IfirmaRemoteLimit::grantedOn($this->day()), var_export($stored, true));
        }
    }

    // ---- the cron job ------------------------------------------------------------------------------------------------

    private function job()
    {
        return new IfirmaRemoteLimitJob($this->remoteLimit());
    }

    public function testTheJobReportsTheReadingInItsBlock()
    {
        $this->readings[] = array('used' => 1200, 'granted' => 15000);

        $report = $this->job()->run(new IfirmaCronContext(10, function () {
            return 0;
        }));

        $this->assertSame('limit', $this->job()->name());
        $this->assertSame(array('used' => 1200, 'granted' => 15000, 'remaining' => 13800), $report->block());
        $this->assertFalse($report->isRateLimited());
    }

    public function testTheJobReportsNullsWhenIFirmaCannotBeRead()
    {
        $report = $this->job()->run(new IfirmaCronContext(10));

        $this->assertSame(array('used' => null, 'granted' => null, 'remaining' => null), $report->block());
        $this->assertFalse($report->isRateLimited());
    }

    public function testASkippedJobAsksNothing()
    {
        $this->readings[] = array('used' => 1, 'granted' => 15000);

        $report = $this->job()->skip(new IfirmaCronContext(10));

        $this->assertSame(0, $this->asked);
        $this->assertSame(array('used' => null, 'granted' => null, 'remaining' => null), $report->block());
    }

    public function testTheRunnerReadsTheLimitBeforeTheOtherJobs()
    {
        $runner = IfirmaCronRunner::build(new Module());
        $jobs = new ReflectionProperty(IfirmaCronRunner::class, 'jobs');
        $jobs->setAccessible(true);

        $this->assertSame(array('limit', 'queue', 'ksef', 'sync', 'delivery'), array_map(function ($job) {
            return $job->name();
        }, $jobs->getValue($runner)));
    }
}
