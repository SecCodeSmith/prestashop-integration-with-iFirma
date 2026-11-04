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
 * Upgrade 1.1.x -> 1.2.0: the customer page panel needs the displayAdminCustomers hook, and
 * PrestaShop 1.7 support is dropped, so the legacy displayAdminOrder hook is no longer used.
 * No schema or configuration change. Idempotent: each hook is only changed when needed.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_2_0($module)
{
    if ($module->isRegisteredInHook('displayAdminOrder')) {
        $module->unregisterHook('displayAdminOrder');
    }
    if ($module->isRegisteredInHook('displayAdminCustomers')) {
        return true;
    }

    return (bool) $module->registerHook('displayAdminCustomers');
}
