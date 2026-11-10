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
 * The kind of a request to iFirma, for the usage statistics: it is derived from the verb and the path, so callers
 * of IfirmaApiClient cannot mislabel a request. A request that matches no rule is KIND_OTHER.
 */
class IfirmaRequestKind
{
    const KIND_DOCUMENT = 'document';
    const KIND_STATUS = 'status';
    const KIND_PDF = 'pdf';
    const KIND_EMAIL = 'email';
    const KIND_KSEF = 'ksef';
    const KIND_PAYMENT = 'payment';
    const KIND_CONTRACTOR = 'contractor';
    const KIND_LIMIT = 'limit';
    const KIND_OTHER = 'other';

    /**
     * @return string[] every kind, in the order they are shown
     */
    public static function all()
    {
        return array(
            self::KIND_DOCUMENT,
            self::KIND_STATUS,
            self::KIND_PDF,
            self::KIND_EMAIL,
            self::KIND_KSEF,
            self::KIND_PAYMENT,
            self::KIND_CONTRACTOR,
            self::KIND_LIMIT,
            self::KIND_OTHER,
        );
    }

    /**
     * @param string $method HTTP verb
     * @param string $path   path below the API base URL, with or without a query string
     * @return string one of the KIND_* constants
     */
    public static function of($method, $path)
    {
        $verb = strtoupper((string) $method);
        $route = (string) strtok((string) $path, '?');

        foreach (self::rules() as $rule) {
            if (($rule[0] === '' || $rule[0] === $verb) && preg_match($rule[1], $route) === 1) {
                return $rule[2];
            }
        }

        return self::KIND_OTHER;
    }

    /**
     * Ordered: the first match wins, so the longer shapes (KSeF send, mail send, payment, contractors) come before
     * the generic document detail and create shapes they would otherwise match.
     *
     * @return array[] each: array(verb or '' for any, pattern, kind)
     */
    private static function rules()
    {
        return array(
            array('GET', '#^abonent/limit\.json$#', self::KIND_LIMIT),
            array('', '#^kontrahenci(\.json|/[^/]+\.json|/id/[^/]+\.json)$#', self::KIND_CONTRACTOR),
            array('POST', '#^faktury/wplaty/[^/]+/[^/]+\.json$#', self::KIND_PAYMENT),
            array('GET', '#^faktury\.json$#', self::KIND_STATUS),
            array('POST', '#^[a-z_]+/ksef/send/[^/]+\.json$#', self::KIND_KSEF),
            array('POST', '#^[a-z_]+/send/[^/]+\.json$#', self::KIND_EMAIL),
            array('GET', '#^[a-z_]+/[^/]+\.pdf$#', self::KIND_PDF),
            array('GET', '#^[a-z_]+/[^/]+\.json$#', self::KIND_STATUS),
            array('POST', '#^[a-z_]+\.json$#', self::KIND_DOCUMENT),
        );
    }
}
