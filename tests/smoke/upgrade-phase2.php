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

// Upgrade test, phase 2: runs with the current module mounted over a 1.0.0 installation.
// Drives PrestaShop's upgrade runner, then finishes the upgrade as ModuleDataUpdater / ModuleManager
// do (Module::upgradeModuleVersion) and verifies the recorded version, the number of upgrade scripts
// run, the schema, the old receipt and the guard.
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
$fail = 0;
function check($cond, $label, $detail = '')
{
    global $fail;
    echo '[' . ($cond ? ' OK ' : 'FAIL') . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
    if (!$cond) {
        $fail++;
    }
}

$idOrder = isset($argv[1]) ? (int) $argv[1] : (int) $db->getValue("SELECT MAX(id_order) FROM " . _DB_PREFIX_ . "orders");
$m = Module::getInstanceByName('ifirma');
echo "loaded code version: " . $m->version . "\n";
$before = $db->getValue("SELECT version FROM " . _DB_PREFIX_ . "module WHERE name='ifirma'");
echo "db module version before: " . $before . "\n";
check($before === '1.0.0', 'database still records 1.0.0 before the upgrade');

// Mimic the back-office module manager, which sets these before running upgrades.
$m->installed = 1;
$m->database_version = $before;
echo "installed=" . var_export($m->installed, true) . " database_version=" . var_export($m->database_version, true) . "\n";
$needs = Module::needUpgrade($m);
check($needs, 'PrestaShop detects that an upgrade is needed');
if ($needs) {
    Module::initUpgradeModule($m);
    $ok = $m->runUpgradeModule();
    echo "upgrade cache: " . json_encode(isset(Module::$modules_cache['ifirma']['upgrade']) ? Module::$modules_cache['ifirma']['upgrade'] : null) . "\n";
    check(is_array($ok) ? !empty($ok['success']) : (bool) $ok, 'runUpgradeModule reports success', is_array($ok) ? json_encode(array('success' => $ok['success'], 'version_fail' => $ok['version_fail'], 'number_upgraded' => $ok['number_upgraded'])) : var_export($ok, true));
    // The back office and the module manager record the code version after the runner has run the
    // upgrade scripts (ModuleDataUpdater::upgrade / ModuleManager::upgradeMigration), so do the same.
    Module::upgradeModuleVersion('ifirma', $m->version);
    if (is_array($ok)) {
        // One upgrade script per version above 1.0.0 up to the module version must have run.
        $expected = 0;
        foreach (glob(dirname(__DIR__, 2) . '/upgrade/upgrade-*.php') as $script) {
            if (preg_match('/upgrade-(\d+\.\d+\.\d+)\.php$/', $script, $match)
                && version_compare($match[1], '1.0.0', '>') && version_compare($match[1], $m->version, '<=')) {
                $expected++;
            }
        }
        check((int) $ok['number_upgraded'] === $expected, 'one upgrade script ran for every version above 1.0.0 up to ' . $m->version, 'number_upgraded ' . $ok['number_upgraded'] . ', scripts ' . $expected);
    }
}
$after = $db->getValue("SELECT version FROM " . _DB_PREFIX_ . "module WHERE name='ifirma'");
check($after === (string) $m->version, 'database records the module version after the upgrade', 'got ' . $after . ', expected ' . $m->version);

$cols = array();
foreach ($db->executeS("SHOW COLUMNS FROM " . _DB_PREFIX_ . "ifirma_receipt") as $row) {
    $cols[] = $row['Field'];
}
foreach (IfirmaSchema::newReceiptColumnsFor110() as $col => $ddl) {
    check(in_array($col, $cols, true), 'column ' . $col . ' exists');
}
check((bool) $db->getValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . _DB_PREFIX_ . "ifirma_data_request'"), 'table ifirma_data_request exists');
check(Configuration::get('IFIRMA_SEND_CHANNEL') === 'shop_mail', 'new setting IFIRMA_SEND_CHANNEL defaulted', (string) Configuration::get('IFIRMA_SEND_CHANNEL'));
check(strlen((string) Configuration::get('IFIRMA_CRON_TOKEN')) === 32, 'cron token generated');
check(Configuration::get('IFIRMA_USER') === 'test@example.com', 'existing settings preserved');

$receipt = IfirmaReceipt::getByOrder($idOrder);
check($receipt && $receipt->isIssued(), 'old receipt still issued after upgrade');
check($receipt && $receipt->source === 'module', 'old receipt source defaults to module', $receipt ? $receipt->source : '');
check($receipt && $receipt->order_reference === '', 'old receipt has no order reference (pre-1.1.0)');

// download for its customer: the front controller checks login/token/ownership; the PDF fetch itself:
try {
    $pdf = IfirmaReceiptService::build($m)->fetchPdf($receipt);
    check(strncmp($pdf, '%PDF', 4) === 0, 'old receipt PDF still downloads from iFirma');
} catch (Exception $e) {
    check(false, 'old receipt PDF still downloads from iFirma', $e->getMessage());
}
check($receipt && (int) $receipt->id_customer === (int) (new Order($idOrder))->id_customer, 'old receipt still bound to its customer');

// the guard treats the local record as issued: no remote lookup, no creation
$pipeline = IfirmaIssuancePipeline::build($m);
$res = $pipeline->run(new Order($idOrder), array('trigger' => 'manual'));
check($res->outcome === 'already_issued', 'pipeline outcome already_issued', $res->outcome . ' ' . $res->message);
check($res->guard && (int) $res->guard->queries === 0, 'guard made no remote lookup', $res->guard ? (string) $res->guard->queries : 'no guard');
$ch = curl_init((getenv('IFIRMA_MOCK_HOST') ?: 'http://ifirma-mock:8001') . '/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$root = json_decode(curl_exec($ch), true);
curl_close($ch);
check(isset($root['documents_issued']) && (int) $root['documents_issued'] === 1, 'still exactly one document in iFirma', json_encode($root));

echo ($fail ? 'PHASE2_FAILED ' . $fail : 'PHASE2_OK') . "\n";
exit($fail ? 1 : 0);
