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
 * Makes the three statements of IfirmaDownloadDayCap run against the in-memory receipt rows, the way MySQL would:
 * the atomic increment, the give-back and the customer's total of the day.
 */
trait IfirmaDownloadCountDb
{
    protected function scriptDownloadCountDb()
    {
        $db = Db::getInstance();
        $db->whenExecute('/UPDATE `ps_ifirma_receipt`\s+SET download_count = IF\(download_day = \'([\d-]+)\'.*WHERE id_ifirma_receipt = (\d+)/s', function ($sql, $m) {
            $row = IfirmaTestState::objects('ifirma_receipt')[(int) $m[2]];
            $sameDay = isset($row['download_day']) && (string) $row['download_day'] === $m[1];
            ObjectModel::$registry['ifirma_receipt'][(int) $m[2]]['download_count'] = $sameDay ? (int) $row['download_count'] + 1 : 1;
            ObjectModel::$registry['ifirma_receipt'][(int) $m[2]]['download_day'] = $m[1];

            return 1;
        });
        $db->whenExecute('/UPDATE `ps_ifirma_receipt`\s+SET download_count = download_count - 1\s+WHERE id_ifirma_receipt = (\d+)\s+AND download_day = \'([\d-]+)\'\s+AND download_count > 0/s', function ($sql, $m) {
            $row = IfirmaTestState::objects('ifirma_receipt')[(int) $m[1]];
            if ((string) $row['download_day'] !== $m[2] || (int) $row['download_count'] <= 0) {
                return 0;
            }
            ObjectModel::$registry['ifirma_receipt'][(int) $m[1]]['download_count'] = (int) $row['download_count'] - 1;

            return 1;
        });
        $db->whenGetValue('/SELECT COALESCE\(SUM\(download_count\), 0\) FROM `ps_ifirma_receipt`\s+WHERE id_customer = (\d+)\s+AND download_day = \'([\d-]+)\'/s', function ($sql, $m) {
            $total = 0;
            foreach (IfirmaTestState::objects('ifirma_receipt') as $row) {
                if ((int) $row['id_customer'] === (int) $m[1] && isset($row['download_day']) && (string) $row['download_day'] === $m[2]) {
                    $total += (int) $row['download_count'];
                }
            }

            return $total;
        });
    }

    /** @return int the stored download count of a receipt */
    protected function storedDownloadCount($idReceipt)
    {
        $row = IfirmaTestState::objects('ifirma_receipt')[(int) $idReceipt];

        return isset($row['download_count']) ? (int) $row['download_count'] : 0;
    }
}
