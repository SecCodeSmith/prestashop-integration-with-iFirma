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

/*
 * Module smoke run: drives the real PrestaShop classes and the iFirma mock.
 *
 *   docker compose -f test-stack/docker-compose.yml exec prestashop php modules/ifirma/tests/smoke/run.php
 *
 * See tests/smoke/README.md. Exit code 0 = every assertion passed, 1 = at least one failed,
 * 2 = the environment could not be prepared.
 */

if (PHP_SAPI !== 'cli') {
    exit("This script must be run from the command line.\n");
}

$psRoot = getenv('PS_ROOT') ? rtrim(getenv('PS_ROOT'), '/\\') : dirname(__DIR__, 4);
if (!is_file($psRoot . '/config/config.inc.php')) {
    fwrite(STDERR, 'PrestaShop not found at ' . $psRoot . " (set PS_ROOT).\n");
    exit(2);
}
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

// ---------------------------------------------------------------- reporting

$GLOBALS['smoke'] = array('ok' => 0, 'fail' => 0, 'skip' => 0, 'failures' => array());

function smokeOut($tag, $label, $detail = '')
{
    echo '[' . $tag . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
}

function assertTrue($cond, $label, $detail = '')
{
    if ($cond) {
        $GLOBALS['smoke']['ok']++;
        smokeOut(' OK ', $label);

        return true;
    }
    $GLOBALS['smoke']['fail']++;
    $GLOBALS['smoke']['failures'][] = $label;
    smokeOut('FAIL', $label, $detail);

    return false;
}

function assertSame($expected, $actual, $label)
{
    return assertTrue(
        $expected === $actual,
        $label,
        'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
    );
}

function skip($label, $why)
{
    $GLOBALS['smoke']['skip']++;
    smokeOut('SKIP', $label, $why);
}

function section($title)
{
    echo "\n=== " . $title . " ===\n";
}

// ---------------------------------------------------------------- mock access

$mockHost = rtrim(getenv('IFIRMA_MOCK_HOST') ?: 'http://ifirma-mock:8001', '/');
$mockUrl = getenv('IFIRMA_MOCK_URL') ?: 'http://ifirma-mock:8001/iapi/';

/**
 * Calls a test-only /__mock endpoint (no HMAC).
 *
 * @return array array('code' => int, 'data' => mixed, 'raw' => string|false)
 */
function mockRequest($method, $path, $json = null)
{
    global $mockHost;

    return httpRequest($method, $mockHost . $path, $json === null ? null : json_encode($json), array('Content-Type: application/json'));
}

function httpRequest($method, $url, $body = null, array $headers = array())
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array('code' => $raw === false ? 0 : $code, 'data' => $raw === false ? null : json_decode($raw, true), 'raw' => $raw);
}

function mockReset()
{
    $r = mockRequest('POST', '/__mock/reset');
    if ($r['code'] < 200 || $r['code'] >= 300) {
        smokeOut('FAIL', 'mock reset', 'HTTP ' . $r['code'] . ' (is the ifirma-mock service up? IFIRMA_MOCK_HOST)');
        exit(2);
    }
}

/**
 * @return mixed first value stored under $key anywhere in $data, or null
 */
