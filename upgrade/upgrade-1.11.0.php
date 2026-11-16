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
 * Upgrade 1.10.x -> 1.11.0: the module registers on actionPDFInvoiceRender, the hook PrestaShop runs before it
 * renders its own customer invoice, and the setting IFIRMA_NATIVE_INVOICE_LINK ("Serve the iFirma document from the
 * built-in invoice link") is switched on, as it is on a new install. Turning it off restores PrestaShop's invoice.
 * No row and no table is touched. Idempotent: an already registered hook is not registered twice and a value the
 * merchant set stays.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_11_0($module)
{
    if (!$module->isRegisteredInHook('actionPDFInvoiceRender')) {
        if (!$module->registerHook('actionPDFInvoiceRender')) {
            return false;
        }
    }

    if (!Configuration::hasKey('IFIRMA_NATIVE_INVOICE_LINK')) {
        Configuration::updateValue('IFIRMA_NATIVE_INVOICE_LINK', 1);
    }

    return true;
}
