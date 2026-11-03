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
 * Makes the finder queries of IfirmaContractorMap read the in-memory ObjectModel rows.
 */
trait IfirmaContractorMapDb
{
    protected function scriptContractorMapDb()
    {
        $db = Db::getInstance();
        $db->whenGetValue('/SELECT id_ifirma_contractor_map FROM `ps_ifirma_contractor_map`\s+WHERE id_customer = (\d+)/', function ($sql, $m) {
            foreach (IfirmaTestState::objects('ifirma_contractor_map') as $id => $row) {
                if ((int) $row['id_customer'] === (int) $m[1]) {
                    return $id;
                }
            }

            return false;
        });
        $db->whenGetValue('/SELECT id_ifirma_contractor_map FROM `ps_ifirma_contractor_map`\s+WHERE ifirma_identifier = "([^"]*)"/', function ($sql, $m) {
            foreach (IfirmaTestState::objects('ifirma_contractor_map') as $id => $row) {
                if ($row['ifirma_identifier'] === $m[1]) {
                    return $id;
                }
            }

            return false;
        });
        $db->whenExecuteS('/FROM `ps_ifirma_contractor_map`\s+GROUP BY matched_by/', function () {
            $counts = array();
            foreach (IfirmaTestState::objects('ifirma_contractor_map') as $row) {
                $counts[$row['matched_by']] = isset($counts[$row['matched_by']]) ? $counts[$row['matched_by']] + 1 : 1;
            }
            ksort($counts);
            $rows = array();
            foreach ($counts as $type => $n) {
                $rows[] = array('matched_by' => $type, 'total' => $n);
            }

            return $rows;
        });
        $db->whenExecuteS('/SELECT id_customer, ifirma_identifier, matched_by FROM `ps_ifirma_contractor_map`/', function () {
            $rows = array();
            foreach (IfirmaTestState::objects('ifirma_contractor_map') as $row) {
                $rows[] = array(
                    'id_customer' => $row['id_customer'],
                    'ifirma_identifier' => $row['ifirma_identifier'],
                    'matched_by' => $row['matched_by'],
                );
            }

            return $rows;
        });
    }

    /**
     * @return array in-memory contractor map rows (id => row)
     */
    protected function mapRows()
    {
        return IfirmaTestState::objects('ifirma_contractor_map');
    }
}