function findKey($data, $key)
{
    if (!is_array($data)) {
        return null;
    }
    if (isset($data[$key])) {
        return $data[$key];
    }
    foreach ($data as $v) {
        $found = findKey($v, $key);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

/**
 * @return string id of the seeded document ('' when the mock did not answer with one)
 */
function seedDocument(array $body)
{
    $r = mockRequest('POST', '/__mock/documents', $body);
    $id = findKey($r['data'], 'FakturaId');
    if ($id === null) {
        $id = findKey($r['data'], 'Identyfikator');
    }

    return $id === null ? '' : (string) $id;
}

function ifirmaClient()
{
    return new IfirmaApiClient(
        Configuration::get('IFIRMA_USER'),
        array('faktura' => Configuration::get('IFIRMA_KEY_FAKTURA')),
        Configuration::get('IFIRMA_BASE_URL')
    );
}

/**
 * @return int number of (non deleted) documents the mock lists, -1 when the call failed
 */
function remoteDocumentCount()
{
    $rows = ifirmaClient()->listInvoices(array(
        'dataOd' => date('Y-m-d', strtotime('-3 days')),
        'dataDo' => date('Y-m-d', strtotime('+1 day')),
        'iloscNaStronie' => 100,
    ));

    return $rows === null ? -1 : count($rows);
}

// ---------------------------------------------------------------- PrestaShop helpers

/**
 * Picks an active, in-stock product and a customer that owns a usable address.
 *
 * @return array
 */
function discoverFixtures()
{
    $db = Db::getInstance();
    $p = _DB_PREFIX_;

    $product = $db->getRow(
        'SELECT p.id_product FROM ' . $p . 'product p
         INNER JOIN ' . $p . 'product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = 1
         INNER JOIN ' . $p . 'stock_available sa ON sa.id_product = p.id_product AND sa.id_product_attribute = 0
         WHERE ps.active = 1 AND ps.available_for_order = 1 AND ps.price > 0 AND sa.quantity > 0
         ORDER BY p.id_product ASC'
    );
    $address = $db->getRow(
        'SELECT a.id_address, a.id_customer FROM ' . $p . 'address a
         INNER JOIN ' . $p . 'customer c ON c.id_customer = a.id_customer
         WHERE a.deleted = 0 AND a.active = 1 AND c.active = 1 AND c.deleted = 0 AND c.is_guest = 0
         ORDER BY a.id_address ASC'
    );

    return array(
        'id_product' => $product ? (int) $product['id_product'] : 0,
        'id_address' => $address ? (int) $address['id_address'] : 0,
        'id_customer' => $address ? (int) $address['id_customer'] : 0,
    );
}

/**
 * Creates a real order. Uses ps_wirepayment / ps_checkpayment's PaymentModule::validateOrder() when
 * one of them is installed (full order pipeline: totals, stock, order detail, history). Otherwise it
 * falls back to inserting a bare Order row, which is enough for the iFirma module but has no order
 * lines (limitation documented in tests/smoke/README.md).
 *
 * @return Order|null
 */
function createOrder()
{
    $fx = $GLOBALS['fixture'];
    if (!$fx['id_product'] || !$fx['id_address'] || !$fx['id_customer']) {
        return null;
    }

    $customer = new Customer($fx['id_customer']);
    $address = new Address($fx['id_address']);
    $context = Context::getContext();
    $idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
    $idLang = (int) Configuration::get('PS_LANG_DEFAULT');

    $cart = new Cart();
    $cart->id_shop_group = 1;
    $cart->id_shop = 1;
    $cart->id_customer = (int) $customer->id;
    $cart->id_address_delivery = (int) $address->id;
    $cart->id_address_invoice = (int) $address->id;
    $cart->id_currency = $idCurrency;
    $cart->id_lang = $idLang;
    $cart->id_carrier = (int) Configuration::get('PS_CARRIER_DEFAULT');
    $cart->secure_key = $customer->secure_key;
    $cart->add();
    $context->cart = $cart;

    $attribute = method_exists('Product', 'getDefaultAttribute') ? (int) Product::getDefaultAttribute($fx['id_product']) : 0;
    $cart->updateQty(1, $fx['id_product'], $attribute ? $attribute : null);
    if ($cart->id_carrier && method_exists($cart, 'setDeliveryOption')) {
        $cart->setDeliveryOption(array((int) $address->id => (int) $cart->id_carrier . ','));
        $cart->update();
    }

    $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

    foreach (array('ps_wirepayment', 'ps_checkpayment') as $name) {
        if (!Module::isInstalled($name)) {
            continue;
        }
        $payment = Module::getInstanceByName($name);
        if (!$payment || !method_exists($payment, 'validateOrder')) {
            continue;
        }
        $state = $name === 'ps_wirepayment' ? Configuration::get('PS_OS_BANKWIRE') : Configuration::get('PS_OS_CHEQUE');
        $ok = $payment->validateOrder(
            (int) $cart->id,
            (int) $state,
            $total,
            'Smoke',
            null,
            array(),
            $idCurrency,
            false,
            $customer->secure_key
        );
        if ($ok && (int) $payment->currentOrder > 0) {
            return new Order((int) $payment->currentOrder);
        }
    }

    // Fallback: minimal Order row.
    $order = new Order();
    $order->id_shop = 1;
    $order->id_shop_group = 1;
    $order->id_cart = (int) $cart->id;
    $order->id_customer = (int) $customer->id;
    $order->id_carrier = (int) $cart->id_carrier;
    $order->id_lang = $idLang;
    $order->id_currency = $idCurrency;
    $order->id_address_delivery = (int) $address->id;
    $order->id_address_invoice = (int) $address->id;
    $order->current_state = (int) Configuration::get('PS_OS_BANKWIRE');
    $order->secure_key = $customer->secure_key;
    $order->payment = 'Smoke';
    $order->module = 'ps_wirepayment';
    $order->conversion_rate = 1;
    $order->reference = Order::generateReference();
    $gross = $total > 0 ? $total : 10.00;
    $order->total_paid = $order->total_paid_tax_incl = $gross;
    $order->total_paid_tax_excl = round($gross / 1.23, 6);
    $order->total_paid_real = 0;
    $order->total_products = $order->total_products_wt = $gross;
    $order->total_shipping = $order->total_shipping_tax_incl = $order->total_shipping_tax_excl = 0;
    $order->total_discounts = $order->total_discounts_tax_incl = $order->total_discounts_tax_excl = 0;
    $order->total_wrapping = $order->total_wrapping_tax_incl = $order->total_wrapping_tax_excl = 0;
    $order->round_mode = 0;
    $order->round_type = 0;
    if (!$order->add()) {
        return null;
    }

    return $order;
}

/**
 * Moves the order to a new state through OrderHistory, which fires actionOrderStatusPostUpdate.
 * addWithemail(true) is the back office call; PS_MAIL_METHOD 3 keeps it from sending anything.
 */
function changeState(Order $order, $idState)
{
    $history = new OrderHistory();
    $history->id_order = (int) $order->id;
    $history->id_employee = 1;
    $history->changeIdOrderState((int) $idState, $order, true);
    $history->addWithemail(true);
}

/**
 * Resets the mock and creates a fresh order for a scenario.
 *
 * @return Order|null
 */
function freshScenario($title)
{
    section($title);
    mockReset();
    // The whole run sends more than the module's 80 requests per minute guard allows; each scenario
    // starts with a fresh window so the guard does not fail scenarios that are not testing it.
    Configuration::updateValue('IFIRMA_RATE_WINDOW', '');
    $order = createOrder();
    if (!assertTrue($order instanceof Order && Validate::isLoadedObject($order), 'order created')) {
        return null;
    }
    smokeOut('INFO', 'order #' . $order->id . ' ' . $order->reference . ' total ' . $order->total_paid_tax_incl);

    return $order;
}

function receiptOf(Order $order)
{
    return IfirmaReceipt::getByOrder((int) $order->id);
}

function currencyIso(Order $order)
{
    $currency = new Currency((int) $order->id_currency);

    return $currency->iso_code;
}

// ---------------------------------------------------------------- bootstrap shop + module

section('Bootstrap');

$context = Context::getContext();
if (class_exists('Shop') && method_exists('Shop', 'setContext')) {
    Shop::setContext(Shop::CONTEXT_SHOP, 1);
}
$context->employee = new Employee(1);
if (!Validate::isLoadedObject($context->employee)) {
    skip('employee 1', 'no back office employee with id 1; admin actions still run with id 1');
}
if (!$context->language || !Validate::isLoadedObject($context->language)) {
    $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
}
if (!$context->currency || !Validate::isLoadedObject($context->currency)) {
    $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
}
if (!$context->country || !Validate::isLoadedObject($context->country)) {
    $context->country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
}
if (!$context->link) {
    $context->link = new Link();
}

$module = Module::getInstanceByName('ifirma');
if (!$module) {
    fwrite(STDERR, "Module ifirma not found in modules/. Is the bind mount in place?\n");
    exit(2);
}
// PrestaShop's upgrade runner expects the properties the back-office module manager sets.
$module->installed = Module::isInstalled('ifirma') ? 1 : 0;
$module->database_version = (string) Db::getInstance()->getValue(
    'SELECT version FROM `' . _DB_PREFIX_ . "module` WHERE name = 'ifirma'"
);
if (!Module::isInstalled('ifirma')) {
    if (!$module->install()) {
        fwrite(STDERR, 'Module install failed: ' . implode('; ', (array) $module->getErrors()) . "\n");
        exit(2);
    }
    smokeOut('INFO', 'module installed');
} elseif (method_exists('Module', 'needUpgrade') && Module::needUpgrade($module)
    && method_exists('Module', 'initUpgradeModule') && method_exists($module, 'runUpgradeModule')) {
    // Installed from an older version: run the upgrade scripts once.
    Module::initUpgradeModule($module);
    $module->runUpgradeModule();
    smokeOut('INFO', 'module upgraded to ' . $module->version);
}
assertTrue(Module::isInstalled('ifirma'), 'module installed');

// The module classes are normally loaded by the module file / controllers.
foreach (array('IfirmaApiClient', 'IfirmaReceipt', 'IfirmaDataRequest', 'IfirmaReceiptService', 'IfirmaPayloadBuilder', 'IfirmaIssuancePipeline',
    'IfirmaDuplicateGuard', 'IfirmaDeliveryService', 'IfirmaStatusSyncService', 'IfirmaDataRequestService', 'IfirmaAdminActions',
    'IfirmaBulkRunner', 'IfirmaIssueFeed', 'IfirmaDownloadThrottle', 'IfirmaPermission') as $class) {
    if (!class_exists($class)) {
        require_once _PS_MODULE_DIR_ . 'ifirma/classes/' . $class . '.php';
    }
}

// ---------------------------------------------------------------- configuration for the mock

Configuration::updateValue('IFIRMA_BASE_URL', $mockUrl);
Configuration::updateValue('IFIRMA_USER', 'test@example.com');
Configuration::updateValue('IFIRMA_KEY_FAKTURA', str_repeat('1', 64));
Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturakraj');
Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', (int) Configuration::get('PS_OS_PAYMENT'));
Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', 0);
Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', 1);
Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 1);
Configuration::updateValue('IFIRMA_SYNC_INTERVAL_MIN', 0);
Configuration::updateValue('PS_MAIL_METHOD', 3); // never send: Mail::Send() succeeds without SMTP
Configuration::updateValue('PS_ORDER_OUT_OF_STOCK', 1);
if ((string) Configuration::get('IFIRMA_CRON_TOKEN') === '' && class_exists('IfirmaSchema')) {
    Configuration::updateValue('IFIRMA_CRON_TOKEN', IfirmaSchema::generateCronToken());
}
assertTrue($module->isConfigured(), 'module configured for the mock');

$GLOBALS['fixture'] = discoverFixtures();
smokeOut('INFO', 'fixtures: ' . json_encode($GLOBALS['fixture']));
if (!$GLOBALS['fixture']['id_product'] || !$GLOBALS['fixture']['id_address']) {
    fwrite(STDERR, "No in-stock product or customer address found; install the PrestaShop demo data.\n");
    exit(2);
}

$actions = new IfirmaAdminActions($module);
$triggerState = (int) Configuration::get('PS_OS_PAYMENT');

// ---------------------------------------------------------------- scenarios

/**
 * Positions of a seeded document that mirror the order: the module's product lines plus one
 * filler position for shipping and rounding, so the mock's gross (sum of the positions) equals
 * the order total. The guard ignores positions beyond the order's product lines.
 */
function seedPositionsFor(Order $order)
{
    $positions = array();
    $sum = 0.0;
    foreach (IfirmaPayloadBuilder::orderProductLines($order) as $line) {
        $unit = round($line['unit_price_tax_incl'], 2);
        $positions[] = array(
            'NazwaPelna' => $line['name'],
            'Ilosc' => $line['quantity'],
            'CenaJednostkowa' => $unit,
            'StawkaVat' => 0.23,
            'TypStawkiVat' => 'PRC',
        );
        $sum += $unit * $line['quantity'];
    }
    $rest = round((float) $order->total_paid_tax_incl - $sum, 2);
    if (abs($rest) >= 0.005) {
        $positions[] = array(
            'NazwaPelna' => $rest > 0 ? 'Koszt dostawy' : 'Rabat',
            'Ilosc' => 1,
            'CenaJednostkowa' => $rest,
            'StawkaVat' => 0.23,
            'TypStawkiVat' => 'PRC',
        );
    }

    return $positions;
}

/** The contractor identifier the guard filters by: the stored link, otherwise PS-C{id_customer}. */
function guardIdentifierFor(Order $order)
{
    $map = IfirmaContractorMap::getByCustomer((int) $order->id_customer);
    if ($map && trim((string) $map->ifirma_identifier) !== '') {
        return trim((string) $map->ifirma_identifier);
    }

    return 'PS-C' . (int) $order->id_customer;
}

/** Ids of the candidates recorded on the receipt, in stored order. */
function candidateIds($receipt)
{
    $data = $receipt ? $receipt->getDuplicateCandidates() : array();
    $ids = array();
    foreach ((isset($data['candidates']) && is_array($data['candidates'])) ? $data['candidates'] : array() as $c) {
        $ids[] = isset($c['id']) ? (string) $c['id'] : '';
    }

    return $ids;
}

function scenarioA($module, $actions)
{
    $order = freshScenario('A. Duplicate guard: suspected, then link');
    if (!$order) {
        return;
    }
    // A document of the buyer for the order's sale date and products, without the order reference.
    $seedId = seedDocument(array(
        'doc_type' => 'fakturakraj',
        'contractor' => array('Nazwa' => 'Smoke Customer', 'Identyfikator' => guardIdentifierFor($order)),
        'currency' => currencyIso($order),
        'sale_date' => IfirmaPayloadBuilder::orderSaleDate($order),
        'Pozycje' => seedPositionsFor($order),
        'created_by' => 'external',
    ));
    assertTrue($seedId !== '', 'external document of the buyer seeded', 'id=' . $seedId);
    // A same-amount document of another buyer, issued today: never a candidate.
    $otherId = seedDocument(array(
        'doc_type' => 'fakturakraj',
        'contractor' => array('Nazwa' => 'Other Buyer', 'Identyfikator' => 'OTHER-1'),
        'gross' => round((float) $order->total_paid_tax_incl, 2),
        'currency' => currencyIso($order),
        'sale_date' => date('Y-m-d'),
        'created_by' => 'external',
    ));
    assertTrue($otherId !== '', 'same-amount document of another buyer seeded', 'id=' . $otherId);

    $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
    assertSame('suspected', $result->outcome, 'pipeline outcome is suspected');
    $receipt = receiptOf($order);
    assertSame('duplicate_suspected', $receipt ? $receipt->status : null, 'receipt status duplicate_suspected');
    assertSame(array($seedId), candidateIds($receipt), 'only the buyer\'s document is recorded as a candidate');
    $data = $receipt ? $receipt->getDuplicateCandidates() : array();
    assertSame(
        IfirmaPayloadBuilder::orderSaleDate($order),
        isset($data['candidates'][0]['sale_date']) ? $data['candidates'][0]['sale_date'] : null,
        'recorded candidate carries the sale date read from its detail'
    );
    assertSame(2, remoteDocumentCount(), 'no module-created document in iFirma (2 documents: the seeded ones)');

    $r = $actions->linkCandidate((int) $order->id, $seedId, 1);
    assertTrue($r['success'], 'linkCandidate succeeds', $r['message']);
    $receipt = receiptOf($order);
    assertSame('external', $receipt ? $receipt->source : null, 'linked receipt source is external');
    assertSame($seedId, $receipt ? (string) $receipt->ifirma_id : null, 'linked receipt carries the seeded id');
    assertTrue($receipt && $receipt->isIssued(), 'linked receipt isIssued');
}

function scenarioA2($module)
{
    $order = freshScenario('A2. Duplicate guard: same-amount document of another buyer does not block');
    if (!$order) {
        return;
    }
    // The production case: the buyer has no document yet, the unfiltered window query returns
    // another customer's document with the same gross for the same sale date.
    $otherId = seedDocument(array(
        'doc_type' => 'fakturakraj',
        'contractor' => array('Nazwa' => 'Other Buyer', 'Identyfikator' => 'OTHER-1'),
        'gross' => round((float) $order->total_paid_tax_incl, 2),
        'currency' => currencyIso($order),
        'sale_date' => IfirmaPayloadBuilder::orderSaleDate($order),
        'created_by' => 'external',
    ));
    assertTrue($otherId !== '', 'same-amount document of another buyer seeded', 'id=' . $otherId);

    $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
    assertSame('issued', $result->outcome, 'pipeline outcome is issued (other buyer\'s document is not a candidate)');
    $receipt = receiptOf($order);
    assertTrue(
        $receipt && $receipt->isIssued() && (string) $receipt->ifirma_id !== '' && (string) $receipt->ifirma_id !== $otherId,
        'receipt carries a newly created document id',
        $receipt ? (string) $receipt->ifirma_id : 'no receipt'
    );
    assertSame(array(), candidateIds($receipt), 'no candidate recorded');
    assertSame(2, remoteDocumentCount(), 'a new document was created (2 in iFirma: seeded + created)');
}

function scenarioB($module)
{
    $order = freshScenario('B. Duplicate guard: adopt by order reference');
    if (!$order) {
        return;
    }
    // iFirma's own contractor for this buyer: another identifier, the buyer's name (the guard
    // keeps the row by name, then adopts it by the order reference in its detail).
    $address = new Address((int) $order->id_address_invoice);
    $seedId = seedDocument(array(
        'doc_type' => 'fakturakraj',
        'contractor' => array('Nazwa' => trim($address->firstname . ' ' . $address->lastname), 'Identyfikator' => 'EXT1'),
        'gross' => round((float) $order->total_paid_tax_incl, 2),
        'currency' => currencyIso($order),
        'uwagi' => IfirmaPayloadBuilder::buildOrderReference($order),
        'created_by' => 'external',
    ));
    assertTrue($seedId !== '', 'external document with order reference seeded', 'id=' . $seedId);

    $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
    assertSame('adopted', $result->outcome, 'pipeline outcome is adopted');
    $receipt = receiptOf($order);
    assertSame($seedId, $receipt ? (string) $receipt->ifirma_id : null, 'adopted receipt carries the seeded id');
    assertSame(1, remoteDocumentCount(), 'no document was created (still 1 in iFirma)');
}

function scenarioC($module, $actions, $triggerState)
{
    $order = freshScenario('C. Data request: hold, approve, issue with override');
    if (!$order) {
        return;
    }
    $customer = new Customer((int) $order->id_customer);
    $customer->logged = 1; // not a DB column; Customer::isLogged() reads it
    $address = new Address((int) $order->id_address_invoice);
    Context::getContext()->customer = $customer;

    $request = null;
    try {
        $request = IfirmaDataRequestService::build($module)->submit($order, $customer, array(
            'want_invoice_for_company' => 1,
            'company' => 'Smoke Sp. z o.o.',
            'vat_number' => '525-000-12-34',
            'address1' => $address->address1,
            'postcode' => $address->postcode,
            'city' => $address->city,
            'id_country' => (int) $address->id_country,
        ));
    } catch (Exception $e) {
        assertTrue(false, 'data request submitted', $e->getMessage() . ' ' . json_encode(isset($e->errors) ? $e->errors : array()));
    }
    if (!$request) {
        return;
    }
    assertSame('pending', $request->status, 'data request is pending');

    changeState($order, $triggerState);
    $receipt = receiptOf($order);
    assertTrue(!$receipt || !$receipt->isIssued(), 'no document issued while the request is pending');
    assertSame('held', $receipt ? $receipt->status : null, 'receipt status held');
    assertSame(0, remoteDocumentCount(), 'nothing created in iFirma while held');

    $r = $actions->approveRequest((int) $request->id, 1);
    assertTrue($r['success'], 'approveRequest succeeds', $r['message']);
    assertTrue(!empty($r['data']['issued']), 'approval issued the document immediately', $r['message']);
    $receipt = receiptOf($order);
    assertTrue($receipt && $receipt->isIssued(), 'receipt isIssued');
    assertSame('module', $receipt ? $receipt->source : null, 'receipt source is module');
    $payload = $receipt ? (string) $receipt->request_payload : '';
    $reference = IfirmaPayloadBuilder::buildOrderReference($order);
    assertTrue(strpos($payload, 'Smoke Sp. z o.o.') !== false, 'request_payload carries the override company name');
    assertTrue(strpos($payload, '5250001234') !== false, 'request_payload carries the override NIP');
    assertTrue(strpos($payload, $reference) !== false, 'request_payload carries the order reference');
    assertSame('sent', $receipt ? $receipt->delivery_status : null, 'delivery recorded as sent (PS_MAIL_METHOD 3)');

    if ($receipt && $receipt->isIssued()) {
        $detail = ifirmaClient()->getDetail('fakturakraj', $receipt->ifirma_id);
        assertTrue(
            $detail !== null && strpos($detail['raw'], $reference) !== false,
            'order reference present in the iFirma document detail'
        );
        $remotePayment = ($detail !== null && preg_match('/"SposobZaplaty"\s*:\s*"([^"]*)"/', $detail['raw'], $pm)) ? $pm[1] : null;
        assertSame(
            IfirmaPaymentMethodResolver::build()->resolve($order),
            $remotePayment,
            'payment method follows the order'
        );
    }
}

function scenarioD($module, $actions)
{
    $order = freshScenario('D. Status sync and cron');
    if (!$order) {
        return;
    }
    $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
    $receipt = receiptOf($order);
    if (!assertTrue($result->ok && $receipt && $receipt->isIssued(), 'document issued for the sync scenario', $result->message)) {
        return;
    }

    $gross = round((float) $order->total_paid_tax_incl, 2);
    $r = $actions->updateStatus((int) $order->id, $gross);
    assertTrue($r['success'], 'updateStatus registers the payment', $r['message']);
    $r = $actions->refreshStatus((int) $order->id);
    assertTrue($r['success'], 'refreshStatus succeeds', $r['message']);
    $receipt = receiptOf($order);
    assertSame('paid', $receipt ? $receipt->status : null, 'receipt status paid');
    assertTrue(
        $receipt && abs((float) $receipt->remote_paid_amount - $gross) < 0.02,
        'remote_paid_amount synced',
        'got ' . ($receipt ? $receipt->remote_paid_amount : 'n/a')
    );

    $service = IfirmaStatusSyncService::build($module);
    assertSame(false, $service->isValidCronToken('wrong'), 'wrong cron token rejected');
    $token = (string) Configuration::get('IFIRMA_CRON_TOKEN');
    assertSame(true, $service->isValidCronToken($token), 'configured cron token accepted');

    $del = mockRequest('DELETE', '/__mock/documents/' . rawurlencode((string) $receipt->ifirma_id));
    assertTrue($del['code'] >= 200 && $del['code'] < 300, 'document deleted in the mock', 'HTTP ' . $del['code']);
    $summary = $service->runCron();
    assertTrue(is_array($summary) && (int) $summary['marked_missing'] >= 1, 'cron marks the deleted document missing', json_encode($summary));
    $receipt = receiptOf($order);
    assertSame(true, $receipt ? (bool) $receipt->remote_missing : null, 'receipt remote_missing = 1');

    // Real front controller through the web server (skipped when it is not reachable from here).
    $base = rtrim(getenv('PS_LOCAL_URL') ? getenv('PS_LOCAL_URL') : 'http://localhost', '/')
        . '/index.php?fc=module&module=ifirma&controller=cron&token=';
    $bad = httpRequest('GET', $base . 'wrong');
    if (!in_array($bad['code'], array(200, 403), true)) {
        skip('cron URL over HTTP', 'localhost answered HTTP ' . $bad['code'] . ' (set PS_LOCAL_URL)');

        return;
    }
    assertSame(403, $bad['code'], 'cron URL with a wrong token answers 403');
    $good = httpRequest('GET', $base . rawurlencode($token));
    assertSame(200, $good['code'], 'cron URL with the right token answers 200');
    assertTrue(
        is_array($good['data']) && isset($good['data']['receipts_checked']),
        'cron URL returns the JSON summary',
        substr((string) $good['raw'], 0, 120)
    );
}

function scenarioE($module)
{
    $order = freshScenario('E. Customer visibility');
    if (!$order) {
        return;
    }
    $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
    $receipt = receiptOf($order);
    if (!assertTrue($result->ok && $receipt && $receipt->isIssued(), 'document issued for the visibility scenario', $result->message)) {
        return;
    }
    $number = (string) $receipt->ifirma_number;
    assertTrue($number !== '', 'document number stored', 'number=' . $number);

    $context = Context::getContext();
    $owner = new Customer((int) $order->id_customer);
    $owner->logged = 1;
    $context->customer = $owner;
    $html = (string) $module->hookDisplayOrderDetail(array('order' => $order));
    assertTrue($number !== '' && strpos($html, $number) !== false, 'owner sees the document number on the order page');

    $other = new Customer();
    $other->id = (int) $order->id_customer + 100000; // not persisted; the hook only compares ids
    $other->logged = 1;
    $context->customer = $other;
    assertSame('', trim((string) $module->hookDisplayOrderDetail(array('order' => $order))), 'another customer sees nothing');
    $context->customer = $owner;
}

function scenarioF($module)
{
    $order = freshScenario('F. KSeF submission for a B2B order');
    if (!$order) {
        return;
    }
    $address = new Address((int) $order->id_address_invoice);
    $original = array('vat_number' => $address->vat_number, 'dni' => $address->dni);
    $address->vat_number = '1234567890';
    $address->update();
    $mode = Configuration::get('IFIRMA_KSEF_MODE');
    Configuration::updateValue('IFIRMA_KSEF_MODE', 'b2b');

    try {
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual', 'skip_delivery' => true));
        $ksef = null;
        foreach ($result->steps as $step) {
            if ($step['step'] === 'ksef') {
                $ksef = $step;
            }
        }
        if (!assertTrue($result->ok && $ksef !== null, 'B2B order issued and the pipeline has a ksef step', $result->message)) {
            return;
        }
        if ($ksef['status'] === 'failed' && (strpos($ksef['message'], '404') !== false || stripos($ksef['message'], 'not found') !== false)) {
            skip('KSeF step against the mock', 'the mock has no POST /iapi/{type}/ksef/send/{id}.json endpoint (HTTP 404)');
        } else {
            assertSame('ok', $ksef['status'], 'ksef step is ok for a B2B order (' . $ksef['message'] . ')');
            $receipt = receiptOf($order);
            assertSame('WYSLANA', $receipt ? (string) $receipt->ksef_status : null, 'receipt records the KSeF submission');
        }
    } finally {
        $address->vat_number = $original['vat_number'];
        $address->dni = $original['dni'];
        $address->update();
        Configuration::updateValue('IFIRMA_KSEF_MODE', $mode === false ? 'b2b' : $mode);
    }
}

function scenarioG($module, $triggerState)
{
    $order = freshScenario('G. PDF not ready, delivery retries');
    if (!$order) {
        return;
    }
    $r = mockRequest('POST', '/__mock/pdf-not-ready', array('calls' => 2));
    assertSame(200, $r['code'], 'mock armed to answer 404 for the next 2 PDF downloads');

    try {
        smokeOut('INFO', 'waiting for the module PDF retries (about 10 s)');
        changeState($order, $triggerState);
        $receipt = receiptOf($order);
        assertTrue($receipt && $receipt->isIssued(), 'receipt issued although the PDF was not ready at first');
        assertSame('sent', $receipt ? $receipt->delivery_status : null, 'delivery recorded as sent after the retries');
        $state = mockRequest('GET', '/__mock/pdf-not-ready');
        assertSame(0, isset($state['data']['remaining']) ? (int) $state['data']['remaining'] : -1, 'both not-ready answers were consumed by retries');
    } finally {
        mockRequest('POST', '/__mock/pdf-not-ready', array('calls' => 0));
    }
}

function scenarioH($module)
{
    $order = freshScenario('H. Contractor renamed by iFirma');
    if (!$order) {
        return;
    }
    $idCustomer = (int) $order->id_customer;
    IfirmaContractorMap::unlinkCustomer($idCustomer);
    $r = mockRequest('POST', '/__mock/contractor-mode', array('create' => 'rename'));
    assertSame(200, $r['code'], 'mock contractor-mode set to rename');

    try {
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual', 'skip_delivery' => true));
        $receipt = receiptOf($order);
        if (!assertTrue($result->ok && $receipt && $receipt->isIssued(), 'document issued for a customer iFirma renames', $result->message)) {
            return;
        }
        $payload = json_decode((string) $receipt->request_payload, true);
        $sentId = is_array($payload) && isset($payload['IdentyfikatorKontrahenta']) ? (string) $payload['IdentyfikatorKontrahenta'] : '';
        assertTrue(strpos($sentId, 'IFI') === 0, 'document references the identifier iFirma assigned (not PS-C)', 'IdentyfikatorKontrahenta=' . $sentId);
        $contractor = is_array($payload) && isset($payload['_contractor']) && is_array($payload['_contractor']) ? $payload['_contractor'] : array();
        assertSame('created', isset($contractor['action']) ? $contractor['action'] : null, '_contractor.action is created');
        assertSame($sentId, isset($contractor['assigned_identifier']) ? (string) $contractor['assigned_identifier'] : null, '_contractor.assigned_identifier equals the identifier on the document');

        $map = IfirmaContractorMap::getByCustomer($idCustomer);
        assertSame($sentId, $map ? (string) $map->ifirma_identifier : null, 'contractor map holds the assigned identifier');
        assertSame('module', $map ? (string) $map->matched_by : null, 'contractor map matched_by is module');

        $html = (string) $module->hookDisplayAdminOrderMainBottom(array('id_order' => (int) $order->id));
        assertTrue($sentId !== '' && strpos($html, $sentId) !== false, 'admin order panel shows the assigned identifier');
    } finally {
        mockRequest('POST', '/__mock/contractor-mode', array('create' => 'normal'));
    }
}

function scenarioI($module, $actions)
{
    $order = freshScenario('I. Customer panel: search, link, issue, count, unlink');
    if (!$order) {
        return;
    }
    $idCustomer = (int) $order->id_customer;
    $customer = new Customer($idCustomer);
    $address = new Address((int) $order->id_address_invoice);
    $phone = trim((string) $address->phone) !== '' ? (string) $address->phone : (string) $address->phone_mobile;
    if ($phone === '') {
        $phone = '+48 600 700 800';
    }
    $fullName = trim($customer->firstname . ' ' . $customer->lastname);
    $identifier = 'KOWALSKI-01';

    IfirmaContractorMap::unlinkCustomer($idCustomer);
    try {
        $seed = mockRequest('POST', '/__mock/contractors', array(
            'Identyfikator' => $identifier,
            'Nazwa' => $fullName,
            'Telefon' => $phone,
            'KodPocztowy' => '00-001',
            'Miejscowosc' => 'Warszawa',
            'Ulica' => 'Testowa 1',
            'Kraj' => 'Polska',
        ));
        assertSame(200, $seed['code'], 'mock contractor ' . $identifier . ' seeded');

        $search = $actions->customerSearch(array(
            'firstname' => $customer->firstname,
            'lastname' => $customer->lastname,
            'company' => (string) $customer->company,
            'nip' => '',
            'email' => '',
            'phone' => $phone,
        ));
        assertTrue(!empty($search['success']), 'customerSearch succeeded', isset($search['message']) ? $search['message'] : '');
        $hit = null;
        $rows = isset($search['data']['search']['rows']) ? $search['data']['search']['rows'] : array();
        foreach ($rows as $row) {
            if (isset($row['identifier']) && $row['identifier'] === $identifier) {
                $hit = $row;
            }
        }
        assertTrue($hit !== null, 'customerSearch lists ' . $identifier, json_encode(array_column($rows, 'identifier')));
        assertSame(true, $hit && isset($hit['flags']['phone']) ? $hit['flags']['phone'] : null, 'listed contractor carries the phone flag');
        assertSame(true, $hit && isset($hit['flags']['name']) ? $hit['flags']['name'] : null, 'listed contractor carries the name flag');

        $link = $actions->customerLink($idCustomer, $identifier);
        assertTrue(!empty($link['success']), 'customerLink succeeded', isset($link['message']) ? $link['message'] : '');
        $map = IfirmaContractorMap::getByCustomer($idCustomer);
        assertSame($identifier, $map ? (string) $map->ifirma_identifier : null, 'map row holds ' . $identifier);
        assertSame('manual', $map ? (string) $map->matched_by : null, 'map row matched_by is manual');

        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual', 'skip_delivery' => true));
        $receipt = receiptOf($order);
        if (assertTrue($result->ok && $receipt && $receipt->isIssued(), 'document issued for the linked customer', $result->message)) {
            $payload = json_decode((string) $receipt->request_payload, true);
            $sent = '';
            if (is_array($payload)) {
                if (isset($payload['IdentyfikatorKontrahenta'])) {
                    $sent = (string) $payload['IdentyfikatorKontrahenta'];
                } elseif (isset($payload['Kontrahent']['Identyfikator'])) {
                    $sent = (string) $payload['Kontrahent']['Identyfikator'];
                }
            }
            assertSame($identifier, $sent, 'sent payload references ' . $identifier);
        }

        $count = $actions->customerCountRemote($idCustomer);
        assertTrue(!empty($count['success']), 'customerCountRemote succeeded', isset($count['message']) ? $count['message'] : '');
        $c = isset($count['data']['count']) ? $count['data']['count'] : array();
        assertTrue(isset($c['total']) && (int) $c['total'] >= 1, 'remote count total >= 1', json_encode($c));
        assertSame(true, isset($c['complete']) ? (bool) $c['complete'] : null, 'remote count is complete');

        $unlink = $actions->customerUnlink($idCustomer);
        assertTrue(!empty($unlink['success']), 'customerUnlink succeeded', isset($unlink['message']) ? $unlink['message'] : '');
        assertTrue(IfirmaContractorMap::getByCustomer($idCustomer) === null || !IfirmaContractorMap::getByCustomer($idCustomer), 'no map row after unlink');
    } finally {
        IfirmaContractorMap::unlinkCustomer($idCustomer);
        mockReset();
    }
}

// ---------------------------------------------------------------- admin receipt operations (1.5.0)

/** A throw-away module record (no iFirma call); the caller deletes it. */
function seedReceipt($idOrder, $idCustomer, $status, array $extra = array())
{
    $r = new IfirmaReceipt();
    $r->id_order = (int) $idOrder;
    $r->id_customer = (int) $idCustomer;
    $r->id_shop = 1;
    $r->status = $status;
    $r->document_type = 'fakturakraj';
    $r->source = 'module';
    $r->security_token = Tools::passwdGen(32);
    foreach ($extra as $k => $v) {
        $r->$k = $v;
    }
    $r->add();

    return $r;
}

/** @return array array('profile' => Profile, 'employee' => Employee) of a profile that holds no roles of the module */
function createRolelessEmployee($tag)
{
    $profile = new Profile();
    foreach (Language::getLanguages(false) as $l) {
        $profile->name[$l['id_lang']] = 'ifirma-smoke-' . $tag;
    }
    $profile->add();
    $employee = new Employee();
    $employee->firstname = 'Smoke';
    $employee->lastname = ucfirst($tag);
    $employee->email = 'ifirma-smoke-' . $tag . '@example.com';
    $employee->passwd = Tools::hash('Passw0rd!x');
    $employee->id_profile = (int) $profile->id;
    $employee->id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
    $employee->active = 1;
    $employee->add();

    return array('profile' => $profile, 'employee' => $employee);
}

function dropRolelessEmployee(array $pair)
{
    $db = Db::getInstance();
    if ($pair['employee'] && (int) $pair['employee']->id) {
        $pair['employee']->delete();
    }
    if ($pair['profile'] && (int) $pair['profile']->id) {
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'access` WHERE id_profile = ' . (int) $pair['profile']->id);
        $pair['profile']->delete();
    }
}

