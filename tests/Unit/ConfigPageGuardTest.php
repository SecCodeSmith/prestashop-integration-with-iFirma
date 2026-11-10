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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaConfigPageGuard.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaBackOfficeToken.php';

/**
 * Given a request to the configuration page, when the guard decides, then an action runs only for an employee who may
 * configure the module, by POST, with a valid back-office token, and a refusal says no more than the first failed
 * check (spec receipt-access-control "Configuration page actions need POST and a valid token").
 */
class ConfigPageGuardTest extends TestCase
{
    private function guard($mayConfigure, $isPost, $tokenIsValid)
    {
        return new IfirmaConfigPageGuard(
            function () use ($mayConfigure) {
                return $mayConfigure;
            },
            function () use ($isPost) {
                return $isPost;
            },
            function () use ($tokenIsValid) {
                return $tokenIsValid;
            }
        );
    }

    public function testAPostWithAValidTokenOfAnEmployeeWhoMayConfigureIsAllowed()
    {
        $guard = $this->guard(true, true, true);

        $this->assertSame(IfirmaConfigPageGuard::ALLOWED, $guard->verdict());
        $this->assertTrue($guard->allowsStateChange());
        $this->assertTrue($guard->mayConfigure());
    }

    public function testAnEmployeeWhoMayNotConfigureIsDeniedWhateverTheRequestIs()
    {
        $guard = $this->guard(false, true, true);

        $this->assertSame(IfirmaConfigPageGuard::DENIED, $guard->verdict());
        $this->assertFalse($guard->allowsStateChange());
        $this->assertFalse($guard->mayConfigure());
    }

    public function testARequestThatIsNotAPostIsNotAllowedAndTheTokenIsNotEvenAsked()
    {
        $tokenAsked = false;
        $guard = new IfirmaConfigPageGuard(
            function () {
                return true;
            },
            function () {
                return false;
            },
            function () use (&$tokenAsked) {
                $tokenAsked = true;

                return true;
            }
        );

        $this->assertSame(IfirmaConfigPageGuard::NOT_A_POST, $guard->verdict());
        $this->assertFalse($guard->allowsStateChange());
        $this->assertFalse($tokenAsked);
    }

    public function testAPostWithoutAValidTokenIsAskedToProveItself()
    {
        $guard = $this->guard(true, true, false);

        $this->assertSame(IfirmaConfigPageGuard::BAD_TOKEN, $guard->verdict());
        $this->assertFalse($guard->allowsStateChange());
    }

    public function testTheTokenIsNotAskedForAnEmployeeWhoMayNotConfigure()
    {
        $tokenAsked = false;
        $guard = new IfirmaConfigPageGuard(
            function () {
                return false;
            },
            function () {
                return true;
            },
            function () use (&$tokenAsked) {
                $tokenAsked = true;

                return true;
            }
        );

        $guard->verdict();

        $this->assertFalse($tokenAsked);
    }

    // ---- the token ----------------------------------------------------------------------------------------------

    private function token($posted, $expected, $coreVerdict = null)
    {
        return new IfirmaBackOfficeToken(
            function () use ($posted) {
                return $posted;
            },
            function () use ($expected) {
                return $expected;
            },
            $coreVerdict
        );
    }

    public function testTheFixedTokenOfTheEmployeeIsValidWhenItIsPostedBack()
    {
        $this->assertTrue($this->token('abc123', 'abc123')->isValid());
    }

    public function testAnotherTokenIsNotValid()
    {
        $this->assertFalse($this->token('abc124', 'abc123')->isValid());
    }

    /**
     * @dataProvider unusableTokens
     */
    public function testAMissingOrMalformedTokenIsNotValid($posted)
    {
        $this->assertFalse($this->token($posted, 'abc123')->isValid());
    }

    public static function unusableTokens()
    {
        return array(
            'missing' => array(false),
            'null' => array(null),
            'empty' => array(''),
            'array' => array(array('abc123')),
        );
    }

    public function testNothingIsValidWhenTheEmployeeHasNoExpectedToken()
    {
        $this->assertFalse($this->token('anything', false)->isValid());
        $this->assertFalse($this->token('anything', '')->isValid());
    }

    public function testWhenTheCoreHasACheckItDecidesAndTheFixedTokenIsNotCompared()
    {
        $this->assertTrue($this->token('random-form-1', 'random-form-2', function () {
            return true;
        })->isValid());
        $this->assertFalse($this->token('abc123', 'abc123', function () {
            return false;
        })->isValid());
    }

    public function testTheCoreVerdictDoesNotRescueARequestWithoutAToken()
    {
        $this->assertFalse($this->token('', 'abc123', function () {
            return true;
        })->isValid());
    }
}
