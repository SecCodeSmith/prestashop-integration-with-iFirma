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
require_once dirname(__FILE__) . '/IfirmaSchema.php';

/**
 * Links shop customers to the contractors of an iFirma CSV export, offline (no API calls).
 */
class IfirmaContractorImport
{
    /** Maximum length of an iFirma contractor identifier. */
    const MAX_IDENTIFIER = IfirmaSchema::CONTRACTOR_IDENTIFIER_LENGTH;

    /** How many unmatched identifiers the report lists. */
    const REPORT_UNMATCHED = 20;

    /** @var array canonical column => normalised header aliases */
    private static $aliases = array(
        'identifier' => array('identyfikator', 'id', 'identifier'),
        'name' => array('nazwa', 'nazwakontrahenta', 'name'),
        'nip' => array('nip', 'vat'),
        'email' => array('email', 'adresemail'),
        'postcode' => array('kodpocztowy', 'kod', 'postalcode'),
    );

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
     * @param string $content raw file content
     * @return array rows (list of assoc rows: identifier, name, nip, email, postcode), errors (list)
     */
    public function parse($content)
    {
        $content = (string) $content;
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = self::convertWindows1250($content);
        }
        $content = str_replace(array("\r\n", "\r"), "\n", $content);
        if (trim($content) === '') {
            return array('rows' => array(), 'errors' => array('empty file'));
        }

        $firstLine = strtok($content, "\n");
        $delimiter = $this->detectDelimiter((string) $firstLine);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $header = null;
        $columns = array();
        $rows = array();
        $errors = array();
        $line = 0;
        while (($cells = fgetcsv($handle, 0, $delimiter, '"')) !== false) {
            $line++;
            if ($header === null) {
                $header = $cells;
                $columns = $this->mapHeader($cells);
                if (!isset($columns['identifier'])) {
                    fclose($handle);

                    return array('rows' => array(), 'errors' => array('missing column: Identyfikator'));
                }
                continue;
            }
            if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                continue;
            }
            $row = array('identifier' => '', 'name' => '', 'nip' => '', 'email' => '', 'postcode' => '');
            foreach ($columns as $field => $index) {
                $row[$field] = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
            }
            if ($row['identifier'] === '') {
                continue;
            }
            if (mb_strlen($row['identifier'], 'UTF-8') > self::MAX_IDENTIFIER) {
                $errors[] = 'line ' . $line . ': identifier longer than ' . self::MAX_IDENTIFIER . ' characters skipped';
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return array('rows' => $rows, 'errors' => $errors);
    }

    /**
     * Windows-1250 to UTF-8. mbstring does not know Windows-1250, so iconv is used when present
     * and a byte table for the Polish letters otherwise.
     *
     * @param string $content
     * @return string
     */
    public static function convertWindows1250($content)
    {
        if (function_exists('iconv')) {
            $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $content);
            if ($converted !== false) {
                return $converted;
            }
        }

