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
 * The document type and the recipient signature type of an iFirma document: the values the module
 * offers, and what it makes of a stored or posted value (a value it does not offer reads as the default).
 * Static and independent of the shop, so the module file, the settings form and the payload builder
 * share one definition.
 */
class IfirmaDocumentSettings
{
    /**
     * Document types the merchant can choose. `fakturaparagon` (invoice to a fiscal receipt) is
     * disabled for now: the only supported type is the domestic VAT invoice, and a stored
     * `fakturaparagon` setting is treated as `fakturakraj` until the option returns.
     *
     * @return string[] type => label key
     */
    public static function enabledDocumentTypes()
    {
        return array('fakturakraj' => 'Domestic VAT invoice (fakturakraj)');
    }

    /**
     * @param mixed $value configured value
     * @return string an enabled document type, `fakturakraj` for anything else
     */
    public static function normalizeDocumentType($value)
    {
        $type = trim((string) $value);

        return array_key_exists($type, self::enabledDocumentTypes()) ? $type : 'fakturakraj';
    }

    /**
     * The only RodzajPodpisuOdbiorcy values the document endpoints accept (fakturakraj and
     * fakturaparagon documentation): anything else makes iFirma answer Kod 200 "nie można utworzyć
     * obiektu na podstawie zawartości żądania" without naming the field.
     *
     * @return string[] code => documented meaning (Polish)
     */
    public static function signatureTypes()
    {
        return array(
            'BPO' => 'bez podpisu odbiorcy',
            'BWO' => 'bez podpisu odbiorcy i wystawcy',
            'OUP' => 'osoba upoważniona do otrzymania faktury VAT',
            'UPO' => 'upoważnienie',
        );
    }

    /**
     * @param mixed $value configured value
     * @return string a documented code; BPO (no recipient signature, the online-sales default) otherwise
     */
    public static function normalizeSignatureType($value)
    {
        $code = strtoupper(trim((string) $value));

        return array_key_exists($code, self::signatureTypes()) ? $code : 'BPO';
    }
}
