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
 * Captures the SQL a test pins: sentQueries() returns every query the Db stub recorded, with the CRLF
 * line endings of a Windows checkout turned into "\n". For a PHPUnit TestCase; compare its result with
 * the SQL text the test expects, after the code under test has run.
 */
trait IfirmaSentQueries
{
    /**
     * @return string[] every SQL string sent so far, in order, with the CRLF line endings of a Windows
     *                  checkout turned into "\n", so that the text is the same on every checkout
     */
    protected function sentQueries()
    {
        return array_map(function ($sql) {
            return str_replace("\r\n", "\n", (string) $sql);
        }, Db::getInstance()->queries);
    }
}
