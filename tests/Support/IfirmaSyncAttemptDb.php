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
 * Makes the statements of the customer page sync run against the in-memory receipt rows, the way MySQL would: the
 * customer's anchor record (the smallest id of an issued record), the conditional claim UPDATE that reports the rows
 * it changed, the release of a claim, and the finder of the customer's other stale records of one issue date.
 */
trait IfirmaSyncAttemptDb
{
    /** @var callable|null runs once, just before the anchor SELECT answers: a rival request that starts at that moment */
    protected $beforeAnchorLookup = null;

    /** @var callable|null runs just before the finder of the other stale records answers */
    protected $beforeStaleFinder = null;

    protected function scriptSyncAttemptDb()
    {
        $db = Db::getInstance();
        $db->whenGetValue('/SELECT MIN\(id_ifirma_receipt\) FROM `ps_ifirma_receipt`\s+WHERE id_customer = (\d+)\s+AND ifirma_id != \'\' AND status != \'error\'/s', function ($sql, $m) {
            if ($this->beforeAnchorLookup !== null) {
                $rival = $this->beforeAnchorLookup;
                $this->beforeAnchorLookup = null;
                $rival();
            }
            $ids = array();
            foreach (self::issuedRowsOf((int) $m[1]) as $id => $row) {
                $ids[] = $id;
            }

            return $ids === array() ? null : min($ids);
        });
        $db->whenExecute('/UPDATE `ps_ifirma_receipt`\s+SET sync_attempt_at = \'([^\']*)\'\s+WHERE id_ifirma_receipt = (\d+)\s+AND \(sync_attempt_at IS NULL OR sync_attempt_at < \'([^\']*)\' OR sync_attempt_at > \'([^\']*)\'\)/s', function ($sql, $m) {
            $id = (int) $m[2];
            $row = IfirmaTestState::objects('ifirma_receipt')[$id];
            if (!self::attemptIsOlderThan($row, $m[3], $m[4])) {
                return 0;
            }
            ObjectModel::$registry['ifirma_receipt'][$id]['sync_attempt_at'] = $m[1];

            return 1;
        });
        $db->whenExecute('/UPDATE `ps_ifirma_receipt`\s+SET sync_attempt_at = NULL\s+WHERE id_ifirma_receipt = (\d+)\s+AND sync_attempt_at = \'([^\']*)\'/s', function ($sql, $m) {
            $row = IfirmaTestState::objects('ifirma_receipt')[(int) $m[1]];
            if (!isset($row['sync_attempt_at']) || (string) $row['sync_attempt_at'] !== $m[2]) {
                return 0;
            }
            ObjectModel::$registry['ifirma_receipt'][(int) $m[1]]['sync_attempt_at'] = null;

            return 1;
        });
        $db->whenExecuteS('/SELECT id_ifirma_receipt FROM `ps_ifirma_receipt`\s+WHERE id_customer = (\d+)\s+AND id_ifirma_receipt != (\d+)\s+AND ifirma_id != \'\' AND status != \'error\'\s+AND COALESCE\(NULLIF\(issue_date, \'0000-00-00\'\), DATE\(date_add\)\) = \'([^\']*)\'\s+AND \(last_sync_at IS NULL OR last_sync_at < \'([^\']*)\'\)\s+ORDER BY id_ifirma_receipt\s+LIMIT (\d+)/s', function ($sql, $m) {
            if ($this->beforeStaleFinder !== null) {
                call_user_func($this->beforeStaleFinder);
            }
            $found = array();
            foreach (self::issuedRowsOf((int) $m[1]) as $id => $row) {
                $syncIsOld = empty($row['last_sync_at']) || (string) $row['last_sync_at'] < $m[4];
                if ($id !== (int) $m[2] && self::issueDateOf($row) === $m[3] && $syncIsOld && count($found) < (int) $m[5]) {
                    $found[] = array('id_ifirma_receipt' => $id);
                }
            }

            return $found;
        });
    }

    /** @return string|null the stored attempt time of a receipt */
    protected function storedSyncAttempt($idReceipt)
    {
        $row = IfirmaTestState::objects('ifirma_receipt')[(int) $idReceipt];

        return isset($row['sync_attempt_at']) ? (string) $row['sync_attempt_at'] : null;
    }

    private static function attemptIsOlderThan(array $row, $staleBefore, $now)
    {
        return empty($row['sync_attempt_at']) || (string) $row['sync_attempt_at'] < $staleBefore || (string) $row['sync_attempt_at'] > $now;
    }

    /** @return string Y-m-d, as lookupDate() reads it: the issue date unless it is empty or the zero date */
    private static function issueDateOf(array $row)
    {
        if (!empty($row['issue_date']) && $row['issue_date'] !== '0000-00-00') {
            return substr((string) $row['issue_date'], 0, 10);
        }

        return substr((string) $row['date_add'], 0, 10);
    }

    /** @return array[] id => row of the issued records of the customer, in id order */
    private static function issuedRowsOf($idCustomer)
    {
        $rows = array();
        foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
            if ((int) $row['id_customer'] === $idCustomer && (string) $row['ifirma_id'] !== '' && $row['status'] !== 'error') {
                $rows[(int) $id] = $row;
            }
        }
        ksort($rows);

        return $rows;
    }
}
