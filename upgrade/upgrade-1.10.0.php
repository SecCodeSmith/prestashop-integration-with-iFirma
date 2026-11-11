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

/**
 * Upgrade 1.9.x -> 1.10.0: the receipt table gets delivery_attempts and delivery_retry_at, the state of the automatic
 * delivery retry, and download_day and download_count, the per-customer daily download count. No row is written: every
 * delivery that failed before the upgrade keeps a NULL due time, so the cron never retries it and it stays "failed"
 * until an employee sends it again, and every document starts with a count of 0. The upgrade also creates the empty
 * table ifirma_pdf_copy for the stored PDF copies. Idempotent: running it again changes nothing, and a failed ALTER or
 * CREATE is reported so that PrestaShop offers the upgrade again.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_10_0($module)
{
    $retryColumns = IfirmaSchema::ensureReceiptRetryColumns();
    $downloadCountColumns = IfirmaSchema::ensureReceiptDownloadCountColumns();
    $pdfCopyTable = IfirmaSchema::ensurePdfCopyTable();

    return $retryColumns && $downloadCountColumns && $pdfCopyTable;
}
