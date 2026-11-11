<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaOrderGridTooltip.php';
require_once dirname(__FILE__) . '/IfirmaDocumentStateRank.php';

/**
 * What the "iFirma" column of the back-office Orders list shows for each order: the SQL that reads the
 * newest module record of the order, and the icon cell built from it. Framework-free on purpose: the grid
 * hooks in ifirma.php only wire these two methods into the Symfony grid.
 */
class IfirmaOrderGridIndicator
{
    const FIELD = 'ifirma_document';           // the column id and the record key the presenter fills
    const FIELD_NUMBER = 'ifirma_document_number';
    const FIELD_STATUS = 'ifirma_document_status';
    const FIELD_STATE = 'ifirma_document_state';   // the raw status of the newest record, '' without a row
    const FIELD_TYPE = 'ifirma_document_type';
    const FIELD_DELIVERY = 'ifirma_document_delivery';
    const FIELD_SENT_DATE = 'ifirma_document_sent_date';
    const FIELD_KSEF_STATUS = 'ifirma_document_ksef_status';
    const FIELD_KSEF_NUMBER = 'ifirma_document_ksef_number';
    const FIELD_ERROR = 'ifirma_document_error';
    const FIELD_RANK = 'ifirma_document_rank';
    const FIELD_DELIVERY_ATTEMPTS = 'ifirma_document_delivery_attempts';
    const FIELD_DELIVERY_RETRY_AT = 'ifirma_document_delivery_retry_at';
    const ICON = 'receipt_long';

    /** The ORDER BY alias of the column's sort (the column id itself holds the HTML of the cell). */
    const SORT_ALIAS = self::FIELD_RANK;

    /** Position of each state in the sort, ascending: what needs action first (the ranks live in IfirmaDocumentStateRank). */
    const RANK_ERROR = IfirmaDocumentStateRank::ERROR;
    const RANK_UNKNOWN = IfirmaDocumentStateRank::UNKNOWN;
    const RANK_SUSPECTED = IfirmaDocumentStateRank::SUSPECTED;
    const RANK_HELD = IfirmaDocumentStateRank::HELD;
    const RANK_QUEUED = IfirmaDocumentStateRank::QUEUED;
    const RANK_NO_RECORD = IfirmaDocumentStateRank::NO_RECORD;
    const RANK_ISSUED = IfirmaDocumentStateRank::ISSUED;
    const RANK_AWAITING_KSEF = IfirmaDocumentStateRank::AWAITING_KSEF;
    const RANK_SENT = IfirmaDocumentStateRank::SENT;
    const RANK_PAID = IfirmaDocumentStateRank::PAID;

    /** @var string alias of the joined receipt row; none of PrestaShop's order-grid aliases (o, cu, cur, a, c, os, osl, s) */
    const JOIN_ALIAS = 'ifr';

    /**
     * One LEFT JOIN on the newest module record of each order (the MAX id, found through idx_order), so the two
     * selected values cost one lookup per listed row instead of a correlated subquery each.
     *
     * @param string $orderAlias alias of the orders table in the grid query (PrestaShop uses 'o')
     * @return array 'table', 'alias' and 'condition' for QueryBuilder::leftJoin($orderAlias, table, alias, condition)
     */
    public static function joinSql($orderAlias)
    {
        self::assertAlias($orderAlias);
        $t = '`' . _DB_PREFIX_ . 'ifirma_receipt`';

        return array(
            'table' => $t,
            'alias' => self::JOIN_ALIAS,
            'condition' => self::JOIN_ALIAS . '.id_ifirma_receipt = (SELECT MAX(r.id_ifirma_receipt) FROM ' . $t
                . ' r WHERE r.id_order = ' . $orderAlias . '.id_order)',
        );
    }