        return self::convertWindows1250Fallback($content);
    }

    /**
     * @param string $content
     * @return string Polish letters converted, any other byte above 0x7F replaced with "?"
     */
    public static function convertWindows1250Fallback($content)
    {
        $map = array(
            "\xA5" => "\xC4\x84", "\xB9" => "\xC4\x85", "\xC6" => "\xC4\x86", "\xE6" => "\xC4\x87",
            "\xCA" => "\xC4\x98", "\xEA" => "\xC4\x99", "\xA3" => "\xC5\x81", "\xB3" => "\xC5\x82",
            "\xD1" => "\xC5\x83", "\xF1" => "\xC5\x84", "\xD3" => "\xC3\x93", "\xF3" => "\xC3\xB3",
            "\x8C" => "\xC5\x9A", "\x9C" => "\xC5\x9B", "\x8F" => "\xC5\xB9", "\x9F" => "\xC5\xBA",
            "\xAF" => "\xC5\xBB", "\xBF" => "\xC5\xBC",
        );
        $out = '';
        $length = strlen($content);
        for ($i = 0; $i < $length; $i++) {
            $byte = $content[$i];
            if (ord($byte) < 0x80) {
                $out .= $byte;
            } else {
                $out .= isset($map[$byte]) ? $map[$byte] : '?';
            }
        }

        return $out;
    }

    /**
     * @param string $header
     * @return string
     */
    private function detectDelimiter($header)
    {
        $best = ';';
        $max = substr_count($header, ';');
        foreach (array(',', "\t") as $candidate) {
            $n = substr_count($header, $candidate);
            if ($n > $max) {
                $max = $n;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param array $cells header cells
     * @return array canonical field => column index
     */
    private function mapHeader(array $cells)
    {
        $out = array();
        foreach ($cells as $index => $cell) {
            $key = str_replace(' ', '', IfirmaContractorService::normalizeName((string) $cell));
            foreach (self::$aliases as $field => $names) {
                if (!isset($out[$field]) && in_array($key, $names, true)) {
                    $out[$field] = $index;
                }
            }
        }

        return $out;
    }

    /**
     * Matches rows against customers and stored mappings with three queries.
     *
     * @param array $rows parse() rows
     * @return array linked, by (type => n), unmatched, unmatched_rows, skipped_existing, unchanged, links
     */
    public function match(array $rows)
    {
        $db = Db::getInstance();
        $customers = $db->executeS('SELECT id_customer, email, firstname, lastname FROM `' . _DB_PREFIX_ . 'customer`');
        $addresses = $db->executeS(
            'SELECT id_customer, vat_number, dni, postcode, company, firstname, lastname FROM `' . _DB_PREFIX_ . 'address`
             WHERE id_customer > 0 AND deleted = 0'
        );
        $mappings = $db->executeS(
            'SELECT id_customer, ifirma_identifier, matched_by FROM `' . _DB_PREFIX_ . 'ifirma_contractor_map`'
        );

        $nipIndex = array();
        $emailIndex = array();
        $nameIndex = array();
        $postcodes = array();
        foreach (is_array($addresses) ? $addresses : array() as $a) {
            $id = (int) $a['id_customer'];
            $postcode = preg_replace('/\D/', '', (string) $a['postcode']);
            $postcodes[$id][$postcode] = true;
            foreach (array('vat_number', 'dni') as $field) {
                $nip = preg_replace('/\D/', '', (string) $a[$field]);
                if (strlen($nip) >= 10) {
                    $nipIndex[$nip][$id] = true;
                }
            }
            $names = array(
                IfirmaContractorService::normalizeName(trim((string) $a['company'])),
                IfirmaContractorService::normalizeName(trim($a['firstname'] . ' ' . $a['lastname'])),
            );
            foreach ($names as $name) {
                if ($name !== '' && $postcode !== '') {
                    $nameIndex[$name . '|' . $postcode][$id] = true;
                }
            }
        }
        foreach (is_array($customers) ? $customers : array() as $c) {
            $id = (int) $c['id_customer'];
            $email = mb_strtolower(trim((string) $c['email']), 'UTF-8');
            if ($email !== '') {
                $emailIndex[$email][$id] = true;
            }
            $name = IfirmaContractorService::normalizeName(trim($c['firstname'] . ' ' . $c['lastname']));
            if ($name !== '' && isset($postcodes[$id])) {
                foreach (array_keys($postcodes[$id]) as $postcode) {
                    if ($postcode !== '') {
                        $nameIndex[$name . '|' . $postcode][$id] = true;
                    }
                }
            }
        }

        $existing = array();
        foreach (is_array($mappings) ? $mappings : array() as $m) {
            $existing[(int) $m['id_customer']] = array(
                'identifier' => (string) $m['ifirma_identifier'],
                'type' => (string) $m['matched_by'],
            );
        }

        $plan = array();
        $unmatchedRows = array();
        $unmatched = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            list($type, $ids) = $this->candidates($row, $nipIndex, $emailIndex, $nameIndex);
            if (!$ids) {
                $unmatched++;
                if (count($unmatchedRows) < self::REPORT_UNMATCHED) {
                    $unmatchedRows[] = $row['identifier'];
                }
                continue;
            }
            $rank = self::$rank[$type];
            foreach ($ids as $id) {
                $current = isset($plan[$id]) ? $plan[$id] : null;
                $fromDb = false;
                if ($current === null && isset($existing[$id])) {
                    $current = $existing[$id];
                    $fromDb = true;
                }
                if ($current === null || $rank > $this->rankOf($current['type'])) {
                    $plan[$id] = array('identifier' => $row['identifier'], 'type' => $type, 'name' => $row['name'], 'changed' => true);
                    continue;
                }
                if ($fromDb) {
                    if ($current['identifier'] === $row['identifier'] && $current['type'] === $type) {
                        $plan[$id] = array('identifier' => $current['identifier'], 'type' => $type, 'name' => $row['name'], 'changed' => false);
                    } else {
                        $skipped++;
                    }
                }
            }
        }

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
    private function rankOf($type)
    {
        return isset(self::$rank[$type]) ? self::$rank[$type] : 4;
    }

    /**
     * @param array $row
     * @param array $nipIndex
     * @param array $emailIndex
     * @param array $nameIndex
     * @return array [type, customer ids]
     */
    private function candidates(array $row, array $nipIndex, array $emailIndex, array $nameIndex)
    {
        $nip = preg_replace('/\D/', '', (string) $row['nip']);
        if (strlen($nip) >= 10 && isset($nipIndex[$nip])) {
            return array(IfirmaContractorMap::BY_IMPORT_NIP, array_keys($nipIndex[$nip]));
        }
        $email = mb_strtolower(trim((string) $row['email']), 'UTF-8');
        if ($email !== '' && isset($emailIndex[$email])) {
            return array(IfirmaContractorMap::BY_IMPORT_EMAIL, array_keys($emailIndex[$email]));
        }
        $name = IfirmaContractorService::normalizeName((string) $row['name']);
        $postcode = preg_replace('/\D/', '', (string) $row['postcode']);
        if ($name !== '' && $postcode !== '' && isset($nameIndex[$name . '|' . $postcode])
            && count($nameIndex[$name . '|' . $postcode]) === 1) {
            return array(IfirmaContractorMap::BY_IMPORT_NAME, array_keys($nameIndex[$name . '|' . $postcode]));
        }

        return array('', array());
    }

    /**
     * Writes the new or changed links of a match() report.
     *
     * @param array $report match() result
     * @return int number of mappings written
     */
    public function apply(array $report)
    {
        $written = 0;
        foreach (isset($report['links']) ? $report['links'] : array() as $link) {
            if (empty($link['changed'])) {
                continue;
            }
            IfirmaContractorMap::link($link['id_customer'], $link['identifier'], $link['type'], $link['name']);
            $written++;
        }

        return $written;
    }

    /**
     * parse + match + apply.
     *
     * @param string $content
     * @return array match() report plus rows, errors and written; only rows (0) and errors when refused
     */
    public function import($content)
    {
        $parsed = $this->parse($content);
        if (!$parsed['rows']) {
            return array('errors' => $parsed['errors'] ? $parsed['errors'] : array('no contractor rows found'), 'rows' => 0);
        }
        IfirmaSchema::ensureContractorMapTable();
        $report = $this->match($parsed['rows']);
        $report['written'] = $this->apply($report);
        $report['rows'] = count($parsed['rows']);
        $report['errors'] = $parsed['errors'];

        return $report;
    }
}
