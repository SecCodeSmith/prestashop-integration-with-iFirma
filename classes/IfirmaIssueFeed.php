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
 * The back-office "issue events" feed: the module records that need an employee's attention, the kind of each
 * (decided here, in PHP), the count of those newer than the employee's seen mark, and the seen mark itself.
 * It carries no words: kinds are codes and the module class words them.
 *
 * A record is listed while it is in one of the states of issuePredicate(); once repaired (issued, delivered,
 * back in iFirma, request answered) it no longer matches and disappears from the feed.
 */
class IfirmaIssueFeed
{
    const KIND_HELD = 'held';
    const KIND_ERROR = 'error';
    const KIND_UNKNOWN = 'unknown';
    const KIND_SUSPECTED = 'suspected';
    const KIND_MISSING = 'missing';
    const KIND_KSEF_FAILED = 'ksef_failed';
    const KIND_DELIVERY_FAILED = 'delivery_failed';

    const SEEN_KEY_PREFIX = 'IFIRMA_SEEN_';
    const DEFAULT_LIMIT = 20;

    /** @var callable|null returns a unix timestamp */
    private $clock;

    /**
     * @param callable|null $clock returns a unix timestamp; time() when null
     */
    public function __construct($clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @return string[] the kinds in the order that decides which one a record with several problems shows
     */
    public static function kinds()
    {
        return array(
            self::KIND_HELD, self::KIND_ERROR, self::KIND_UNKNOWN, self::KIND_SUSPECTED,
            self::KIND_MISSING, self::KIND_KSEF_FAILED, self::KIND_DELIVERY_FAILED,
        );
    }

    /**
     * SQL condition for "this record needs attention".
     *
     * @param string $alias alias of the receipt table in the query
     * @return string
     */
    public static function issuePredicate($alias = 'r')
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new InvalidArgumentException('The alias is not a plain SQL identifier: ' . $alias);
        }
        $a = $alias;

        return "({$a}.status IN ('error','unknown','duplicate_suspected') OR ({$a}.status = 'held' AND " . self::pendingExists($a)
            . ") OR {$a}.delivery_status = 'failed' OR {$a}.ksef_status = 'BLAD' OR {$a}.remote_missing = 1)";
    }

    /**
     * @param string $a receipt alias
     * @return string EXISTS (...) of a pending data request of the record's order
     */
    private static function pendingExists($a)
    {
        return 'EXISTS (SELECT 1 FROM ' . _DB_PREFIX_ . 'ifirma_data_request d WHERE d.id_order = ' . $a
            . ".id_order AND d.status = 'pending')";
    }

    /**
     * The newest records that need attention, newest first.
     *
     * @param int $limit
     * @return array[] id_ifirma_receipt, id_order, reference, kind, number, time, last_error
     */
    public function latest($limit = self::DEFAULT_LIMIT)
    {
        $limit = max(1, (int) $limit);
        $sql = 'SELECT r.id_ifirma_receipt, r.id_order, o.reference, r.status, r.delivery_status, r.ksef_status, r.remote_missing, '
            . 'r.ifirma_number, r.ifirma_id, r.last_error, r.date_upd, ' . self::pendingExists('r') . ' AS has_pending_request '
            . 'FROM ' . _DB_PREFIX_ . 'ifirma_receipt r '
            . 'LEFT JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_order = r.id_order '
            . 'WHERE ' . self::issuePredicate('r') . ' '
            . 'ORDER BY r.date_upd DESC, r.id_ifirma_receipt DESC LIMIT ' . $limit;

        $rows = Db::getInstance()->executeS($sql);
        $items = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $number = isset($row['ifirma_number']) ? trim((string) $row['ifirma_number']) : '';
            $items[] = array(
                'id_ifirma_receipt' => (int) $row['id_ifirma_receipt'],
                'id_order' => (int) $row['id_order'],
                'reference' => isset($row['reference']) ? (string) $row['reference'] : '',
                'kind' => self::classify($row),
                'number' => $number !== '' ? $number : (isset($row['ifirma_id']) ? (string) $row['ifirma_id'] : ''),
                'time' => isset($row['date_upd']) ? (string) $row['date_upd'] : '',
                'last_error' => isset($row['last_error']) ? (string) $row['last_error'] : '',
            );
        }

        return $items;
    }

    /**
     * The first kind that applies, in the order held, error, unknown, suspected, missing, ksef_failed,
     * delivery_failed.
     *
     * @param array $row status, delivery_status, ksef_status, remote_missing, has_pending_request
     * @return string one of the KIND_* constants, or '' when the row matches none
     */
    public static function classify(array $row)
    {
        $status = isset($row['status']) ? (string) $row['status'] : '';
        if ($status === 'held' && !empty($row['has_pending_request'])) {
            return self::KIND_HELD;
        }
        if ($status === 'error') {
            return self::KIND_ERROR;
        }
        if ($status === 'unknown') {
            return self::KIND_UNKNOWN;
        }
        if ($status === 'duplicate_suspected') {
            return self::KIND_SUSPECTED;
        }
        if (!empty($row['remote_missing'])) {
            return self::KIND_MISSING;
        }
        if (isset($row['ksef_status']) && (string) $row['ksef_status'] === 'BLAD') {
            return self::KIND_KSEF_FAILED;
        }
        if (isset($row['delivery_status']) && (string) $row['delivery_status'] === 'failed') {
            return self::KIND_DELIVERY_FAILED;
        }

        return '';
    }

    /**
     * @param string $since datetime of the employee's seen mark, '' when there is none
     * @return int records that need attention and changed after the mark
     */
    public function unseenCount($since)
    {
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'ifirma_receipt r WHERE ' . self::issuePredicate('r');
        if ((string) $since !== '') {
            $sql .= " AND r.date_upd > '" . pSQL((string) $since) . "'";
        }

        return (int) Db::getInstance()->getValue($sql);
    }

    /**
     * @param int $idEmployee
     * @return string the employee's seen mark (datetime), '' when none
     */
    public function seenAt($idEmployee)
    {
        return (string) Configuration::get(self::SEEN_KEY_PREFIX . (int) $idEmployee);
    }

    /**
     * @param int      $idEmployee
     * @param int|null $now unix timestamp; the clock when null
     */
    public function markSeen($idEmployee, $now = null)
    {
        Configuration::updateValue(
            self::SEEN_KEY_PREFIX . (int) $idEmployee,
            date('Y-m-d H:i:s', $now ?: $this->now())
        );
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock ? (int) call_user_func($this->clock) : time();
    }
}
