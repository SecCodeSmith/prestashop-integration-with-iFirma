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

/**
 * api-usage-accounting: "Sent requests are counted per kind and day" (day rollover, zero counts) and
 * "Kind counts agree with the limit counter" (minute and day totals come from the guard windows).
 */
class ApiUsageTest extends TestCase
{
    /** @var int */
    private $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 10, 2026);
    }

    private function usage()
    {
        $self = $this;

        return new IfirmaApiUsage(function () use ($self) {
            return $self->now;
        });
    }

    private function today()
    {
        return date('Y-m-d', $this->now);
    }

    public function testEveryKindReadsZeroWhenNothingWasSent()
    {
        $this->assertSame(
            array('document' => 0, 'status' => 0, 'pdf' => 0, 'email' => 0, 'ksef' => 0, 'payment' => 0, 'contractor' => 0, 'limit' => 0, 'other' => 0),
            $this->usage()->todayByKind()
        );
    }

    public function testARecordedRequestRaisesOnlyItsKind()
    {
        $this->usage()->record(IfirmaRequestKind::KIND_STATUS);

        $counts = $this->usage()->todayByKind();
        $this->assertSame(1, $counts['status']);
        $this->assertSame(1, array_sum($counts));
    }

    public function testTwoRecordedRequestsOfOneKindCountTwo()
    {
        $usage = $this->usage();
        $usage->record(IfirmaRequestKind::KIND_PDF);
        $usage->record(IfirmaRequestKind::KIND_PDF);

        $this->assertSame(2, $usage->todayByKind()['pdf']);
        $this->assertSame($this->today() . ':2', Configuration::get('IFIRMA_USAGE_PDF'));
    }

    public function testTheCounterOfAnExistingRowIsRaisedByOneStatement()
    {
        Configuration::updateValue('IFIRMA_USAGE_PDF', $this->today() . ':4');
        $db = Db::getInstance();

        $this->usage()->record(IfirmaRequestKind::KIND_PDF);

        $this->assertCount(1, $db->usageQueries);
        $this->assertStringContainsString("`name` = 'IFIRMA_USAGE_PDF'", $db->usageQueries[0]);
        $this->assertSame($this->today() . ':5', Configuration::get('IFIRMA_USAGE_PDF'));
    }

    public function testAMissingRowIsCreatedWithTheFirstCount()
    {
        Db::getInstance()->whenExecute('/IFIRMA_USAGE_EMAIL/', 0);

        $this->usage()->record(IfirmaRequestKind::KIND_EMAIL);

        $this->assertSame($this->today() . ':1', Configuration::get('IFIRMA_USAGE_EMAIL'));
    }

    public function testARowThatIsThereButUnchangedIsNotWrittenAgain()
    {
        Configuration::updateValue('IFIRMA_USAGE_EMAIL', '');
        Db::getInstance()->whenExecute('/IFIRMA_USAGE_EMAIL/', 0);
        $writes = count(Configuration::$writes);

        $this->usage()->record(IfirmaRequestKind::KIND_EMAIL);

        $this->assertCount($writes, Configuration::$writes);
    }

    public function testTheFirstRequestOfANewDayStartsAtOne()
    {
        Configuration::updateValue('IFIRMA_USAGE_PDF', '2026-11-09:12');
        $usage = $this->usage();

        $this->assertSame(0, $usage->todayByKind()['pdf']);
        $usage->record(IfirmaRequestKind::KIND_PDF);

        $this->assertSame(1, $usage->todayByKind()['pdf']);
        $this->assertSame($this->today() . ':1', Configuration::get('IFIRMA_USAGE_PDF'));
    }

    public function testTheDayFollowsTheClockPastMidnight()
    {
        $usage = $this->usage();
        $usage->record(IfirmaRequestKind::KIND_PDF);
        $this->now += 86400;

        $this->assertSame(0, $usage->todayByKind()['pdf']);
    }

    public function testAnUnknownKindIsCountedAsOtherAndNeverReachesTheQuery()
    {
        $this->usage()->record("pdf' WHERE 1=1; DROP TABLE x; --");

        $this->assertSame(1, $this->usage()->todayByKind()['other']);
        foreach (Db::getInstance()->usageQueries as $sql) {
            $this->assertStringNotContainsString('DROP', $sql);
        }
    }

    public function testAFailingWriteIsLoggedAndDoesNotThrow()
    {
        Db::getInstance()->whenExecute('/IFIRMA_USAGE_/', function () {
            throw new RuntimeException('database is gone');
        });

        $this->usage()->record(IfirmaRequestKind::KIND_PDF);

        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertStringContainsString('database is gone', PrestaShopLogger::$logs[0]['message']);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
    }

    public function testASuccessfulWriteLogsNothing()
    {
        $this->usage()->record(IfirmaRequestKind::KIND_PDF);

        $this->assertSame(array(), PrestaShopLogger::$logs);
    }

    public function testTheSummaryTakesMinuteAndDayFromTheGuardWindows()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':6');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now) . ':250');
        Configuration::updateValue('IFIRMA_USAGE_PDF', $this->today() . ':40');

        $summary = $this->usage()->summary();

        $this->assertSame(6, $summary['minute']);
        $this->assertSame(250, $summary['day']);
        $this->assertSame(40, $summary['by_kind']['pdf']);
        $this->assertSame(IfirmaRequestKind::all(), array_keys($summary['by_kind']));
    }

    public function testTheSummaryReadsZeroFromWindowsOfAnEarlierMinuteAndDay()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', '202611100900:30');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', '20261109:900');

        $summary = $this->usage()->summary();

        $this->assertSame(0, $summary['minute']);
        $this->assertSame(0, $summary['day']);
    }

    public function testTheWindowsCarryTheCapsOfTheGuard()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '60');
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':6');

        $windows = $this->usage()->windows();

        $this->assertSame(array('used' => 6, 'cap' => 60), $windows['minute']);
        $this->assertSame(array('used' => 0, 'cap' => IfirmaRateGuard::DEFAULT_DAY_CAP), $windows['day']);
    }

    public function testTheBackoffEndIsReadFromTheGuard()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) ($this->now + 40));

        $this->assertSame($this->now + 40, $this->usage()->backoffUntil());
    }

    public function testNoRunningBackoffReadsZero()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) ($this->now - 5));

        $this->assertSame(0, $this->usage()->backoffUntil());
    }
}
