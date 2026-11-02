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
 * Pure helpers of the duplicate guard: candidate selection from iFirma list rows,
 * confirmation of a candidate by searching the order reference in its detail JSON,
 * and row summaries. No I/O.
 */
class IfirmaDuplicateMatcher
{
    /**
     * @return array list of `Rodzaj` values the module can produce
     */
    public static function producibleTypes()
    {
        return array('prz_faktura_paragon', 'prz_faktura_kraj');
    }

    /**
     * @param string $rodzaj
     * @return string|null endpoint document type
     */
    public static function rodzajToDocumentType($rodzaj)
    {
        $map = array('prz_faktura_paragon' => 'fakturaparagon', 'prz_faktura_kraj' => 'fakturakraj');

        return isset($map[$rodzaj]) ? $map[$rodzaj] : null;
    }

    /**
     * @param string $type
     * @return string|null
     */
    public static function documentTypeToRodzaj($type)
    {
        $map = array('fakturaparagon' => 'prz_faktura_paragon', 'fakturakraj' => 'prz_faktura_kraj');

        return isset($map[$type]) ? $map[$type] : null;
    }

    /**
     * @param array  $rows
     * @param float  $orderGross
     * @param string $currencyIso
     * @param float  $tolerance
     * @return array candidate rows (original order preserved)
     */
    public static function selectCandidates(array $rows, $orderGross, $currencyIso, $tolerance)
    {
        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['CzySzkic'])) {
                continue;
            }
            $rodzaj = isset($row['Rodzaj']) ? $row['Rodzaj'] : '';
            if (!in_array($rodzaj, self::producibleTypes(), true)) {
                continue;
            }
            $waluta = (isset($row['Waluta']) && $row['Waluta'] !== '') ? $row['Waluta'] : 'PLN';
            if (strcasecmp((string) $waluta, (string) $currencyIso) !== 0) {
                continue;
            }
            if (!isset($row['Brutto']) || !is_numeric($row['Brutto'])) {
                continue;
            }
            if (abs((float) $row['Brutto'] - (float) $orderGross) > (float) $tolerance + 1e-9) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param string $detailRaw raw JSON text of the document detail
     * @param string $reference order reference
     * @return bool
     */
    public static function confirm($detailRaw, $reference)
    {
        $detailRaw = (string) $detailRaw;
        $reference = (string) $reference;
        if ($detailRaw === '' || $reference === '') {
            return false;
        }
        if (strpos($detailRaw, $reference) !== false) {
            return true;
        }
        $variants = array(
            substr((string) json_encode($reference), 1, -1),
            substr((string) json_encode($reference, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 1, -1),
        );
        foreach ($variants as $v) {
            if ($v !== '' && $v !== $reference && strpos($detailRaw, $v) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $row
     * @return array
     */
    public static function summarize(array $row)
    {
        $get = function ($key, $default = '') use ($row) {
            return isset($row[$key]) ? $row[$key] : $default;
        };

        return array(
            'id' => (string) $get('FakturaId'),
            'number' => (string) $get('PelnyNumer'),
            'date' => (string) $get('DataWystawienia'),
            'gross' => (float) $get('Brutto', 0),
            'paid' => (float) $get('Zaplacono', 0),
            'contractor' => (string) $get('KontrahentNazwa'),
            'rodzaj' => (string) $get('Rodzaj'),
            'currency' => (string) $get('Waluta', 'PLN'),
            'sent' => !empty($row['CzyWyslano']),
        );
    }
}
