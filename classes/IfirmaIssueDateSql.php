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
 * The issue date of a module record as SQL text, for every query that shows or matches it. Framework-free.
 *
 * A record without an issue date (NULL, or a zero date an older lax server stored) reads its record date instead.
 * The test is "earlier than 1000-01-01", never a comparison with '0000-00-00': MySQL 8 with its default NO_ZERO_DATE
 * and STRICT_TRANS_TABLES refuses that literal (error 1525), which took the back-office Orders list down.
 */
class IfirmaIssueDateSql
{
    /** The earliest date MySQL and MariaDB support for DATE; anything before it is a zero or invalid date. */
    const FIRST_VALID_DATE = '1000-01-01';

    /**
     * @param string $alias the record table's alias in the query, '' for the table itself
     * @return string the expression, a DATE
     */
    public static function expression($alias = '')
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        return "COALESCE(IF({$prefix}issue_date < '" . self::FIRST_VALID_DATE . "', NULL, {$prefix}issue_date), DATE({$prefix}date_add))";
    }
}
