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
 * Pure helpers of the duplicate guard: buyer filter and candidate selection from iFirma
 * list rows, confirmation of a candidate by searching the order reference in its detail
 * JSON, verification of a candidate's sale date and positions against the order, and row
 * summaries. No I/O.
 *
 * Rows kept by filterByBuyer() carry `_matched_by` (identifier|nip|name); the guard adds
 * `_sale_date` after verifyDetail(). summarize() exposes both as sale_date / matched_by.
 */
class IfirmaDuplicateMatcher
{
    /** Quantities closer than this are equal. */
    const QUANTITY_EPSILON = 0.001;

    /**
     * Text normalisation used for every name comparison: string cast, trim, whitespace runs
     * collapsed to one space, lower case (UTF-8). Diacritics are kept.
     *
     * @param mixed $value
     * @return string
     */
    public static function normalizeText($value)
    {
        $value = trim((string) $value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Keep the rows that belong to the buyer: same contractor identifier, or same NIP (digits,
     * both non-empty), or a KontrahentNazwa equal to one of the buyer names after normalisation.
     * Each kept row gets `_matched_by` = identifier | nip | name.
     *
     * @param array $rows  list rows
     * @param array $buyer identifier (string), nip (string), names (string[])
     * @return array
     */
    public static function filterByBuyer(array $rows, array $buyer)
    {
        $identifier = isset($buyer['identifier']) ? trim((string) $buyer['identifier']) : '';
        $nip = isset($buyer['nip']) ? preg_replace('/\D+/', '', (string) $buyer['nip']) : '';
        $names = array();
        $rawNames = (isset($buyer['names']) && is_array($buyer['names'])) ? $buyer['names'] : array();
        foreach ($rawNames as $name) {
            $name = self::normalizeText($name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $reason = self::buyerMatch($row, $identifier, $nip, $names);
            if ($reason !== null) {
                $row['_matched_by'] = $reason;
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array    $row
     * @param string   $identifier
     * @param string   $nip digits
     * @param string[] $names normalised
     * @return string|null identifier | nip | name, null when the row is another buyer's
     */
    private static function buyerMatch(array $row, $identifier, $nip, array $names)
    {
        $rowId = isset($row['IdentyfikatorKontrahenta']) ? trim((string) $row['IdentyfikatorKontrahenta']) : '';
        if ($identifier !== '' && $rowId !== '' && strcasecmp($rowId, $identifier) === 0) {
            return 'identifier';
        }
        $rowNip = isset($row['NIPKontrahenta']) ? preg_replace('/\D+/', '', (string) $row['NIPKontrahenta']) : '';
        if ($nip !== '' && $rowNip !== '' && $rowNip === $nip) {
            return 'nip';
        }
        $rowName = isset($row['KontrahentNazwa']) ? self::normalizeText($row['KontrahentNazwa']) : '';
        if ($rowName !== '' && in_array($rowName, $names, true)) {
            return 'name';
        }

        return null;
    }

    /**
     * @param array  $detail   decoded detail node
     * @param string $saleDate order sale date (Y-m-d)
     * @return bool|null null when the detail carries no DataSprzedazy
     */
    public static function saleDateMatches(array $detail, $saleDate)
    {
        $value = isset($detail['DataSprzedazy']) ? trim((string) $detail['DataSprzedazy']) : '';
        $saleDate = trim((string) $saleDate);
        if ($value === '' || $saleDate === '') {
            return null;
        }
        $format = isset($detail['FormatDatySprzedazy']) ? strtoupper(trim((string) $detail['FormatDatySprzedazy'])) : '';
        $length = ($format === 'MSC') ? 7 : 10;

        return substr($value, 0, $length) === substr($saleDate, 0, $length);
    }

    /**
     * True when every order line is matched by a distinct position with the same normalised
     * NazwaPelna and the same Ilosc. Positions left over (shipping, fees) are ignored; an order
     * without lines is covered by any position list.
     *
     * @param array $positions detail Pozycje
     * @param array $lines     order lines: name, quantity
     * @return bool
     */
    public static function positionsCover(array $positions, array $lines)
    {
        if (empty($lines)) {
            return true;
        }
        $pool = array();
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }
            $pool[] = array(
                'name' => self::normalizeText(isset($position['NazwaPelna']) ? $position['NazwaPelna'] : ''),
                'quantity' => isset($position['Ilosc']) ? (float) $position['Ilosc'] : 0.0,
                'used' => false,
            );
        }
        foreach ($lines as $line) {
            $name = self::normalizeText(isset($line['name']) ? $line['name'] : '');
            $quantity = isset($line['quantity']) ? (float) $line['quantity'] : 0.0;
            $found = false;
            foreach ($pool as $i => $candidate) {
                if (!$candidate['used'] && $candidate['name'] === $name
                    && abs($candidate['quantity'] - $quantity) < self::QUANTITY_EPSILON) {
                    $pool[$i]['used'] = true;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verify a candidate's detail against the order: matched true when sale date and positions
     * both match, false when either is known to differ, null when the detail lacks DataSprzedazy
     * or Pozycje and nothing contradicts the match.
     *
     * @param array  $detail   decoded detail node
     * @param string $saleDate order sale date (Y-m-d)
     * @param array  $lines    order lines: name, quantity
     * @return array matched (bool|null), sale_date (string, '' when unknown)
     */
    public static function verifyDetail(array $detail, $saleDate, array $lines)
    {
        $recorded = isset($detail['DataSprzedazy']) ? trim((string) $detail['DataSprzedazy']) : '';
        $date = self::saleDateMatches($detail, $saleDate);
        $positions = (isset($detail['Pozycje']) && is_array($detail['Pozycje']))
            ? self::positionsCover($detail['Pozycje'], $lines)
            : null;

        if ($date === false || $positions === false) {
            $matched = false;
        } elseif ($date === null || $positions === null) {
            $matched = null;
        } else {
            $matched = true;
        }

        return array('matched' => $matched, 'sale_date' => $recorded);
    }
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
     * @param string          $detailRaw raw JSON text of the document detail
     * @param string|string[] $reference one order reference stamp, or a list of accepted stamps
     * @return bool true when any stamp is found
     */
    public static function confirm($detailRaw, $reference)
    {
        $detailRaw = (string) $detailRaw;
        if ($detailRaw === '') {
            return false;
        }
        foreach ((array) $reference as $stamp) {
            if (self::containsStamp($detailRaw, (string) $stamp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $detailRaw
     * @param string $reference
     * @return bool
     */
    private static function containsStamp($detailRaw, $reference)
    {
        if ($reference === '') {
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
            'sale_date' => (string) $get('_sale_date'),
            'matched_by' => (string) $get('_matched_by'),
        );
    }
}
