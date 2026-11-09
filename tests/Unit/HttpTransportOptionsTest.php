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
 * The cURL options of IfirmaHttpTransport and its answer-size cap, checked without a network.
 */
class HttpTransportOptionsTest extends TestCase
{
    /** @var IfirmaHttpTransport */
    private $transport;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->transport = new IfirmaHttpTransport();
    }

    private function options($method = 'GET', $body = '')
    {
        return $this->transport->options($method, 'https://www.ifirma.pl/iapi/x.json', array('Accept: application/json'), $body, 30);
    }

    public function testOnlyHttpAndHttpsAreSpoken()
    {
        $options = $this->options();
        $expected = CURLPROTO_HTTP | CURLPROTO_HTTPS;

        $this->assertSame($expected, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame($expected, $options[CURLOPT_REDIR_PROTOCOLS]);
    }

    public function testRedirectsAreNeverFollowed()
    {
        $options = $this->options();

        $this->assertArrayHasKey(CURLOPT_FOLLOWLOCATION, $options);
        $this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
    }

    public function testTheCertificateIsStillVerified()
    {
        $options = $this->options();

        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertTrue($options[CURLOPT_RETURNTRANSFER]);
    }

    public function testTheWriteCallbackIsTheTransportsOwn()
    {
        $options = $this->options();

        $this->assertSame(array($this->transport, 'writeChunk'), $options[CURLOPT_WRITEFUNCTION]);
    }

    public function testTimeoutsHeadersAndMethods()
    {
        $get = $this->options('GET');
        $this->assertSame(30, $get[CURLOPT_TIMEOUT]);
        $this->assertSame(15, $get[CURLOPT_CONNECTTIMEOUT]);
        $this->assertSame(array('Accept: application/json'), $get[CURLOPT_HTTPHEADER]);
        $this->assertArrayNotHasKey(CURLOPT_POST, $get);

        $post = $this->options('post', '{"a":1}');
        $this->assertTrue($post[CURLOPT_POST]);
        $this->assertSame('{"a":1}', $post[CURLOPT_POSTFIELDS]);

        $put = $this->options('PUT', '{"b":2}');
        $this->assertSame('PUT', $put[CURLOPT_CUSTOMREQUEST]);
        $this->assertSame('{"b":2}', $put[CURLOPT_POSTFIELDS]);

        $emptyPut = $this->options('PUT', '');
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $emptyPut);

        $short = $this->transport->options('GET', 'https://x.example/', array(), '', 0);
        $this->assertSame(1, $short[CURLOPT_TIMEOUT]);
        $this->assertSame(1, $short[CURLOPT_CONNECTTIMEOUT]);
    }

    public function testChunksAreCollectedAndCounted()
    {
        $this->assertSame(5, $this->transport->writeChunk(null, 'hello'));
        $this->assertSame(6, $this->transport->writeChunk(null, ' world'));

        $this->assertSame('hello world', $this->transport->receivedBody());
        $this->assertFalse($this->transport->exceededTheCap());
    }

    public function testAnAnswerOfExactlyTwentyMegabytesIsAccepted()
    {
        $limit = 20 * 1024 * 1024;
        $this->assertSame($limit, IfirmaHttpTransport::MAX_RESPONSE_BYTES);

        $chunk = str_repeat('a', 1024 * 1024);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(strlen($chunk), $this->transport->writeChunk(null, $chunk));
        }

        $this->assertSame($limit, strlen($this->transport->receivedBody()));
        $this->assertFalse($this->transport->exceededTheCap());
    }

    public function testTheFirstByteOverTwentyMegabytesAbortsTheTransfer()
    {
        $chunk = str_repeat('a', 1024 * 1024);
        for ($i = 0; $i < 20; $i++) {
            $this->transport->writeChunk(null, $chunk);
        }

        $this->assertSame(-1, $this->transport->writeChunk(null, 'b'));
        $this->assertTrue($this->transport->exceededTheCap());
        $this->assertSame('', $this->transport->receivedBody(), 'the partial answer is dropped');
    }

    public function testASingleChunkOverTheCapAborts()
    {
        $this->assertSame(-1, $this->transport->writeChunk(null, str_repeat('a', 20 * 1024 * 1024 + 1)));
        $this->assertTrue($this->transport->exceededTheCap());
    }

    public function testAFinishedTransferGivesTheBufferedAnswerAndEmptiesTheBuffer()
    {
        $this->transport->writeChunk(null, '{"a":1}');

        $this->assertSame(array('status' => 200, 'body' => '{"a":1}', 'error' => '', 'headers' => array()), $this->transport->outcome(true, 200, ''));
        $this->assertSame('', $this->transport->receivedBody());
    }

    public function testTheHeaderCallbackIsTheTransportsOwn()
    {
        $options = $this->options();

        $this->assertSame(array($this->transport, 'collectHeader'), $options[CURLOPT_HEADERFUNCTION]);
    }

    public function testHeadersAreCollectedWithLowerCasedNames()
    {
        $this->transport->collectHeader(null, "HTTP/1.1 429 Too Many Requests\r\n");
        $this->transport->collectHeader(null, "Retry-After: 20\r\n");
        $this->transport->collectHeader(null, "Content-Type:  application/json \r\n");
        $this->transport->collectHeader(null, "\r\n");

        $answer = $this->transport->outcome(true, 429, '');

        $this->assertSame(array('retry-after' => '20', 'content-type' => 'application/json'), $answer['headers']);
    }

    public function testARepeatedHeaderKeepsTheLastValue()
    {
        $this->transport->collectHeader(null, "Retry-After: 5\r\n");
        $this->transport->collectHeader(null, "retry-after: 9\r\n");

        $this->assertSame(array('retry-after' => '9'), $this->transport->outcome(true, 429, '')['headers']);
    }

    public function testAnAnswerWithoutHeadersHasAnEmptyArray()
    {
        $this->assertSame(array(), $this->transport->outcome(true, 200, '')['headers']);
    }

    public function testANewStatusLineStartsTheHeadersOver()
    {
        // a redirect or a 100 Continue is followed by the real answer; only the last block counts
        $this->transport->collectHeader(null, "HTTP/1.1 100 Continue\r\n");
        $this->transport->collectHeader(null, "X-First: yes\r\n");
        $this->transport->collectHeader(null, "HTTP/1.1 200 OK\r\n");
        $this->transport->collectHeader(null, "Retry-After: 3\r\n");

        $this->assertSame(array('retry-after' => '3'), $this->transport->outcome(true, 200, '')['headers']);
    }

    public function testALineWithoutAColonIsIgnoredAndTheLengthIsReturned()
    {
        $this->assertSame(7, $this->transport->collectHeader(null, "garbage"));
        $this->assertSame(array(), $this->transport->outcome(true, 200, '')['headers']);
    }

    public function testTheHeadersOfOneTransferDoNotLeakIntoTheNext()
    {
        $this->transport->collectHeader(null, "Retry-After: 5\r\n");
        $this->transport->outcome(true, 429, '');

        $this->assertSame(array(), $this->transport->outcome(true, 200, '')['headers']);
    }

    public function testAFailedTransferGivesStatusZeroAndTheCurlError()
    {
        $this->transport->writeChunk(null, 'partial');

        $this->assertSame(
            array('status' => 0, 'body' => '', 'error' => 'Could not resolve host'),
            $this->transport->outcome(false, 0, 'Could not resolve host')
        );
        $this->assertSame('', $this->transport->receivedBody());
    }

    public function testAFailedTransferWithoutAnErrorTextIsNamedUnknown()
    {
        $this->assertSame('unknown transport error', $this->transport->outcome(false, 0, '')['error']);
    }

    public function testAnAbortedOverlongAnswerIsReportedAsTooLarge()
    {
        $this->transport->writeChunk(null, str_repeat('a', 20 * 1024 * 1024 + 1));

        $this->assertSame('iFirma answer larger than 20 MB', $this->transport->outcome(false, 200, 'Failed writing body')['error']);
    }
}
