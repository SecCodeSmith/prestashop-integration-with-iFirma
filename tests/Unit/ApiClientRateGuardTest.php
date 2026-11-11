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
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->now . '=5');
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertFalse($this->c->isRateLimited());
        $this->assertCount(1, $this->t->calls);
        $this->assertSame($this->now . '=6', Configuration::get('IFIRMA_RATE_WINDOW'));
        $this->assertSame(1, $this->c->getRequestCount());
    }

    public function testFirstCallStartsWindow()
    {
        $this->c->get('x.json', 'faktura');
        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testDefaultCapRefusesWithoutTransportCall()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->now . '=80');
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(
            'iFirma rate limit guard: 80 requests in the last 60 seconds (cap 80). Retry shortly.',
            $this->c->getLastError()
        );
        $this->assertSame($this->now . '=80', Configuration::get('IFIRMA_RATE_WINDOW'));
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

    public function testTheCounterRestartsOnceTheRequestsAreOlderThanSixtySeconds()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->now . '=80');
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->now += 60;
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->now += 1;
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertFalse($this->c->isRateLimited());
        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testRateLimitedFlagOnlyForRefusedCall()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 1);
        $this->c->get('x.json', 'faktura');
        $this->assertFalse($this->c->isRateLimited());
        $this->c->get('x.json', 'faktura');
        $this->assertTrue($this->c->isRateLimited());
        $this->now += 61;
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

    public function testCounterIsOneReadAndOneCompareAndSetStatement()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '7');
        Configuration::updateValue('IFIRMA_RATE_WINDOW', ($this->now - 5) . '=2');
        $this->c->get('x.json', 'faktura');

        $statements = Db::getInstance()->rateQueries;
        $this->assertCount(2, $statements);
        $this->assertSame("SELECT `value` FROM `ps_configuration` WHERE `name` = 'IFIRMA_RATE_WINDOW' ORDER BY `id_configuration`", $statements[0]);
        $this->assertSame(
            "UPDATE `ps_configuration` SET `value` = '" . ($this->now - 5) . "=2," . $this->now . "=1' "
            . "WHERE `name` = 'IFIRMA_RATE_WINDOW' AND `value` = '" . ($this->now - 5) . "=2'",
            $statements[1]
        );
    }

    public function testOneAffectedRowSends()
    {
        Db::getInstance()->whenExecute('/IFIRMA_RATE_WINDOW/', 1);
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertFalse($this->c->isRateLimited());
        $this->assertCount(1, $this->t->calls);
        $this->assertCount(1, Db::getInstance()->rateQueries, 'no second look at the row');
    }

    public function testNoAffectedRowOnAnExistingRowRefusesAtTheCapAfterTheRetries()
    {
        Db::getInstance()->whenExecute('/UPDATE .*IFIRMA_RATE_WINDOW/s', 0);
        Db::getInstance()->whenGetValue('/SELECT `value` .*IFIRMA_RATE_WINDOW/s', $this->now . '=79');
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(
            'iFirma rate limit guard: 80 requests in the last 60 seconds (cap 80). Retry shortly.',
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
        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_RATE_WINDOW'));
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
        $this->assertSame($this->now . '=2', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testStoredWindowOfAnEarlierMinuteIsResetToOne()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now - 120) . ':80');
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertSame($this->now . '=1', Configuration::get('IFIRMA_RATE_WINDOW'));
    }

    public function testTheRetryAfterOfAMinuteRefusalIsWhenTheOldestRequestsAreSixtyOneSecondsOld()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', ($this->now - 30) . '=80');

        $this->assertNull($this->c->get('x.json', 'faktura'));

        $this->assertSame(31, $this->c->retryAfterSeconds());
    }
}
