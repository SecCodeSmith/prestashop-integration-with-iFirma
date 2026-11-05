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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaHttpStatus.php';

class HttpStatusTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    /**
     * @dataProvider mappedProvider
     */
    public function testMappedCodeGetsItsOwnReasonPhrase($code, $expected)
    {
        $this->assertSame($expected, IfirmaHttpStatus::statusLine($code));
    }

    public function mappedProvider()
    {
        return array(
            '200 stays OK' => array(200, 'HTTP/1.1 200 OK'),
            '403 stays Forbidden' => array(403, 'HTTP/1.1 403 Forbidden'),
            '404 is Not Found, not Forbidden' => array(404, 'HTTP/1.1 404 Not Found'),
            '429 is Too Many Requests, not Forbidden' => array(429, 'HTTP/1.1 429 Too Many Requests'),
            '500 is Internal Server Error, not Forbidden' => array(500, 'HTTP/1.1 500 Internal Server Error'),
        );
    }

    public function testNumericStringIsTreatedAsItsInteger()
    {
        $this->assertSame('HTTP/1.1 403 Forbidden', IfirmaHttpStatus::statusLine('403'));
        // Only the integer survives the cast, so a hostile string cannot add a header line.
        $this->assertSame('HTTP/1.1 403 Forbidden', IfirmaHttpStatus::statusLine("403\r\nX-Injected: 1"));
    }

    /**
     * @dataProvider unmappedProvider
     */
    public function testUnmappedCodeKeepsItsNumberWithAGenericReason($code)
    {
        $this->assertSame('HTTP/1.1 ' . $code . ' Unknown Status', IfirmaHttpStatus::statusLine($code));
    }

    public function unmappedProvider()
    {
        // 100 and 599 are the edges of the valid range.
        return array(
            'lowest code' => array(100),
            'unassigned 2xx' => array(299),
            'teapot' => array(418),
            'highest code' => array(599),
        );
    }

    /**
     * @dataProvider outOfRangeProvider
     */
    public function testCodeOutsideTheStatusRangeIsReportedAsServerError($code)
    {
        $this->assertSame('HTTP/1.1 500 Internal Server Error', IfirmaHttpStatus::statusLine($code));
    }

    public function outOfRangeProvider()
    {
        return array(
            'zero' => array(0),
            'just below the range' => array(99),
            'just above the range' => array(600),
            'negative' => array(-403),
            'text' => array('abc'),
            'null' => array(null),
        );
    }

    public function testEveryCodeOfTheStatusRangeYieldsAValidStatusLine()
    {
        $invalid = array();
        for ($code = 100; $code <= 599; $code++) {
            $line = IfirmaHttpStatus::statusLine($code);
            // HTTP-version SP status-code SP reason-phrase: visible text, no control characters.
            if (preg_match('/^HTTP\/1\.1 ' . $code . ' [!-~][ -~]*$/D', $line) !== 1) {
                $invalid[] = $code . ': ' . $line;
            }
        }

        $this->assertSame(array(), $invalid);
    }
}
