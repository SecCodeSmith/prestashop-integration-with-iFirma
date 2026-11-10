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
 * Pure helper that builds the HTTP status line for a status code, so a controller does not
 * hand-write the reason phrase next to header(). No I/O: the caller passes the result to header().
 *
 * A code listed in REASONS gets its standard phrase. Any other code from 100 to 599 keeps its
 * number and gets UNKNOWN_REASON, so the line stays well formed. A value that is no status code
 * at all (below 100, above 599, not a number) cannot form a status line and is reported as 500,
 * the response of a server that failed.
 */
class IfirmaHttpStatus
{
    /** Reason phrase of a valid code that REASONS does not list. */
    const UNKNOWN_REASON = 'Unknown Status';

    /** Status code => standard reason phrase. Extend it when a controller starts to send another code. */
    const REASONS = array(
        200 => 'OK',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
    );

    /**
     * @param int|string $code HTTP status code
     * @return string e.g. "HTTP/1.1 403 Forbidden"
     */
    public static function statusLine($code)
    {
        $code = (int) $code;
        if ($code < 100 || $code > 599) {
            $code = 500;
        }
        $reason = isset(self::REASONS[$code]) ? self::REASONS[$code] : self::UNKNOWN_REASON;

        return 'HTTP/1.1 ' . $code . ' ' . $reason;
    }
}
