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
    const ICON = 'receipt_long';

    /**
     * @param string $orderAlias alias of the orders table in the grid query (PrestaShop uses 'o')
     * @return string[] two select expressions, each with an AS alias, for QueryBuilder::addSelect()
     */
    public static function selectSql($orderAlias)
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $orderAlias)) {
            throw new InvalidArgumentException('The order alias is not a plain SQL identifier: ' . $orderAlias);
        }
        $a = $orderAlias;
        $t = '`' . _DB_PREFIX_ . 'ifirma_receipt`';
        // the same "issued" rule as IfirmaReceipt::isIssued(): an identifier and not the error state
        $issued = "r.ifirma_id <> '' AND r.status <> '" . pSQL(IfirmaReceipt::STATUS_ERROR) . "'";
        $newest = "FROM {$t} r WHERE r.id_order = {$a}.id_order ORDER BY r.id_ifirma_receipt DESC LIMIT 1";

        return array(
            "(SELECT IF({$issued}, IF(r.ifirma_number <> '', r.ifirma_number, r.ifirma_id), '') {$newest}) AS " . self::FIELD_NUMBER,
            "(SELECT IF({$issued}, r.status, '') {$newest}) AS " . self::FIELD_STATUS,
        );
    }

    /**
     * @param array $record one grid record (the row as fetched), with FIELD_NUMBER and FIELD_STATUS when the query ran
     * @param array $labels 'paid' => word for a paid document, 'issued' => word for any other issued document
     * @return string '' when the order has no issued document, else the escaped <i> icon
     */
    public static function cell(array $record, array $labels)
    {
        $number = isset($record[self::FIELD_NUMBER]) ? trim((string) $record[self::FIELD_NUMBER]) : '';
        if ($number === '') {
            return '';
        }
        $status = isset($record[self::FIELD_STATUS]) ? (string) $record[self::FIELD_STATUS] : '';
        $paid = ($status === IfirmaReceipt::STATUS_PAID);
        $word = $paid ? (isset($labels['paid']) ? $labels['paid'] : 'Paid') : (isset($labels['issued']) ? $labels['issued'] : 'Issued');
        $title = 'iFirma: ' . $number . ' (' . $word . ')';

        return '<i class="material-icons ifirma-grid-icon ' . ($paid ? 'text-success' : 'text-info') . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . self::ICON . '</i>';
    }
}
