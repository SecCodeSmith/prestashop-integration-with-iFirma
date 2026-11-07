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
 * Upgrade 1.4.x -> 1.5.0: the iFirma tab moves under the Orders menu as "iFirma documents". The existing tab row
 * is updated in place, so its id and its permission rows survive. The actionAdminControllerSetMedia hook is
 * registered, the receipt table gets the last_download_at column and the idx_date_upd index, and the
 * IFIRMA_AUTH_FAILURE default is written when missing. Idempotent: running it again changes nothing.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_5_0($module)
{
    $idTab = (int) Tab::getIdFromClassName('AdminIfirma');
    if (!$idTab) {
        if (!$module->installVisibleTab()) {
            return false;
        }
    } else {
        // the same lookup as Ifirma::ordersTabParentId(): AdminParentOrders, else the parent of AdminOrders
        $idParent = (int) Tab::getIdFromClassName('AdminParentOrders');
        if (!$idParent) {
            $idOrders = (int) Tab::getIdFromClassName('AdminOrders');
            if ($idOrders) {
                $ordersTab = new Tab($idOrders);
                $idParent = (int) $ordersTab->id_parent;
            }
        }

        $tab = new Tab($idTab);
        if ($idParent) {
            $tab->id_parent = $idParent;
        }
        $tab->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'iFirma documents';
        }
        if (!$tab->update()) {
            return false;
        }
    }

    if (!$module->isRegisteredInHook('actionAdminControllerSetMedia')) {
        if (!$module->registerHook('actionAdminControllerSetMedia')) {
            return false;
        }
    }

    if (!Configuration::hasKey('IFIRMA_AUTH_FAILURE')) {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', '');
    }

    return IfirmaSchema::ensureReceiptDownloadColumn() && IfirmaSchema::ensureReceiptDateIndex();
}
