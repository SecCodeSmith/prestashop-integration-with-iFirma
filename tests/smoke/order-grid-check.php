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

// Check of the "iFirma" column of the back-office Orders list on the Docker shop: the three grid hooks are
// registered, the real Symfony order grid (factory + presenter) is built, the column sits right before
// "actions", the icon cell is filled for an order with an issued document and empty for one without, the
// record total is unchanged, and building the grid sends no request to the iFirma mock.
ob_start(); // keep output buffered: the grid form builder starts a session and refuses once headers are sent
$psRoot = getenv('PS_ROOT') ? rtrim(getenv('PS_ROOT'), '/\\') : '/var/www/html';
require $psRoot . '/config/config.inc.php';
if (!isset($GLOBALS['kernel'])) {
    foreach (array('AdminKernel', 'FrontKernel', 'AppKernel') as $c) {
        if (class_exists($c) && !(new ReflectionClass($c))->isAbstract()) {
            $kernel = new $c('dev', true);
            $kernel->boot();
            $GLOBALS['kernel'] = $kernel;
            break;
        }
    }
}
Shop::setContext(Shop::CONTEXT_SHOP, 1);
$ctx = Context::getContext();
$ctx->employee = new Employee(1);
if (!$ctx->currency || !Validate::isLoadedObject($ctx->currency)) { $ctx->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT')); }
if (!$ctx->country || !Validate::isLoadedObject($ctx->country)) { $ctx->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT')); }
if (!$ctx->language || !Validate::isLoadedObject($ctx->language)) { $ctx->language = new Language((int) Configuration::get('PS_LANG_DEFAULT')); }
if (!$ctx->link) { $ctx->link = new Link(); }
$module = Module::getInstanceByName('ifirma');
$db = Db::getInstance();
$fail = 0;
$pass = 0;
function check($cond, $label, $detail = '')
{
    global $fail, $pass;
    echo '[' . ($cond ? ' OK ' : 'FAIL') . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
    if ($cond) {
        $pass++;
    } else {
        $fail++;
    }
}
function skip($label, $reason)
{
    echo '[SKIP] ' . $label . ' -- ' . $reason . "\n";
}

echo 'PrestaShop ' . _PS_VERSION_ . "\n";

// 1. the three grid hooks are registered (the 1.4.0 upgrade registers them; run it when they are not)
$hooks = array('actionOrderGridDefinitionModifier', 'actionOrderGridQueryBuilderModifier', 'actionOrderGridPresenterModifier');
$missing = array();
foreach ($hooks as $h) {
    if (!$module->isRegisteredInHook($h)) {
        $missing[] = $h;
    }
}
if ($missing) {
    echo 'hooks missing (' . implode(', ', $missing) . "), running upgrade_module_1_4_0()\n";
    require_once _PS_MODULE_DIR_ . 'ifirma/upgrade/upgrade-1.4.0.php';
    upgrade_module_1_4_0($module);
    $missing = array();
    foreach ($hooks as $h) {
        if (!$module->isRegisteredInHook($h)) {
            $missing[] = $h;
        }
    }
}
check(!$missing, 'the three order-grid hooks are registered', $missing ? 'missing: ' . implode(', ', $missing) : implode(', ', $hooks));

// 2. fixtures from the database
$t = _DB_PREFIX_;
$issued = $db->getRow('SELECT r.id_order, r.ifirma_id, r.ifirma_number, r.status FROM ' . $t . 'ifirma_receipt r
    WHERE r.id_ifirma_receipt = (SELECT MAX(r2.id_ifirma_receipt) FROM ' . $t . 'ifirma_receipt r2 WHERE r2.id_order = r.id_order)
    AND r.ifirma_id <> \'\' AND r.status <> \'error\' ORDER BY r.id_order DESC');
$issuedOrder = $issued ? (int) $issued['id_order'] : 0;
$issuedNumber = $issued ? ($issued['ifirma_number'] !== '' ? $issued['ifirma_number'] : $issued['ifirma_id']) : '';
$issuedStatus = $issued ? $issued['status'] : '';
$emptyOrder = (int) $db->getValue('SELECT o.id_order FROM ' . $t . 'orders o LEFT JOIN ' . $t . 'ifirma_receipt r ON r.id_order = o.id_order WHERE r.id_order IS NULL ORDER BY o.id_order DESC');
echo "fixtures: issued order $issuedOrder ($issuedNumber, $issuedStatus), order without a record $emptyOrder\n";
check($issuedOrder > 0, 'an order with an issued document exists', (string) $issuedOrder);

// 3. mock request counter, via the module's own client (abonent/limit.json counts every call, itself included)
$mockCount = function () {
    try {
        $limit = IfirmaApiClient::fromConfiguration()->getLimit();
    } catch (Exception $e) {
        return null;
    }
    return is_array($limit) ? (int) $limit['used'] : null;
};
$before = $mockCount();

// 4. the real order grid
$container = null;
$how = '';
$factory = null;
$presenter = null;
try {
    $container = $GLOBALS['kernel']->getContainer();
    $factory = $container->get('prestashop.core.grid.factory.order');
    $presenter = $container->get('prestashop.core.grid.presenter.grid_presenter');
    $how = 'kernel container';
} catch (Exception $e) {
    $factory = $presenter = null;
    $err1 = $e->getMessage();
}
if (!$factory) {
    try {
        $container = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::getInstance();
        $factory = $container->get('prestashop.core.grid.factory.order');
        $presenter = $container->get('prestashop.core.grid.presenter.grid_presenter');
        $how = 'SymfonyContainer::getInstance()';
    } catch (Exception $e) {
        $factory = $presenter = null;
        echo 'kernel container: ' . $err1 . "\nSymfonyContainer: " . $e->getMessage() . "\n";
    }
}
check($factory !== null && $presenter !== null, 'order grid factory and presenter obtained', $how);
// The grid builds filter forms whose alert extension reads the session of the current request: give the
// request stack one (array-backed, nothing is stored) the way a back-office request would have it.
if ($factory && $container->has('request_stack')) {
    $request = new \Symfony\Component\HttpFoundation\Request();
    $request->setSession(new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
    $container->get('request_stack')->push($request);
}
if (!$factory || !$presenter) {
    echo "ORDER_GRID_CHECK_ABORTED\n";
    exit(1);
}

$filters = new \PrestaShop\PrestaShop\Core\Search\Filters\OrderFilters(array_merge(
    \PrestaShop\PrestaShop\Core\Search\Filters\OrderFilters::getDefaults(),
    array('limit' => 100000, 'offset' => 0)
));
$grid = $factory->getGrid($filters);
$presented = $presenter->present($grid);

// 4a. column order
$ids = array();
foreach ($grid->getDefinition()->getColumns() as $column) {
    $ids[] = $column->getId();
}
$pos = array_search('ifirma_document', $ids, true);
$posActions = array_search('actions', $ids, true);
check($pos !== false, 'grid definition has the ifirma_document column', implode(',', $ids));
check($pos !== false && $posActions !== false && $pos === $posActions - 1, 'ifirma_document sits right before actions', 'positions ' . var_export($pos, true) . ' / ' . var_export($posActions, true));

// 4b. cells
$records = array();
$presentedRecords = $presented["data"]["records"];
if (is_object($presentedRecords) && method_exists($presentedRecords, "all")) {
    $presentedRecords = $presentedRecords->all();
}
foreach ($presentedRecords as $rec) {
    $records[(int) $rec['id_order']] = $rec;
}
if ($issuedOrder > 0) {
    $cell = isset($records[$issuedOrder]['ifirma_document']) ? (string) $records[$issuedOrder]['ifirma_document'] : null;
    check(isset($records[$issuedOrder]), 'issued order is among the presented records', (string) count($records) . ' records');
    check($cell !== null, 'issued order record carries the ifirma_document key', $cell === null ? 'key absent: the presenter hook did not fill it' : '');
    check(isset($records[$issuedOrder]['ifirma_document_number']) && $records[$issuedOrder]['ifirma_document_number'] === $issuedNumber, 'query builder hook selected the number and status of the issued order', json_encode(array_intersect_key($records[$issuedOrder], array('ifirma_document_number' => 1, 'ifirma_document_status' => 1))));
    $word = $issuedStatus === 'paid' ? '(Paid)' : '(Issued)';
    check($cell !== null && strpos($cell, 'receipt_long') !== false && strpos($cell, 'material-icons') !== false
        && strpos($cell, htmlspecialchars($issuedNumber, ENT_QUOTES, 'UTF-8')) !== false && strpos($cell, $word) !== false,
        'issued order cell has the icon, the number and ' . $word, (string) $cell);
}
if ($emptyOrder > 0) {
    check(isset($records[$emptyOrder]['ifirma_document']) && $records[$emptyOrder]['ifirma_document'] === '', 'order without a record has an empty cell', $emptyOrder . ': ' . var_export(isset($records[$emptyOrder]['ifirma_document']) ? $records[$emptyOrder]['ifirma_document'] : 'key absent', true));
} else {
    skip('empty cell for an order without a record', 'every order already has a ps_ifirma_receipt row (receipts are not deleted by this check)');
}

// 4c. total unchanged
$total = (int) $grid->getData()->getRecordsTotal();
$all = (int) $db->getValue('SELECT COUNT(*) FROM ' . $t . 'orders');
$cmp = 'COUNT(*) FROM ps_orders';
if ($total !== $all) {
    $cmp = 'COUNT(*) of orders in shop 1 (the grid restricts by shop context)';
    $all = (int) $db->getValue('SELECT COUNT(*) FROM ' . $t . 'orders WHERE id_shop = 1');
}
check($total === $all, 'records total equals the order count', "grid $total, $cmp = $all");
check(count($presentedRecords) === $total, 'every order is presented (limit lifted)', count($presentedRecords) . ' of ' . $total);

// 4d. no iFirma HTTP request while building the grid
$after = $mockCount();
if ($before === null || $after === null) {
    skip('no request to the iFirma mock while building the grid', 'the mock keeps no request log; the fallback (IfirmaApiClient::getLimit() before/after, the mock counts every call) needs IFIRMA_KEY_ABONENT, which this shop leaves empty');
} else {
    check($after - $before === 1, 'no request to the iFirma mock while building the grid', "mock counter $before -> $after (the second limit read is the only +1)");
}

echo "SUMMARY PrestaShop " . _PS_VERSION_ . ": $pass passed, $fail failed\n";
echo ($fail ? 'ORDER_GRID_CHECK_FAILED ' . $fail : 'ORDER_GRID_CHECK_OK') . "\n";
exit($fail ? 1 : 0);
