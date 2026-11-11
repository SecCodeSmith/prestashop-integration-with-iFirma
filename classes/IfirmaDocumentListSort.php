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

require_once dirname(__FILE__) . '/IfirmaDocumentStateRank.php';

/**
 * How the documents list sorts its state column: the rank of each record as a selected value to order by, the
 * direction reduced to ASC or DESC, and the newest-first tie-break. Framework-free: the controller only hands the
 * pieces of the core's ORDER BY clause to it.
 */
class IfirmaDocumentListSort
{
    /** The select alias the state column orders by (a column key would be split at a dot or an exclamation mark). */
    const RANK_ALIAS = 'ifirma_state_rank';

    const ASCENDING = 'ASC';
    const DESCENDING = 'DESC';

    /** Records of the same state, newest first, whatever the direction of the state. */
    const TIE_BREAK = 'a.`id_ifirma_receipt` DESC';

    /**
     * @param string $recordAlias alias of the receipt row in the list query
     * @return string the select expression that gives each record its rank
     */
    public static function rankSelect($recordAlias)
    {
        return IfirmaDocumentStateRank::sql($recordAlias) . ' AS ' . self::RANK_ALIAS;
    }

    /**
     * @param mixed $value a request value
     * @return string 'DESC' for desc in any letter case, 'ASC' for everything else: the value never reaches the SQL
     */
    public static function direction($value)
    {
        return self::isDirection($value) && strtolower($value) === 'desc' ? self::DESCENDING : self::ASCENDING;
    }

    /**
     * @param mixed $value a request value
     * @return bool whether it is asc or desc in any letter case
     */
    public static function isDirection($value)
    {
        return is_string($value) && in_array(strtolower($value), array('asc', 'desc'), true);
    }

    /**
     * @param string $clause  the core's ORDER BY clause, starting with " ORDER BY <column> <way>" (a suffix may follow)
     * @param string $orderBy the column the core ordered by, as it wrote it into the clause
     * @param string $way     ASC or DESC, as the core wrote it into the clause
     * @return string the clause with the tie-break added after the state sort; any other clause unchanged
     */
    public static function withTieBreak($clause, $orderBy, $way)
    {
        $head = ' ORDER BY ' . $orderBy . ' ' . $way;
        if ($orderBy !== self::RANK_ALIAS || strpos($clause, $head) !== 0) {
            return $clause;
        }

        return $head . ', ' . self::TIE_BREAK . substr($clause, strlen($head));
    }
}
