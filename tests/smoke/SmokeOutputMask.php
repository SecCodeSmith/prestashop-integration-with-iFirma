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

/**
 * Masks keys and tokens in smoke output to their last four characters. The CI run keeps that output as a public
 * artifact, and a failing check prints both sides of a comparison, which could be a cron or download token.
 */
final class SmokeOutputMask
{
    const MIN_SECRET_LENGTH = 8;
    const MIN_TOKEN_LENGTH = 24;

    /**
     * @param string   $text
     * @param string[] $secrets exact values to mask wherever they appear (for example the configured cron token)
     */
    public static function mask($text, array $secrets = array())
    {
        foreach ($secrets as $secret) {
            if (strlen((string) $secret) >= self::MIN_SECRET_LENGTH) {
                $text = str_replace($secret, self::hide($secret), $text);
            }
        }
        $text = preg_replace_callback(
            '/\b(token|key)=([^&\s"\']+)/i',
            function (array $m) {
                return $m[1] . '=' . self::hide($m[2]);
            },
            $text
        );

        // A long run of token characters with a digit is a key or token; plain identifiers (class names) have no digit.
        return preg_replace_callback(
            '/[A-Za-z0-9_+\/-]{' . self::MIN_TOKEN_LENGTH . ',}/',
            function (array $m) {
                return preg_match('/\d/', $m[0]) === 1 ? self::hide($m[0]) : $m[0];
            },
            $text
        );
    }

    private static function hide($value)
    {
        return '****' . substr($value, -4);
    }
}
