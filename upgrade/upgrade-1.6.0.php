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
 * Upgrade 1.5.x -> 1.6.0: the guest code table is created, the auto_mode column of the data request table is
 * added (when the schema class knows it), and the four new settings get their defaults when missing:
 * IFIRMA_DATA_REQUEST_AUTO_ACCEPT, IFIRMA_DOC_TEMPLATE, IFIRMA_ORDER_STAMP_PREFIX and IFIRMA_SHOP_URL.
 * Idempotent: running it again changes nothing.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_6_0($module)
{
    foreach (array(
        'IFIRMA_DATA_REQUEST_AUTO_ACCEPT' => 'none',
        'IFIRMA_DOC_TEMPLATE' => '',
        'IFIRMA_ORDER_STAMP_PREFIX' => 'PS-ORDER',
        'IFIRMA_SHOP_URL' => '',
    ) as $key => $default) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateValue($key, $default);
        }
    }

    if (!IfirmaSchema::ensureGuestCodeTable()) {
        return false;
    }
    if (method_exists('IfirmaSchema', 'ensureDataRequestAutoModeColumn') && !IfirmaSchema::ensureDataRequestAutoModeColumn()) {
        return false;
    }

    return true;
}
