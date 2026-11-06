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

// Upgrade test, cleanup: runs between phase 1 and phase 2 with the current tree mounted.
// Makes the database look like a shop that only ever ran 1.0.0, because the 1.0.0 uninstaller
// does not know the later tables and settings.
$psRoot = getenv('PS_ROOT') ? rtrim(getenv('PS_ROOT'), '/\\') : dirname(__DIR__, 4);
require $psRoot . '/config/config.inc.php';
Shop::setContext(Shop::CONTEXT_SHOP, 1);
$db = Db::getInstance();

$dropped = 0;
$deleted = 0;
try {
    // Tables that 1.0.0 does not know. Dropped only if they exist, so re-running the cleanup is safe.
    $tables = array('ifirma_data_request', 'ifirma_contractor_map');
    foreach ($tables as $table) {
        $fullName = _DB_PREFIX_ . $table;
        $exists = (bool) $db->getValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $fullName . "'");
        if ($exists) {
            if (!$db->execute('DROP TABLE IF EXISTS `' . $fullName . '`')) {
                throw new Exception('DROP TABLE ' . $fullName . ' failed: ' . $db->getMsgError());
            }
            $dropped++;
            echo "dropped table " . $fullName . "\n";
        } else {
            echo "absent table " . $fullName . "\n";
        }
    }

    // the keys ifirma.php defines at 6565eab (1.0.0)
    $keep = array(
        'IFIRMA_AUTO_CREATE_STATE',
        'IFIRMA_BASE_URL',
        'IFIRMA_DEFAULT_UNIT',
        'IFIRMA_DOC_TYPE',
        'IFIRMA_KEY_ABONENT',
        'IFIRMA_KEY_FAKTURA',
        'IFIRMA_KEY_RACHUNEK',
        'IFIRMA_MARK_PAID_ON_CREATE',
        'IFIRMA_PAYMENT_METHOD',
        'IFIRMA_PAYMENT_TYPE_TOKEN',
        'IFIRMA_USER',
    );
    $rows = $db->executeS("SELECT DISTINCT name FROM " . _DB_PREFIX_ . "configuration WHERE name LIKE 'IFIRMA\\_%'");
    foreach ($rows as $row) {
        $name = $row['name'];
        if (in_array($name, $keep, true)) {
            echo "kept setting " . $name . "\n";
            continue;
        }
        Configuration::deleteByName($name);
        $deleted++;
        echo "deleted setting " . $name . "\n";
    }
} catch (Exception $e) {
    echo "CLEANUP_FAILED " . $e->getMessage() . "\n";
    exit(1);
}

echo "CLEANUP_OK tables=" . $dropped . " settings=" . $deleted . "\n";
exit(0);
