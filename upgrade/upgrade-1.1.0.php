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
 * Upgrade 1.0.x -> 1.1.0. Idempotent: every step checks before changing anything.
 *
 * @param Module $module
 * @return bool
 */
function upgrade_module_1_1_0($module)
{
    $db = Db::getInstance();
    $table = _DB_PREFIX_ . 'ifirma_receipt';

    foreach (IfirmaSchema::newReceiptColumnsFor110() as $column => $ddl) {
        if (IfirmaSchema::columnExists($table, $column)) {
            continue;
        }
        if (!$db->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $ddl)) {
            return false;
        }
    }

    if (!$db->execute(IfirmaSchema::dataRequestCreateSql())) {
        return false;
    }
    if (!$db->execute(IfirmaSchema::contractorMapCreateSql())) {
        return false;
    }
    IfirmaSchema::widenContractorMapIdentifier();
    IfirmaSchema::ensureReceiptKsefColumns();

    foreach (IfirmaSchema::configurationDefaults() as $key => $value) {
        if (Configuration::get($key) !== false) {
            continue;
        }
        if ($value === null) {
            $value = IfirmaSchema::generateCronToken();
        }
        Configuration::updateValue($key, $value);
    }

    return true;
}
