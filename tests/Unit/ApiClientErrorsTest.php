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

class ApiClientErrorsTest extends TestCase
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
        $this->t = new ScriptedTransport();
        $this->c = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('ab', 16)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->now = mktime(10, 0, 0, 11, 1, 2026);
        $self = $this;
        $this->c->setClock(function () use ($self) {
            return $self->now;
        });
    }

    public function kodProvider()
    {
        return array(
            array(403, 'Invalid signature - check the API key (Kod 403): Bad hash'),
            array(401, 'Invalid user login (Kod 401): Bad hash'),
            array(499, 'iFirma terms of service not accepted (Kod 499): Bad hash'),
            array(500, 'Insufficient permissions (super-account required) (Kod 500): Bad hash'),
            array(100, 'Technical problem on the iFirma side (Kod 100): Bad hash'),
            array(201, 'Validation error in document data (Kod 201): Bad hash'),
            array(777, 'iFirma error (Kod 777): Bad hash'),
        );
    }

    /**
     * @dataProvider kodProvider
     */
    public function testKodMessages($kod, $expected)
    {
        $this->t->queue('POST', '#x\.json#', array('body' => json_encode(array('response' => array('Kod' => $kod, 'Informacja' => 'Bad hash')))));
        $out = $this->c->post('x.json', 'faktura', array());

        $this->assertSame($kod, $out['response']['Kod']);
        $this->assertSame($kod, $this->c->getLastKod());
        $this->assertSame($expected, $this->c->getLastError());
    }

    public function testKodWithoutInformacjaAndSuccessResetsKod()
    {
        $this->t->queue('GET', '#x\.json#', array('body' => '{"response":{"Kod":403}}'));
        $this->t->queue('GET', '#x\.json#', array('body' => '{"response":{"Kod":0}}'));
        $this->c->get('x.json', 'faktura');
        $this->assertSame('Invalid signature - check the API key (Kod 403)', $this->c->getLastError());
        $this->now += 901; // the 403 starts a cool-down: the second call waits for it to end
        $this->c->get('x.json', 'faktura');
        $this->assertNull($this->c->getLastKod());
        $this->assertSame('', $this->c->getLastError());
    }

    public function testKodAtTopLevelWithoutResponseEnvelope()
    {
        $this->t->queue('GET', '#x\.json#', array('body' => '{"Kod":401,"Informacja":"nope"}'));
        $this->c->get('x.json', 'faktura');
        $this->assertSame(401, $this->c->getLastKod());
    }

    public function testHttp502WithoutBody()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 502, 'body' => ''));
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertSame('HTTP 502 returned by iFirma.', $this->c->getLastError());
        $this->assertSame(502, $this->c->getLastHttpCode());
        $this->assertNull($this->c->getLastKod());
    }

    public function testHttp4xxWithJsonKodReturnsDecoded()
    {
        $this->t->queue('POST', '#x\.json#', array('status' => 401, 'body' => '{"response":{"Kod":401,"Informacja":"zly user"}}'));
        $out = $this->c->post('x.json', 'faktura', array());
        $this->assertSame(401, $out['response']['Kod']);
        $this->assertSame('Invalid user login (Kod 401): zly user', $this->c->getLastError());
        $this->assertSame(401, $this->c->getLastHttpCode());
    }

    public function testTransportFailure()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 0, 'error' => 'Operation timed out'));
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertSame('cURL error: Operation timed out', $this->c->getLastError());
    }

    public function testPdfEndpointJsonErrorUsesInformacja()
    {
        $this->t->queue('GET', '#\.pdf#', array('body' => '{"response":{"Kod":200,"Informacja":"Brak dokumentu"}}'));
        $this->assertFalse($this->c->getBinary('fakturaparagon/1.pdf', 'faktura'));
        $this->assertStringContainsString('Brak dokumentu', $this->c->getLastError());
        $this->assertStringContainsString('Kod 200', $this->c->getLastError());
    }

    public function testPdfEndpointJsonWithInformacjaOnlyAndUnparseable()
    {
        $this->t->queue('GET', '#\.pdf#', array('body' => '{"Informacja":"Sam tekst"}'));
        $this->t->queue('GET', '#\.pdf#', array('body' => '<html>oops</html>'));
        $this->assertFalse($this->c->getBinary('a.pdf', 'faktura'));
        $this->assertSame('Sam tekst', $this->c->getLastError());
        $this->assertFalse($this->c->getBinary('a.pdf', 'faktura'));
        $this->assertSame('Unexpected non-PDF response from iFirma.', $this->c->getLastError());
    }

    public function testIsPdf()
    {
        $this->assertTrue($this->c->isPdf('%PDF-1.7'));
        $this->assertFalse($this->c->isPdf('{"a":1}'));
        $this->assertFalse($this->c->isPdf(''));
    }

    public function authFailureProvider()
    {
        return array(
            'Kod 400' => array(200, '{"response":{"Kod":400,"Informacja":"x"}}'),
            'Kod 401' => array(200, '{"response":{"Kod":401}}'),
            'Kod 402' => array(200, '{"response":{"Kod":402}}'),
            'Kod 403' => array(200, '{"response":{"Kod":403}}'),
            'Kod 499' => array(200, '{"response":{"Kod":499}}'),
            'HTTP 401 html' => array(401, '<html>denied</html>'),
            'HTTP 403 empty' => array(403, ''),
        );
    }

    /**
     * @dataProvider authFailureProvider
     */
    public function testAuthFailureSetsTheMark($status, $body)
    {
        $this->t->queue('GET', '#x\.json#', array('status' => $status, 'body' => $body));
        $this->c->get('x.json', 'faktura');
        $mark = (string) Configuration::get('IFIRMA_AUTH_FAILURE');
        $this->assertStringStartsWith($this->now . '|', $mark);
        $this->assertTrue($this->c->isAuthBlocked(), 'the mark is active right after the failing call');
        $this->assertFalse($this->c->lastCallWasAuthBlocked(), 'the failing call itself was sent');
    }

    public function testSecondCallBlocked()
    {
        $body = json_encode(array('response' => array('Kod' => 403, 'Informacja' => 'Bad hash')));
        $this->t->queue('POST', '#x\.json#', array('status' => 200, 'body' => $body));
        $this->t->queue('POST', '#x\.json#', array('status' => 200, 'body' => '{"response":{"Kod":0}}'));

        $this->assertNotNull($this->c->post('x.json', 'faktura', array()));
        $failedAt = $this->now;
        $this->assertCount(1, $this->t->calls);

        $this->now += 120;
        $this->assertNull($this->c->post('x.json', 'faktura', array()));
        $this->assertCount(1, $this->t->calls, 'no second request reaches the transport');
        $this->assertFalse($this->c->lastRequestWasSent());
        $this->assertTrue($this->c->isAuthBlocked());
        $this->assertTrue($this->c->lastCallWasAuthBlocked());
        $this->assertFalse($this->c->isRateLimited());
        $this->assertNull($this->c->getLastKod());
        $this->assertSame(
            'iFirma rejected the credentials at ' . date('Y-m-d H:i:s', $failedAt)
            . ': Invalid signature - check the API key (Kod 403): Bad hash; calls are paused until '
            . date('Y-m-d H:i:s', $failedAt + 900),
            $this->c->getLastError()
        );
        $this->assertSame(1, $this->c->getRequestCount());
    }

    public function testBlockedCallsCoverEveryVerbAndBinary()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 403, 'body' => ''));
        $this->c->get('x.json', 'faktura');
        $this->assertNull($this->c->put('x.json', 'faktura', array()));
        $this->assertFalse($this->c->getBinary('x.pdf', 'faktura'));
        $this->assertNull($this->c->getDetail('fakturaparagon', '1'));
        $this->assertNull($this->c->listInvoices(array('dataOd' => 'a', 'dataDo' => 'b', 'iloscNaStronie' => 1)));
        $this->assertCount(1, $this->t->calls);
        $this->assertTrue($this->c->isAuthBlocked());
    }

    public function testBareHttp403StartsTheCoolDown()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 403, 'body' => ''));
        $this->assertNull($this->c->get('x.json', 'faktura'));
        $this->assertSame(403, $this->c->getLastHttpCode());
        $this->assertSame($this->now . '|HTTP 403 returned by iFirma.', Configuration::get('IFIRMA_AUTH_FAILURE'));
        $this->c->get('x.json', 'faktura');
        $this->assertCount(1, $this->t->calls);
    }

    public function testWindowOverSendsAgain()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 403, 'body' => ''));
        $this->t->queue('GET', '#x\.json#', array('status' => 200, 'body' => '{"response":{"Kod":0}}'));
        $this->c->get('x.json', 'faktura');
        $this->now += 899;
        $this->c->get('x.json', 'faktura');
        $this->assertCount(1, $this->t->calls, 'still inside the 900 seconds');
        $this->now += 1;
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertCount(2, $this->t->calls);
        $this->assertFalse($this->c->isAuthBlocked());
        $this->assertFalse($this->c->lastCallWasAuthBlocked());
        $this->assertSame('', $this->c->getLastError());
    }

    public function testKod500DoesNotPause()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 200, 'body' => '{"response":{"Kod":500}}'));
        $this->t->queue('GET', '#x\.json#', array('status' => 200, 'body' => '{"response":{"Kod":0}}'));
        $this->c->get('x.json', 'faktura');
        $this->assertFalse(Configuration::get('IFIRMA_AUTH_FAILURE'));
        $this->assertNotNull($this->c->get('x.json', 'faktura'));
        $this->assertCount(2, $this->t->calls);
        $this->assertFalse($this->c->isAuthBlocked());
    }

    public function testOtherFailuresDoNotPause()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 502, 'body' => ''));
        $this->t->queue('GET', '#x\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->c->get('x.json', 'faktura');
        $this->c->get('x.json', 'faktura');
        $this->assertFalse(Configuration::get('IFIRMA_AUTH_FAILURE'));
        $this->assertCount(2, $this->t->calls);
    }

    public function testIsAuthBlockedIsLiveAfterTheFailingCallToo()
    {
        $this->t->queue('GET', '#x\.json#', array('status' => 403, 'body' => ''));
        $this->assertFalse($this->c->isAuthBlocked());
        $this->c->get('x.json', 'faktura');
        $this->assertTrue($this->c->isAuthBlocked(), 'a bulk run stops right after the item that set the mark');
        $this->now += 900;
        $this->assertFalse($this->c->isAuthBlocked(), 'no call needed to see the window end');
    }

    public function testIsAuthBlockedReadsTheMarkBeforeAnyCall()
    {
        $this->assertFalse($this->c->isAuthBlocked());
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($this->now - 60) . '|Bad hash');
        $this->assertTrue($this->c->isAuthBlocked());
        $this->now += 900;
        $this->assertFalse($this->c->isAuthBlocked());
    }

    public function testAMarkDatedInTheFutureIsExpired()
    {
        // the clock of the server was stepped back: the mark must not pause the calls until it catches up
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($this->now + 3600) . '|Bad hash');

        $this->assertFalse($this->c->isAuthBlocked());
        $this->assertSame('', $this->c->getAuthBlockMessage());

        $this->t->queue('GET', '#/x\.json$#', array('body' => '{"response":{"Kod":0}}'));
        $this->assertSame(array('response' => array('Kod' => 0)), $this->c->get('x.json', 'faktura'));
        $this->assertFalse($this->c->lastCallWasAuthBlocked());
        $this->assertCount(1, $this->t->calls);
    }

    public function testAMarkDatedThisVerySecondStillPauses()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|Bad hash');

        $this->assertTrue($this->c->isAuthBlocked());
    }

    public function testAMessageWithAPipeKeepsItsText()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|a|b');
        $this->c->get('x.json', 'faktura');
        $this->assertStringContainsString(': a|b; calls are paused until', $this->c->getLastError());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testMissingKeyIsReportedBeforeTheCoolDown()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|x');
        $c = new IfirmaApiClient('u', array(), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $c->get('x.json', 'faktura');
        $this->assertSame('Missing iFirma API key "faktura".', $c->getLastError());
    }
}
