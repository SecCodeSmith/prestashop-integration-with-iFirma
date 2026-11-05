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

require_once dirname(__FILE__) . '/IfirmaContractorMap.php';
require_once dirname(__FILE__) . '/IfirmaContractorService.php';

/**
 * Pure matching of the rows of an iFirma contractor export against the lookups of the shop
 * (IfirmaCustomerIndex): finds the customers each row belongs to, ranks the match and plans which
 * links are new, replaced, confirmed or kept because the stored link is at least as strong.
 * No Db, Configuration or Context.
 */
class IfirmaContractorMatcher
{
    /** How many unmatched identifiers the report lists. */
    const REPORT_UNMATCHED = 20;

    /** @var array match type => strength (higher wins) */
    private static $rank = array(
        'module' => 0,
        'name' => 1,
        'import-name' => 1,
        'email' => 2,
        'import-email' => 2,
        'nip' => 3,
        'import-nip' => 3,
        'manual' => 4,
    );

    /**
     * @param array               $rows  IfirmaContractorImport::parse() rows
     * @param IfirmaCustomerIndex $index the shop's lookups and stored links
     * @return array linked, by (type => n), unmatched, unmatched_rows, skipped_existing, unchanged, links
     */
    public static function match(array $rows, IfirmaCustomerIndex $index)
    {
        $plan = array();
        $unmatchedRows = array();
        $unmatched = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            list($type, $ids) = self::candidates($row, $index);
            if (!$ids) {
                $unmatched++;
                if (count($unmatchedRows) < self::REPORT_UNMATCHED) {
                    $unmatchedRows[] = $row['identifier'];
                }
                continue;
            }
            foreach ($ids as $id) {
                $skipped += self::planLink($plan, $id, $row, $type, $index->existing);
            }
        }

        return self::report($plan, $unmatched, $unmatchedRows, $skipped);
    }

    /**
     * Plans the link of one row to one customer. The first row of the strongest type wins; a stored
     * link is replaced only by a stronger match, confirmed when the row repeats it and otherwise kept.
     *
     * @param array  $plan     id_customer => identifier, type, name, changed; extended here
     * @param int    $id       customer
     * @param array  $row      parse() row
     * @param string $type     match type of the row
     * @param array  $existing stored links by customer
     * @return int 1 when a stored link is kept against the row, else 0
     */
    private static function planLink(array &$plan, $id, array $row, $type, array $existing)
    {
        $current = isset($plan[$id]) ? $plan[$id] : null;
        $fromDb = false;
        if ($current === null && isset($existing[$id])) {
            $current = $existing[$id];
            $fromDb = true;
        }
        if ($current === null || self::$rank[$type] > self::rankOf($current['type'])) {
            $plan[$id] = array('identifier' => $row['identifier'], 'type' => $type, 'name' => $row['name'], 'changed' => true);

            return 0;
        }
        if (!$fromDb) {
            return 0;
        }
        if ($current['identifier'] === $row['identifier'] && $current['type'] === $type) {
            $plan[$id] = array('identifier' => $current['identifier'], 'type' => $type, 'name' => $row['name'], 'changed' => false);

            return 0;
        }

        return 1;
    }

    /**
     * @param array $plan          id_customer => identifier, type, name, changed
     * @param int   $unmatched     rows without a customer
     * @param array $unmatchedRows identifiers of the first of them
     * @param int   $skipped       stored links kept against a row
     * @return array linked, by (type => n), unmatched, unmatched_rows, skipped_existing, unchanged, links
     */
    private static function report(array $plan, $unmatched, array $unmatchedRows, $skipped)
    {
        $by = array();
        $links = array();
        $unchanged = 0;
        foreach ($plan as $id => $link) {
            $by[$link['type']] = isset($by[$link['type']]) ? $by[$link['type']] + 1 : 1;
            if (empty($link['changed'])) {
                $unchanged++;
            }
            $links[] = array(
                'id_customer' => (int) $id,
                'identifier' => $link['identifier'],
                'type' => $link['type'],
                'name' => $link['name'],
                'changed' => !empty($link['changed']),
            );
        }
        ksort($by);

        return array(
            'linked' => count($plan),
            'by' => $by,
            'unmatched' => $unmatched,
            'unmatched_rows' => $unmatchedRows,
            'skipped_existing' => $skipped,
            'unchanged' => $unchanged,
            'links' => $links,
        );
    }

    /**
     * Stored types that are not known (e.g. written by a later version) are never replaced.
     *
     * @param string $type
     * @return int
     */
    private static function rankOf($type)
    {
        return isset(self::$rank[$type]) ? self::$rank[$type] : 4;
    }

    /**
     * The customers a row belongs to: by NIP when the shop knows it, else by e-mail, else by name
     * and postcode when exactly one customer has them.
     *
     * @param array               $row
     * @param IfirmaCustomerIndex $index
     * @return array [type, customer ids]
     */
    private static function candidates(array $row, IfirmaCustomerIndex $index)
    {
        $nip = preg_replace('/\D/', '', (string) $row['nip']);
        if (strlen($nip) >= 10 && isset($index->nip[$nip])) {
            return array(IfirmaContractorMap::BY_IMPORT_NIP, array_keys($index->nip[$nip]));
        }
        $email = mb_strtolower(trim((string) $row['email']), 'UTF-8');
        if ($email !== '' && isset($index->email[$email])) {
            return array(IfirmaContractorMap::BY_IMPORT_EMAIL, array_keys($index->email[$email]));
        }
        $name = IfirmaContractorService::normalizeName((string) $row['name']);
        $postcode = preg_replace('/\D/', '', (string) $row['postcode']);
        if ($name !== '' && $postcode !== '' && isset($index->name[$name . '|' . $postcode])
            && count($index->name[$name . '|' . $postcode]) === 1) {
            return array(IfirmaContractorMap::BY_IMPORT_NAME, array_keys($index->name[$name . '|' . $postcode]));
        }

        return array('', array());
    }
}
