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
 * Admission by the minute window and the day window (spec ifirma-api-compliance "Local rate guard").
 */
class RateGuardTest extends TestCase
{
    /** @var int */
    public $now;

    /** @var IfirmaRateGuard */
    private $guard;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 1, 2026);
        $self = $this;
        $this->guard = new IfirmaRateGuard(function () use ($self) {
            return $self->now;
        });
    }

    private function minute()
    {
        return date('YmdHi', $this->now);
    }

    private function day()
    {
        return date('Ymd', $this->now);
    }

    public function testUnderTheCapAdmitsAndCountsBothWindows()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':5');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':100');

        $decision = $this->guard->admit();

        $this->assertTrue($decision->isAllowed());
        $this->assertSame($this->minute() . ':6', Configuration::get('IFIRMA_RATE_WINDOW'));
        $this->assertSame($this->day() . ':101', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testMinuteCapReachedNamesTheMinuteWindow()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':80');

        $decision = $this->guard->admit();

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $decision->getWindow());
        $this->assertSame(
            'iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.',
            $decision->getMessage()
        );
        $this->assertSame($this->minute() . ':80', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testDayCapReachedNamesTheDayWindow()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 500);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':500');

        $decision = $this->guard->admit();

        $this->assertFalse($decision->isAllowed());
        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $decision->getWindow());
        $this->assertSame(
            'iFirma rate limit guard: 500 requests today (cap 500). Retry tomorrow.',
            $decision->getMessage()
        );
        $this->assertSame($this->day() . ':500', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testNewMinuteRestartsTheMinuteCounter()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now - 60) . ':80');

        $this->assertTrue($this->guard->admit()->isAllowed());
        $this->assertSame($this->minute() . ':1', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testNewDayRestartsTheDayCounter()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 500);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now - 86400) . ':500');

        $this->assertTrue($this->guard->admit()->isAllowed());
        $this->assertSame($this->day() . ':1', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testMinuteIsCheckedBeforeTheDay()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':80');
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 500);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':500');

        $decision = $this->guard->admit();

        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $decision->getWindow());
        $this->assertSame(array(), Db::getInstance()->rateDayQueries, 'the day window is not touched');
    }

    public function testADayRefusalLeavesTheMinuteSlotUsed()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':5');
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 500);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':500');

        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $this->guard->admit()->getWindow());
        $this->assertSame($this->minute() . ':6', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testEachWindowIsOneAtomicStatementCarryingItsBucketAndCap()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 7);
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 900);
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':2');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':2');

        $this->guard->admit();

        $d = $this->day();
        $this->assertCount(1, Db::getInstance()->rateQueries);
        $this->assertSame(
            "UPDATE `ps_configuration` SET `value` = IF(SUBSTRING_INDEX(`value`, ':', 1) = '" . $d . "', "
            . "CONCAT('" . $d . ":', SUBSTRING_INDEX(`value`, ':', -1) + 1), '" . $d . ":1') "
            . "WHERE `name` = 'IFIRMA_RATE_DAY_WINDOW' AND (SUBSTRING_INDEX(`value`, ':', 1) <> '" . $d . "' "
            . "OR SUBSTRING_INDEX(`value`, ':', -1) < 900)",
            Db::getInstance()->rateDayQueries[0]
        );
        $this->assertCount(1, Db::getInstance()->rateDayQueries);
    }

    public function testAMissingDayRowIsInsertedOnce()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':2');
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_DAY_WINDOW'));

        $this->assertTrue($this->guard->admit()->isAllowed());

        $this->assertSame($this->day() . ':1', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
        $inserts = 0;
        foreach (Configuration::$writes as $write) {
            if ($write[0] === 'IFIRMA_RATE_DAY_WINDOW') {
                $inserts++;
            }
        }
        $this->assertSame(1, $inserts);
        $this->assertTrue($this->guard->admit()->isAllowed());
        $this->assertSame($this->day() . ':2', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testCapsFallBackToTheDefaultsWhenUnset()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':14000');

        $decision = $this->guard->admit();

        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $decision->getWindow());
        $this->assertStringContainsString('(cap 14000)', $decision->getMessage());
    }

    public function testTheDecisionComesFromTheAffectedRowsAlone()
    {
        Db::getInstance()->whenExecute('/UPDATE .*IFIRMA_RATE_DAY_WINDOW/s', 0);
        Db::getInstance()->whenGetValue('/SELECT `value` .*IFIRMA_RATE_DAY_WINDOW/s', $this->day() . ':5');

        $decision = $this->guard->admit();

        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $decision->getWindow());
        $this->assertStringContainsString('requests today', $decision->getMessage());
    }

    public function testTheClientNamesTheWindowThatRefusedAndUsesItsOwnClock()
    {
        $transport = new ScriptedTransport();
        $transport->queue('GET', '#x\.json#', array('status' => 200, 'body' => '{"response":{"Kod":0}}'));
        $client = new IfirmaApiClient('u', array('faktura' => 'abcd'), 'http://ifirma-mock:8001/iapi/', 30, $transport);
        $self = $this;
        $client->setClock(function () use ($self) {
            return $self->now;
        });
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 500);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':500');

        $this->assertNull($client->get('x.json', 'faktura'));

        $this->assertTrue($client->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $client->rateLimitWindow());
        $this->assertStringContainsString('requests today', $client->getLastError());
        $this->assertSame(array(), $transport->calls);
    }

    public function testASentRequestLeavesNoWindowBehind()
    {
        $transport = new ScriptedTransport();
        $transport->queue('GET', '#x\.json#', array('status' => 200, 'body' => '{"response":{"Kod":0}}'));
        $client = new IfirmaApiClient('u', array('faktura' => 'abcd'), 'http://ifirma-mock:8001/iapi/', 30, $transport);

        $this->assertNotNull($client->get('x.json', 'faktura'));

        $this->assertFalse($client->isRateLimited());
        $this->assertSame('', $client->rateLimitWindow());
    }
}
