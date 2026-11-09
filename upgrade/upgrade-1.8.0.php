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
 * Upgrade 1.7.x -> 1.8.0: the six new settings get their defaults when missing: IFIRMA_RATE_MAX_PER_DAY (the
 * day cap), the internal IFIRMA_RATE_DAY_WINDOW, IFIRMA_RATE_BACKOFF_UNTIL and IFIRMA_REMOTE_LIMIT,
 * IFIRMA_KSEF_SUBMITTER and IFIRMA_CRON_MAX_ITEMS. No row and no table is touched: nothing becomes queued or awaiting KSeF retroactively,
 * and a document issued before the upgrade is delivered as it was. Idempotent: running it again changes nothing.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_8_0($module)
{
    foreach (array(
        'IFIRMA_RATE_MAX_PER_DAY' => 14000,
        'IFIRMA_RATE_DAY_WINDOW' => '',
        'IFIRMA_RATE_BACKOFF_UNTIL' => '',
        'IFIRMA_REMOTE_LIMIT' => '',
        'IFIRMA_KSEF_SUBMITTER' => 'module',
        'IFIRMA_CRON_MAX_ITEMS' => 10,
    ) as $key => $default) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateValue($key, $default);
        }
    }

    return true;
}
