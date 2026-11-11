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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRemoteLimit.php';

/**
 * Spec ifirma-api-compliance "Unusable answer": a figure of iFirma's limit answer that is not a non-negative whole
 * number within a sane bound is no answer, and nothing is stored.
 */
class RemoteLimitFiguresTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function remoteLimit($answer)
    {
        return new IfirmaRemoteLimit(function () use ($answer) {
            return $answer;
        }, function () {
            return mktime(10, 2, 0, 11, 1, 2026);
        });
    }

    /**
     * @return array[] used, granted
     */
    public static function badFigures()
    {
        return array(
            'exponent used' => array(1e30, 15000),
            'exponent granted' => array(5, 1e30),
            'huge digit string' => array('99999999999999999999', 15000),
            'negative used' => array(-5, 15000),
            'negative granted' => array(5, -15000),
            'letters after digits' => array('12abc', 15000),
            'float used' => array(12.5, 15000),
            'float granted' => array(5, 15000.0),
            'float string' => array('12.5', 15000),
            'exponent string' => array('1e3', 15000),
            'signed string' => array('+12', 15000),
            'blank' => array('', 15000),
            'array' => array(array(1), 15000),
            'boolean' => array(true, 15000),
            'above the bound' => array(10000001, 15000),
            'granted above the bound' => array(5, 10000001),
        );
    }

    /**
     * @dataProvider badFigures
     */
    public function testAFigureThatIsNotAPlainWholeNumberIsNoAnswerAndStoresNothing($used, $granted)
    {
        $remote = $this->remoteLimit(array('used' => $used, 'granted' => $granted));

        $check = $remote->check();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertNull(IfirmaRemoteLimit::stored());
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_DAY_WINDOW'));
        $this->assertNull($this->remoteLimit(array('used' => $used, 'granted' => $granted))->refresh());
    }

    /**
     * @return array[] used, granted, expected used, expected granted
     */
    public static function goodFigures()
    {
        return array(
            'integers' => array(1200, 15000, 1200, 15000),
            'digit strings' => array('1200', '15000', 1200, 15000),
            'zero used' => array(0, 15000, 0, 15000),
            'the bound itself' => array(10000000, 10000000, 10000000, 10000000),
        );
    }

    /**
     * @dataProvider goodFigures
     */
    public function testAWholeNumberWithinTheBoundIsStored($used, $granted, $expectedUsed, $expectedGranted)
    {
        $check = $this->remoteLimit(array('used' => $used, 'granted' => $granted))->check();

        $this->assertSame(IfirmaLimitCheck::CHECKED, $check->status());
        $this->assertSame($expectedUsed, $check->reading()->used());
        $this->assertSame($expectedGranted, $check->reading()->granted());
    }

    public function testTheClientHandsOverAFigureItCannotTrustWithoutCastingIt()
    {
        $t = new ScriptedTransport();
        $t->queue('GET', '#abonent/limit\.json#', array('body' => '{"response":{"Kod":0,"LimitWykorzystany":1e30,"LimitPrzyznany":15000}}'));
        $client = new IfirmaApiClient('u@example.com', array('abonent' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $t);

        $limit = $client->getLimit();

        $this->assertSame(1e30, $limit['used'], 'a cast would have turned it into an arbitrary integer');
        $this->assertNull($this->remoteLimit($limit)->refresh());
    }

    public function testTheClientStillTurnsADigitStringIntoAnInteger()
    {
        $t = new ScriptedTransport();
        $t->queue('GET', '#abonent/limit\.json#', array('body' => '{"response":{"Kod":0,"LimitWykorzystany":"12","LimitPrzyznany":15000}}'));
        $client = new IfirmaApiClient('u@example.com', array('abonent' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $t);

        $this->assertSame(array('used' => 12, 'granted' => 15000), $client->getLimit());
    }
}
