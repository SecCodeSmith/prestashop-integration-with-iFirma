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

// Boot the Symfony kernel: PrestaShop 1.7.7+/8/9 resolve hooks and services through the
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
foreach (array('IfirmaApiClient', 'IfirmaReceipt', 'IfirmaDataRequest', 'IfirmaReceiptService', 'IfirmaIssuancePipeline',
    'IfirmaDuplicateGuard', 'IfirmaDeliveryService', 'IfirmaStatusSyncService', 'IfirmaDataRequestService', 'IfirmaAdminActions') as $class) {
    if (!class_exists($class)) {
        require_once _PS_MODULE_DIR_ . 'ifirma/classes/' . $class . '.php';
    }
}

// ---------------------------------------------------------------- configuration for the mock

Configuration::updateValue('IFIRMA_BASE_URL', $mockUrl);
Configuration::updateValue('IFIRMA_USER', 'test@example.com');
Configuration::updateValue('IFIRMA_KEY_FAKTURA', str_repeat('1', 64));
Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturaparagon');
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

function scenarioA($module, $actions)
{
    $order = freshScenario('A. Duplicate guard: suspected, then link');
    if (!$order) {
        return;
    }
    $seedId = seedDocument(array(
        'doc_type' => 'fakturaparagon',
        'contractor' => array('Nazwa' => 'Smoke Customer', 'Identyfikator' => 'PS-C' . (int) $order->id_customer),
        'gross' => round((float) $order->total_paid_tax_incl, 2),
        'currency' => currencyIso($order),
        'created_by' => 'external',
    ));
    assertTrue($seedId !== '', 'external document seeded', 'id=' . $seedId);

    $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
    assertSame('suspected', $result->outcome, 'pipeline outcome is suspected');
    $receipt = receiptOf($order);
    assertSame('duplicate_suspected', $receipt ? $receipt->status : null, 'receipt status duplicate_suspected');
    assertTrue($receipt && count($receipt->getDuplicateCandidates()) > 0, 'duplicate candidates recorded');
    assertSame(1, remoteDocumentCount(), 'no module-created document in iFirma (1 document: the seeded one)');

    $r = $actions->linkCandidate((int) $order->id, $seedId, 1);
    assertTrue($r['success'], 'linkCandidate succeeds', $r['message']);
    $receipt = receiptOf($order);
    assertSame('external', $receipt ? $receipt->source : null, 'linked receipt source is external');
    assertSame($seedId, $receipt ? (string) $receipt->ifirma_id : null, 'linked receipt carries the seeded id');
    assertTrue($receipt && $receipt->isIssued(), 'linked receipt isIssued');
}

function scenarioB($module)
{
    $order = freshScenario('B. Duplicate guard: adopt by order reference');
    if (!$order) {
        return;
    }
    $seedId = seedDocument(array(
        'doc_type' => 'fakturaparagon',
        'contractor' => array('Nazwa' => 'Other Name', 'Identyfikator' => 'EXT1'),
        'gross' => round((float) $order->total_paid_tax_incl, 2),
        'currency' => currencyIso($order),
        'uwagi' => IfirmaReceiptService::buildOrderReference($order),
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
    $reference = IfirmaReceiptService::buildOrderReference($order);
    assertTrue(strpos($payload, 'Smoke Sp. z o.o.') !== false, 'request_payload carries the override company name');
    assertTrue(strpos($payload, '5250001234') !== false, 'request_payload carries the override NIP');
    assertTrue(strpos($payload, $reference) !== false, 'request_payload carries the order reference');
    assertSame('sent', $receipt ? $receipt->delivery_status : null, 'delivery recorded as sent (PS_MAIL_METHOD 3)');

    if ($receipt && $receipt->isIssued()) {
        $detail = ifirmaClient()->getDetail('fakturaparagon', $receipt->ifirma_id);
        assertTrue(
            $detail !== null && strpos($detail['raw'], $reference) !== false,
            'order reference present in the iFirma document detail'
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

$scenarios = array(
    'A' => function () use ($module, $actions) {
        scenarioA($module, $actions);
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