function scenarioJ($module)
{
    $order1 = freshScenario('J. Bulk runner: retry, resend, refresh');
    if (!$order1) {
        return;
    }
    $order2 = createOrder();
    $order3 = createOrder();
    if (!assertTrue($order2 instanceof Order && $order3 instanceof Order, 'two more orders created')) {
        return;
    }
    $made = array();
    // the duplicate guard would flag the second order as a same-buyer, same-amount document of the first one
    // once the first is issued; a different total keeps the two retries independent
    $order2->total_paid_tax_incl = $order2->total_paid = round((float) $order2->total_paid_tax_incl + 5, 2);
    $order2->total_paid_tax_excl = round((float) $order2->total_paid_tax_excl + 5 / 1.23, 6);
    $order2->update();
    try {
        $r1 = seedReceipt($order1->id, $order1->id_customer, IfirmaReceipt::STATUS_ERROR, array('last_error' => 'smoke error'));
        $r2 = seedReceipt($order2->id, $order2->id_customer, IfirmaReceipt::STATUS_UNKNOWN, array('last_error' => 'smoke unknown'));
        $made = array($r1, $r2);
        $runner = new IfirmaBulkRunner($module);

        $window = (string) Configuration::get('IFIRMA_RATE_WINDOW');
        $res = $runner->run('retry', array((int) $r1->id, (int) $r2->id), 1, array('final' => true));
        $outcomes = array();
        foreach ($res['results'] as $row) {
            $outcomes[(int) $row['id']] = $row['outcome'];
        }
        smokeOut('INFO', 'retry outcomes ' . json_encode($outcomes) . ', stopped ' . json_encode($res['stopped']));
        foreach (array($r1, $r2) as $rec) {
            $o = isset($outcomes[(int) $rec->id]) ? $outcomes[(int) $rec->id] : null;
            assertTrue(in_array($o, array('issued', 'adopted'), true), 'retry of record ' . $rec->id . ' (' . $rec->status . ') ends issued or adopted', var_export($o, true));
        }
        assertSame('', $res['stopped'], 'retry run was not stopped');
        assertSame(0, count($res['remaining']), 'retry run left nothing remaining');
        assertTrue((string) Configuration::get('IFIRMA_RATE_WINDOW') !== $window, 'IFIRMA_RATE_WINDOW moved during the retry', $window . ' -> ' . Configuration::get('IFIRMA_RATE_WINDOW'));
        $issued1 = IfirmaReceipt::getByOrder((int) $order1->id);
        $issued2 = IfirmaReceipt::getByOrder((int) $order2->id);
        assertTrue($issued1 && $issued1->isIssued() && $issued2 && $issued2->isIssued(), 'both retried records are issued now');

        // an issued record whose delivery failed, with its document in the mock
        $docId = seedDocument(array("Waluta" => "PLN"));
        assertTrue($docId !== '', 'a mock document was seeded for the delivery-failed record', $docId);
        $r3 = seedReceipt($order3->id, $order3->id_customer, IfirmaReceipt::STATUS_CREATED, array(
            'ifirma_id' => $docId,
            'ifirma_number' => '1/2026',
            'issue_date' => date('Y-m-d'),
            'delivery_status' => IfirmaReceipt::DELIVERY_FAILED,
            'delivery_channel' => 'shop_mail',
            'delivery_error' => 'smoke failure',
        ));
        $made[] = $r3;

        $res = $runner->run('resend', array((int) $r3->id), 1, array('final' => true));
        $row = $res['results'] ? $res['results'][0] : array('outcome' => null, 'message' => '');
        assertSame('sent', $row['outcome'], 'resend of the delivery-failed record');
        $r3b = new IfirmaReceipt((int) $r3->id);
        assertSame('sent', $r3b->delivery_status, 'delivery_status is sent after the resend');

        $ids = array((int) $issued1->id, (int) $issued2->id, (int) $r3->id);
        $res = $runner->run('refresh', $ids, 1, array('final' => true));
        $outcomes = array();
        foreach ($res['results'] as $row) {
            $outcomes[(int) $row['id']] = $row['outcome'];
        }
        smokeOut('INFO', 'refresh outcomes ' . json_encode($outcomes));
        foreach ($ids as $id) {
            assertSame('refreshed', isset($outcomes[$id]) ? $outcomes[$id] : null, 'refresh of record ' . $id);
        }
    } finally {
        foreach ($made as $rec) {
            $rec->delete();
        }
        if (isset($issued1) && $issued1) {
            $issued1->delete();
        }
        if (isset($issued2) && $issued2) {
            $issued2->delete();
        }
        mockReset();
    }
}

