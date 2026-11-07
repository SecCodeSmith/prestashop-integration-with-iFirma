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

// One-off drive of upgrade_module_1_5_0() on a running shop whose ps_module row still says 1.4.x (so PrestaShop
// does not run the script itself). Prints the tab row, the profile-1 access rows, the hook registration, the
// receipt column and index and the configuration key before and after the first run, runs the function a second
// time and checks that nothing changed. Safe to run any number of times: the upgrade is idempotent.
//
//   docker exec -u www-data <shop> php modules/ifirma/tests/smoke/upgrade-150-check.php
if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}
$psRoot = getenv('PS_ROOT') ? rtrim(getenv('PS_ROOT'), '/\\') : dirname(__DIR__, 4);
require $psRoot . '/config/config.inc.php';
if (!isset($GLOBALS['kernel'])) {
    foreach (array('AdminKernel', 'FrontKernel', 'AppKernel') as $c) {
        if (class_exists($c) && !(new ReflectionClass($c))->isAbstract()) {
            $env = (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) ? 'dev' : 'prod';
            $kernel = new $c($env, $env === 'dev');
            $kernel->boot();
            $GLOBALS['kernel'] = $kernel;
            break;
        }
    }
}
Shop::setContext(Shop::CONTEXT_SHOP, 1);
Context::getContext()->employee = new Employee(1);
$db = Db::getInstance();
$t = _DB_PREFIX_;
$pass = 0;
$fail = 0;
function check($cond, $label, $detail = '')
{
    global $pass, $fail;
    echo '[' . ($cond ? ' OK ' : 'FAIL') . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
    $cond ? $pass++ : $fail++;
}

function facts(Module $module)
{
    global $db, $t;
    $tab = $db->getRow('SELECT id_tab, id_parent, active, class_name, module FROM ' . $t . "tab WHERE class_name = 'AdminIfirma'");
    $names = $tab ? $db->executeS('SELECT id_lang, name FROM ' . $t . 'tab_lang WHERE id_tab = ' . (int) $tab['id_tab'] . ' ORDER BY id_lang') : array();
    $access = $db->executeS('SELECT r.slug, a.id_profile FROM ' . $t . 'access a JOIN ' . $t . "authorization_role r ON r.id_authorization_role = a.id_authorization_role WHERE a.id_profile = 1 AND r.slug LIKE 'ROLE_MOD_TAB_ADMINIFIRMA_%' ORDER BY r.slug");
    $hookRows = (int) $db->getValue('SELECT COUNT(*) FROM ' . $t . 'hook_module hm JOIN ' . $t . 'hook h ON h.id_hook = hm.id_hook JOIN ' . $t . "module m ON m.id_module = hm.id_module WHERE m.name = 'ifirma' AND h.name = 'actionAdminControllerSetMedia'");
    $column = (bool) $db->executeS('SHOW COLUMNS FROM ' . $t . "ifirma_receipt LIKE 'last_download_at'");
    $index = (bool) $db->executeS('SHOW INDEX FROM ' . $t . "ifirma_receipt WHERE Key_name = 'idx_date_upd'");
    $cfg = $db->getRow('SELECT COUNT(*) AS n FROM ' . $t . "configuration WHERE name = 'IFIRMA_AUTH_FAILURE'");

    return array(
        'tab' => $tab,
        'tab_names' => $names,
        'ordersParent' => (int) Tab::getIdFromClassName('AdminParentOrders'),
        'access_profile1' => $access,
        'hook_rows' => $hookRows,
        'column_last_download_at' => $column,
        'index_idx_date_upd' => $index,
        'IFIRMA_AUTH_FAILURE_rows' => (int) $cfg['n'],
        'module_version_in_db' => (string) $db->getValue('SELECT version FROM ' . $t . "module WHERE name = 'ifirma'"),
    );
}

echo 'PrestaShop ' . _PS_VERSION_ . "\n";
$module = Module::getInstanceByName('ifirma');
require_once _PS_MODULE_DIR_ . 'ifirma/upgrade/upgrade-1.5.0.php';

$before = facts($module);
echo 'BEFORE ' . json_encode($before) . "\n";
$r1 = upgrade_module_1_5_0($module);
$after1 = facts($module);
echo 'RESULT1 ' . var_export($r1, true) . "\n";
echo 'AFTER1 ' . json_encode($after1) . "\n";
$r2 = upgrade_module_1_5_0($module);
$after2 = facts($module);
echo 'RESULT2 ' . var_export($r2, true) . "\n";
echo 'AFTER2 ' . json_encode($after2) . "\n";

check($r1 === true, 'the first run returns true');
check($r2 === true, 'the second run returns true');
check($before['tab'] && $after1['tab'] && $before['tab']['id_tab'] === $after1['tab']['id_tab'], 'the tab id is unchanged', $after1['tab']['id_tab']);
check($after1['ordersParent'] > 0 && (int) $after1['tab']['id_parent'] === $after1['ordersParent'], 'id_parent is the id of AdminParentOrders', $after1['tab']['id_parent'] . ' / ' . $after1['ordersParent']);
$allNames = true;
foreach ($after1['tab_names'] as $row) {
    // "Dokumenty iFirma" for a Polish language row, "iFirma documents" for every other language (upgrade-1.5.0.php)
    $allNames = $allNames && in_array($row['name'], array('iFirma documents', 'Dokumenty iFirma'), true);
}
check($after1['tab_names'] && $allNames, 'ps_tab_lang names the tab "iFirma documents" (or "Dokumenty iFirma" in Polish) in every language', json_encode($after1['tab_names']));
check($after1['hook_rows'] === 1, 'actionAdminControllerSetMedia is registered once', (string) $after1['hook_rows']);
check($after1['column_last_download_at'], 'ps_ifirma_receipt.last_download_at exists');
check($after1['index_idx_date_upd'], 'ps_ifirma_receipt has the index idx_date_upd');
check($before['access_profile1'] === $after1['access_profile1'] && count($after1['access_profile1']) > 0, 'the profile-1 access rows are unchanged', count($after1['access_profile1']) . ' rows');
check($after1['IFIRMA_AUTH_FAILURE_rows'] >= 1, 'IFIRMA_AUTH_FAILURE is present in ps_configuration', (string) $after1['IFIRMA_AUTH_FAILURE_rows']);
check($after1 === $after2, 'a second run changes nothing');
check($after2['hook_rows'] === 1, 'the hook is still registered once after the second run');

echo "SUMMARY PrestaShop " . _PS_VERSION_ . ": $pass passed, $fail failed\n";
echo ($fail ? 'UPGRADE_150_CHECK_FAILED ' . $fail : 'UPGRADE_150_CHECK_OK') . "\n";
exit($fail ? 1 : 0);
