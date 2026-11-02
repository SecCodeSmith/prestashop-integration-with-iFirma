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
// Repo folder is named "ifirma", so _PS_MODULE_DIR_ . 'ifirma/...' resolves to the real files.
define('_PS_MODULE_DIR_', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
define('_PS_ROOT_DIR_', dirname(__DIR__, 2));
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
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCustomerView.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaStatusSyncService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDeliveryService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaGuardResult.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDuplicateMatcher.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDuplicateGuard.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequestService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPipelineResult.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaIssuancePipeline.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAdminActions.php';
