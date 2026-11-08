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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaFormToken.php';

/**
 * The per-visitor form token (red team 1.6.0, F2): the token of one visitor is refused for another.
 */
class FormTokenTest extends TestCase
{
    const KEY = 'a-cookie-key';

    private function visitor()
    {
        return new stdClass();
    }

    public function testTheTokenIsDerivedFromTheNonceOfTheCookieWithTheKey()
    {
        $cookie = $this->visitor();

        $token = IfirmaFormToken::forVisitor($cookie, self::KEY);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $cookie->ifirma_form_nonce);
        $this->assertSame(hash_hmac('sha256', 'ifirma-form:' . $cookie->ifirma_form_nonce, self::KEY), $token);
        $this->assertSame($token, IfirmaFormToken::forVisitor($cookie, self::KEY));
    }

    public function testTwoVisitorsGetDifferentTokensAndOneTokenIsRefusedForTheOther()
    {
        $one = $this->visitor();
        $two = $this->visitor();

        $tokenOne = IfirmaFormToken::forVisitor($one, self::KEY);
        $tokenTwo = IfirmaFormToken::forVisitor($two, self::KEY);

        $this->assertNotSame($tokenOne, $tokenTwo);
        $this->assertTrue(IfirmaFormToken::isValid($tokenOne, $one, self::KEY));
        $this->assertFalse(IfirmaFormToken::isValid($tokenOne, $two, self::KEY));
        $this->assertFalse(IfirmaFormToken::isValid($tokenTwo, $one, self::KEY));
    }

    public function testAVisitorWithoutANonceRefusesEveryTokenAndCheckingCreatesNone()
    {
        $cookie = $this->visitor();
        $forged = hash_hmac('sha256', 'ifirma-form:', self::KEY);

        $this->assertFalse(IfirmaFormToken::isValid($forged, $cookie, self::KEY));
        $this->assertFalse(IfirmaFormToken::isValid('', $cookie, self::KEY));
        $this->assertFalse(isset($cookie->ifirma_form_nonce));
    }

    public function testANonceThatIsNotOurOwnIsIgnored()
    {
        $cookie = $this->visitor();
        $cookie->ifirma_form_nonce = 'short';

        $this->assertFalse(IfirmaFormToken::isValid(hash_hmac('sha256', 'ifirma-form:short', self::KEY), $cookie, self::KEY));
        // the next token replaces it with a real nonce
        IfirmaFormToken::forVisitor($cookie, self::KEY);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $cookie->ifirma_form_nonce);
    }

    public function testAnEmptyKeyGivesNoTokenAndAcceptsNone()
    {
        $cookie = $this->visitor();
        $token = IfirmaFormToken::forVisitor($cookie, self::KEY);

        $this->assertSame('', IfirmaFormToken::forVisitor($cookie, ''));
        $this->assertFalse(IfirmaFormToken::isValid($token, $cookie, ''));
        $this->assertFalse(IfirmaFormToken::isValid(hash_hmac('sha256', 'ifirma-form:' . $cookie->ifirma_form_nonce, ''), $cookie, ''));
    }

    public function testOnlyAStringTokenThatMatchesIsAccepted()
    {
        $cookie = $this->visitor();
        $token = IfirmaFormToken::forVisitor($cookie, self::KEY);

        foreach (array(false, null, 0, array($token), '', 'wrong', $token . 'x') as $given) {
            $this->assertFalse(IfirmaFormToken::isValid($given, $cookie, self::KEY), var_export($given, true));
        }
        $this->assertTrue(IfirmaFormToken::isValid($token, $cookie, self::KEY));
    }

    public function testTheKeyChangesTheToken()
    {
        $cookie = $this->visitor();

        $this->assertNotSame(IfirmaFormToken::forVisitor($cookie, 'key-a'), IfirmaFormToken::forVisitor($cookie, 'key-b'));
    }

    public function testTheKeyComesFromTheCookieKeyConstant()
    {
        $this->assertSame(_COOKIE_KEY_, IfirmaFormToken::cookieKey());
    }
}
