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
// every order may already carry a record (the 9.1 shop does): then copy one order row as a throw-away order
// without a record; it is deleted again at the end
$tmpOrderCopy = 0;
if ($emptyOrder === 0 && $issuedOrder > 0) {
    $cols = array();
    foreach ($db->executeS('SHOW COLUMNS FROM ' . $t . 'orders') as $c) {
        if ($c['Field'] !== 'id_order') {
            $cols[] = '`' . $c['Field'] . '`';
        }
    }
    $list = implode(',', $cols);
    $db->execute('INSERT INTO ' . $t . 'orders (' . $list . ') SELECT ' . $list . ' FROM ' . $t . 'orders WHERE id_order = ' . $issuedOrder);
    $tmpOrderCopy = (int) $db->Insert_ID();
    $emptyOrder = $tmpOrderCopy;
    echo "throw-away order $tmpOrderCopy copied from $issuedOrder (no record)\n";
}
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
    // the tooltip's first line names the kind and the state in words and ends with the number
    $headline = ': ' . htmlspecialchars($issuedNumber, ENT_QUOTES, 'UTF-8');
    check($cell !== null && strpos($cell, 'receipt_long') !== false && strpos($cell, 'material-icons') !== false
        && strpos($cell, $headline) !== false && preg_match('/title="[^"]*(Invoice|Receipt|Faktura|Paragon)/u', $cell) === 1,
        'issued order cell has the icon and a tooltip headline ending with the number', (string) $cell);
}
if ($emptyOrder > 0) {
    check(isset($records[$emptyOrder]['ifirma_document']) && strpos($records[$emptyOrder]['ifirma_document'], 'receipt_long') === false, 'order without a record has no issued icon in its cell', $emptyOrder . ': ' . var_export(isset($records[$emptyOrder]['ifirma_document']) ? $records[$emptyOrder]['ifirma_document'] : 'key absent', true));
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

// 4c2. sorting by the column: both directions, the rank non-decreasing (or non-increasing), newest order first inside a state
$sortedIds = array();
foreach (array('asc', 'desc') as $way) {
    $sortedFilters = new \PrestaShop\PrestaShop\Core\Search\Filters\OrderFilters(array_merge(
        \PrestaShop\PrestaShop\Core\Search\Filters\OrderFilters::getDefaults(),
        array('limit' => 100000, 'offset' => 0, 'orderBy' => 'ifirma_document', 'sortOrder' => $way)
    ));
    $sortedGrid = $factory->getGrid($sortedFilters);
    $sortedPresented = $presenter->present($sortedGrid);
    $sortedRecords = $sortedPresented['data']['records'];
    if (is_object($sortedRecords) && method_exists($sortedRecords, 'all')) {
        $sortedRecords = $sortedRecords->all();
    }
    $ranks = array();
    $tieOk = true;
    $previous = null;
    foreach ($sortedRecords as $rec) {
        $rank = (int) $rec[IfirmaOrderGridIndicator::FIELD_RANK];
        if ($previous !== null && $previous['rank'] === $rank && (int) $rec['id_order'] > $previous['id']) {
            $tieOk = false;
        }
        $previous = array('rank' => $rank, 'id' => (int) $rec['id_order']);
        $ranks[] = $rank;
    }
    $expected = $ranks;
    $way === 'asc' ? sort($expected) : rsort($expected);
    check($ranks === $expected && count($ranks) === $total, 'sorted ' . $way . ' by the iFirma column: the ranks run ' . ($way === 'asc' ? 'up' : 'down'), implode(',', array_slice($ranks, 0, 20)));
    check($tieOk, 'sorted ' . $way . ': orders in the same state are listed newest first');
    $sortedIds[$way] = array_map(function ($rec) {
        return (int) $rec['id_order'];
    }, $sortedRecords);
}
check(count($sortedIds['asc']) === $total && count($sortedIds['desc']) === $total, 'sorting changes neither the total nor the paging');

// 4d. no iFirma HTTP request while building the grid
$after = $mockCount();
if ($before === null || $after === null) {
    skip('no request to the iFirma mock while building the grid', 'the mock keeps no request log; the fallback (IfirmaApiClient::getLimit() before/after, the mock counts every call) needs IFIRMA_KEY_ABONENT, which this shop leaves empty');
} else {
    check($after - $before === 1, 'no request to the iFirma mock while building the grid', "mock counter $before -> $after (the second limit read is the only +1)");
}

// 4e. the third select (raw state) and the Issue anchor
if ($issuedOrder > 0 && isset($records[$issuedOrder])) {
    check(array_key_exists(IfirmaOrderGridIndicator::FIELD_STATE, $records[$issuedOrder]) && $records[$issuedOrder][IfirmaOrderGridIndicator::FIELD_STATE] === $issuedStatus,
        'query builder hook selected the third value ifirma_document_state for the issued order', var_export(isset($records[$issuedOrder][IfirmaOrderGridIndicator::FIELD_STATE]) ? $records[$issuedOrder][IfirmaOrderGridIndicator::FIELD_STATE] : 'key absent', true) . ' expected ' . $issuedStatus);
    check(strpos((string) $records[$issuedOrder]['ifirma_document'], 'js-ifirma-issue') === false, 'an order with an issued document gets the icon, not the Issue anchor');
}
if ($emptyOrder > 0 && isset($records[$emptyOrder])) {
    $cellEmpty = (string) $records[$emptyOrder]['ifirma_document'];
    check(array_key_exists(IfirmaOrderGridIndicator::FIELD_STATE, $records[$emptyOrder]) && $records[$emptyOrder][IfirmaOrderGridIndicator::FIELD_STATE] === '', 'an order without a record has an empty ifirma_document_state');
    check(strpos($cellEmpty, 'js-ifirma-issue') !== false && strpos($cellEmpty, 'data-id-order="' . $emptyOrder . '"') !== false,
        'SuperAdmin context employee: an order without a document presents the Issue anchor with its data-id-order', $cellEmpty);
}

// 4f. filtering by the column: one throw-away order per state, filtered through the grid's own filter object
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDocumentStateRank.php';
$listed = function (array $extraFilters, array $sort = array()) use ($factory, $presenter) {
    $defaults = \PrestaShop\PrestaShop\Core\Search\Filters\OrderFilters::getDefaults();
    $filterSet = new \PrestaShop\PrestaShop\Core\Search\Filters\OrderFilters(array_merge(
        $defaults,
        array('limit' => 100000, 'offset' => 0, 'filters' => array_merge(isset($defaults['filters']) ? $defaults['filters'] : array(), $extraFilters)),
        $sort
    ));
    $gridOfFilter = $factory->getGrid($filterSet);
    $rows = $presenter->present($gridOfFilter)['data']['records'];
    if (is_object($rows) && method_exists($rows, 'all')) {
        $rows = $rows->all();
    }
    $ids = array();
    $ranks = array();
    foreach ($rows as $row) {
        $ids[] = (int) $row['id_order'];
        $ranks[(int) $row['id_order']] = (int) $row[IfirmaOrderGridIndicator::FIELD_RANK];
    }

    return array('ids' => $ids, 'ranks' => $ranks, 'total' => (int) $gridOfFilter->getData()->getRecordsTotal());
};
$stateSeed = array(
    IfirmaDocumentStateRank::ERROR => array('status' => 'error'),
    IfirmaDocumentStateRank::UNKNOWN => array('status' => 'unknown'),
    IfirmaDocumentStateRank::SUSPECTED => array('status' => 'duplicate_suspected'),
    IfirmaDocumentStateRank::HELD => array('status' => 'held'),
    IfirmaDocumentStateRank::QUEUED => array('status' => 'queued'),
    IfirmaDocumentStateRank::NO_RECORD => null,
    IfirmaDocumentStateRank::ISSUED => array('status' => 'created', 'ifirma_id' => 'SMKGRID7', 'delivery_status' => 'none'),
    IfirmaDocumentStateRank::AWAITING_KSEF => array('status' => 'created', 'ifirma_id' => 'SMKGRID8', 'delivery_status' => 'awaiting_ksef'),
    IfirmaDocumentStateRank::SENT => array('status' => 'created', 'ifirma_id' => 'SMKGRID9', 'delivery_status' => 'sent'),
    IfirmaDocumentStateRank::PAID => array('status' => 'paid', 'ifirma_id' => 'SMKGRID10', 'delivery_status' => 'sent'),
);
$gridTemplateOrder = $issuedOrder > 0 ? $issuedOrder : (int) $db->getValue('SELECT MAX(id_order) FROM ' . $t . 'orders');
$seededOrder = array();
$seededReceipts = array();
$orderColumns = array();
foreach ($db->executeS('SHOW COLUMNS FROM ' . $t . 'orders') as $c) {
    if ($c['Field'] !== 'id_order') {
        $orderColumns[] = '`' . $c['Field'] . '`';
    }
}
try {
    foreach ($stateSeed as $rank => $record) {
        $db->execute('INSERT INTO ' . $t . 'orders (' . implode(',', $orderColumns) . ') SELECT ' . implode(',', $orderColumns) . ' FROM ' . $t . 'orders WHERE id_order = ' . (int) $gridTemplateOrder);
        $idOrder = (int) $db->Insert_ID();
        $db->execute('UPDATE ' . $t . 'orders SET reference = \'SMKGRID' . sprintf('%02d', $rank) . '\' WHERE id_order = ' . $idOrder);
        $seededOrder[$rank] = $idOrder;
        if ($record === null) {
            continue;
        }
        $r = new IfirmaReceipt();
        $r->id_order = $idOrder;
        $r->id_customer = (int) $db->getValue('SELECT id_customer FROM ' . $t . 'orders WHERE id_order = ' . $idOrder);
        $r->id_shop = 1;
        $r->document_type = 'fakturakraj';
        $r->source = 'module';
        $r->security_token = Tools::passwdGen(32);
        foreach ($record as $field => $value) {
            $r->$field = $value;
        }
        $r->add();
        $seededReceipts[] = (int) $r->id;
    }
    check(count($seededOrder) === 10 && count($seededReceipts) === 9, 'one throw-away order per document state is seeded', implode(',', $seededOrder));
    $unfiltered = $listed(array());

    foreach ($stateSeed as $rank => $record) {
        $name = $rank . ' (' . IfirmaDocumentStateRank::states()[$rank - 1]['state'] . ')';
        $result = $listed(array('ifirma_document' => (string) $rank));
        $seededListed = array_values(array_intersect($result['ids'], array_values($seededOrder)));
        check($seededListed === array($seededOrder[$rank]), 'filter ' . $name . ': of the seeded orders only the one in that state is listed', json_encode($seededListed) . ' expected ' . $seededOrder[$rank]);
        check($result['total'] === count($result['ids']) && $result['total'] > 0, 'filter ' . $name . ': the reported total equals the listed orders', 'total ' . $result['total'] . ', listed ' . count($result['ids']));
        check(array_values(array_unique($result['ranks'])) === array($rank), 'filter ' . $name . ': every listed order is in that state', json_encode(array_count_values($result['ranks'])));
        $combined = $listed(array('ifirma_document' => (string) $rank, 'reference' => 'SMKGRID'));
        check($combined['ids'] === array($seededOrder[$rank]) && $combined['total'] === 1, 'filter ' . $name . ' with the reference filter: exactly the seeded order, total 1', json_encode($combined['ids']) . ' total ' . $combined['total']);
    }

    $sortedState = $listed(array('ifirma_document' => (string) IfirmaDocumentStateRank::SENT), array('orderBy' => 'ifirma_document', 'sortOrder' => 'desc'));
    $plainState = $listed(array('ifirma_document' => (string) IfirmaDocumentStateRank::SENT));
    check(count($sortedState['ids']) > 0 && $sortedState['total'] === count($sortedState['ids']) && $sortedState['ids'] === $plainState['ids'], 'filter 9 with the sort on the same column keeps the listed orders, newest first, and the total', 'total ' . $sortedState['total']);
    $sortedById = $listed(array('ifirma_document' => (string) IfirmaDocumentStateRank::SENT, 'reference' => 'SMKGRID'), array('orderBy' => 'id_order', 'sortOrder' => 'asc'));
    check($sortedById['ids'] === array($seededOrder[IfirmaDocumentStateRank::SENT]), 'filter 9 with the reference filter and another sort lists the seeded order', json_encode($sortedById['ids']));

    foreach (array('refunded', '1 OR 1=1', '0', '11') as $invalid) {
        $everything = $listed(array('ifirma_document' => $invalid));
        check($everything['ids'] === $unfiltered['ids'], 'an invalid filter value (' . $invalid . ') lists every order', count($everything['ids']) . ' of ' . count($unfiltered['ids']));
        check($everything['total'] === $unfiltered['total'], 'an invalid filter value (' . $invalid . ') leaves the total as it is', $everything['total'] . ' = ' . $unfiltered['total']);
    }

    $afterReset = $listed(array());
    check($afterReset['total'] === $unfiltered['total'] && $afterReset['ids'] === $unfiltered['ids'], 'after the filters the unfiltered grid lists the same orders again', $afterReset['total'] . ' = ' . $unfiltered['total']);
} finally {
    foreach ($seededReceipts as $idReceipt) {
        $db->execute('DELETE FROM ' . $t . 'ifirma_receipt WHERE id_ifirma_receipt = ' . (int) $idReceipt);
    }
    foreach ($seededOrder as $idOrder) {
        $db->execute('DELETE FROM ' . $t . 'orders WHERE id_order = ' . (int) $idOrder);
    }
}
check((int) $db->getValue('SELECT COUNT(*) FROM ' . $t . 'orders WHERE reference LIKE \'SMKGRID%\'') === 0, 'the seeded throw-away orders are deleted');

// a throw-away profile without roles and an employee of it: the same grid then shows no anchor
$tmpProfile = null;
$tmpEmployee = null;
try {
    $tmpProfile = new Profile();
    foreach (Language::getLanguages(false) as $l) {
        $tmpProfile->name[$l['id_lang']] = 'ifirma-smoke-noroles';
    }
    $tmpProfile->add();
    $tmpEmployee = new Employee();
    $tmpEmployee->firstname = 'Smoke';
    $tmpEmployee->lastname = 'Noroles';
    $tmpEmployee->email = 'ifirma-smoke-noroles@example.com';
    $tmpEmployee->passwd = Tools::hash('Passw0rd!x');
    $tmpEmployee->id_profile = (int) $tmpProfile->id;
    $tmpEmployee->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $tmpEmployee->active = 1;
    $tmpEmployee->add();
    check((int) $tmpProfile->id > 0 && (int) $tmpEmployee->id > 0, 'throw-away profile and employee created', 'profile ' . $tmpProfile->id . ', employee ' . $tmpEmployee->id);
    $ctx->employee = $tmpEmployee;
    check(!IfirmaPermission::canIssue($tmpEmployee), 'the throw-away profile has no issue level');
    $grid2 = $factory->getGrid($filters);
    $presented2 = $presenter->present($grid2);
    $recs2 = $presented2['data']['records'];
    if (is_object($recs2) && method_exists($recs2, 'all')) {
        $recs2 = $recs2->all();
    }
    $byId = array();
    foreach ($recs2 as $rec) {
        $byId[(int) $rec['id_order']] = $rec;
    }
    if ($emptyOrder > 0) {
        $c2 = isset($byId[$emptyOrder]['ifirma_document']) ? (string) $byId[$emptyOrder]['ifirma_document'] : '';
        check($c2 === '' && strpos(json_encode($byId[$emptyOrder]), 'js-ifirma-issue') === false, 'context employee of a profile without roles: no Issue anchor (cell is empty)', var_export($c2, true));
    }
} catch (Exception $e) {
    check(false, 'the profile-without-roles part ran', $e->getMessage());
}
$ctx->employee = new Employee(1);
if ($tmpEmployee && (int) $tmpEmployee->id) {
    $tmpEmployee->delete();
}
if ($tmpProfile && (int) $tmpProfile->id) {
    $tmpProfile->delete();
}
if ($tmpOrderCopy) {
    $db->execute('DELETE FROM ' . $t . 'orders WHERE id_order = ' . $tmpOrderCopy);
    check(!(bool) $db->getValue('SELECT COUNT(*) FROM ' . $t . 'orders WHERE id_order = ' . $tmpOrderCopy), 'throw-away order deleted');
}
check(!(bool) $db->getValue('SELECT COUNT(*) FROM ' . $t . "employee WHERE email = 'ifirma-smoke-noroles@example.com'"), 'throw-away employee deleted');

echo "SUMMARY PrestaShop " . _PS_VERSION_ . ": $pass passed, $fail failed\n";
echo ($fail ? 'ORDER_GRID_CHECK_FAILED ' . $fail : 'ORDER_GRID_CHECK_OK') . "\n";
exit($fail ? 1 : 0);
