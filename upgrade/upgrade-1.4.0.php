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
 * Upgrade 1.3.x -> 1.4.0: the Orders list column needs the three order-grid hooks. No schema or configuration
 * change. Idempotent: a hook already registered is left alone.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_4_0($module)
{
    $hooks = array(
        'actionOrderGridDefinitionModifier',
        'actionOrderGridQueryBuilderModifier',
        'actionOrderGridPresenterModifier',
    );
    foreach ($hooks as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            if (!$module->registerHook($hook)) {
                return false;
            }
        }
    }

    return true;
}
