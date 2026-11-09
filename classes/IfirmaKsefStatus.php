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
 * How a stored or reported KSeF status reads. Pure: no state, no iFirma call.
 *
 * Accepted means the status PRZYJETA or a KSeF number. Rejected means BLAD or ODRZUCONA. Any other non-empty
 * value is in progress, so an unknown value iFirma reports neither causes a resubmission nor a delivery.
 */
class IfirmaKsefStatus
{
    const ACCEPTED = 'accepted';
    const REJECTED = 'rejected';
    const IN_PROGRESS = 'in_progress';
    const NONE = 'none';

    /** Status values */
    const STATUS_ACCEPTED = 'PRZYJETA';
    const STATUS_ERROR = 'BLAD';
    const STATUS_REJECTED = 'ODRZUCONA';

    /**
     * @param mixed $status KsefStatus as stored or reported
     * @param mixed $number KsefNumer as stored or reported
     * @return string one of the class constants ACCEPTED, REJECTED, IN_PROGRESS, NONE
     */
    public static function classify($status, $number = '')
    {
        $status = strtoupper(trim((string) $status));
        if (trim((string) $number) !== '' || $status === self::STATUS_ACCEPTED) {
            return self::ACCEPTED;
        }
        if ($status === self::STATUS_ERROR || $status === self::STATUS_REJECTED) {
            return self::REJECTED;
        }

        return $status !== '' ? self::IN_PROGRESS : self::NONE;
    }

    /**
     * @param mixed $status
     * @param mixed $number
     * @return bool
     */
    public static function isAccepted($status, $number = '')
    {
        return self::classify($status, $number) === self::ACCEPTED;
    }

    /**
     * @param mixed $status
     * @param mixed $number
     * @return bool
     */
    public static function isRejected($status, $number = '')
    {
        return self::classify($status, $number) === self::REJECTED;
    }

    /**
     * @param mixed $status
     * @param mixed $number
     * @return bool
     */
    public static function isInProgress($status, $number = '')
    {
        return self::classify($status, $number) === self::IN_PROGRESS;
    }

    /**
     * @param mixed $status
     * @param mixed $number
     * @return bool true when there is no KSeF state yet
     */
    public static function isEmpty($status, $number = '')
    {
        return self::classify($status, $number) === self::NONE;
    }
}
