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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The anti-forgery token of the customer-facing forms, one per visitor.
 *
 * PrestaShop's Tools::getToken(false) is the same string for every anonymous visitor of a shop, so it
 * cannot stop a forged request on the guest page. This token is derived from a random nonce kept in the
 * visitor's own cookie: hash_hmac('sha256', 'ifirma-form:' . nonce, key), with the cookie key of the shop.
 * A page of another site cannot read the nonce nor the token, and the token of one visitor is useless
 * for another.
 *
 * Public contract:
 *  - cookieKey(): the shop's _COOKIE_KEY_, '' when it is not defined.
 *  - forVisitor(cookie, key): the token of the visitor, creating the nonce on first use; '' when the key
 *    is empty (nothing trustworthy can be derived from it).
 *  - isValid(given, cookie, key): whether given is the token of this visitor; false for a non-string, an
 *    empty value, an empty key and a visitor without a nonce (the nonce is never created by a check).
 *
 * The cookie is any object with the PrestaShop Cookie property access (a missing property reads as unset).
 */
class IfirmaFormToken
{
    const NONCE_FIELD = 'ifirma_form_nonce';

    /**
     * @return string
     */
    public static function cookieKey()
    {
        return defined('_COOKIE_KEY_') ? (string) _COOKIE_KEY_ : '';
    }

    /**
     * @param object $cookie
     * @return string the nonce of the visitor, '' when there is none (or it is not one of ours)
     */
    private static function nonce($cookie)
    {
        $field = self::NONCE_FIELD;
        $value = isset($cookie->$field) ? $cookie->$field : '';

        return is_string($value) && preg_match('/^[0-9a-f]{32}$/', $value) ? $value : '';
    }

    /**
     * @param string $nonce
     * @param string $key
     * @return string
     */
    private static function derive($nonce, $key)
    {
        return hash_hmac('sha256', 'ifirma-form:' . $nonce, $key);
    }

    /**
     * @param object $cookie
     * @param string $key
     * @return string
     */
    public static function forVisitor($cookie, $key)
    {
        $key = (string) $key;
        if ($key === '') {
            return '';
        }
        $nonce = self::nonce($cookie);
        if ($nonce === '') {
            $nonce = bin2hex(random_bytes(16));
            $field = self::NONCE_FIELD;
            $cookie->$field = $nonce;
        }

        return self::derive($nonce, $key);
    }

    /**
     * @param mixed  $given
     * @param object $cookie
     * @param string $key
     * @return bool
     */
    public static function isValid($given, $cookie, $key)
    {
        $key = (string) $key;
        $nonce = self::nonce($cookie);
        if (!is_string($given) || $given === '' || $key === '' || $nonce === '') {
            return false;
        }

        return hash_equals(self::derive($nonce, $key), $given);
    }
}