function scenarioK($module)
{
    $order = freshScenario('K. Issue feed: latest, unseen count, mark seen');
    if (!$order) {
        return;
    }
    $feed = new IfirmaIssueFeed();
    $seenKey = IfirmaIssueFeed::SEEN_KEY_PREFIX . 1;
    $previousSeen = Configuration::get($seenKey);
    $failed = seedReceipt($order->id, $order->id_customer, IfirmaReceipt::STATUS_ERROR, array('last_error' => 'smoke feed error'));
    $deliveryFailed = seedReceipt($order->id, $order->id_customer, IfirmaReceipt::STATUS_CREATED, array(
        'ifirma_id' => '999001', 'ifirma_number' => 'SMOKE/1', 'delivery_status' => IfirmaReceipt::DELIVERY_FAILED,
    ));
    try {
        $kinds = array();
        foreach ($feed->latest(100) as $item) {
            $kinds[(int) $item['id_ifirma_receipt']] = $item['kind'];
        }
        assertSame(IfirmaIssueFeed::KIND_ERROR, isset($kinds[(int) $failed->id]) ? $kinds[(int) $failed->id] : null, 'latest() lists the failed record with kind error');
        assertSame(IfirmaIssueFeed::KIND_DELIVERY_FAILED, isset($kinds[(int) $deliveryFailed->id]) ? $kinds[(int) $deliveryFailed->id] : null, 'latest() lists the delivery-failed record with kind delivery_failed');
        assertTrue($feed->unseenCount('') >= 2, "unseenCount('') counts the seeded records", (string) $feed->unseenCount(''));
        $feed->markSeen(1);
        assertTrue($feed->seenAt(1) !== '', 'markSeen stored a time', $feed->seenAt(1));
        assertSame(0, $feed->unseenCount($feed->seenAt(1)), 'unseenCount(seenAt) is 0 after markSeen');
    } finally {
        $failed->delete();
        $deliveryFailed->delete();
        if ($previousSeen === false || $previousSeen === null || $previousSeen === '') {
            Configuration::deleteByName($seenKey);
        } else {
            Configuration::updateValue($seenKey, $previousSeen);
        }
    }
}

