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

class ScriptedTransportTest extends TestCase
{
    public function testFifoQueueAndRecording()
    {
        $t = new ScriptedTransport();
        $t->queue('POST', '#/iapi/fakturaparagon\.json$#', ['status' => 201, 'body' => 'one']);
        $t->queue('POST', '#/iapi/fakturaparagon\.json$#', ['body' => 'two']);

        $r1 = $t->request('POST', 'https://x.test/iapi/fakturaparagon.json', ['Authentication: IAPIS user=u, hmac-sha1=abc'], '{"a":1}', 30);
        $r2 = $t->request('POST', 'https://x.test/iapi/fakturaparagon.json?q=1', ['Authentication: IAPIS user=u, hmac-sha1=def'], '{}', 10);

        $this->assertSame(['status' => 201, 'body' => 'one', 'error' => ''], $r1);
        $this->assertSame(['status' => 200, 'body' => 'two', 'error' => ''], $r2);
        $this->assertCount(2, $t->calls);
        $this->assertSame('{"a":1}', $t->calls[0]['body']);
        $this->assertSame(30, $t->calls[0]['timeout']);
        $this->assertSame('IAPIS user=u, hmac-sha1=def', $t->lastAuthHeader());
    }

    public function testUnmatchedCallFailsLikeTransportError()
    {
        $t = new ScriptedTransport();
        $r = $t->request('GET', 'https://x.test/nothing', [], '', 5);

        $this->assertSame(0, $r['status']);
        $this->assertSame('ScriptedTransport: no scripted response for GET https://x.test/nothing', $r['error']);
        $this->assertNull($t->lastAuthHeader());
    }

    public function testCallableResponseSeesRequest()
    {
        $t = new ScriptedTransport();
        $t->queue('*', '#/echo#', function ($method, $url, $headers, $body) {
            return ['status' => 200, 'body' => $method . ':' . $body];
        });

        $this->assertSame('POST:xyz', $t->request('POST', 'https://x.test/echo', [], 'xyz', 5)['body']);
        $this->assertSame('GET:', $t->request('GET', 'https://x.test/echo', [], '', 5)['body']);
    }

    public function testAScriptedAnswerCanCarryHeadersWithLowerCasedNames()
    {
        $t = new ScriptedTransport();
        $t->queue('GET', '#/limited#', ['status' => 429, 'headers' => ['Retry-After' => 20]]);
        $t->queue('GET', '#/plain#', ['status' => 200]);

        $limited = $t->request('GET', 'https://x.test/limited', [], '', 5);
        $plain = $t->request('GET', 'https://x.test/plain', [], '', 5);

        $this->assertSame(['retry-after' => '20'], $limited['headers']);
        $this->assertArrayNotHasKey('headers', $plain);
    }
}
