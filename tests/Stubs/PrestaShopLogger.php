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

class PrestaShopLogger
{
    /** @var array[] keys: message, severity, errorCode, objectType, objectId, allowDuplicate, idEmployee */
    public static $logs = [];

    public static function reset()
    {
        self::$logs = [];
    }

    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null)
    {
        self::$logs[] = [
            'message' => $message, 'severity' => $severity, 'errorCode' => $errorCode,
            'objectType' => $objectType, 'objectId' => $objectId,
            'allowDuplicate' => $allowDuplicate, 'idEmployee' => $idEmployee,
        ];

        return true;
    }
}
