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

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $this->c = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('ab', 16)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
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
}