class SmokeJsonOut extends Exception
{
    public $payload;

    public function __construct(array $payload)
    {
        parent::__construct('json');
        $this->payload = $payload;
    }
}

class SmokeTextOut extends Exception
{
    public $status;
    public $text;

    public function __construct($status, $text)
    {
        parent::__construct('text');
        $this->status = $status;
        $this->text = $text;
    }
}

function scenarioL($module)
{
    section('L. Permissions through the admin controller');
    require_once _PS_MODULE_DIR_ . 'ifirma/controllers/admin/AdminIfirmaController.php';
    if (!class_exists('SmokeIfirmaController', false)) {
        eval('class SmokeIfirmaController extends AdminIfirmaController {
            public $statuses = array();
            public $called = array();
            public function wire($module, $context) { $this->module = $module; $this->context = $context; }
            protected function output(array $payload) { throw new SmokeJsonOut($payload); }
            protected function textResponse($status, $text) { throw new SmokeTextOut($status, $text); }
            protected function statusHeader($code) { $this->statuses[] = $code; }
            protected function actions() { return new SmokeRecordingActions($this->called); }
        }');
        eval('class SmokeRecordingActions {
            private $log;
            public function __construct(array &$log) { $this->log = &$log; }
            public function createReceipt($o, $e) { $this->log[] = "createReceipt"; return array("success" => true, "message" => "recorded", "data" => array()); }
            public function resend($o) { $this->log[] = "resend"; return array("success" => true, "message" => "recorded", "data" => array()); }
        }');
    }

    $pair = createRolelessEmployee('perm');
    $context = Context::getContext();
    $original = $context->employee;
    $profileId = (int) $pair['profile']->id;
    try {
        assertTrue($profileId > 0 && (int) $pair['employee']->id > 0, 'throw-away profile and employee created', 'profile ' . $profileId);
        $context->employee = $pair['employee'];
        $token = Tools::getAdminTokenLite('AdminIfirma');
        $_GET['token'] = $_POST['token'] = $token;
        $_POST['id_order'] = $_GET['id_order'] = '1';

        $dispatch = function ($method) use ($context, $module) {
            $c = (new ReflectionClass("SmokeIfirmaController"))->newInstanceWithoutConstructor();
            $c->wire($module, $context);
            try {
                $c->$method();
            } catch (SmokeJsonOut $j) {
                return array('kind' => 'json', 'payload' => $j->payload, 'statuses' => $c->statuses, 'called' => $c->called);
            } catch (SmokeTextOut $t) {
                return array('kind' => 'text', 'status' => $t->status, 'text' => $t->text, 'statuses' => $c->statuses, 'called' => $c->called);
            }

            return array('kind' => 'none', 'statuses' => $c->statuses, 'called' => $c->called);
        };

        foreach (array('ajaxProcessCreateReceipt' => 'CreateReceipt', 'ajaxProcessResendDocument' => 'ResendDocument') as $method => $name) {
            $out = $dispatch($method);
            assertTrue($out['kind'] === 'json' && $out['payload']['success'] === false && $out['statuses'] === array(403) && !$out['called'],
                $name . ' without roles answers the 403 failure envelope', json_encode($out));
            smokeOut('INFO', $name . ' message: ' . (isset($out['payload']['message']) ? $out['payload']['message'] : ''));
        }
        $out = $dispatch('ajaxProcessDownloadPdf');
        assertTrue($out['kind'] === 'text' && $out['status'] === 403 && $out['text'] !== '', 'DownloadPdf without roles answers 403 with a text', json_encode($out));

        // grant view + issue
        $db = Db::getInstance();
        $db->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'access` (id_profile, id_authorization_role) SELECT ' . $profileId . ", id_authorization_role FROM `" . _DB_PREFIX_
            . "authorization_role` WHERE slug IN ('ROLE_MOD_TAB_ADMINIFIRMA_READ', 'ROLE_MOD_TAB_ADMINIFIRMA_CREATE')");
        assertTrue(IfirmaPermission::canView($pair['employee']) && IfirmaPermission::canIssue($pair['employee']) && !IfirmaPermission::canManage($pair['employee']),
            'the profile now holds view and issue, not manage');

        $out = $dispatch('ajaxProcessCreateReceipt');
        assertTrue($out['kind'] === 'json' && $out['payload']['success'] === true && $out['called'] === array('createReceipt') && !$out['statuses'],
            'CreateReceipt with READ + CREATE is dispatched (no 403)', json_encode($out));
        $out = $dispatch('ajaxProcessResendDocument');
        assertTrue($out['kind'] === 'json' && $out['payload']['success'] === false && $out['statuses'] === array(403) && !$out['called'],
            'ResendDocument with READ + CREATE is still 403', json_encode($out));
    } finally {
        $context->employee = $original;
        unset($_GET['token'], $_POST['token'], $_POST['id_order'], $_GET['id_order']);
        dropRolelessEmployee($pair);
    }
    assertSame(0, (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'access` WHERE id_profile = ' . $profileId), 'throw-away profile has no access rows left');
}

