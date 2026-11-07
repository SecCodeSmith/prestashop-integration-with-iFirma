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

/**
 * PHPUnit bootstrap: defines the PrestaShop constants, loads the lightweight
 * stubs from tests/Stubs and the module classes (NOT ifirma.php / controllers,
 * see tests/Support/ModuleLoader.php).
 */

define('_PS_VERSION_', '8.1.0');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');
// _PS_MODULE_DIR_ . 'ifirma/...' must resolve to this repository whatever the checkout folder is
// called: PrestaShop installs the module as modules/ifirma, but a clone may be named after the
// GitHub repository. When the folder is not 'ifirma', point _PS_MODULE_DIR_ at a temp directory
// holding an 'ifirma' symlink to the repository.
$ifirmaRepoRoot = dirname(__DIR__);
if (basename($ifirmaRepoRoot) === 'ifirma') {
    $ifirmaModulesDir = dirname($ifirmaRepoRoot);
} else {
    $ifirmaModulesDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ifirma-tests-' . substr(md5($ifirmaRepoRoot), 0, 8);
    if (!is_dir($ifirmaModulesDir) && !@mkdir($ifirmaModulesDir, 0777, true) && !is_dir($ifirmaModulesDir)) {
        fwrite(STDERR, 'tests/bootstrap.php: cannot create ' . $ifirmaModulesDir . PHP_EOL);
        exit(1);
    }
    $ifirmaLink = $ifirmaModulesDir . DIRECTORY_SEPARATOR . 'ifirma';
    if (is_link($ifirmaLink) && realpath($ifirmaLink) !== realpath($ifirmaRepoRoot)) {
        @unlink($ifirmaLink);
    }
    if (!is_link($ifirmaLink) && !is_dir($ifirmaLink) && !@symlink($ifirmaRepoRoot, $ifirmaLink)) {
        fwrite(STDERR, 'tests/bootstrap.php: the repository folder is not named "ifirma" and a symlink could not be created at '
            . $ifirmaLink . '. Clone the repository into a folder named ifirma (git clone <url> ifirma) and run the tests from there.' . PHP_EOL);
        exit(1);
    }
}
define('_PS_MODULE_DIR_', $ifirmaModulesDir . DIRECTORY_SEPARATOR);
define('_PS_ROOT_DIR_', $ifirmaModulesDir);
define('_PS_MAIL_DIR_', _PS_ROOT_DIR_ . '/mails/');

require_once __DIR__ . '/vendor/autoload.php';

foreach (glob(__DIR__ . '/Support/*.php') as $file) {
    require_once $file;
}
require_once __DIR__ . '/Stubs/functions.php';
foreach (glob(__DIR__ . '/Stubs/*.php') as $file) {
    require_once $file;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiClient.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaContractorService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCustomerContractorService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDownloadThrottle.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCustomerView.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaStatusSyncService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDeliveryService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaGuardResult.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDuplicateMatcher.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDuplicateGuard.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequestNotifier.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequestService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPipelineResult.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaIssuancePipeline.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAdminActions.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaOrderGridIndicator.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaIssueFeed.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaBulkRunner.php';
