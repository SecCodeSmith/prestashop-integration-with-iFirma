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

// Upgrade test, phase 1: runs with the 1.0.0 module mounted. Reinstalls the module as 1.0.0
// and issues one document against the mock so phase 2 can upgrade and verify it survives.
$psRoot = getenv('PS_ROOT') ? rtrim(getenv('PS_ROOT'), '/\\') : dirname(__DIR__, 4);
require $psRoot . '/config/config.inc.php';
// Boot the Symfony kernel: PrestaShop 8/9 resolve hooks and services through the
// container, which a plain CLI bootstrap of config.inc.php does not create. PrestaShop 9
// made AppKernel abstract and split it into AdminKernel / FrontKernel.
if (!isset($GLOBALS['kernel'])) {
    $smokeKernelClass = null;
    foreach (array('AdminKernel', 'FrontKernel', 'AppKernel') as $smokeCandidate) {
        if (class_exists($smokeCandidate) && !(new ReflectionClass($smokeCandidate))->isAbstract()) {
            $smokeKernelClass = $smokeCandidate;
            break;
        }
    }
    if ($smokeKernelClass !== null) {
        $smokeEnv = (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) ? 'dev' : 'prod';
        $kernel = new $smokeKernelClass($smokeEnv, $smokeEnv === 'dev');
        $kernel->boot();
        $GLOBALS['kernel'] = $kernel;
    }
}
Shop::setContext(Shop::CONTEXT_SHOP, 1);
Context::getContext()->employee = new Employee(1);
$db = Db::getInstance();

$m = Module::getInstanceByName('ifirma');
echo "loaded code version: " . $m->version . "\n";
if ($m->version !== '1.0.0') {
    echo "ABORT: expected the 1.0.0 worktree to be mounted\n";
    exit(2);
}
if (Module::isInstalled('ifirma')) {
    echo "uninstall current: " . var_export($m->uninstall(), true) . "\n";
}
echo "install 1.0.0: " . var_export($m->install(), true) . "\n";
echo "db module version: " . $db->getValue("SELECT version FROM " . _DB_PREFIX_ . "module WHERE name='ifirma'") . "\n";
echo "receipt columns: " . (int) $db->getValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . _DB_PREFIX_ . "ifirma_receipt'") . "\n";
echo "data_request table exists: " . var_export((bool) $db->getValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . _DB_PREFIX_ . "ifirma_data_request'"), true) . "\n";

foreach (array(
    'IFIRMA_BASE_URL' => getenv('IFIRMA_MOCK_URL') ?: 'http://ifirma-mock:8001/iapi/',
    'IFIRMA_USER' => 'test@example.com',
    'IFIRMA_KEY_FAKTURA' => str_repeat('1', 64),
    'IFIRMA_DOC_TYPE' => 'fakturakraj',
) as $k => $v) {
    Configuration::updateValue($k, $v);
}

$ch = curl_init((getenv('IFIRMA_MOCK_HOST') ?: 'http://ifirma-mock:8001') . '/__mock/reset');
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
curl_exec($ch);
curl_close($ch);

$idOrder = (int) $db->getValue("SELECT MAX(id_order) FROM " . _DB_PREFIX_ . "orders");
$order = new Order($idOrder);
$service = IfirmaReceiptService::build($m);
$receipt = $service->createReceipt($order);
echo "issued with 1.0.0: id_order=" . $idOrder . " id_customer=" . $order->id_customer . " ifirma_id=" . $receipt->ifirma_id . " number=" . $receipt->ifirma_number . " status=" . $receipt->status . "\n";
echo "PHASE1_OK order=" . $idOrder . "\n";
