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
 * The budget of the status syncs that customers' order pages start: all customers together, so page views cannot
 * use up the request limit that issuance, the cron and the downloads share.
 */
class IfirmaCustomerSyncBudget extends IfirmaRequestBudget
{
    const MINUTE_KEY = 'IFIRMA_SYNC_CUSTOMER_MINUTE';
    const DAY_KEY = 'IFIRMA_SYNC_CUSTOMER_DAY';

    const MINUTE_CAP = 10;
    const DAY_CAP = 1000;

    /**
     * @param callable|null $clock
     * @return IfirmaCustomerSyncBudget 10 syncs in any 60 seconds and 1,000 per day for all customers together
     */
    public static function create($clock = null)
    {
        return new self(
            self::MINUTE_KEY,
            self::MINUTE_CAP,
            new IfirmaRateWindow(IfirmaRateDecision::WINDOW_DAY, self::DAY_KEY, self::DAY_BUCKET_FORMAT, self::DAY_CAP),
            $clock
        );
    }
}
