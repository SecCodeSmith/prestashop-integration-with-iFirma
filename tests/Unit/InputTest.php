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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaInput.php';

/** IfirmaInput: request values become scalars or amounts; arrays and objects never get through. */
class InputTest extends TestCase
{
    public function scalars()
    {
        return array(
            'text' => array('abc', 'abc'),
            'integer' => array(15, '15'),
            'float' => array(12.5, '12.5'),
            'zero' => array(0, '0'),
            'the string zero' => array('0', '0'),
            'empty string' => array('', ''),
        );
    }

    /** @dataProvider scalars */
    public function testScalarKeepsAScalarAsItsString($value, $expected)
    {
        $this->assertSame($expected, IfirmaInput::scalar($value));
    }

    public function testScalarTurnsArraysObjectsNullAndFalseIntoAnEmptyString()
    {
        $this->assertSame('', IfirmaInput::scalar(array(1)));
        $this->assertSame('', IfirmaInput::scalar(array()));
        $this->assertSame('', IfirmaInput::scalar(new stdClass()));
        $this->assertSame('', IfirmaInput::scalar(null));
        $this->assertSame('', IfirmaInput::scalar(false));
    }

    public function amounts()
    {
        return array(
            'comma decimal' => array('12,50', 12.5),
            'dot decimal' => array('12.50', 12.5),
            'two decimals with a comma' => array('0,99', 0.99),
            'one decimal' => array('3.5', 3.5),
            'whole number' => array('100', 100.0),
            'surrounding blanks' => array('  7,25 ', 7.25),
            'zero' => array('0', 0.0),
            'numeric float' => array(12.5, 12.5),
        );
    }

    /** @dataProvider amounts */
    public function testDecimalAcceptsAnAmountWithAtMostTwoDecimals($value, $expected)
    {
        $this->assertSame($expected, IfirmaInput::decimal($value));
    }

    public function refusedAmounts()
    {
        return array(
            'three decimals' => array('12.505'),
            'three decimals with a comma' => array('12,505'),
            'text' => array('abc'),
            'negative' => array('-5'),
            'two separators' => array('1,000.00'),
            'exponent' => array('1e3'),
            'empty' => array(''),
            'blank only' => array('   '),
            'boolean true' => array(true),
            'boolean false' => array(false),
            'null' => array(null),
            'array' => array(array(1)),
            'object' => array(new stdClass()),
        );
    }

    /** @dataProvider refusedAmounts */
    public function testDecimalRefusesEverythingElse($value)
    {
        $this->assertNull(IfirmaInput::decimal($value));
    }

    public function testOnlyAPostIsAPost()
    {
        foreach (array('POST' => true, 'post' => true, 'GET' => false, 'HEAD' => false, '' => false) as $method => $expected) {
            $_SERVER['REQUEST_METHOD'] = (string) $method;
            $this->assertSame($expected, IfirmaInput::isPost(), var_export($method, true));
        }

        unset($_SERVER['REQUEST_METHOD']);
        $this->assertFalse(IfirmaInput::isPost());
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }
}
