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

class ApiClientRateGuardTest extends TestCase
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $c;

    /** @var int */
    public $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 1, 2026);
        $this->t = new ScriptedTransport();
        $this->t->queue('GET', '#x\.json#', function () {
            return array('status' => 200, 'body' => '{"response":{"Kod":0}}');
        });
        $this->c = new IfirmaApiClient('u', array('faktura' => 'abcd'), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $self = $this;
        $this->c->setClock(function () use ($self) {
            return $self->now;
        });
    }

    private function minute()
    {
        return date('YmdHi', $this->now);
    }

    public function testUnderCapSendsAndIncrements()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':5');
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertFalse($this->c->isRateLimited());
        $this->assertCount(1, $this->t->calls);
        $this->assertSame($this->minute() . ':6', Configuration::get('IFIRMA_RATE_WINDOW'));
        $this->assertSame(1, $this->c->getRequestCount());
    }

    public function testFirstCallStartsWindow()
    {
        $this->c->get('x.json', 'faktura');
        $this->assertSame($this->minute() . ':1', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testDefaultCapRefusesWithoutTransportCall()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':80');
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(
            'iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.',
            $this->c->getLastError()
        );
        $this->assertSame($this->minute() . ':80', Configuration::get('IFIRMA_RATE_WINDOW'));
        $this->assertSame(0, $this->c->getRequestCount());
    }

    public function testConfiguredCapAndBinaryRefusal()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '3');
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':3');
        $this->assertFalse($this->c->getBinary('x.pdf', 'faktura'));
        $this->assertTrue($this->c->isRateLimited());
        $this->assertStringContainsString('(cap 3)', $this->c->getLastError());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testNewMinuteResetsCounter()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':80');
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->now += 60;
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertFalse($this->c->isRateLimited());
        $this->assertSame($this->minute() . ':1', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testRateLimitedFlagOnlyForRefusedCall()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 1);
        $this->c->get('x.json', 'faktura');
        $this->assertFalse($this->c->isRateLimited());
        $this->c->get('x.json', 'faktura');
        $this->assertTrue($this->c->isRateLimited());
        $this->now += 60;
        $this->c->get('x.json', 'faktura');
        $this->assertFalse($this->c->isRateLimited());
        $this->assertCount(2, $this->t->calls);
    }

    public function testLocalKeyErrorDoesNotConsumeBudget()
    {
        $c = new IfirmaApiClient('u', array('faktura' => 'xyz'), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $c->get('x.json', 'faktura');
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_WINDOW'));
    }

    public function testCounterIsOneStatementCarryingTheMinuteAndTheCap()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '7');
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':2');
        $this->c->get('x.json', 'faktura');

        $statements = Db::getInstance()->rateQueries;
        $this->assertCount(1, $statements);
        $m = $this->minute();
        $this->assertSame(
            "UPDATE `ps_configuration` SET `value` = IF(SUBSTRING_INDEX(`value`, ':', 1) = '" . $m . "', "
            . "CONCAT('" . $m . ":', SUBSTRING_INDEX(`value`, ':', -1) + 1), '" . $m . ":1') "
            . "WHERE `name` = 'IFIRMA_RATE_WINDOW' AND (SUBSTRING_INDEX(`value`, ':', 1) <> '" . $m . "' "
            . "OR SUBSTRING_INDEX(`value`, ':', -1) < 7)",
            $statements[0]
        );
        $this->assertSame(array($statements[0]), Db::getInstance()->rateQueries);
    }

    public function testOneAffectedRowSends()
    {
        Db::getInstance()->whenExecute('/IFIRMA_RATE_WINDOW/', 1);
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertFalse($this->c->isRateLimited());
        $this->assertCount(1, $this->t->calls);
        $this->assertCount(1, Db::getInstance()->rateQueries, 'no second look at the row');
    }

    public function testNoAffectedRowOnAnExistingRowRefusesWithTheStoredCount()
    {
        Db::getInstance()->whenExecute('/UPDATE .*IFIRMA_RATE_WINDOW/s', 0);
        Db::getInstance()->whenGetValue('/SELECT `value` .*IFIRMA_RATE_WINDOW/s', $this->minute() . ':80');
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(
            'iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.',
            $this->c->getLastError()
        );
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_WINDOW'), 'a refusal writes nothing');
    }

    public function testTheDecisionComesFromTheAffectedRowsAlone()
    {
        // The row says 5 of 80, but another process won the race for the last slot: 0 rows affected refuses.
        Db::getInstance()->whenExecute('/UPDATE .*IFIRMA_RATE_WINDOW/s', 0);
        Db::getInstance()->whenGetValue('/SELECT `value` .*IFIRMA_RATE_WINDOW/s', $this->minute() . ':5');
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testMissingRowIsInsertedOnceAndSends()
    {
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_WINDOW'));
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertSame($this->minute() . ':1', Configuration::get('IFIRMA_RATE_WINDOW'));
        $inserts = 0;
        foreach (Configuration::$writes as $write) {
            if ($write[0] === 'IFIRMA_RATE_WINDOW') {
                $inserts++;
            }
        }
        $this->assertSame(1, $inserts);
        $this->assertCount(1, $this->t->calls);

        // the next call finds the row and counts on it
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertSame($this->minute() . ':2', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testStoredWindowOfAnEarlierMinuteIsResetToOne()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now - 120) . ':80');
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertSame($this->minute() . ':1', Configuration::get('IFIRMA_RATE_WINDOW'));
    }
}
