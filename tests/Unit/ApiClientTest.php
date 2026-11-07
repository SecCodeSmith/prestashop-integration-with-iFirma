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

class ApiClientTest extends TestCase
{
    const BASE = 'http://ifirma-mock:8001/iapi/';

    /** @var ScriptedTransport */
    private $t;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
    }

    private function client($keys = null, $timeout = 30)
    {
        $keys = $keys === null ? array('faktura' => str_repeat('1', 64), 'abonent' => str_repeat('2', 64)) : $keys;

        return new IfirmaApiClient('test@example.com', $keys, self::BASE, $timeout, $this->t);
    }

    public function testSigningVector()
    {
        $this->t->queue('POST', '#fakturaparagon\.json#', array('body' => '{"response":{"Kod":0}}'));
        $this->client()->post('fakturaparagon.json', 'faktura', array('a' => 1));

        $expected = 'IAPIS user=test@example.com, hmac-sha1=' . hash_hmac(
            'sha1',
            'http://ifirma-mock:8001/iapi/fakturaparagon.json' . 'test@example.com' . 'faktura' . '{"a":1}',
            hex2bin(str_repeat('1', 64))
        );
        $this->assertSame($expected, $this->t->lastAuthHeader());
        $this->assertSame('{"a":1}', $this->t->lastCall()['body']);
        $this->assertContains('Content-Type: application/json; charset=UTF-8', $this->t->lastCall()['headers']);
        $this->assertContains('Accept: application/json', $this->t->lastCall()['headers']);
    }

    public function testQueryStringExcludedFromSignatureButInUrl()
    {
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":0}}'));
        $this->client()->get('faktury.json?dataOd=2026-01-01&x=1', 'faktura');

        $this->assertSame(self::BASE . 'faktury.json?dataOd=2026-01-01&x=1', $this->t->lastCall()['url']);
        $expected = 'IAPIS user=test@example.com, hmac-sha1=' . hash_hmac(
            'sha1',
            self::BASE . 'faktury.json' . 'test@example.com' . 'faktura',
            hex2bin(str_repeat('1', 64))
        );
        $this->assertSame($expected, $this->t->lastAuthHeader());
    }

    public function testPdfEndpointSendsPdfAcceptHeader()
    {
        $this->t->queue('GET', '#\.pdf#', array('body' => '%PDF-1.4 data'));
        $this->assertSame('%PDF-1.4 data', $this->client()->getBinary('fakturaparagon/7.pdf', 'faktura'));
        $this->assertContains('Accept: application/pdf', $this->t->lastCall()['headers']);
    }

    /**
     * @dataProvider badKeys
     */
    public function testInvalidKeyRefusedWithoutRequest($key)
    {
        $c = $this->client(array('faktura' => $key));
        $this->assertNull($c->get('x.json', 'faktura'));
        $this->assertSame(array(), $this->t->calls);
        $this->assertStringContainsString('not a valid hexadecimal', $c->getLastError());
        $this->assertFalse($c->getBinary('x.pdf', 'faktura'));
        $this->assertNull($c->post('x.json', 'faktura', array()));
        $this->assertSame(array(), $this->t->calls);
    }

    public function badKeys()
    {
        return array(array('abc'), array('zz11'), array('12345'));
    }

    public function testMissingKeyRefused()
    {
        $c = $this->client(array());
        $this->assertNull($c->get('x.json', 'faktura'));
        $this->assertSame(array(), $this->t->calls);
        $this->assertStringContainsString('Missing iFirma API key', $c->getLastError());
    }

    public function testListInvoicesRejectsMissingParams()
    {
        $c = $this->client();
        foreach (array('dataOd', 'dataDo', 'iloscNaStronie') as $missing) {
            $params = array('dataOd' => '2026-01-01', 'dataDo' => '2026-01-02', 'iloscNaStronie' => 100);
            unset($params[$missing]);
            try {
                $c->listInvoices($params);
                $this->fail('expected InvalidArgumentException for ' . $missing);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($missing, $e->getMessage());
            }
        }
        $this->assertSame(array(), $this->t->calls);
    }

    public function testListInvoicesBuildsQueryAndReturnsRows()
    {
        $rows = array(array('FakturaId' => 5, 'PelnyNumer' => '1/2026'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows)))));
        $out = $this->client()->listInvoices(array('dataOd' => '2026-01-01', 'dataDo' => '2026-01-31', 'iloscNaStronie' => 100, 'strona' => 2));

        $this->assertSame($rows, $out);
        $this->assertSame(self::BASE . 'faktury.json?dataOd=2026-01-01&dataDo=2026-01-31&iloscNaStronie=100&strona=2', $this->t->lastCall()['url']);
    }

    public function testListInvoicesEmptyAndFailure()
    {
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":0}}'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":403,"Informacja":"x"}}'));
        $c = $this->client();
        $p = array('dataOd' => '2026-01-01', 'dataDo' => '2026-01-31', 'iloscNaStronie' => 10);
        $this->assertSame(array(), $c->listInvoices($p));
        $this->assertNull($c->listInvoices($p));
    }

    public function testGetDetailReturnsRawAndData()
    {
        $body = '{"response":{"Kod":0,"Numer":3}}';
        $this->t->queue('GET', '#fakturaparagon/12%2F3\.json#', array('body' => $body));
        $out = $this->client()->getDetail('fakturaparagon', '12/3');

        $this->assertSame($body, $out['raw']);
        $this->assertSame(3, $out['data']['response']['Numer']);
        $this->assertSame(self::BASE . 'fakturaparagon/12%2F3.json', $this->t->lastCall()['url']);
    }

    public function testGetDetailFailureReturnsNull()
    {
        $c = $this->client();
        $this->assertNull($c->getDetail('fakturaparagon', '1'));
        $this->assertNotSame('', $c->getLastError());
    }

    public function testSendPathAndBody()
    {
        $this->t->queue('POST', '#fakturaparagon/send/9\.json#', array('body' => '{"response":{"Kod":0}}'));
        $out = $this->client()->send('fakturaparagon', '9', array('Do' => 'a@b.pl'));

        $this->assertSame(0, $out['response']['Kod']);
        $this->assertSame('POST', $this->t->lastCall()['method']);
        $this->assertSame(self::BASE . 'fakturaparagon/send/9.json', $this->t->lastCall()['url']);
        $this->assertSame('{"Do":"a@b.pl"}', $this->t->lastCall()['body']);
    }

    public function testGetLimitWithoutAbonentKeyMakesNoRequest()
    {
        foreach (array(array('faktura' => str_repeat('1', 64)), array('faktura' => str_repeat('1', 64), 'abonent' => '')) as $keys) {
            $this->assertNull($this->client($keys)->getLimit());
        }
        $this->assertSame(array(), $this->t->calls);
    }

    public function testGetLimitParsesUsedAndGranted()
    {
        $this->t->queue('GET', '#abonent/limit\.json#', array('body' => '{"response":{"Kod":0,"LimitWykorzystany":"12","LimitPrzyznany":15000}}'));
        $this->assertSame(array('used' => 12, 'granted' => 15000), $this->client()->getLimit());
        $this->assertSame(self::BASE . 'abonent/limit.json', $this->t->lastCall()['url']);
        $this->assertSame('IAPIS user=test@example.com, hmac-sha1=' . hash_hmac(
            'sha1',
            self::BASE . 'abonent/limit.json' . 'test@example.com' . 'abonent',
            hex2bin(str_repeat('2', 64))
        ), $this->t->lastAuthHeader());
    }

    public function testGetLimitFailureReturnsNull()
    {
        $this->assertNull($this->client()->getLimit());
    }

    public function testGetLimitWithoutLimitFieldsReturnsNullWithError()
    {
        $this->t->queue('GET', '#abonent/limit\.json#', array('body' => '{"response":{"Kod":0,"Wynik":{}}}'));
        $c = $this->client();
        $this->assertNull($c->getLimit());
        $this->assertSame('Unexpected limit response from iFirma.', $c->getLastError());
    }

    public function testTimeouts()
    {
        $this->t->queue('GET', '#x\.json#', array('body' => '{}'));
        $this->t->queue('GET', '#x\.json#', array('body' => '{}'));
        $this->t->queue('GET', '#x\.json#', array('body' => '{}'));
        $c = $this->client();
        $this->assertSame(30, $c->getTimeout());
        $c->get('x.json', 'faktura');
        $c->get('x.json', 'faktura', 5);
        $c->setTimeout(12);
        $c->get('x.json', 'faktura');

        $this->assertSame(30, $this->t->calls[0]['timeout']);
        $this->assertSame(5, $this->t->calls[1]['timeout']);
        $this->assertSame(12, $this->t->calls[2]['timeout']);
        $this->assertSame($this->t, $c->getTransport());
    }

    public function testDefaultTransportIsCurl()
    {
        $c = new IfirmaApiClient('u', array());
        $this->assertInstanceOf('IfirmaHttpTransport', $c->getTransport());
    }

    public function testReceiptServiceStillWorksAgainstClient()
    {
        $client = $this->client();
        $service = new IfirmaReceiptService($client, new Module());
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Wynik' => array(array('FakturaId' => 77, 'PelnyNumer' => '5/11/2026')),
        )))));

        $this->assertSame('5/11/2026', $service->fetchDocumentNumber('77', '2026-11-01'));
        $this->assertStringContainsString('dataOd=2026-11-01', $this->t->lastCall()['url']);
        $this->assertStringContainsString('iloscNaStronie=100', $this->t->lastCall()['url']);
        $this->assertInstanceOf('IfirmaReceiptService', IfirmaReceiptService::build(new Module()));
    }
}