    /**
     * @return string[] the select expressions on the joined row, each with an AS alias, for QueryBuilder::addSelect():
     *                  number and status ('' for an order without an issued document), the raw state of the newest
     *                  record, its document type, delivery state, delivery date, KSeF status and number, the error
     *                  (one character more than shown, so the builder knows when to add the ellipsis) and the rank of
     *                  the sort. Every one reads the one joined row: no subquery, no iFirma call.
     */
    public static function selectSql()
    {
        $j = self::JOIN_ALIAS;
        $issued = IfirmaDocumentStateRank::issuedSql($j);

        return array(
            "IF({$issued}, IF({$j}.ifirma_number <> '', {$j}.ifirma_number, {$j}.ifirma_id), '') AS " . self::FIELD_NUMBER,
            "IF({$issued}, {$j}.status, '') AS " . self::FIELD_STATUS,
            "IFNULL({$j}.status, '') AS " . self::FIELD_STATE,
            "IFNULL({$j}.document_type, '') AS " . self::FIELD_TYPE,
            "IFNULL({$j}.delivery_status, '') AS " . self::FIELD_DELIVERY,
            "IFNULL(DATE({$j}.delivered_at), '') AS " . self::FIELD_SENT_DATE,
            "IFNULL({$j}.ksef_status, '') AS " . self::FIELD_KSEF_STATUS,
            "IFNULL({$j}.ksef_number, '') AS " . self::FIELD_KSEF_NUMBER,
            "LEFT(IFNULL({$j}.last_error, ''), " . (IfirmaOrderGridTooltip::ERROR_LIMIT + 1) . ") AS " . self::FIELD_ERROR,
            IfirmaDocumentStateRank::sql($j) . ' AS ' . self::FIELD_RANK,
        );
    }

    /**
     * @return string[] the two retry fields (attempts used, next due time) for QueryBuilder::addSelect(). Kept apart from
     *                  selectSql() because the columns come from an upgrade: the caller adds them once the table has them,
     *                  so a shop that did not add them yet still lists its orders.
     */
    public static function retrySelectSql()
    {
        $j = self::JOIN_ALIAS;

        return array(
            "IFNULL({$j}.delivery_attempts, 0) AS " . self::FIELD_DELIVERY_ATTEMPTS,
            "IFNULL({$j}.delivery_retry_at, '') AS " . self::FIELD_DELIVERY_RETRY_AT,
        );
    }

    /**
     * @param string $alias
     * @throws InvalidArgumentException when the alias is not a plain SQL identifier
     */
    private static function assertAlias($alias)
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new InvalidArgumentException('The order alias is not a plain SQL identifier: ' . $alias);
        }
    }

    /**
     * @param array $record    one grid record (the row as fetched), with FIELD_NUMBER, FIELD_STATUS and FIELD_STATE
     *                         when the query ran, and id_order
     * @param array $labels    'paid' => word for a paid document, 'issued' => word for any other issued document,
     *                         'issue' => title of the issue anchor, error | unknown | duplicate_suspected | held =>
     *                         the word for that state of an unissued record
     * @param bool  $canIssue  true when the employee may issue documents: an order without an issued document then
     *                         gets the issue anchor (the back-office script posts it)
     * @return string the escaped issued icon, else the issue anchor when $canIssue, else ''
     */
    public static function cell(array $record, array $labels, $canIssue = false)
    {
        $number = isset($record[self::FIELD_NUMBER]) ? trim((string) $record[self::FIELD_NUMBER]) : '';
        if ($number === '') {
            return $canIssue ? self::issueAnchor($record, $labels) : '';
        }
        $status = isset($record[self::FIELD_STATUS]) ? (string) $record[self::FIELD_STATUS] : '';
        $paid = ($status === IfirmaReceipt::STATUS_PAID);
        $title = implode("\n", IfirmaOrderGridTooltip::lines($record, $labels));

        return '<i class="material-icons ifirma-grid-icon ' . ($paid ? 'text-success' : 'text-info') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . self::ICON . '</i>';
    }

    /**
     * @param array $record
     * @param array $labels
     * @return string
     */
    private static function issueAnchor(array $record, array $labels)
    {
        $idOrder = isset($record['id_order']) ? (int) $record['id_order'] : 0;
        $title = implode("\n", IfirmaOrderGridTooltip::lines($record, $labels));

        return '<a href="#" class="js-ifirma-issue" data-id-order="' . $idOrder . '" title="'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><i class="material-icons">add_circle_outline</i></a>';
    }
}