function scenarioM($module)
{
    $order = freshScenario('M. Customer download throttle');
    if (!$order) {
        return;
    }
    $db = Db::getInstance();
    $fakeCustomer = 99999;
    $made = array();
    try {
        $receipt = seedReceipt($order->id, $fakeCustomer, IfirmaReceipt::STATUS_CREATED, array('ifirma_id' => '999002', 'ifirma_number' => 'SMOKE/2'));
        $made[] = $receipt;
        $now = time();
        $throttle = new IfirmaDownloadThrottle();
        assertTrue($throttle->allows($receipt), 'a first download is allowed');
        $throttle->stamp($receipt);
        assertTrue(!empty($receipt->last_download_at), 'stamp() sets last_download_at', (string) $receipt->last_download_at);
        assertSame(false, $throttle->allows($receipt), 'the second download within 30 s is refused');
        $later = new IfirmaDownloadThrottle(function () use ($now) {
            return $now + IfirmaDownloadThrottle::DOCUMENT_WINDOW + 1;
        });
        assertSame(true, $later->allows($receipt), 'the same document is allowed again after 30 s');

        // the per-customer count: ten stamped documents of the customer in the last minute refuse an eleventh
        for ($i = 0; $i < IfirmaDownloadThrottle::CUSTOMER_MAX - 1; $i++) {
            $extra = seedReceipt($order->id, $fakeCustomer, IfirmaReceipt::STATUS_CREATED, array('ifirma_id' => '99910' . $i));
            $throttle->stamp($extra);
            $made[] = $extra;
        }
        $count = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE id_customer = ' . $fakeCustomer . " AND last_download_at >= '" . pSQL(date('Y-m-d H:i:s', $now - 60)) . "'");
        assertSame(IfirmaDownloadThrottle::CUSTOMER_MAX, $count, 'ten documents of the customer carry a download stamp in the last minute');
        $fresh = seedReceipt($order->id, $fakeCustomer, IfirmaReceipt::STATUS_CREATED, array('ifirma_id' => '999200'));
        $made[] = $fresh;
        assertSame(false, $throttle->allows($fresh), 'an eleventh document of the customer is refused although it was never downloaded');
        $afterWindow = new IfirmaDownloadThrottle(function () use ($now) {
            return $now + IfirmaDownloadThrottle::CUSTOMER_WINDOW + 5;
        });
        assertSame(true, $afterWindow->allows($fresh), 'it is allowed once the minute has passed');
    } finally {
        foreach ($made as $rec) {
            $rec->delete();
        }
        mockReset();
    }
}

