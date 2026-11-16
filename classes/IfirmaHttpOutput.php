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

require_once dirname(__FILE__) . '/IfirmaHttpStatus.php';
require_once dirname(__FILE__) . '/IfirmaShopLink.php';

/**
 * Sends a PDF document as the response, for the front receipt controller and the admin
 * AdminIfirmaController: the one place that knows the headers of a PDF download.
 *
 * pdf() does not end the request; the controller calls exit right after it. Every header line
 * goes through a sender that is the native header() unless a test replaced it, so a test can
 * record the lines instead of sending them (useHeaderSink()).
 */
class IfirmaHttpOutput
{
    /** @var callable receives each header line */
    private static $sendHeader = 'header';

    /**
     * Testability seam: where pdf() sends its header lines.
     *
     * @param callable|null $sink receives each header line; null restores the native header()
     */
    public static function useHeaderSink($sink = null)
    {
        self::$sendHeader = $sink === null ? 'header' : $sink;
    }

    /**
     * Discards the pending output buffer, sends the PDF headers and echoes the document.
     *
     * The file name is built by IfirmaShopLink::pdfFileName() from the raw document number or identifier, so no caller
     * can put a quote, a slash or a line break into the header.
     *
     * @param string $pdf  the PDF bytes
     * @param string $name the document number or, when there is none, the identifier (raw)
     */
    public static function pdf($pdf, $name)
    {
        $filename = IfirmaShopLink::pdfFileName($name);

        if (ob_get_level()) {
            ob_end_clean();
        }
        call_user_func(self::$sendHeader, 'Content-Type: application/pdf');
        call_user_func(self::$sendHeader, 'Content-Disposition: attachment; filename="' . $filename . '"');
        call_user_func(self::$sendHeader, 'Content-Length: ' . strlen($pdf));
        call_user_func(self::$sendHeader, 'Cache-Control: private, must-revalidate');
        call_user_func(self::$sendHeader, 'X-Content-Type-Options: nosniff');
        echo $pdf;
    }

    /**
     * Discards the pending output buffer, sends a 429 with the seconds to wait and echoes the message. The caller
     * ends the request right after it.
     *
     * The wait is cast to a whole number of at least one second, so no text can reach a header line; the message is
     * plain text under nosniff and is never part of a header.
     *
     * @param int    $retryAfterSeconds
     * @param string $message           plain text for the person who was refused
     */
    public static function tooManyRequests($retryAfterSeconds, $message)
    {
        self::plainTextAnswer(429, array('Retry-After: ' . max(1, (int) $retryAfterSeconds)), $message);
    }

    /**
     * Discards the pending output buffer, sends a 404 and echoes the message. The caller ends the request right
     * after it.
     *
     * The message is plain text under nosniff, never cached and never part of a header. A caller gives one fixed
     * sentence for every reason, so the answer does not tell why nothing is available.
     *
     * @param string $message plain text for the person who was refused
     */
    public static function notAvailable($message)
    {
        self::plainTextAnswer(404, array(), $message);
    }

    /**
     * @param int      $statusCode
     * @param string[] $extraHeaders header lines that go between the status line and the content type
     * @param string   $message
     */
    private static function plainTextAnswer($statusCode, array $extraHeaders, $message)
    {
        if (ob_get_level()) {
            ob_end_clean();
        }
        call_user_func(self::$sendHeader, IfirmaHttpStatus::statusLine($statusCode));
        foreach ($extraHeaders as $line) {
            call_user_func(self::$sendHeader, $line);
        }
        call_user_func(self::$sendHeader, 'Content-Type: text/plain; charset=utf-8');
        call_user_func(self::$sendHeader, 'Cache-Control: no-store');
        call_user_func(self::$sendHeader, 'X-Content-Type-Options: nosniff');
        echo $message;
    }
}
