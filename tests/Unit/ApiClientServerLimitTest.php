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
 * iFirma's own limit answer: recognised, never an error or an unknown outcome, and followed by a stored
 * back-off during which nothing is sent (spec ifirma-api-compliance "iFirma limit answer starts a back-off").
 */
class ApiClientServerLimitTest extends TestCase
{
    const LIMIT_BODY = '{"response":{"Kod":100,"Informacja":"Przekroczono limit zapytań (limit minutowy: 100)"}}';

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $c;

    /** @var int */
    public $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 40, 11, 1, 2026);
        $this->t = new ScriptedTransport();
        $this->c = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('ab', 16)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $self = $this;
        $this->c->setClock(function () use ($self) {
            return $self->now;
        });
    }

    private function answerWith($status, $body, array $headers = array())
    {
        $this->t->queue('GET', '#x\.json#', array('status' => $status, 'body' => $body, 'headers' => $headers));
    }

    private function answerOk()
    {
        $this->answerWith(200, '{"response":{"Kod":0}}');
    }

    public function testHttp429WithRetryAfterIsRateLimitedAndStartsABackOff()
    {
        $this->answerWith(429, self::LIMIT_BODY, array('Retry-After' => '20'));

        $this->assertNull($this->c->get('x.json', 'faktura'));

        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_SERVER, $this->c->rateLimitWindow());
        $this->assertSame((string) ($this->now + 20), (string) Configuration::get('IFIRMA_RATE_BACKOFF_UNTIL'));
        $this->assertStringContainsString(date('Y-m-d H:i:s', $this->now + 20), $this->c->getLastError());
    }

    public function testTheBackOffSendsNothingUntilItEnds()
    {
        $this->answerWith(429, self::LIMIT_BODY, array('Retry-After' => '20'));
        $this->c->get('x.json', 'faktura');
        $this->answerOk();

        $this->now += 19;
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertCount(1, $this->t->calls, 'no request during the back-off');

        $this->now += 1;
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertCount(2, $this->t->calls);
        $this->assertFalse($this->c->isRateLimited());
    }

    public function testALimitMessageWithoutHttp429IsRateLimitedAndNotATechnicalError()
    {
        $this->answerWith(200, self::LIMIT_BODY);

        $this->assertNull($this->c->get('x.json', 'faktura'));

        $this->assertTrue($this->c->isRateLimited());
        $this->assertNull($this->c->getLastKod());
        $this->assertStringNotContainsString('Technical problem', $this->c->getLastError());
        $this->assertFalse($this->c->isAuthBlocked());
        $this->assertFalse(Configuration::hasKey('IFIRMA_AUTH_FAILURE'));
    }

    public function testNoRetryAfterBacksOffToTheStartOfTheNextMinute()
    {
        $this->answerWith(429, self::LIMIT_BODY);

        $this->c->get('x.json', 'faktura');

        $this->assertSame((string) mktime(10, 16, 0, 11, 1, 2026), (string) Configuration::get('IFIRMA_RATE_BACKOFF_UNTIL'));
    }

    public function testAnExcessiveRetryAfterIsCappedAtADay()
    {
        $this->answerWith(429, self::LIMIT_BODY, array('Retry-After' => '999999'));

        $this->c->get('x.json', 'faktura');

        $this->assertSame((string) ($this->now + 86400), (string) Configuration::get('IFIRMA_RATE_BACKOFF_UNTIL'));
    }

    /**
     * @return array[]
     */
    public function unusableRetryAfter()
    {
        return array(
            'text' => array('soon'),
            'zero' => array('0'),
            'negative' => array('-5'),
            'decimal' => array('1.5'),
            'empty' => array(''),
            'a date' => array('Wed, 21 Oct 2026 07:28:00 GMT'),
        );
    }

    /**
     * @dataProvider unusableRetryAfter
     */
    public function testARetryAfterThatIsNotAPositiveWholeNumberFallsBackToTheNextMinute($value)
    {
        $this->answerWith(429, self::LIMIT_BODY, array('Retry-After' => $value));

        $this->c->get('x.json', 'faktura');

        $this->assertSame((string) mktime(10, 16, 0, 11, 1, 2026), (string) Configuration::get('IFIRMA_RATE_BACKOFF_UNTIL'));
    }

    public function testAnHttp429WithABodyThatIsNotJsonIsStillRateLimited()
    {
        $this->answerWith(429, '<html>Too Many Requests</html>');

        $this->assertNull($this->c->get('x.json', 'faktura'));

        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_SERVER, $this->c->rateLimitWindow());
    }

    public function testABinaryDownloadThatIsRefusedStartsTheBackOffToo()
    {
        $this->t->queue('GET', '#x\.pdf#', array('status' => 429, 'body' => self::LIMIT_BODY, 'headers' => array('Retry-After' => '30')));

        $this->assertFalse($this->c->getBinary('x.pdf', 'faktura'));

        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame((string) ($this->now + 30), (string) Configuration::get('IFIRMA_RATE_BACKOFF_UNTIL'));
    }

    public function testABackOffRefusalIsLocalAndNamesItsEndTime()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) ($this->now + 90));
        $this->answerOk();

        $this->assertNull($this->c->get('x.json', 'faktura'));

        $this->assertSame(array(), $this->t->calls);
        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_BACKOFF, $this->c->rateLimitWindow());
        $this->assertStringContainsString(date('Y-m-d H:i:s', $this->now + 90), $this->c->getLastError());
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_WINDOW'), 'a back-off refusal counts nothing');
    }

    public function testABackOffThatEndedDoesNotRefuse()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) ($this->now - 1));
        $this->answerOk();

        $this->assertNotNull($this->c->get('x.json', 'faktura'));
    }

    public function testABackOffMoreThanADayAheadIsAClockMistakeAndCountsAsEnded()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) ($this->now + 86401));
        $this->answerOk();

        $this->assertNotNull($this->c->get('x.json', 'faktura'));
    }

    public function testAnUnusableStoredBackOffIsIgnored()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', 'tomorrow');
        $this->answerOk();

        $this->assertNotNull($this->c->get('x.json', 'faktura'));
    }

    public function testOtherErrorCodesAreHandledAsBefore()
    {
        $this->answerWith(200, '{"response":{"Kod":100,"Informacja":"Bad hash"}}');

        $out = $this->c->get('x.json', 'faktura');

        $this->assertSame(100, $out['response']['Kod']);
        $this->assertSame(100, $this->c->getLastKod());
        $this->assertSame('Technical problem on the iFirma side (Kod 100): Bad hash', $this->c->getLastError());
        $this->assertFalse($this->c->isRateLimited());
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_BACKOFF_UNTIL'));
    }

    public function testAValidationMessageThatMentionsALimitIsNotTheRequestLimit()
    {
        $this->answerWith(200, '{"response":{"Kod":201,"Informacja":"Pole Nazwa przekracza limit znaków"}}');

        $this->assertNotNull($this->c->get('x.json', 'faktura'));

        $this->assertFalse($this->c->isRateLimited());
        $this->assertSame(201, $this->c->getLastKod());
    }

    public function testAnOrdinaryServerErrorIsNotARateLimit()
    {
        $this->answerWith(500, '{"response":{"Kod":0}}');

        $this->c->get('x.json', 'faktura');

        $this->assertFalse($this->c->isRateLimited());
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_BACKOFF_UNTIL'));
    }

    public function testTheRequestThatWasRefusedByIfirmaStillCountsAsSent()
    {
        $this->answerWith(429, self::LIMIT_BODY, array('Retry-After' => '20'));

        $this->c->get('x.json', 'faktura');

        $this->assertTrue($this->c->lastRequestWasSent());
        $this->assertSame(429, $this->c->getLastHttpCode());
        $this->assertSame(1, $this->c->getRequestCount());
    }
}