$scenarios = array(
    'J' => function () use ($module) {
        scenarioJ($module);
    },
    'K' => function () use ($module) {
        scenarioK($module);
    },
    'L' => function () use ($module) {
        scenarioL($module);
    },
    'M' => function () use ($module) {
        scenarioM($module);
    },
    'A' => function () use ($module, $actions) {
        scenarioA($module, $actions);
    },
    'A2' => function () use ($module) {
        scenarioA2($module);
    },
    'B' => function () use ($module) {
        scenarioB($module);
    },
    'C' => function () use ($module, $actions, $triggerState) {
        scenarioC($module, $actions, $triggerState);
    },
    'D' => function () use ($module, $actions) {
        scenarioD($module, $actions);
    },
    'E' => function () use ($module) {
        scenarioE($module);
    },
    'F' => function () use ($module) {
        scenarioF($module);
    },
    'G' => function () use ($module, $triggerState) {
        scenarioG($module, $triggerState);
    },
    'H' => function () use ($module) {
        scenarioH($module);
    },
    'I' => function () use ($module, $actions) {
        scenarioI($module, $actions);
    },
);

$only = getenv('SMOKE_ONLY') ? strtoupper(getenv('SMOKE_ONLY')) : '';
foreach ($scenarios as $name => $fn) {
    if ($only !== '' && strpos($only, $name) === false) {
        continue;
    }
    try {
        $fn();
    } catch (Exception $e) {
        assertTrue(false, 'scenario ' . $name . ' ran without exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    } catch (Throwable $e) {
        assertTrue(false, 'scenario ' . $name . ' ran without error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

// ---------------------------------------------------------------- summary

$s = $GLOBALS['smoke'];
echo "\n=== Summary ===\n";
echo 'passed ' . $s['ok'] . ', failed ' . $s['fail'] . ', skipped ' . $s['skip'] . "\n";
foreach ($s['failures'] as $label) {
    echo '  FAILED: ' . $label . "\n";
}
exit($s['fail'] > 0 ? 1 : 0);
