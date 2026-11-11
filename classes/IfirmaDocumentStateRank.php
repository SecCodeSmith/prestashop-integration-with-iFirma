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
 * The state of a module record as a number, for sorting and filtering the Orders list and the documents list
 * by the same order: what needs action first, then no record, then the issued ones from "not sent" to "paid".
 * Framework-free: it only builds SQL text for a record alias and names the ten states.
 */
class IfirmaDocumentStateRank
{
    const ERROR = 1;
    const UNKNOWN = 2;
    const SUSPECTED = 3;
    const HELD = 4;
    const QUEUED = 5;
    const NO_RECORD = 6;
    const ISSUED = 7;
    const AWAITING_KSEF = 8;
    const SENT = 9;
    const PAID = 10;

    /**
     * @return array[] the ten states in rank order, each with 'rank', 'state' (a short code) and 'label_key' (the key
     *                 of the word that names the state in the module's labels)
     */
    public static function states()
    {
        return array(
            array('rank' => self::ERROR, 'state' => 'error', 'label_key' => 'state_error'),
            array('rank' => self::UNKNOWN, 'state' => 'unknown', 'label_key' => 'state_unknown'),
            array('rank' => self::SUSPECTED, 'state' => 'duplicate_suspected', 'label_key' => 'state_duplicate_suspected'),
            array('rank' => self::HELD, 'state' => 'held', 'label_key' => 'state_held'),
            array('rank' => self::QUEUED, 'state' => 'queued', 'label_key' => 'state_queued'),
            array('rank' => self::NO_RECORD, 'state' => 'none', 'label_key' => 'state_none'),
            array('rank' => self::ISSUED, 'state' => 'issued', 'label_key' => 'state_issued'),
            array('rank' => self::AWAITING_KSEF, 'state' => 'awaiting_ksef', 'label_key' => 'state_awaiting_ksef'),
            array('rank' => self::SENT, 'state' => 'sent', 'label_key' => 'state_sent'),
            array('rank' => self::PAID, 'state' => 'paid', 'label_key' => 'state_paid'),
        );
    }

    /**
     * The same "issued" rule as IfirmaReceipt::isIssued(): an identifier and not the error state.
     *
     * @param string $alias alias of the receipt row in the query
     * @return string an SQL condition
     * @throws InvalidArgumentException when the alias is not a plain SQL identifier
     */
    public static function issuedSql($alias)
    {
        self::assertAlias($alias);

        return "{$alias}.ifirma_id <> '' AND {$alias}.status <> '" . pSQL(IfirmaReceipt::STATUS_ERROR) . "'";
    }

    /**
     * @param string $alias alias of the receipt row in the query (a LEFT JOINed row yields "no record")
     * @return string a CASE expression giving the rank of the row; a paid document wins over its delivery state
     * @throws InvalidArgumentException when the alias is not a plain SQL identifier
     */
    public static function sql($alias)
    {
        $issued = self::issuedSql($alias);
        $rows = array(
            "WHEN {$alias}.id_ifirma_receipt IS NULL THEN " . self::NO_RECORD,
            "WHEN {$issued} AND {$alias}.status = 'paid' THEN " . self::PAID,
            "WHEN {$issued} AND {$alias}.delivery_status = 'awaiting_ksef' THEN " . self::AWAITING_KSEF,
            "WHEN {$issued} AND {$alias}.delivery_status = 'sent' THEN " . self::SENT,
            "WHEN {$issued} THEN " . self::ISSUED,
            "WHEN {$alias}.status = 'error' THEN " . self::ERROR,
            "WHEN {$alias}.status = 'unknown' THEN " . self::UNKNOWN,
            "WHEN {$alias}.status = 'duplicate_suspected' THEN " . self::SUSPECTED,
            "WHEN {$alias}.status = 'held' THEN " . self::HELD,
            "WHEN {$alias}.status = 'queued' THEN " . self::QUEUED,
        );

        return 'CASE ' . implode(' ', $rows) . ' ELSE ' . self::NO_RECORD . ' END';
    }

    /**
     * Reads a request value as a rank. Only an integer from 1 to 10 (as an int or its plain decimal string) is one:
     * the value is never trusted any further than that, and nothing else is ever put into SQL.
     *
     * @param mixed $value
     * @return int|null the rank, null for anything else
     */
    public static function parse($value)
    {
        if (is_int($value)) {
            return ($value >= self::ERROR && $value <= self::PAID) ? $value : null;
        }
        if (is_string($value) && preg_match('/\A(?:10|[1-9])\z/', $value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param string $alias
     * @throws InvalidArgumentException when the alias is not a plain SQL identifier
     */
    private static function assertAlias($alias)
    {
        if (!is_string($alias) || !preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new InvalidArgumentException('The record alias is not a plain SQL identifier.');
        }
    }
}
