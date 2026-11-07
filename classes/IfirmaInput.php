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
 * Turns a raw request value into the scalar a controller may use. A request value is an array or an
 * object when the client sends `name[]=...`; such a value must never reach a string or number
 * operation as it is.
 */
class IfirmaInput
{
    /**
     * @param mixed $value a request value
     * @return string '' for an array, an object, null or false; the string form of any other scalar
     */
    public static function scalar($value)
    {
        if (is_array($value) || is_object($value) || $value === null || $value === false) {
            return '';
        }

        return (string) $value;
    }

    /**
     * An amount in zloty: a scalar with a comma or a dot as the decimal separator and at most two
     * decimals. Anything else (arrays, booleans, negative numbers, more decimals, text) is null.
     *
     * @param mixed $value a request value
     * @return float|null
     */
    public static function decimal($value)
    {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
        $text = str_replace(',', '.', trim((string) $value));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            return null;
        }

        return (float) $text;
    }
}
