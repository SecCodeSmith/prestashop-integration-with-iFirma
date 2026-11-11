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

require_once dirname(__FILE__) . '/../classes/IfirmaSchema.php';
require_once dirname(__FILE__) . '/../classes/IfirmaPdfCopyRetention.php';

/**
 * Upgrade 1.9.x -> 1.10.0: the receipt table gets delivery_attempts and delivery_retry_at, the state of the automatic
 * delivery retry, and download_day and download_count, the per-customer daily download count. No receipt row is
 * written: every delivery that failed before the upgrade keeps a NULL due time, so the cron never retries it and it
 * stays "failed" until an employee sends it again, and every document starts with a count of 0. The upgrade also
 * creates the empty table ifirma_pdf_copy for the stored PDF copies, and sets "Keep PDF copies (days)"
 * (IFIRMA_PDF_COPY_DAYS) to its default of 30 when it is not set; a value the merchant set, 0 included, is kept.
 * Idempotent: running it again changes nothing, and a failed ALTER or CREATE is reported so that PrestaShop offers
 * the upgrade again.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_10_0($module)
{
    $retryColumns = IfirmaSchema::ensureReceiptRetryColumns();
    $downloadCountColumns = IfirmaSchema::ensureReceiptDownloadCountColumns();
    $pdfCopyTable = IfirmaSchema::ensurePdfCopyTable();
    // get() falls back across shop contexts, hasKey() with no ids sees only the global entry
    if (Configuration::get(IfirmaPdfCopyRetention::SETTING) === false) {
        $defaults = IfirmaSchema::configurationDefaults();
        Configuration::updateValue(IfirmaPdfCopyRetention::SETTING, $defaults[IfirmaPdfCopyRetention::SETTING]);
    }

    return $retryColumns && $downloadCountColumns && $pdfCopyTable;
}
