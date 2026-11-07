<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

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
    const ICON = 'receipt_long';

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
     * @return string[] three select expressions on the joined row, each with an AS alias, for QueryBuilder::addSelect();
     *                  the first two are '' for an order without an issued document (no row joined, or not issued);
     *                  the third is the raw status of the newest record, '' without a row
     */
    public static function selectSql()
    {
        $j = self::JOIN_ALIAS;
        // the same "issued" rule as IfirmaReceipt::isIssued(): an identifier and not the error state
        $issued = "{$j}.ifirma_id <> '' AND {$j}.status <> '" . pSQL(IfirmaReceipt::STATUS_ERROR) . "'";

        return array(
            "IF({$issued}, IF({$j}.ifirma_number <> '', {$j}.ifirma_number, {$j}.ifirma_id), '') AS " . self::FIELD_NUMBER,
            "IF({$issued}, {$j}.status, '') AS " . self::FIELD_STATUS,
            "IFNULL({$j}.status, '') AS " . self::FIELD_STATE,
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
        $word = $paid ? (isset($labels['paid']) ? $labels['paid'] : 'Paid') : (isset($labels['issued']) ? $labels['issued'] : 'Issued');
        $title = 'iFirma: ' . $number . ' (' . $word . ')';

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
        $title = isset($labels['issue']) ? (string) $labels['issue'] : 'Issue iFirma document';
        $state = isset($record[self::FIELD_STATE]) ? (string) $record[self::FIELD_STATE] : '';
        if ($state !== '') {
            $title .= ' (' . (isset($labels[$state]) ? $labels[$state] : $state) . ')';
        }

        return '<a href="#" class="js-ifirma-issue" data-id-order="' . $idOrder . '" title="'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"><i class="material-icons">add_circle_outline</i></a>';
    }
}
