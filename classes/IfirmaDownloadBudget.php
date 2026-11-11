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

require_once dirname(__FILE__) . '/IfirmaRequestBudget.php';

/**
 * A named budget of PDF downloads in any 60 seconds and, optionally, per calendar day. It is independent of the
 * rate guard: downloads by customers or employees count here first, so they cannot use up the request limit that
 * issuance, the status sync and the cron share.
 *
 * Counters are Configuration rows (see IfirmaRollingWindowCounter and IfirmaWindowCounter). A unit is taken before the request is sent and given
 * back when the request was refused locally, so the count equals the downloads that reached iFirma.
 */
class IfirmaDownloadBudget extends IfirmaRequestBudget
{
    const CUSTOMER_MINUTE_KEY = 'IFIRMA_DL_CUSTOMER_MINUTE';
    const CUSTOMER_DAY_KEY = 'IFIRMA_DL_CUSTOMER_DAY';
    const EMPLOYEE_MINUTE_KEY = 'IFIRMA_DL_EMPLOYEE_MINUTE';
    const EMPLOYEE_DAY_KEY = 'IFIRMA_DL_EMPLOYEE_DAY';

    const CUSTOMER_MINUTE_CAP = 20;
    const CUSTOMER_DAY_CAP = 2000;
    const EMPLOYEE_MINUTE_CAP = 30;
    const EMPLOYEE_DAY_CAP = 2000;

    /**
     * @param callable|null $clock
     * @return IfirmaDownloadBudget 20 downloads in any 60 seconds and 2,000 per day for all customers together
     */
    public static function forCustomers($clock = null)
    {
        return new self(
            self::CUSTOMER_MINUTE_KEY,
            self::CUSTOMER_MINUTE_CAP,
            new IfirmaRateWindow(IfirmaRateDecision::WINDOW_DAY, self::CUSTOMER_DAY_KEY, self::DAY_BUCKET_FORMAT, self::CUSTOMER_DAY_CAP),
            $clock
        );
    }

    /**
     * @param callable|null $clock
     * @return IfirmaDownloadBudget 30 in any 60 seconds and 2,000 per day for all employees together
     */
    public static function forEmployees($clock = null)
    {
        return new self(
            self::EMPLOYEE_MINUTE_KEY,
            self::EMPLOYEE_MINUTE_CAP,
            new IfirmaRateWindow(IfirmaRateDecision::WINDOW_DAY, self::EMPLOYEE_DAY_KEY, self::DAY_BUCKET_FORMAT, self::EMPLOYEE_DAY_CAP),
            $clock
        );
    }
}
