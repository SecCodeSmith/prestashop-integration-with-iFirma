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

require_once __DIR__ . '/SmokeSelection.php';
require_once __DIR__ . '/SmokeCheckFloor.php';
require_once __DIR__ . '/SmokeOutputMask.php';

// Every scenario name, in run order. The map of scenario bodies further down must hold exactly these keys.
$smokeScenarioNames = array('J', 'K', 'L', 'M', 'A', 'A2', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'N', 'O', 'P', 'Q', 'R', 'S');

// Decided before the shop is touched: a typo in the selection must not change any shop setting.
try {
    $smokeSelection = SmokeSelection::parse((string) getenv('SMOKE_ONLY'), (string) getenv('SMOKE_SKIP'), $smokeScenarioNames);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
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

$GLOBALS['smokeSecrets'] = array();

// The output is kept as a public CI artifact; keys and tokens are shown by their last four characters only.
function smokeOut($tag, $label, $detail = '')
{
    echo SmokeOutputMask::mask(
        '[' . $tag . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n",
        $GLOBALS['smokeSecrets']
    );
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

/**
 * Resets the mock. The reset restarts the document numbering, so a record of an earlier scenario that still waits for
 * automation (a document waiting for KSeF, a queued issuance) would from now on be matched by the cron with a different
 * document that carries the same id and number. Nothing in a real shop does this (iFirma never reuses an id); the
 * harness removes the leftovers from the cron's work at the point where it makes them inconsistent.
 */
function mockReset()
{
    $r = mockRequest('POST', '/__mock/reset');
    if ($r['code'] < 200 || $r['code'] >= 300) {
        smokeOut('FAIL', 'mock reset', 'HTTP ' . $r['code'] . ' (is the ifirma-mock service up? IFIRMA_MOCK_HOST)');
        exit(2);
    }
    smokeClearCronWork();
}

// ---------------------------------------------------------------- mail sink access

/** @return array the sink's message listing, newest first (empty when the sink does not answer) */
function mailbox()
{
    $r = mockRequest('GET', '/__mock/mail');

    return ($r['code'] === 200 && is_array($r['data']) && isset($r['data']['mail']) && is_array($r['data']['mail'])) ? $r['data']['mail'] : array();
}

/** @return array|null the full record of one captured message */
function mailGet($id)
{
    $r = mockRequest('GET', '/__mock/mail/' . (int) $id);

    return ($r['code'] === 200 && is_array($r['data'])) ? $r['data'] : null;
}

function mailClear()
{
    return mockRequest('DELETE', '/__mock/mail');
}

function mailReject($on)
{
    return mockRequest('POST', '/__mock/mail/reject', array('enabled' => (bool) $on));
}

/** Stops the run (exit 2) when the mock has no mailbox: an old mock image, or the wrong host. */
function mailCheck()
{
    global $mockHost;

    $r = mockRequest('GET', '/__mock/mail');
    if ($r['code'] !== 200) {
        smokeOut('FAIL', 'mail sink', 'HTTP ' . $r['code'] . ' at ' . $mockHost . '/__mock/mail (rebuild the ifirma-mock image: docker compose up -d --build --no-deps ifirma-mock)');
        exit(2);
    }
}

/**
 * The records among $list (full records, fetched one by one) that satisfy $filter.
 *
 * @param callable $filter function (array $fullRecord): bool
 * @return array full records, newest first
 */
function mailFind(callable $filter)
{
    $found = array();
    foreach (mailbox() as $row) {
        $full = mailGet($row['id']);
        if ($full !== null && $filter($full)) {
            $found[] = $full;
        }
    }

    return $found;
}

/**
 * One [ OK ] / [FAIL] per fact. Keys of $expected (all optional): from, to (array), bcc (array),
 * rcpt_tos (array, the SMTP envelope), subject_contains, text_contains (array), html_contains (array),
 * attachment_count (int), inline_count (int, the sink's "inline" list: cid: parts), attachments (list of array('filename' => ..., 'content_type' => ...)).
 * Addresses are compared lower-cased, the sink lower-cases them too.
 */
function assertMail(array $mail, array $expected, $label)
{
    $lower = function ($list) {
        return array_map('strtolower', array_values((array) $list));
    };
    if (isset($expected['from'])) {
        assertSame(strtolower($expected['from']), strtolower((string) (isset($mail['from']) ? $mail['from'] : '')), $label . ': from');
    }
    foreach (array('to', 'bcc') as $field) {
        if (isset($expected[$field])) {
            assertSame($lower($expected[$field]), $lower(isset($mail[$field]) ? $mail[$field] : array()), $label . ': ' . $field);
        }
    }
    if (isset($expected['rcpt_tos'])) {
        $rcpt = isset($mail['envelope']['rcpt_tos']) ? $mail['envelope']['rcpt_tos'] : array();
        assertSame($lower($expected['rcpt_tos']), $lower($rcpt), $label . ': envelope recipients');
    }
    if (isset($expected['subject_contains'])) {
        $subject = (string) (isset($mail['subject']) ? $mail['subject'] : '');
        assertTrue(strpos($subject, $expected['subject_contains']) !== false, $label . ': subject contains "' . $expected['subject_contains'] . '"', 'subject: ' . $subject);
    }
    foreach (array('text', 'html') as $part) {
        $key = $part . '_contains';
        foreach (isset($expected[$key]) ? $expected[$key] : array() as $needle) {
            $body = (string) (isset($mail[$part]) ? $mail[$part] : '');
            assertTrue($body !== '' && strpos($body, $needle) !== false, $label . ': ' . $part . ' part contains "' . $needle . '"', $body === '' ? 'the part is empty' : substr($body, 0, 160));
        }
    }
    $attachments = isset($mail['attachments']) && is_array($mail['attachments']) ? $mail['attachments'] : array();
    if (isset($expected['attachment_count'])) {
        assertSame((int) $expected['attachment_count'], count($attachments), $label . ': attachment count');
    }
    if (isset($expected['inline_count'])) {
        $inline = isset($mail['inline']) && is_array($mail['inline']) ? $mail['inline'] : array();
        assertSame((int) $expected['inline_count'], count($inline), $label . ': inline part count');
    }
    foreach (isset($expected['attachments']) ? $expected['attachments'] : array() as $i => $att) {
        $got = isset($attachments[$i]) ? $attachments[$i] : array();
        foreach ($att as $k => $v) {
            assertSame($v, isset($got[$k]) ? $got[$k] : null, $label . ': attachment ' . $i . ' ' . $k);
        }
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
 * addWithemail(true) is the back office call; the status mails it sends land in the mail sink.
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
    mailClear();
    // The whole run sends more than the module's 80 requests per minute guard allows; each scenario
    // starts with a fresh window so the guard does not fail scenarios that are not testing it.
    smokeClearRateWindow();
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
    'IfirmaBulkRunner', 'IfirmaIssueFeed', 'IfirmaDownloadThrottle', 'IfirmaPermission', 'IfirmaShopLink', 'IfirmaGuestCodeService',
    'IfirmaDataRequestAutoAccept', 'IfirmaSchema') as $class) {
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
// Mail goes over SMTP to the mock's mail sink (see tests/smoke/README.md); the settings stay after the run.
$smtpHost = getenv('IFIRMA_SMTP_HOST') ? getenv('IFIRMA_SMTP_HOST') : 'ifirma-mock';
$smtpPort = getenv('IFIRMA_SMTP_PORT') ? (int) getenv('IFIRMA_SMTP_PORT') : 1025;
Configuration::updateValue('PS_MAIL_METHOD', 2);
Configuration::updateValue('PS_MAIL_SERVER', $smtpHost);
Configuration::updateValue('PS_MAIL_SMTP_PORT', $smtpPort);
Configuration::updateValue('PS_MAIL_SMTP_ENCRYPTION', 'off');
Configuration::updateValue('PS_MAIL_USER', '');
Configuration::updateValue('PS_MAIL_PASSWD', '');
Configuration::updateValue('PS_MAIL_TYPE', 3); // HTML + the template's text part
Configuration::updateValue('PS_ORDER_OUT_OF_STOCK', 1);
if ((string) Configuration::get('IFIRMA_CRON_TOKEN') === '' && class_exists('IfirmaSchema')) {
    Configuration::updateValue('IFIRMA_CRON_TOKEN', IfirmaSchema::generateCronToken());
}
$GLOBALS['smokeSecrets'][] = (string) Configuration::get('IFIRMA_CRON_TOKEN');
assertTrue($module->isConfigured(), 'module configured for the mock');
mailCheck();

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
    // earlier smoke runs leave this customer's requests behind; the module allows ten per 24 hours
    // (invoice-data-change-request, "Submission cool-down"), so start the scenario from a clean slate
    Db::getInstance()->delete('ifirma_data_request', 'id_customer = ' . (int) $customer->id);

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

    $reference = IfirmaPayloadBuilder::buildOrderReference($order);
    $merchantTo = trim((string) Configuration::get('IFIRMA_NOTIFY_EMAIL'));
    if ($merchantTo === '') {
        $merchantTo = trim((string) Configuration::get('PS_SHOP_EMAIL'));
    }
    $notice = mailFind(function ($m) use ($order) {
        return strpos((string) $m['subject'], (string) $order->reference) !== false && strpos((string) $m['subject'], 'Invoice data change request') !== false;
    });
    if (assertSame(1, count($notice), 'merchant notification captured at submission')) {
        assertMail($notice[0], array(
            'to' => array($merchantTo),
            'subject_contains' => (string) $order->reference,
            'text_contains' => array('Smoke Sp. z o.o.'),
        ), 'merchant notification');
    }

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
    assertTrue(strpos($payload, 'Smoke Sp. z o.o.') !== false, 'request_payload carries the override company name');
    assertTrue(strpos($payload, '5250001234') !== false, 'request_payload carries the override NIP');
    assertTrue(strpos($payload, $reference) !== false, 'request_payload carries the order reference');
    assertSame('sent', $receipt ? $receipt->delivery_status : null, 'delivery recorded as sent');

    $customerEmail = strtolower((string) $customer->email);
    $approved = mailFind(function ($m) use ($customerEmail) {
        return strpos((string) $m['subject'], 'Your invoice data change was approved') !== false && in_array($customerEmail, array_map('strtolower', (array) $m['to']), true);
    });
    if (assertSame(1, count($approved), 'approval mail to the customer captured')) {
        assertMail($approved[0], array(
            'to' => array($customerEmail),
            'subject_contains' => 'Your invoice data change was approved',
            'text_contains' => array((string) $order->reference),
        ), 'approval mail');
    }
    $delivery = mailFind(function ($m) use ($receipt) {
        return $receipt && (string) $receipt->ifirma_number !== '' && strpos((string) $m['subject'], (string) $receipt->ifirma_number) !== false;
    });
    assertSame(1, count($delivery), 'delivery mail of the issued document captured (order of the two mails not asserted)');

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

    // A second request, on a new order (the mock is not reset), is rejected with a reason.
    $order2 = createOrder();
    if (!assertTrue($order2 instanceof Order && Validate::isLoadedObject($order2), 'second order created for the rejection')) {
        return;
    }
    $address2 = new Address((int) $order2->id_address_invoice);
    $request2 = null;
    try {
        $request2 = IfirmaDataRequestService::build($module)->submit($order2, $customer, array(
            'want_invoice_for_company' => 1,
            'company' => 'Smoke Sp. z o.o.',
            'vat_number' => '525-000-12-34',
            'address1' => $address2->address1,
            'postcode' => $address2->postcode,
            'city' => $address2->city,
            'id_country' => (int) $address2->id_country,
        ));
    } catch (Exception $e) {
        assertTrue(false, 'second data request submitted', $e->getMessage() . ' ' . json_encode(isset($e->errors) ? $e->errors : array()));
    }
    if (!$request2) {
        return;
    }
    mailClear();
    $r = $actions->rejectRequest((int) $request2->id, 1, 'Smoke rejection reason');
    assertTrue($r['success'], 'rejectRequest succeeds', $r['message']);
    $rejected = mailbox();
    if (assertSame(1, count($rejected), 'one mail captured by the rejection')) {
        assertMail(mailGet($rejected[0]['id']), array(
            'to' => array($customerEmail),
            'subject_contains' => 'Your invoice data change was rejected',
            'text_contains' => array((string) $order2->reference, 'Smoke rejection reason'),
        ), 'rejection mail');
    }
}

/** Starts every request counter of the usage statistics from zero (the rows are raised by raw SQL, see smokeIsCounterKey()). */
function smokeClearUsageCounters()
{
    require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRequestKind.php';
    foreach (IfirmaRequestKind::all() as $kind) {
        smokeWriteSetting(IfirmaApiUsage::keyOf($kind), '');
    }
}

/**
 * Seeds a document of the order's buyer in the mock (not a call of the module) and a module record for it, as the
 * module would have left it after an issuance with a delivered mail.
 *
 * @return IfirmaReceipt
 */
function smokeIssuedRecordFor(Order $order)
{
    $documentId = seedDocument(array(
        'doc_type' => 'fakturakraj',
        'contractor' => array('Nazwa' => 'Smoke Customer', 'Identyfikator' => guardIdentifierFor($order)),
        'currency' => currencyIso($order),
        'sale_date' => IfirmaPayloadBuilder::orderSaleDate($order),
        'Pozycje' => seedPositionsFor($order),
        'created_by' => 'external',
    ));
    assertTrue($documentId !== '', 'usage: a document of order #' . (int) $order->id . ' is seeded in the mock', 'id=' . $documentId);

    return seedReceipt((int) $order->id, (int) $order->id_customer, 'created', array(
        'ifirma_id' => $documentId,
        'delivery_status' => 'sent',
        'source' => 'module',
    ));
}

/** @return int the mock's own count of the calls it served since its reset */
function mockCallCount()
{
    $rate = mockRateStatus();

    return isset($rate['calls_total']) ? (int) $rate['calls_total'] : -1;
}

/**
 * Scenario D, part 2: the module's request counts match what the mock received, and a refusal by the module's own
 * minute cap reaches the owner, the employee and the cron without a single request to iFirma.
 */
function smokeUsageAndRefusalChecks($module, $actions)
{
    $first = freshScenario('D. Usage counts and refusals at the minute cap');
    if (!$first) {
        return;
    }
    $second = createOrder();
    if (!assertTrue($second instanceof Order && Validate::isLoadedObject($second), 'usage: second order created')) {
        return;
    }
    if (time() % 60 >= 35) {
        // the counts below must all fall into one minute of the guard
        sleepToNextMinute();
    }
    $previousCap = smokeSetting('IFIRMA_RATE_MAX_PER_MIN', 0);
    try {
        smokeClearUsageCounters();
        smokeClearRateWindow();
        $record = smokeIssuedRecordFor($first);
        $refused = smokeIssuedRecordFor($second);
        $usage = new IfirmaApiUsage();
        assertSame(0, mockCallCount(), 'usage: the mock has served no call yet');
        assertSame(0, array_sum($usage->todayByKind()), 'usage: the module has counted no request yet');

        // Counts match the mock: one status refresh and one PDF download
        $r = $actions->refreshStatus((int) $first->id);
        assertTrue($r['success'], 'usage: the status refresh succeeds', $r['message']);
        $download = smokeDownloadAs($first, $record);
        $overHttp = $download['code'] === 200;
        if ($overHttp) {
            assertSame('%PDF-', substr($download['body'], 0, 5), 'usage: the owner download over HTTP returns a PDF');
        } else {
            skip('usage: PDF download over HTTP', 'the owner could not be logged in over HTTP with the demo password (HTTP ' . $download['code'] . ' at ' . $download['url'] . ')');
            IfirmaReceiptService::build($module)->fetchPdf($record, false);
        }
        $byKind = $usage->todayByKind();
        assertSame(1, $byKind[IfirmaRequestKind::KIND_STATUS], 'usage: the module counted 1 request of kind status');
        assertSame(1, $byKind[IfirmaRequestKind::KIND_PDF], 'usage: the module counted 1 request of kind pdf');
        assertSame(2, array_sum($byKind), 'usage: the module counted nothing else', json_encode($byKind));
        assertSame(2, mockCallCount(), 'usage: the mock counted exactly 2 calls');

        // The minute cap is the number of requests already sent this minute
        $summary = $usage->summary();
        assertSame(2, $summary['minute'], 'usage: the guard counted both requests in this minute');
        smokeWriteSetting('IFIRMA_RATE_MAX_PER_MIN', (string) $summary['minute']);

        // Download at the cap: the owner gets 429 with Retry-After, and the mock is not called
        if ($overHttp) {
            $answer = smokeDownloadAs($second, $refused);
            assertSame(429, $answer['code'], 'usage: the owner\'s download at the cap answers 429');
            $retryAfter = preg_match('/^Retry-After:\s*(\d+)/mi', $answer['headers'], $m) ? (int) $m[1] : null;
            assertSame(60, $retryAfter, 'usage: the 429 carries Retry-After 60');
            assertSame('Too many requests at the moment. Please try again shortly.', trim($answer['body']), 'usage: the 429 body is the generic text');
        } else {
            skip('usage: the 429 of the download over HTTP', 'the owner could not be logged in over HTTP (see above); the refusal is asserted through the service below');
        }
        $refusedByService = null;
        try {
            IfirmaReceiptService::build($module)->fetchPdf($refused, false);
        } catch (IfirmaRateLimitedException $e) {
            $refusedByService = $e;
        }
        assertTrue($refusedByService instanceof IfirmaRateLimitedException, 'usage: fetchPdf at the cap throws IfirmaRateLimitedException');
        assertSame(2, mockCallCount(), 'usage: the refused downloads reached the mock with no call');

        // Refused refresh: the employee is told, the mock is not called
        $r = $actions->refreshStatus((int) $first->id);
        assertSame(false, $r['success'], 'usage: the employee\'s status refresh at the cap fails');
        assertTrue(strpos((string) $r['message'], 'The status was not refreshed:') === 0, 'usage: the failure says the status was not refreshed', (string) $r['message']);
        assertSame(2, mockCallCount(), 'usage: the refused refresh reached the mock with no call');
        assertSame(2, array_sum($usage->todayByKind()), 'usage: a refused request is not counted');

        // The cron answer carries the usage block with every kind, also when the limit refused the run
        $summary = runCron($module);
        $kinds = isset($summary['usage']['by_kind']) && is_array($summary['usage']['by_kind']) ? array_keys($summary['usage']['by_kind']) : array();
        assertSame(IfirmaRequestKind::all(), $kinds, 'usage: the cron answer carries the usage block with all nine kinds');
        assertSame(9, count($kinds), 'usage: there are nine kinds');
        assertSame(2, mockCallCount(), 'usage: the cron run at the cap reached the mock with no call');
    } finally {
        smokeRestoreSetting('IFIRMA_RATE_MAX_PER_MIN', $previousCap);
        smokeClearRateWindow();
        mockReset();
    }
}

/**
 * Makes the records look last synced 20 minutes ago, and the customer look as if no order page had started a
 * refresh (the attempt time sits on one record of the customer, so it is cleared on all of them).
 */
function smokeMakeSyncStale(array $records)
{
    $ids = array();
    $customers = array();
    foreach ($records as $record) {
        $ids[] = (int) $record->id;
        $customers[(int) $record->id_customer] = (int) $record->id_customer;
    }
    $db = Db::getInstance();
    $db->execute(
        'UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET last_sync_at = \'' . pSQL(date('Y-m-d H:i:s', time() - 20 * 60)) . '\'
         WHERE id_ifirma_receipt IN (' . implode(',', $ids) . ')'
    );
    $db->execute('UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET sync_attempt_at = NULL WHERE id_customer IN (' . implode(',', $customers) . ')');
    Cache::clean('objectmodel_IfirmaReceipt_*');
}

/** @return int how many records of the customer hold an attempt time of a customer page sync */
function smokeCountSyncAttempts($idCustomer)
{
    return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE id_customer = ' . (int) $idCustomer . ' AND sync_attempt_at IS NOT NULL', false);
}

/** @return array last_sync_at and sync_attempt_at of the record, read from the database */
function smokeSyncRow(IfirmaReceipt $record)
{
    $row = Db::getInstance()->getRow('SELECT last_sync_at, sync_attempt_at FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE id_ifirma_receipt = ' . (int) $record->id, false);

    return is_array($row) ? $row : array();
}

/**
 * Scenario D, part 4: the sync a customer's order page starts. One list call per customer and interval refreshes every
 * stale document of the viewed document's issue date; a document of another issue date waits for the cron; with the
 * minute budget of the customer page syncs used up a stale page sends nothing.
 */
function smokeCustomerPageSyncChecks($module)
{
    require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCustomerSyncBudget.php';
    $first = freshScenario('D. Customer page sync');
    if (!$first) {
        return;
    }
    $second = createOrder();
    if (!assertTrue($second instanceof Order && Validate::isLoadedObject($second), 'customer page sync: second order created')) {
        return;
    }
    $minuteKey = IfirmaCustomerSyncBudget::MINUTE_KEY;
    $previous = smokeReadSetting($minuteKey);
    $context = Context::getContext();
    $previousCustomer = $context->customer;
    $third = createOrder();
    if (!assertTrue($third instanceof Order && Validate::isLoadedObject($third), 'customer page sync: third order created')) {
        return;
    }
    $records = array(smokeIssuedRecordFor($first), smokeIssuedRecordFor($second));
    $otherDate = smokeIssuedRecordFor($third);
    Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET issue_date = \'2020-01-02\' WHERE id_ifirma_receipt = ' . (int) $otherDate->id);
    $records[] = $otherDate;
    try {
        $owner = new Customer((int) $first->id_customer);
        $owner->logged = 1;
        $context->customer = $owner;

        smokeMakeSyncStale($records);
        $calls = mockCallCount();
        $module->hookDisplayOrderDetail(array('order' => $first));
        assertSame($calls + 1, mockCallCount(), 'customer page sync: opening the first order page sent exactly one list call');
        $refreshedAfter = time() - 120;
        foreach (array_slice($records, 0, 2) as $i => $record) {
            $row = smokeSyncRow($record);
            assertTrue(isset($row['last_sync_at']) && strtotime((string) $row['last_sync_at']) > $refreshedAfter, 'customer page sync: document ' . ($i + 1) . ' of the customer was refreshed by that one call', json_encode($row));
        }
        $calls = mockCallCount();
        $module->hookDisplayOrderDetail(array('order' => $second));
        assertSame($calls, mockCallCount(), 'customer page sync: opening the second order page sent no call');
        $beforeOtherDate = smokeSyncRow($otherDate);
        $module->hookDisplayOrderDetail(array('order' => $third));
        assertSame($calls, mockCallCount(), 'customer page sync: the order page of another issue date, inside the interval, sent no call');
        assertSame($beforeOtherDate['last_sync_at'], smokeSyncRow($otherDate)['last_sync_at'], 'customer page sync: the record of the other issue date is unchanged');

        smokeMakeSyncStale($records);
        if (time() % 60 >= 50) {
            sleepToNextMinute(); // the full counter is the current minute's
        }
        $full = date(IfirmaCustomerSyncBudget::MINUTE_BUCKET_FORMAT) . ':' . IfirmaCustomerSyncBudget::MINUTE_CAP;
        smokeWriteSetting($minuteKey, $full);
        $before = smokeSyncRow($records[0]);
        $calls = mockCallCount();
        $module->hookDisplayOrderDetail(array('order' => $first));
        assertSame($calls, mockCallCount(), 'customer page sync: with the minute budget used up the stale page sent nothing');
        $after = smokeSyncRow($records[0]);
        assertSame($before['last_sync_at'], $after['last_sync_at'], 'customer page sync: the last sync time of the record is unchanged');
        assertSame(0, smokeCountSyncAttempts((int) $first->id_customer), 'customer page sync: no attempt was recorded');
        assertSame($full, smokeStoredValue($minuteKey), 'customer page sync: the refused page spent nothing of the budget');
    } finally {
        $context->customer = $previousCustomer;
        smokeRestoreSetting($minuteKey, $previous);
        foreach ($records as $record) {
            $record->delete();
        }
        mockReset();
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

    smokeUsageAndRefusalChecks($module, $actions);
    smokeCustomerBudgetChecks($actions);
    smokeCustomerPageSyncChecks($module);

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
    assertTrue(
        is_array($good['data']) && isset($good['data']['queue'], $good['data']['ksef'], $good['data']['delivery'], $good['data']['rate_limited']),
        'cron URL also carries the queue, ksef and delivery blocks and rate_limited',
        substr((string) $good['raw'], 0, 160)
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

        $window = (string) smokeReadSetting('IFIRMA_RATE_WINDOW');
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
        assertTrue((string) smokeReadSetting('IFIRMA_RATE_WINDOW') !== $window, 'IFIRMA_RATE_WINDOW moved during the retry', $window . ' -> ' . smokeReadSetting('IFIRMA_RATE_WINDOW'));
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

        // quiet time: the same bulk resend again, a moment after the delivery, sends nothing and reports a skip
        $res = $runner->run('resend', array((int) $r3->id), 1, array('final' => true));
        $row = $res['results'] ? $res['results'][0] : array('outcome' => null, 'message' => '');
        assertSame('skipped', $row['outcome'], 'quiet time: a repeated bulk resend reports the document as skipped');
        assertSame('This document was sent a moment ago. Wait before sending it again.', (string) $row['message'], 'quiet time: the skip names the reason');
        assertSame('', $res['stopped'], 'quiet time: the skipped resend does not stop the run');
        assertSame((string) $r3b->delivered_at, (string) (new IfirmaReceipt((int) $r3->id))->delivered_at, 'quiet time: the skipped resend left delivered_at unchanged');

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

/**
 * Moves the recorded delivery of a record into the past in the database (the quiet time of a manual resend is 30
 * seconds; the run does not sleep it out).
 *
 * @return string the new delivered_at
 */
function smokeAgeDelivery(IfirmaReceipt $receipt, $seconds = 31)
{
    $aged = date('Y-m-d H:i:s', time() - (int) $seconds);
    Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET delivered_at = \'' . pSQL($aged) . '\' WHERE id_ifirma_receipt = ' . (int) $receipt->id
    );
    Cache::clean('objectmodel_IfirmaReceipt_*');

    return $aged;
}

/**
 * Scenario N, part 4: a manual resend right after a delivery is refused and changes nothing; after the quiet time it
 * goes out. The order's document has just been delivered by the last resend.
 */
function smokeManualResendQuietTimeChecks($actions, Order $order)
{
    $before = deliveryMailCountFor($order);
    $deliveredAt = (string) receiptOf($order)->delivered_at;
    $r = $actions->resend((int) $order->id);
    assertSame(false, $r['success'], 'quiet time: a resend right after a delivery is refused');
    assertSame('This document was sent a moment ago. Wait before sending it again.', (string) $r['message'], 'quiet time: the refusal says the document was sent a moment ago');
    assertSame($before, deliveryMailCountFor($order), 'quiet time: the sink still holds the same e-mails of the order');
    $receipt = receiptOf($order);
    assertSame($deliveredAt, (string) $receipt->delivered_at, 'quiet time: delivered_at is unchanged by the refused resend');
    assertSame('sent', (string) $receipt->delivery_status, 'quiet time: the delivery status stays sent');

    $aged = smokeAgeDelivery($receipt);
    $r = $actions->resend((int) $order->id);
    assertTrue($r['success'], 'quiet time: a resend 31 seconds after the delivery succeeds', $r['message']);
    assertSame($before + 1, deliveryMailCountFor($order), 'quiet time: the resend after the quiet time added exactly one e-mail');
    assertTrue((string) receiptOf($order)->delivered_at > $aged, 'quiet time: delivered_at is renewed by the later resend', $aged . ' -> ' . receiptOf($order)->delivered_at);
}

/**
 * Scenario N, part 6: "Mark as sent" records a delivery that happened outside the module. It is a state change only:
 * no e-mail, no request to iFirma, no later cron retry; a second mark is refused and changes nothing.
 */
function smokeMarkSentChecks($module, $actions)
{
    $order = smokeOrderWithFailedDelivery($module, 'mark sent');
    if (!$order) {
        return;
    }
    mailReject(false);
    $receipt = receiptOf($order);
    assertSame('failed', $receipt ? (string) $receipt->delivery_status : null, 'mark sent: the document starts with a failed delivery');
    $mailsBefore = count(mailbox());
    $callsBefore = mockCallCount();

    $r = $actions->markSent((int) $order->id, 1);
    assertTrue($r['success'], 'mark sent: the employee marks the failed delivery as sent', $r['message']);
    $receipt = receiptOf($order);
    assertSame('sent', (string) $receipt->delivery_status, 'mark sent: the delivery state is sent');
    assertSame('manual', (string) $receipt->delivery_channel, 'mark sent: the channel is manual');
    assertTrue((string) $receipt->delivered_at !== '' && strpos((string) $receipt->delivered_at, '0000') !== 0, 'mark sent: a delivery time is set', (string) $receipt->delivered_at);
    assertSame('', (string) $receipt->delivery_error, 'mark sent: the delivery error is empty');
    assertSame(null, $receipt->getDeliveryRetryAt(), 'mark sent: no retry time is left');
    assertSame($mailsBefore, count(mailbox()), 'mark sent: the sink received no new message');
    assertSame($callsBefore, mockCallCount(), 'mark sent: the mock received no new request');

    smokeMakeRetryDue($order);
    smokeCronRetryBlock($module);
    assertSame(0, deliveryMailCountFor($order), 'mark sent: a cron run afterwards sends no e-mail for the document');
    assertSame('sent', (string) receiptOf($order)->delivery_status, 'mark sent: the cron leaves the delivery state sent');

    $deliveredAt = (string) receiptOf($order)->delivered_at;
    $r = $actions->markSent((int) $order->id, 1);
    assertSame(false, $r['success'], 'mark sent: a second mark is refused');
    assertSame('This document is already recorded as sent.', (string) $r['message'], 'mark sent: the refusal says the document is already recorded as sent');
    $receipt = receiptOf($order);
    assertSame($deliveredAt, (string) $receipt->delivered_at, 'mark sent: the refused second mark leaves delivered_at unchanged');
    assertSame('manual', (string) $receipt->delivery_channel, 'mark sent: the refused second mark leaves the channel manual');
    assertSame($mailsBefore, count(mailbox()), 'mark sent: the refused second mark sent no message');
}

function scenarioN($module, $actions)
{
    $order = freshScenario('N. E-mail delivery through the SMTP sink');
    if (!$order) {
        return;
    }
    $customer = new Customer((int) $order->id_customer);
    $customerEmail = strtolower((string) $customer->email);
    $originalCopyMode = Configuration::get('IFIRMA_SEND_COPY_MODE');
    $originalShopEmail = Configuration::get('PS_SHOP_EMAIL');
    $shopEmail = strtolower((string) $originalShopEmail);
    if ($shopEmail === $customerEmail) {
        // the copy steps need a shop address that differs from the customer's
        $shopEmail = 'smoke-shop@example.com';
        Configuration::updateValue('PS_SHOP_EMAIL', $shopEmail);
    }
    global $mockHost;

    try {
        // 1. copy mode none: one message to the customer, no blind copy, the PDF attached
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'none');
        mailClear(); // the order confirmation mails of createOrder()
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        if (!assertTrue($result->ok && $receipt && $receipt->isIssued(), 'document issued and delivered through the shop mail', $result->message)) {
            return;
        }
        $number = (string) $receipt->ifirma_number;
        assertSame('sent', $receipt->delivery_status, 'delivery recorded as sent');
        $rows = mailbox();
        if (assertSame(1, count($rows), 'copy none: exactly one message in the sink')) {
            $mail = mailGet($rows[0]['id']);
            assertMail($mail, array(
                'from' => $shopEmail,
                'to' => array($customerEmail),
                'bcc' => array(),
                'rcpt_tos' => array($customerEmail),
                'subject_contains' => $number,
                'text_contains' => array($number, (string) $order->reference),
                'html_contains' => array($number, (string) $order->reference, 'cid:', IfirmaShopLink::url()),
                'inline_count' => 1,
                'attachment_count' => 1,
                'attachments' => array(array('filename' => IfirmaShopLink::pdfFileName($number), 'content_type' => 'application/pdf')),
            ), 'copy none');
            $pdf = httpRequest('GET', $mockHost . '/__mock/mail/' . (int) $mail['id'] . '/attachments/0');
            $bytes = is_string($pdf['raw']) ? $pdf['raw'] : '';
            assertSame('%PDF-', substr($bytes, 0, 5), 'copy none: the attachment starts with %PDF-');
            $listed = isset($mail['attachments'][0]['size']) ? (int) $mail['attachments'][0]['size'] : -1;
            assertSame($listed, strlen($bytes), 'copy none: the downloaded attachment has the listed size');
        }
        $deliveredAt = (string) $receipt->delivered_at;

        // 2. copy mode shop, manual resend: the shop address is a blind copy
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'shop');
        sleep(1); // delivered_at has a one-second resolution
        $deliveredAt = smokeAgeDelivery($receipt); // an employee resends after the quiet time of a delivery
        $r = $actions->resend((int) $order->id);
        assertTrue($r['success'], 'resend with copy mode shop succeeds', $r['message']);
        $rows = mailbox();
        if (assertSame(2, count($rows), 'copy shop: the resend added a second message')) {
            assertMail(mailGet($rows[0]['id']), array(
                'to' => array($customerEmail),
                'bcc' => array($shopEmail),
                'rcpt_tos' => array($customerEmail, $shopEmail),
                'subject_contains' => $number,
                'attachment_count' => 1,
                'attachments' => array(array('filename' => IfirmaShopLink::pdfFileName($number), 'content_type' => 'application/pdf')),
            ), 'copy shop');
        }
        $receipt = receiptOf($order);
        assertTrue($receipt && (string) $receipt->delivered_at > $deliveredAt, 'copy shop: delivered_at renewed by the resend', $deliveredAt . ' -> ' . ($receipt ? $receipt->delivered_at : 'n/a'));

        // 3. the copy address equals the customer's (compared ignoring case): the envelope holds one recipient
        smokeAgeDelivery($receipt); // the resend above delivered a moment ago: the employee waits out the quiet time
        Configuration::updateValue('PS_SHOP_EMAIL', strtoupper($customerEmail));
        $r = $actions->resend((int) $order->id);
        assertTrue($r['success'], 'resend with the shop address equal to the customer succeeds', $r['message']);
        $rows = mailbox();
        if (assertSame(3, count($rows), 'copy equal to the customer: a third message')) {
            assertMail(mailGet($rows[0]['id']), array(
                'to' => array($customerEmail),
                'bcc' => array(),
                'rcpt_tos' => array($customerEmail),
            ), 'copy equal to the customer');
        }
        smokeManualResendQuietTimeChecks($actions, $order);
        Configuration::updateValue('PS_SHOP_EMAIL', $shopEmail);
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'none');

        // 4. the mail server refuses: a second order, the mock is not reset
        $order2 = createOrder();
        if (!assertTrue($order2 instanceof Order && Validate::isLoadedObject($order2), 'second order created for the refusal')) {
            return;
        }
        $before = count(mailbox()); // includes the order confirmation mails of createOrder()
        mailReject(true);
        $result = IfirmaIssuancePipeline::build($module)->run($order2, array('trigger' => 'manual', 'bypass_guard' => true));
        $receipt2 = receiptOf($order2);
        assertTrue($receipt2 && $receipt2->isIssued() && (string) $receipt2->ifirma_number !== '', 'refusal: the document is issued although the mail was refused', $result->message);
        assertSame('failed', $receipt2 ? $receipt2->delivery_status : null, 'refusal: delivery recorded as failed');
        assertTrue($receipt2 && (string) $receipt2->delivery_error !== '', 'refusal: delivery_error is not empty', $receipt2 ? (string) $receipt2->delivery_error : 'no receipt');
        assertSame($before, count(mailbox()), 'refusal: the sink captured no message');

        mailReject(false);
        $r = $actions->resend((int) $order2->id);
        assertTrue($r['success'], 'resend after the switch was cleared succeeds', $r['message']);
        $receipt2 = receiptOf($order2);
        assertSame('sent', $receipt2 ? $receipt2->delivery_status : null, 'recovery: delivery recorded as sent');
        assertSame('', $receipt2 ? (string) $receipt2->delivery_error : null, 'recovery: delivery_error cleared');
        assertSame($before + 1, count(mailbox()), 'recovery: one new message in the sink');

        smokeAutomaticRetryChecks($module, $actions);
        smokeMarkSentChecks($module, $actions);
    } finally {
        mailReject(false);
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', $originalCopyMode === false ? 'none' : $originalCopyMode);
        Configuration::updateValue('PS_SHOP_EMAIL', $originalShopEmail);
    }
}

/**
 * Issues a document for a new order while the mail sink refuses mail, so the delivery is recorded as failed with its
 * first automatic attempt used. The sink keeps refusing mail afterwards.
 *
 * @return Order|null
 */
function smokeOrderWithFailedDelivery($module, $label)
{
    $order = createOrder();
    if (!assertTrue($order instanceof Order && Validate::isLoadedObject($order), $label . ': order created')) {
        return null;
    }
    mailReject(true);
    IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual', 'bypass_guard' => true));

    return $order;
}

/** Moves the due time of an order's failed delivery into the past in the database, so the run does not wait for it. */
function smokeMakeRetryDue(Order $order)
{
    Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET delivery_retry_at = \'' . pSQL(date('Y-m-d H:i:s', time() - 60)) . '\' WHERE id_order = ' . (int) $order->id
    );
    Cache::clean('objectmodel_IfirmaReceipt_*');
}

/** @return array the delivery_retry block of one cron run: handled, succeeded, failed, left */
function smokeCronRetryBlock($module)
{
    $summary = runCron($module);

    return isset($summary['delivery_retry']) && is_array($summary['delivery_retry']) ? $summary['delivery_retry'] : array();
}

/** @return int seconds from now until the due time of the order's failed delivery (a large negative number when none is set) */
function smokeSecondsUntilRetry(Order $order)
{
    $due = receiptOf($order)->getDeliveryRetryAt();

    return $due === null ? -PHP_INT_MAX : strtotime($due) - time();
}

/**
 * Scenario N, part 5: the automatic delivery retry, end to end against the mail sink. The due time is moved in the
 * database; nothing waits for it.
 */
function smokeAutomaticRetryChecks($module, $actions)
{
    require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDeliveryRetryPolicy.php';
    $retryWait = IfirmaDeliveryRetryPolicy::WAITS[0];
    $secondWait = IfirmaDeliveryRetryPolicy::WAITS[1];

    // Rejected, then delivered by the cron (and: the cron before the due time sends nothing)
    $order = smokeOrderWithFailedDelivery($module, 'retry');
    if (!$order) {
        return;
    }
    $receipt = receiptOf($order);
    assertSame('failed', $receipt ? (string) $receipt->delivery_status : null, 'retry: the rejected delivery is recorded as failed');
    assertSame(1, $receipt ? $receipt->getDeliveryAttempts() : null, 'retry: one attempt is used');
    $untilRetry = smokeSecondsUntilRetry($order);
    assertTrue($untilRetry >= $retryWait - 60 && $untilRetry <= $retryWait + 5, 'retry: the second attempt is due in about 15 minutes', $untilRetry . ' s');
    $dueAt = $receipt->getDeliveryRetryAt();
    mailReject(false);
    $block = smokeCronRetryBlock($module);
    assertSame(0, isset($block['handled']) ? $block['handled'] : null, 'retry: a cron run before the due time handles no retry');
    assertSame(0, deliveryMailCountFor($order), 'retry: a cron run before the due time sends no e-mail');
    $receipt = receiptOf($order);
    assertSame(1, $receipt->getDeliveryAttempts(), 'retry: the attempt count is unchanged before the due time');
    assertSame($dueAt, $receipt->getDeliveryRetryAt(), 'retry: the due time is unchanged before the due time');
    smokeMakeRetryDue($order);
    $block = smokeCronRetryBlock($module);
    assertSame(array(1, 1, 0), array(
        isset($block['handled']) ? $block['handled'] : null,
        isset($block['succeeded']) ? $block['succeeded'] : null,
        isset($block['failed']) ? $block['failed'] : null,
    ), 'retry: the cron after the due time retries once and it succeeds');
    $mails = mailFind(function ($mail) use ($order) {
        return strpos((string) $mail['subject'], (string) receiptOf($order)->ifirma_number) !== false
            && strpos((string) (isset($mail['text']) ? $mail['text'] : '') . (isset($mail['html']) ? $mail['html'] : ''), (string) $order->reference) !== false;
    });
    if (assertSame(1, count($mails), 'retry: exactly one e-mail after the due time')) {
        assertMail($mails[0], array(
            'attachment_count' => 1,
            'attachments' => array(array('filename' => IfirmaShopLink::pdfFileName((string) receiptOf($order)->ifirma_number), 'content_type' => 'application/pdf')),
        ), 'retry mail');
    }
    $receipt = receiptOf($order);
    assertSame('sent', (string) $receipt->delivery_status, 'retry: delivery is recorded as sent');
    assertSame(null, $receipt->getDeliveryRetryAt(), 'retry: no due time is left after the delivery');
    assertSame(2, $receipt->getDeliveryAttempts(), 'retry: two attempts are used after the successful retry');

    // Three rejections
    $order = smokeOrderWithFailedDelivery($module, 'retry x3');
    if (!$order) {
        return;
    }
    smokeMakeRetryDue($order);
    $block = smokeCronRetryBlock($module);
    assertSame(array(1, 0, 1), array(
        isset($block['handled']) ? $block['handled'] : null,
        isset($block['succeeded']) ? $block['succeeded'] : null,
        isset($block['failed']) ? $block['failed'] : null,
    ), 'retry x3: the second attempt is made and refused');
    $receipt = receiptOf($order);
    assertSame(2, $receipt->getDeliveryAttempts(), 'retry x3: two attempts are used');
    $untilRetry = smokeSecondsUntilRetry($order);
    assertTrue($untilRetry >= $secondWait - 60 && $untilRetry <= $secondWait + 5, 'retry x3: the third attempt is due in about two hours', $untilRetry . ' s');
    smokeMakeRetryDue($order);
    $block = smokeCronRetryBlock($module);
    assertSame(array(1, 0, 1), array(
        isset($block['handled']) ? $block['handled'] : null,
        isset($block['succeeded']) ? $block['succeeded'] : null,
        isset($block['failed']) ? $block['failed'] : null,
    ), 'retry x3: the third attempt is made and refused');
    $receipt = receiptOf($order);
    assertSame('failed', (string) $receipt->delivery_status, 'retry x3: delivery ends as failed');
    assertSame(3, $receipt->getDeliveryAttempts(), 'retry x3: three attempts are used');
    assertSame(null, $receipt->getDeliveryRetryAt(), 'retry x3: no due time is left');
    assertSame(0, deliveryMailCountFor($order), 'retry x3: the sink holds no e-mail of the document');
    mailReject(false);
    $block = smokeCronRetryBlock($module);
    assertSame(0, isset($block['handled']) ? $block['handled'] : null, 'retry x3: a further cron run attempts nothing');
    assertSame(0, deliveryMailCountFor($order), 'retry x3: the sink still holds no e-mail of the document after it');
    assertSame(3, receiptOf($order)->getDeliveryAttempts(), 'retry x3: the attempt count stays at three');

    // Manual resend ends the retries
    $order = smokeOrderWithFailedDelivery($module, 'retry and resend');
    if (!$order) {
        return;
    }
    smokeMakeRetryDue($order);
    mailReject(false);
    $r = $actions->resend((int) $order->id);
    assertTrue($r['success'], 'retry and resend: the employee\'s resend succeeds', $r['message']);
    $block = smokeCronRetryBlock($module);
    assertSame(0, isset($block['handled']) ? $block['handled'] : null, 'retry and resend: the cron after the due time retries nothing');
    assertSame(1, deliveryMailCountFor($order), 'retry and resend: exactly one e-mail in the sink');
    assertSame('sent', (string) receiptOf($order)->delivery_status, 'retry and resend: delivery stays sent');
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

class SmokeLimitOut extends Exception
{
    public $seconds;
    public $text;

    public function __construct($seconds, $text)
    {
        parent::__construct('limit');
        $this->seconds = (int) $seconds;
        $this->text = $text;
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

// ---------------------------------------------------------------- download limits (customer budget, day cap, employee budget)

/** Loads the classes of the download limits (the module loads them lazily). */
function smokeLimitClasses()
{
    foreach (array('IfirmaSchema', 'IfirmaDownloadThrottle', 'IfirmaDownloadBudget', 'IfirmaDownloadDayCap') as $class) {
        require_once _PS_MODULE_DIR_ . 'ifirma/classes/' . $class . '.php';
    }
}

/** Frees the document's own 30-second download window (the stamp), so the next download of it is judged by the other limits. */
function smokeClearDownloadStamp(IfirmaReceipt $record)
{
    Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET last_download_at = NULL WHERE id_ifirma_receipt = ' . (int) $record->id);
    Cache::clean('objectmodel_IfirmaReceipt_*');
}

/** @return array the download columns of the record, read from the database */
function smokeDownloadRow(IfirmaReceipt $record)
{
    $row = Db::getInstance()->getRow('SELECT last_download_at, download_day, download_count FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE id_ifirma_receipt = ' . (int) $record->id, false);

    return is_array($row) ? $row : array();
}

/** The 429 of the customer download: status, Retry-After 60 and the generic text, asserted on an HTTP answer. */
function smokeAssertCustomerRefusal(array $answer, $label)
{
    assertSame(429, $answer['code'], $label . ': the owner\'s download answers 429');
    $retryAfter = preg_match('/^Retry-After:\s*(\d+)/mi', $answer['headers'], $m) ? (int) $m[1] : null;
    assertSame(60, $retryAfter, $label . ': the 429 carries Retry-After 60');
    assertSame('Too many requests at the moment. Please try again shortly.', trim($answer['body']), $label . ': the 429 body is the generic text');
}

/**
 * Scenario D, part 3: the budget all customers share (20 per minute, 2,000 per day). A full counter refuses the owner's
 * download without a request to iFirma and without spending anything, and the module's own status request still goes out.
 */
function smokeCustomerBudgetChecks($actions)
{
    smokeLimitClasses();
    $order = freshScenario('D. Customer download budget');
    if (!$order) {
        return;
    }
    $record = smokeIssuedRecordFor($order);
    $windows = array(
        'minute' => array(IfirmaDownloadBudget::CUSTOMER_MINUTE_KEY, IfirmaDownloadBudget::MINUTE_BUCKET_FORMAT, IfirmaDownloadBudget::CUSTOMER_MINUTE_CAP),
        'day' => array(IfirmaDownloadBudget::CUSTOMER_DAY_KEY, IfirmaDownloadBudget::DAY_BUCKET_FORMAT, IfirmaDownloadBudget::CUSTOMER_DAY_CAP),
    );
    $previous = array();
    foreach ($windows as $spec) {
        $previous[$spec[0]] = smokeReadSetting($spec[0]);
    }
    try {
        smokeClearRateWindow();
        $probe = smokeDownloadAs($order, $record);
        $overHttp = $probe['code'] === 200;
        if ($overHttp) {
            assertSame('%PDF-', substr($probe['body'], 0, 5), 'customer budget: the owner download over HTTP returns a PDF while the budget has room');
        } else {
            skip('customer budget: the download over HTTP', 'the owner could not be logged in over HTTP with the demo password (HTTP ' . $probe['code'] . '); the budget is asserted through its class');
        }
        foreach ($windows as $name => $spec) {
            if (time() % 60 >= 50) {
                sleepToNextMinute(); // the full counter is the current minute's
            }
            $full = date($spec[1]) . ':' . $spec[2];
            smokeWriteSetting($spec[0], $full);
            smokeClearDownloadStamp($record);
            $calls = mockCallCount();
            $label = 'customer budget (' . $name . ')';
            if ($overHttp) {
                smokeAssertCustomerRefusal(smokeDownloadAs($order, $record), $label);
            }
            assertSame($full, smokeStoredValue($spec[0]), $label . ': the refused download left the counter unchanged');
            assertSame($calls, mockCallCount(), $label . ': the refused download reached the mock with no call');
            assertSame(false, IfirmaDownloadBudget::forCustomers()->take(), $label . ': the budget class refuses a take');
            assertSame($full, smokeStoredValue($spec[0]), $label . ': a refused take spends nothing');

            $r = $actions->refreshStatus((int) $order->id);
            assertTrue($r['success'], $label . ': the module\'s own status refresh still succeeds', $r['message']);
            assertSame($calls + 1, mockCallCount(), $label . ': the status refresh sent exactly one request');
            smokeWriteSetting($spec[0], '');
        }
        if ($overHttp) {
            smokeClearDownloadStamp($record);
            $calls = mockCallCount();
            $again = smokeDownloadAs($order, $record);
            assertSame(200, $again['code'], 'customer budget: the owner download works again once the counters are cleared');
            assertSame($calls + 1, mockCallCount(), 'customer budget: that download sent exactly one request');
            assertTrue(preg_match('/^\d{12}:1$/', (string) smokeStoredValue(IfirmaDownloadBudget::CUSTOMER_MINUTE_KEY)) === 1, 'customer budget: the download took one unit of the minute counter', (string) smokeStoredValue(IfirmaDownloadBudget::CUSTOMER_MINUTE_KEY));
        }
    } finally {
        foreach ($previous as $key => $value) {
            smokeRestoreSetting($key, $value);
        }
        smokeClearRateWindow();
        $record->delete();
        mockReset();
    }
}

/**
 * Scenario M, part 2: the customer's own day cap (30 downloads, all their documents together), end to end. The refused
 * request spends no throttle slot and no shared budget; another customer is not affected; yesterday's count is ignored.
 */
function smokeCustomerDayCapChecks(Order $order)
{
    smokeLimitClasses();
    IfirmaSchema::ensureReceiptDownloadCountColumns();
    $idCustomer = (int) $order->id_customer;
    $made = array();
    $minuteKey = IfirmaDownloadBudget::CUSTOMER_MINUTE_KEY;
    $previousMinute = smokeReadSetting($minuteKey);
    try {
        smokeClearRateWindow();
        $used = smokeIssuedRecordFor($order);
        $wanted = smokeIssuedRecordFor($order);
        $made = array($used, $wanted);
        $today = date('Y-m-d');
        $db = Db::getInstance();
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET download_day = \'' . pSQL($today) . '\', download_count = ' . (int) IfirmaDownloadThrottle::CUSTOMER_DAILY_MAX . ' WHERE id_ifirma_receipt = ' . (int) $used->id);
        Cache::clean('objectmodel_IfirmaReceipt_*');
        $cap = new IfirmaDownloadDayCap();
        assertSame(true, $cap->isExhausted($idCustomer), 'day cap: a customer with 30 downloads today is exhausted');
        assertSame(false, $cap->isExhausted($idCustomer + 100000), 'day cap: another customer id is not affected (asserted through the class: the scenario has one customer with a known password)');

        smokeWriteSetting($minuteKey, '');
        $before = smokeDownloadRow($wanted);
        $calls = mockCallCount();
        $answer = smokeDownloadAs($order, $wanted);
        $overHttp = in_array($answer['code'], array(200, 429), true);
        if ($overHttp) {
            smokeAssertCustomerRefusal($answer, 'day cap');
        } else {
            skip('day cap: the download over HTTP', 'the owner could not be logged in over HTTP with the demo password (HTTP ' . $answer['code'] . '); the cap is asserted through its class');
        }
        assertSame($calls, mockCallCount(), 'day cap: the refused download reached the mock with no call');
        assertSame($before, smokeDownloadRow($wanted), 'day cap: the refused download used no throttle slot and counted nothing');
        assertSame('', (string) smokeStoredValue($minuteKey), 'day cap: the refused download did not touch the shared minute counter');

        $yesterday = date('Y-m-d', time() - 86400);
        $db->execute('UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt` SET download_day = \'' . pSQL($yesterday) . '\' WHERE id_ifirma_receipt = ' . (int) $used->id);
        assertSame(false, $cap->isExhausted($idCustomer), 'day cap: yesterday\'s count no longer applies');
        if ($overHttp) {
            $answer = smokeDownloadAs($order, $wanted);
            assertSame(200, $answer['code'], 'day cap: the owner\'s download works once the count is from yesterday');
            $row = smokeDownloadRow($wanted);
            assertSame(1, (int) $row['download_count'], 'day cap: the download was counted once on its document');
            assertSame($today, (string) $row['download_day'], 'day cap: the count carries today\'s date');
        }
    } finally {
        smokeRestoreSetting($minuteKey, $previousMinute);
        foreach ($made as $rec) {
            $rec->delete();
        }
    }
}

/**
 * Scenario L, part 2: a state-changing action sent with GET is refused with 405 and changes nothing; the read-only feed
 * still answers GET.
 */
function smokePostOnlyChecks($dispatch, IfirmaReceipt $markRecord = null)
{
    $order = createOrder();
    if (!assertTrue($order instanceof Order && Validate::isLoadedObject($order), 'post only: an order without a document created')) {
        return;
    }
    $_POST['id_order'] = $_GET['id_order'] = (string) (int) $order->id;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    try {
        $calls = mockCallCount();
        $out = $dispatch('ajaxProcessCreateReceipt');
        assertTrue($out['kind'] === 'json' && $out['payload']['success'] === false && $out['statuses'] === array(405) && !$out['called'], 'CreateReceipt sent with GET answers the 405 failure envelope', json_encode($out));
        assertSame('This action must be sent with POST.', isset($out['payload']['message']) ? (string) $out['payload']['message'] : null, 'post only: the 405 carries the POST message');
        assertTrue(!IfirmaReceipt::getByOrder((int) $order->id), 'post only: the refused GET created no document record');
        assertSame($calls, mockCallCount(), 'post only: the refused GET reached the mock with no call');
        $out = $dispatch('ajaxProcessIssueFeed');
        assertTrue($out['kind'] === 'json' && $out['payload']['success'] === true && !$out['statuses'], 'the read-only IssueFeed still answers GET', json_encode($out));
        if ($markRecord) {
            $_POST['id_order'] = $_GET['id_order'] = (string) (int) $markRecord->id_order;
            $out = $dispatch('ajaxProcessMarkSent');
            assertTrue($out['kind'] === 'json' && $out['payload']['success'] === false && $out['statuses'] === array(405) && !$out['called'], 'MarkSent sent with GET answers the 405 failure envelope', json_encode($out));
            Cache::clean('objectmodel_IfirmaReceipt_*');
            assertSame('failed', (string) IfirmaReceipt::getByOrder((int) $markRecord->id_order)->delivery_status, 'post only: the refused MarkSent GET left the delivery state failed');
        }
    } finally {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['id_order'] = $_GET['id_order'] = '1';
    }
}

/**
 * Scenario L, part 3: the budget of all employees' PDF downloads (30 per minute, 2,000 per day): a full counter answers
 * 429 with the wait and the back-office text, without a request to iFirma; cleared, the download works.
 */
function smokeEmployeeDownloadBudgetChecks($dispatch)
{
    smokeLimitClasses();
    $order = createOrder();
    if (!assertTrue($order instanceof Order && Validate::isLoadedObject($order), 'employee budget: an order created')) {
        return;
    }
    $windows = array(
        'minute' => array(IfirmaDownloadBudget::EMPLOYEE_MINUTE_KEY, IfirmaDownloadBudget::MINUTE_BUCKET_FORMAT, IfirmaDownloadBudget::EMPLOYEE_MINUTE_CAP),
        'day' => array(IfirmaDownloadBudget::EMPLOYEE_DAY_KEY, IfirmaDownloadBudget::DAY_BUCKET_FORMAT, IfirmaDownloadBudget::EMPLOYEE_DAY_CAP),
    );
    $previous = array();
    foreach ($windows as $spec) {
        $previous[$spec[0]] = smokeReadSetting($spec[0]);
    }
    $record = null;
    try {
        smokeClearRateWindow();
        $record = smokeIssuedRecordFor($order);
        $_POST['id_order'] = $_GET['id_order'] = (string) (int) $order->id;
        foreach ($windows as $name => $spec) {
            if (time() % 60 >= 50) {
                sleepToNextMinute();
            }
            $full = date($spec[1]) . ':' . $spec[2];
            smokeWriteSetting($spec[0], $full);
            $calls = mockCallCount();
            $label = 'employee budget (' . $name . ')';
            $out = $dispatch('ajaxProcessDownloadPdf');
            assertTrue($out['kind'] === 'limit' && $out['seconds'] >= 1 && $out['seconds'] <= 60, $label . ': the download answers 429 with a Retry-After between 1 and 60', json_encode($out));
            assertSame('The PDF was not downloaded: the back-office download limit is reached. Try again in a moment.', isset($out['text']) ? (string) $out['text'] : null, $label . ': the answer is the back-office message');
            assertSame($calls, mockCallCount(), $label . ': the refused download reached the mock with no call');
            assertSame($full, smokeStoredValue($spec[0]), $label . ': the refused download left the counter unchanged');
            smokeWriteSetting($spec[0], '');
            $out = $dispatch('ajaxProcessDownloadPdf');
            assertTrue($out['kind'] === 'text' && $out['status'] === 200 && $out['text'] === '%PDF-', $label . ': cleared, the download works', json_encode($out));
            assertSame($calls + 1, mockCallCount(), $label . ': the download sent exactly one request');
        }
    } finally {
        foreach ($previous as $key => $value) {
            smokeRestoreSetting($key, $value);
        }
        smokeClearRateWindow();
        if ($record) {
            $record->delete();
        }
        $_POST['id_order'] = $_GET['id_order'] = '1';
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
            protected function refusedByRateLimit($retryAfterSeconds, $text) { throw new SmokeLimitOut($retryAfterSeconds, $text); }
            protected function streamPdf($pdf, IfirmaReceipt $receipt) { throw new SmokeTextOut(200, substr($pdf, 0, 5)); }
            protected function statusHeader($code) { $this->statuses[] = $code; }
            protected function actions() { return new SmokeRecordingActions($this->called); }
        }');
        eval('class SmokeRecordingActions {
            private $log;
            public function __construct(array &$log) { $this->log = &$log; }
            public function createReceipt($o, $e) { $this->log[] = "createReceipt"; return array("success" => true, "message" => "recorded", "data" => array()); }
            public function resend($o) { $this->log[] = "resend"; return array("success" => true, "message" => "recorded", "data" => array()); }
            public function markSent($o, $e) { $this->log[] = "markSent"; return array("success" => true, "message" => "recorded", "data" => array()); }
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
            } catch (SmokeLimitOut $l) {
                return array('kind' => 'limit', 'seconds' => $l->seconds, 'text' => $l->text, 'statuses' => $c->statuses, 'called' => $c->called);
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

        // view + the customer-edit right but no issue level: registering a contractor is refused before any call
        $db = Db::getInstance();
        $db->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'access` (id_profile, id_authorization_role) SELECT ' . $profileId . ", id_authorization_role FROM `" . _DB_PREFIX_
            . "authorization_role` WHERE slug IN ('ROLE_MOD_TAB_ADMINIFIRMA_READ', 'ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE')");
        assertTrue(IfirmaPermission::canView($pair['employee']) && !IfirmaPermission::canIssue($pair['employee']), 'register: the profile holds view but not issue');
        assertTrue((bool) Access::isGranted('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', $profileId), 'register: the profile may edit customers');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['id_customer'] = $_GET['id_customer'] = '1';
        $callsBefore = mockCallCount();
        $out = $dispatch('ajaxProcessCustomerRegister');
        assertTrue($out['kind'] === 'json' && $out['payload']['success'] === false && $out['statuses'] === array(403), 'CustomerRegister with view and customer-edit but no issue level answers 403', json_encode($out));
        assertSame('You do not have permission to issue iFirma documents.', isset($out['payload']['message']) ? (string) $out['payload']['message'] : null, 'CustomerRegister: the refusal names the issue permission');
        assertSame($callsBefore, mockCallCount(), 'CustomerRegister without the issue level: the mock received no call');
        unset($_SERVER['REQUEST_METHOD'], $_POST['id_customer'], $_GET['id_customer']);

        // grant view + issue
        $db->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'access` (id_profile, id_authorization_role) SELECT ' . $profileId . ", id_authorization_role FROM `" . _DB_PREFIX_
            . "authorization_role` WHERE slug IN ('ROLE_MOD_TAB_ADMINIFIRMA_READ', 'ROLE_MOD_TAB_ADMINIFIRMA_CREATE')");
        assertTrue(IfirmaPermission::canView($pair['employee']) && IfirmaPermission::canIssue($pair['employee']) && !IfirmaPermission::canManage($pair['employee']),
            'the profile now holds view and issue, not manage');

        // state-changing actions run only for a POST; the CLI has no request method
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $out = $dispatch('ajaxProcessCreateReceipt');
        assertTrue($out['kind'] === 'json' && $out['payload']['success'] === true && $out['called'] === array('createReceipt') && !$out['statuses'],
            'CreateReceipt with READ + CREATE is dispatched (no 403)', json_encode($out));
        $out = $dispatch('ajaxProcessResendDocument');
        assertTrue($out['kind'] === 'json' && $out['payload']['success'] === false && $out['statuses'] === array(403) && !$out['called'],
            'ResendDocument with READ + CREATE is still 403', json_encode($out));
        // a seeded record of an order id no shop order has: the recording actions never load an order
        $markOrderId = 990001;
        $markRecord = null;
        {
            $markRecord = seedReceipt($markOrderId, 99999, IfirmaReceipt::STATUS_CREATED, array(
                'ifirma_id' => '999300', 'ifirma_number' => 'SMOKE/MS', 'delivery_status' => IfirmaReceipt::DELIVERY_FAILED,
            ));
            $_POST['id_order'] = $_GET['id_order'] = (string) $markOrderId;
            $out = $dispatch('ajaxProcessMarkSent');
            assertTrue($out['kind'] === 'json' && $out['payload']['success'] === false && $out['statuses'] === array(403) && !$out['called'],
                'MarkSent with READ + CREATE (no manage level) answers the 403 failure envelope', json_encode($out));
            Cache::clean('objectmodel_IfirmaReceipt_*');
            assertSame('failed', (string) IfirmaReceipt::getByOrder($markOrderId)->delivery_status, 'MarkSent without the manage level leaves the delivery state failed');

            $db->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'access` (id_profile, id_authorization_role) SELECT ' . $profileId . ", id_authorization_role FROM `" . _DB_PREFIX_
                . "authorization_role` WHERE slug = 'ROLE_MOD_TAB_ADMINIFIRMA_UPDATE'");
            assertTrue(IfirmaPermission::canManage($pair['employee']), 'MarkSent: the profile now holds the manage level');
            $out = $dispatch('ajaxProcessMarkSent');
            assertTrue($out['kind'] === 'json' && $out['payload']['success'] === true && $out['called'] === array('markSent') && !$out['statuses'],
                'MarkSent with the manage level is dispatched (no 403)', json_encode($out));
            $_POST['id_order'] = $_GET['id_order'] = '1';
        }

        smokePostOnlyChecks($dispatch, $markRecord);
        smokeEmployeeDownloadBudgetChecks($dispatch);
    } finally {
        if (isset($markRecord) && $markRecord) {
            $markRecord->delete();
        }
        $context->employee = $original;
        unset($_SERVER['REQUEST_METHOD'], $_GET['token'], $_POST['token'], $_POST['id_order'], $_GET['id_order']);
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

        smokeCustomerDayCapChecks($order);
    } finally {
        foreach ($made as $rec) {
            $rec->delete();
        }
        mockReset();
    }
}

// ---------------------------------------------------------------- scenarios O, P, Q: guest code, auto-accept, stamp prefix

/**
 * True for the rows the module raises with raw SQL (the guard's windows and back-off, the usage counters). The module
 * does not go through Configuration for them, so this process's Configuration cache never learns their value: a
 * Configuration::updateValue() that equals the stale cached value writes nothing, and the row keeps its count.
 */
function smokeIsCounterKey($key)
{
    return preg_match('/^IFIRMA_(RATE_(WINDOW|DAY_WINDOW|BACKOFF_UNTIL)|USAGE_[A-Z]+|DL_(CUSTOMER|EMPLOYEE)_(MINUTE|DAY)|SYNC_CUSTOMER_(MINUTE|DAY))$/', (string) $key) === 1;
}

/** @return string|false the value of a Configuration row read from the database, false when the row does not exist */
function smokeStoredValue($key)
{
    return Db::getInstance()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\'', false);
}

/** @return string|false the current value of a setting; a counter row is read from the database, never from the cache */
function smokeReadSetting($key)
{
    return smokeIsCounterKey($key) ? smokeStoredValue($key) : Configuration::get($key);
}

/**
 * Writes a setting so that the database row and this process's Configuration cache agree afterwards. A counter row
 * is written straight into the database and the cache is reloaded; every other key goes through Configuration.
 */
function smokeWriteSetting($key, $value)
{
    if (!smokeIsCounterKey($key)) {
        Configuration::updateValue($key, $value);

        return;
    }
    if (smokeStoredValue($key) === false) {
        Configuration::updateValue($key, $value);
    } else {
        Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'' . pSQL((string) $value) . '\' WHERE `name` = \'' . pSQL($key) . '\'');
    }
    Configuration::loadConfiguration();
}

/** Starts the module's per-minute counter from zero (the whole run sends far more than one minute's cap). */
function smokeClearRateWindow()
{
    smokeWriteSetting('IFIRMA_RATE_WINDOW', '');
}

/** Sets a configuration value and returns the previous one (false when it was not set). */
function smokeSetting($key, $value)
{
    $previous = smokeReadSetting($key);
    smokeWriteSetting($key, $value);

    return $previous;
}

function smokeRestoreSetting($key, $previous)
{
    if ($previous === false || $previous === null) {
        Configuration::deleteByName($key);
    } else {
        smokeWriteSetting($key, $previous);
    }
}

/**
 * Minimal HTTP client for the shop's own front pages: calls the Apache of the container on 127.0.0.1
 * with the shop domain as Host header and keeps a cookie jar between requests.
 */
class SmokeWeb
{
    private $jar;
    private $host;

    public function __construct()
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'smkweb');
        $this->host = (string) Configuration::get('PS_SHOP_DOMAIN');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * @param string     $method GET|POST
     * @param string     $url    any URL of the shop; only path and query are used
     * @param array|null $post   form fields
     * @return array code, body, headers (raw header block of the last response), url (path used)
     */
    public function request($method, $url, array $post = null)
    {
        $parts = parse_url($url);
        $path = (isset($parts['path']) ? $parts['path'] : '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $out = array('code' => 0, 'body' => '', 'headers' => '', 'url' => $path);
        for ($hop = 0; $hop < 4; $hop++) {
            $ch = curl_init('http://127.0.0.1' . $path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->jar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->jar);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Host: ' . $this->host));
            curl_setopt($ch, CURLOPT_USERAGENT, 'ifirma-smoke');
            if ($method === 'POST' && $hop === 0) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post === null ? array() : $post));
            }
            $raw = curl_exec($ch);
            $out['code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            if ($raw === false) {
                $out['code'] = 0;

                return $out;
            }
            $out['headers'] = substr($raw, 0, $size);
            $out['body'] = (string) substr($raw, $size);
            $out['url'] = $path;
            if ($out['code'] >= 300 && $out['code'] < 400 && preg_match('/^Location:\s*(\S+)/mi', $out['headers'], $m)) {
                $next = parse_url($m[1]);
                $path = (isset($next['path']) ? $next['path'] : '/') . (isset($next['query']) ? '?' . $next['query'] : '');
                continue;
            }

            return $out;
        }

        return $out;
    }
}

/** @return string the module section of a rendered page (the whole page when it is not found) */
function smokeSection($html)
{
    return preg_match('~<section id="ifirma-guest-request".*?</section>~s', $html, $m) ? $m[0] : $html;
}

/** @return string the value of the hidden "token" input of a rendered form; '' when there is none */
function smokeFormToken($html)
{
    return preg_match('/name="token"\s+value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
}

/** A postcode in the format of the address's country that differs from the current one. */
function smokeOtherPostcode(Address $address)
{
    $country = new Country((int) $address->id_country);
    $format = trim((string) $country->zip_code_format);
    if ($format === '') {
        $format = 'NN-NNN';
    }
    foreach (array('95173', '38246') as $digits) {
        $i = 0;
        $zip = '';
        foreach (str_split($format) as $c) {
            if ($c === 'N') {
                $zip .= $digits[$i++ % strlen($digits)];
            } elseif ($c === 'L') {
                $zip .= 'A';
            } elseif ($c === 'C') {
                $zip .= $country->iso_code;
            } else {
                $zip .= $c;
            }
        }
        if ($zip !== (string) $address->postcode) {
            return $zip;
        }
    }

    return '99-999';
}

/**
 * The form input of the invoice address of $order with $changes applied, in the shape of
 * IfirmaDataRequestService::submit() (and, for the guest form, of the posted fields).
 *
 * @return array
 */
function smokeRequestInput($module, Order $order, array $changes = array())
{
    $service = IfirmaDataRequestService::build($module);
    $values = $service->defaultsFromAddress(new Address((int) $order->id_address_invoice));
    $input = array();
    foreach ($values as $field => $value) {
        $input[$field] = is_scalar($value) ? (string) $value : '';
    }
    $input['want_invoice_for_company'] = !empty($values['want_invoice_for_company']) ? '1' : '';
    foreach ($changes as $field => $value) {
        $input[$field] = $value;
    }
    if ($input['want_invoice_for_company'] === '') {
        unset($input['want_invoice_for_company']);
    }

    return $input;
}

/** The detail node of a document as the mock answers it (the "response" object); null when it fails. */
function smokeMockDetail($ifirmaId)
{
    $d = ifirmaClient()->getDetail('fakturakraj', (string) $ifirmaId);

    return ($d && is_array($d['data']) && isset($d['data']['response'])) ? $d['data']['response'] : null;
}

/** @return array merchant "Invoice data change request" mails of the order, full records */
function smokeMerchantMails(Order $order)
{
    return mailFind(function ($m) use ($order) {
        return strpos((string) $m['subject'], (string) $order->reference) !== false && strpos((string) $m['subject'], 'Invoice data change request') !== false;
    });
}

/** Puts the trigger status into the order history without running the pipeline (auto issue switched off meanwhile). */
function smokeReachTriggerQuietly(Order $order, $triggerState)
{
    $previous = smokeSetting('IFIRMA_AUTO_CREATE_STATE', 0);
    try {
        changeState($order, $triggerState);
    } finally {
        smokeRestoreSetting('IFIRMA_AUTO_CREATE_STATE', $previous);
    }
}

function scenarioO($module, $triggerState)
{
    $order = freshScenario('O. Guest one-time code: identify, verify, safe change approved automatically');
    if (!$order) {
        return;
    }
    $customer = new Customer((int) $order->id_customer);
    $customerEmail = strtolower((string) $customer->email);
    $originalGuest = (int) $customer->is_guest;
    $previous = array(
        'mode' => smokeSetting('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe'),
        'template' => smokeSetting('IFIRMA_DOC_TEMPLATE', 'logo'),
    );
    $db = Db::getInstance();
    try {
        // a guest customer; earlier runs leave requests and codes behind (daily cap, address cap of the codes)
        $db->update('customer', array('is_guest' => 1), 'id_customer = ' . (int) $customer->id);
        $db->delete('ifirma_data_request', 'id_customer = ' . (int) $customer->id);
        IfirmaSchema::ensureGuestCodeTable();
        $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'ifirma_guest_code`');

        // a consistent company buyer: the demo address has a company name but no NIP, which the form would read as a change of identity
        $idAddress = (int) $order->id_address_invoice;
        $originalAddress = $db->getRow('SELECT company, vat_number FROM `' . _DB_PREFIX_ . 'address` WHERE id_address = ' . $idAddress);
        $db->update('address', array('company' => 'Smoke Guest Sp. z o.o.', 'vat_number' => '5250001234'), 'id_address = ' . $idAddress);
        Cache::clean('objectmodel_Address_' . $idAddress . '_*');

        // the trigger status is in the history, but nothing was issued: the request will resume the issuance
        smokeReachTriggerQuietly($order, $triggerState);
        assertTrue(!(receiptOf($order) && receiptOf($order)->isIssued()), 'the order has the trigger status and no issued document');
        assertSame(0, IfirmaDataRequestService::build($module)->hasPendingForOrder((int) $order->id) ? 1 : 0, 'the order has no pending request');
        assertSame('safe', Configuration::get('IFIRMA_DATA_REQUEST_AUTO_ACCEPT'), 'auto-accept mode is safe');
        mailClear(); // order confirmation and status mails

        $web = new SmokeWeb();
        $pageUrl = Context::getContext()->link->getModuleLink('ifirma', 'guestrequest');
        $page = $web->request('GET', $pageUrl);
        if (!assertTrue($page['code'] === 200 && strpos($page['body'], 'identifyIfirmaGuest') !== false, 'guest page answers 200 with the identify form', 'HTTP ' . $page['code'] . ' ' . $page['url'] . ' ' . substr(strip_tags(smokeSection($page["body"])), 0, 160))) {
            return;
        }
        $token = smokeFormToken($page['body']);
        assertTrue($token !== '', 'the form carries a CSRF token');

        // 1. the right reference with a WRONG e-mail: neutral answer, no mail
        $wrong = $web->request('POST', $pageUrl, array(
            'token' => $token, 'reference' => $order->reference, 'email' => 'nobody-smoke@example.invalid', 'identifyIfirmaGuest' => '1',
        ));
        assertTrue(strpos($wrong['body'], 'If the data matches an order, we have sent a code') !== false, 'wrong e-mail: the neutral message is shown', 'HTTP ' . $wrong['code']);
        assertSame(0, count(mailbox()), 'wrong e-mail: the sink stays empty');
        assertSame(0, (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_guest_code` WHERE id_order = ' . (int) $order->id), 'wrong e-mail: no code row');

        // 2. the right e-mail: one code mail
        $token = smokeFormToken($wrong['body']) ?: $token;
        $ident = $web->request('POST', $pageUrl, array(
            'token' => $token, 'reference' => $order->reference, 'email' => $customerEmail, 'identifyIfirmaGuest' => '1',
        ));
        assertTrue(strpos($ident['body'], 'If the data matches an order, we have sent a code') !== false && strpos($ident['body'], 'verifyIfirmaGuest') !== false,
            'right e-mail: the same neutral message and the code form', 'HTTP ' . $ident['code']);
        // the subject names the shop only (red team F12): the reference is in the body
        $codeMails = mailFind(function ($m) {
            return strpos((string) $m['subject'], 'Your verification code from') !== false;
        });
        if (!assertSame(1, count($codeMails), 'right e-mail: exactly one code mail in the sink')) {
            return;
        }
        assertMail($codeMails[0], array(
            'to' => array($customerEmail),
            'subject_contains' => 'Your verification code from',
            'text_contains' => array((string) $order->reference),
        ), 'code mail');
        assertTrue(strpos((string) $codeMails[0]['subject'], (string) $order->reference) === false, 'the subject does not carry the order reference');
        $code = preg_match('/order ' . preg_quote((string) $order->reference, '/') . ': (\d{6})/', (string) $codeMails[0]['text'], $m) ? $m[1] : '';
        if (!assertTrue($code !== '', 'the six digits are in the text part of the code mail', substr((string) $codeMails[0]['text'], 0, 200))) {
            return;
        }

        // 3. a wrong code: the generic error, still the code form
        $token = smokeFormToken($ident['body']) ?: $token;
        $badCode = $code === '000000' ? '111111' : '000000';
        $bad = $web->request('POST', $pageUrl, array(
            'token' => $token, 'reference' => $order->reference, 'email' => $customerEmail, 'code' => $badCode, 'verifyIfirmaGuest' => '1',
        ));
        assertTrue(strpos($bad['body'], 'Invalid or expired code') !== false && strpos($bad['body'], 'submitIfirmaDataRequest') === false,
            'wrong code: the generic error and no data form', 'HTTP ' . $bad['code']);

        // 4. the right code: the form of the order
        $token = smokeFormToken($bad['body']) ?: $token;
        $ok = $web->request('POST', $pageUrl, array(
            'token' => $token, 'reference' => $order->reference, 'email' => $customerEmail, 'code' => $code, 'verifyIfirmaGuest' => '1',
        ));
        if (!assertTrue(strpos($ok['body'], 'submitIfirmaDataRequest') !== false && strpos($ok['body'], (string) $order->reference) !== false,
            'right code: the data form of the order is shown', 'HTTP ' . $ok['code'] . ' ' . substr(trim(preg_replace('/\s+/', ' ', strip_tags(smokeSection($ok["body"])))), 0, 200))) {
            return;
        }

        // 5. only the postcode changes, the company / NIP choice stays as the invoice address has it
        $newZip = smokeOtherPostcode(new Address((int) $order->id_address_invoice));
        $post = smokeRequestInput($module, $order, array('postcode' => $newZip));
        $post['token'] = smokeFormToken($ok['body']) ?: $token;
        $post['id_order'] = (string) (int) $order->id;
        $post['submitIfirmaDataRequest'] = '1';
        $done = $web->request('POST', $pageUrl, $post);
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags(smokeSection($done["body"]))));
        assertTrue(strpos($done['body'], 'Your change request has been received') !== false, 'the confirmation page is shown', 'HTTP ' . $done['code'] . ' ' . substr($plain, 0, 240));

        // 6. the request: approved on submission by the safe mode, no employee
        $rows = IfirmaDataRequest::getByOrder((int) $order->id);
        assertSame(1, count($rows), 'one data request exists for the order');
        $row = $rows ? $rows[0] : array();
        assertSame('approved', isset($row['status']) ? $row['status'] : null, 'the request is approved');
        assertSame('safe', isset($row['auto_mode']) ? $row['auto_mode'] : null, 'auto_mode is safe');
        assertSame(0, isset($row['resolved_by']) ? (int) $row['resolved_by'] : -1, 'no employee resolved it (resolved_by 0)');

        $approved = mailFind(function ($m) use ($customerEmail) {
            return strpos((string) $m['subject'], 'Your invoice data change was approved') !== false && in_array($customerEmail, array_map('strtolower', (array) $m['to']), true);
        });
        assertSame(1, count($approved), 'the customer got the approval mail');
        $merchant = smokeMerchantMails($order);
        if (assertSame(1, count($merchant), 'the merchant got the notification')) {
            assertMail($merchant[0], array('text_contains' => array('Approved automatically (mode: safe).')), 'merchant mail');
        }

        // 7. the pipeline resumed: a document with the approved buyer and the template
        $receipt = receiptOf($order);
        if (!assertTrue($receipt && $receipt->isIssued(), 'the issuance resumed: a document is issued', $receipt ? $receipt->status . ' ' . $receipt->last_error : 'no receipt')) {
            return;
        }
        assertSame(1, remoteDocumentCount(), 'one document in iFirma');
        $detail = smokeMockDetail($receipt->ifirma_id);
        assertSame($newZip, is_array($detail) && isset($detail['Kontrahent']['KodPocztowy']) ? $detail['Kontrahent']['KodPocztowy'] : null, 'the document carries the approved postcode');
        assertSame('logo', is_array($detail) && isset($detail['NazwaSzablonu']) ? $detail['NazwaSzablonu'] : null, 'the document carries NazwaSzablonu logo');
        assertTrue(strpos((string) $receipt->request_payload, $newZip) !== false, 'request_payload carries the approved postcode');
    } finally {
        if (isset($originalAddress) && is_array($originalAddress)) {
            $db->update('address', array('company' => $originalAddress['company'], 'vat_number' => $originalAddress['vat_number']), 'id_address = ' . (int) $order->id_address_invoice);
            Cache::clean('objectmodel_Address_' . (int) $order->id_address_invoice . '_*');
        }
        $db->update('customer', array('is_guest' => $originalGuest), 'id_customer = ' . (int) $customer->id);
        smokeRestoreSetting('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', $previous['mode']);
        smokeRestoreSetting('IFIRMA_DOC_TEMPLATE', $previous['template']);
    }
}

function scenarioP($module, $triggerState)
{
    $order = freshScenario('P. Auto-accept keeps identity changes pending');
    if (!$order) {
        return;
    }
    $customer = new Customer((int) $order->id_customer);
    $customer->logged = 1;
    Context::getContext()->customer = $customer;
    $db = Db::getInstance();
    $previousMode = smokeSetting('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe');
    $service = IfirmaDataRequestService::build($module);
    try {
        $db->delete('ifirma_data_request', 'id_customer = ' . (int) $customer->id);
        $address = new Address((int) $order->id_address_invoice);
        $currentNip = preg_replace('/\D/', '', preg_replace('/^\s*PL/i', '', (string) ($address->vat_number !== '' ? $address->vat_number : $address->dni)));
        $newNip = $currentNip === '5250001234' ? '1234563218' : '5250001234';

        // 1. mode safe, the NIP (and so the buyer identity) changes: the request waits
        $input = smokeRequestInput($module, $order, array(
            'want_invoice_for_company' => '1',
            'company' => trim((string) $address->company) !== '' ? $address->company : 'Smoke Sp. z o.o.',
            'vat_number' => $newNip,
        ));
        $request = null;
        try {
            $request = $service->submit($order, $customer, $input);
        } catch (Exception $e) {
            assertTrue(false, 'safe mode: NIP change submitted', $e->getMessage() . ' ' . json_encode(isset($e->errors) ? $e->errors : array()));
        }
        if ($request) {
            assertSame('pending', $request->status, 'safe mode, NIP changed: the request stays pending');
            assertTrue(!$request->isAutoApproved(), 'safe mode, NIP changed: not approved automatically');
            $merchant = smokeMerchantMails($order);
            if (assertSame(1, count($merchant), 'safe mode, NIP changed: the merchant notification is sent')) {
                $both = (string) $merchant[0]['text'] . (string) $merchant[0]['html'];
                assertTrue(strpos($both, 'Approved automatically') === false, 'safe mode, NIP changed: the merchant mail has no automatic line');
                assertTrue(strpos((string) $merchant[0]['text'], $newNip) !== false || strpos((string) $merchant[0]['text'], 'Smoke Sp. z o.o.') !== false || strpos((string) $merchant[0]['text'], $input['company']) !== false,
                    'safe mode, NIP changed: the merchant mail carries the requested data');
            }
            changeState($order, $triggerState);
            $receipt = receiptOf($order);
            assertSame('held', $receipt ? $receipt->status : null, 'safe mode, NIP changed: the pipeline holds the issuance');
            assertTrue(!$receipt || !$receipt->isIssued(), 'safe mode, NIP changed: no document issued');
            assertSame(0, remoteDocumentCount(), 'safe mode, NIP changed: nothing created in iFirma');
        }

        // 2. mode none, only the postcode changes: pending as before (a second order of the same customer)
        smokeSetting('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'none');
        $order2 = createOrder();
        if (!assertTrue($order2 instanceof Order && Validate::isLoadedObject($order2), 'second order created')) {
            return;
        }
        $db->delete('ifirma_data_request', 'id_customer = ' . (int) $customer->id);
        mailClear();
        $input2 = smokeRequestInput($module, $order2, array('postcode' => smokeOtherPostcode(new Address((int) $order2->id_address_invoice))));
        $request2 = null;
        try {
            $request2 = $service->submit($order2, $customer, $input2);
        } catch (Exception $e) {
            assertTrue(false, 'none mode: postcode change submitted', $e->getMessage() . ' ' . json_encode(isset($e->errors) ? $e->errors : array()));
        }
        if ($request2) {
            assertSame('pending', $request2->status, 'none mode, postcode only: the request is pending');
            assertTrue(!$request2->isAutoApproved() && (string) $request2->auto_mode === '', 'none mode: auto_mode stays empty');
            $merchant2 = smokeMerchantMails($order2);
            if (assertSame(1, count($merchant2), 'none mode: the merchant notification is sent')) {
                assertTrue(strpos((string) $merchant2[0]['text'] . (string) $merchant2[0]['html'], 'Approved automatically') === false, 'none mode: the merchant mail has no automatic line');
            }
            changeState($order2, $triggerState);
            $receipt2 = receiptOf($order2);
            assertSame('held', $receipt2 ? $receipt2->status : null, 'none mode: the pipeline holds the issuance');
        }
    } finally {
        smokeRestoreSetting('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', $previousMode);
    }
}

function scenarioQ($module, $actions)
{
    $order = freshScenario('Q. Stamp prefix and shop webpage');
    if (!$order) {
        return;
    }
    $previous = array(
        'prefix' => smokeSetting('IFIRMA_ORDER_STAMP_PREFIX', 'smoke'),
        'url' => smokeSetting('IFIRMA_SHOP_URL', 'https://smoke.example'),
        'copy' => smokeSetting('IFIRMA_SEND_COPY_MODE', 'none'),
    );
    try {
        // 1. a document of the shop: Uwagi with the stamp and the webpage, the bare stamp in NUMER_ZAMOWIENIA
        mailClear();
        $stamp = 'smoke-' . (int) $order->id . '-' . $order->reference;
        assertSame($stamp, IfirmaPayloadBuilder::buildOrderReference($order), 'the order stamp uses the configured prefix');
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        if (!assertTrue($result->ok && $receipt && $receipt->isIssued(), 'document issued', $result->message)) {
            return;
        }
        $detail = smokeMockDetail($receipt->ifirma_id);
        assertSame($stamp . ' https://smoke.example', is_array($detail) && isset($detail['Uwagi']) ? $detail['Uwagi'] : null, 'Uwagi is "<stamp> <shop webpage>"');
        $note = is_array($detail) && isset($detail['DodatkoweUwagi']) ? $detail['DodatkoweUwagi'] : array();
        $info = isset($note['Info']) ? $note['Info'] : (isset($note[0]['Info']) ? $note[0]['Info'] : null);
        assertSame($stamp, $info, 'DodatkoweUwagi Info is the bare stamp');
        assertSame($stamp, (string) $receipt->order_reference, 'the stamp is stored on the receipt');

        // 2. the delivery mail attachment and the customer download carry the shop-domain name
        $number = (string) $receipt->ifirma_number;
        $expectedName = 'smoke.example-' . preg_replace('/[^0-9A-Za-z._-]/', '_', $number) . '.pdf';
        assertSame($expectedName, IfirmaShopLink::pdfFileName($number), 'pdfFileName() gives smoke.example-<number>.pdf');
        $mails = mailFind(function ($m) use ($number) {
            return strpos((string) $m['subject'], $number) !== false;
        });
        if (assertSame(1, count($mails), 'the delivery mail is in the sink')) {
            assertMail($mails[0], array(
                'attachment_count' => 1,
                'attachments' => array(array('filename' => $expectedName, 'content_type' => 'application/pdf')),
                'html_contains' => array('https://smoke.example'),
            ), 'delivery mail');
        }
        smokeCustomerDownloadCheck($order, $receipt, $expectedName);

        // 3. a remote document stamped with the default prefix (legacy stamp) is adopted, nothing is created
        $order2 = createOrder();
        if (!assertTrue($order2 instanceof Order && Validate::isLoadedObject($order2), 'second order created for the legacy stamp')) {
            return;
        }
        mockReset();
        smokeClearRateWindow();
        $address = new Address((int) $order2->id_address_invoice);
        $seedId = seedDocument(array(
            'doc_type' => 'fakturakraj',
            'contractor' => array('Nazwa' => trim($address->firstname . ' ' . $address->lastname), 'Identyfikator' => 'EXT1'),
            'gross' => round((float) $order2->total_paid_tax_incl, 2),
            'currency' => currencyIso($order2),
            'uwagi' => 'PS-ORDER-' . (int) $order2->id . '-' . $order2->reference,
            'created_by' => 'external',
        ));
        assertTrue($seedId !== '', 'document stamped with the default prefix seeded', 'id=' . $seedId);
        $result2 = IfirmaIssuancePipeline::build($module)->run($order2, array('trigger' => 'manual'));
        assertSame('adopted', $result2->outcome, 'the legacy-stamped document is adopted');
        $receipt2 = receiptOf($order2);
        assertSame($seedId, $receipt2 ? (string) $receipt2->ifirma_id : null, 'the receipt carries the seeded id');
        assertSame(1, remoteDocumentCount(), 'no document was created (still 1 in iFirma)');

        // 4. a document stamped by another shop's prefix is not adopted by the stamp
        $order3 = createOrder();
        if (!assertTrue($order3 instanceof Order && Validate::isLoadedObject($order3), 'third order created for the foreign stamp')) {
            return;
        }
        mockReset();
        smokeClearRateWindow();
        $otherId = seedDocument(array(
            'doc_type' => 'fakturakraj',
            'contractor' => array('Nazwa' => 'Smoke Customer', 'Identyfikator' => guardIdentifierFor($order3)),
            'currency' => currencyIso($order3),
            'sale_date' => IfirmaPayloadBuilder::orderSaleDate($order3),
            'Pozycje' => seedPositionsFor($order3),
            'uwagi' => 'other-' . (int) $order3->id . '-' . $order3->reference,
            'created_by' => 'external',
        ));
        assertTrue($otherId !== '', 'document stamped other-... seeded', 'id=' . $otherId);
        $result3 = IfirmaIssuancePipeline::build($module)->run($order3, array('trigger' => 'manual'));
        assertTrue($result3->outcome !== 'adopted', 'the foreign stamp does not adopt the document', 'outcome ' . $result3->outcome);
        assertSame('suspected', $result3->outcome, 'it goes through the detail verification: the buyer\'s same-day document is a suspected duplicate');
        $receipt3 = receiptOf($order3);
        assertSame(array($otherId), candidateIds($receipt3), 'the document is recorded as a candidate');
        assertTrue(!$receipt3 || (string) $receipt3->source !== 'external', 'the receipt is not linked to it');
        assertSame(1, remoteDocumentCount(), 'nothing was created (still 1 in iFirma)');
    } finally {
        smokeRestoreSetting('IFIRMA_ORDER_STAMP_PREFIX', $previous['prefix']);
        smokeRestoreSetting('IFIRMA_SHOP_URL', $previous['url']);
        smokeRestoreSetting('IFIRMA_SEND_COPY_MODE', $previous['copy']);
        mockReset();
    }
}

/**
 * Logs the order's customer in over HTTP (the demo customer's password) and downloads the document
 * through the front controller; the Content-Disposition name must equal the attachment name. Skips when
 * the login does not work for this customer.
 */
function smokeCustomerDownloadCheck(Order $order, $receipt, $expectedName)
{
    $customer = new Customer((int) $order->id_customer);
    $context = Context::getContext();
    $web = new SmokeWeb();
    $login = $web->request('GET', $context->link->getPageLink('authentication'));
    $web->request('POST', $context->link->getPageLink('authentication'), array(
        'email' => $customer->email, 'password' => '123456789', 'submitLogin' => '1', 'back' => 'my-account',
    ));
    unset($login);
    $url = $context->link->getModuleLink('ifirma', 'receipt', array('token' => $receipt->security_token));
    $pdf = $web->request('GET', $url);
    if ($pdf['code'] !== 200 || substr($pdf['body'], 0, 5) !== '%PDF-') {
        skip('customer download name', 'the customer could not be logged in over HTTP with the demo password (HTTP ' . $pdf['code'] . ' at ' . $pdf['url'] . ')');

        return;
    }
    $disposition = preg_match('/^Content-Disposition:\s*(.+)$/mi', $pdf['headers'], $m) ? trim($m[1]) : '';
    assertTrue(strpos($disposition, 'filename="' . $expectedName . '"') !== false || strpos($disposition, 'filename=' . $expectedName) !== false,
        'the customer download answers the name ' . $expectedName, $disposition);
}

// ---------------------------------------------------------------- scenarios R and S: the queue, the limits and KSeF-confirmed delivery

/** Sets the mock's limits at runtime ('per_minute' and/or 'per_day'). */
function mockRateLimit(array $limits)
{
    return mockRequest('POST', '/__mock/rate-limit', $limits);
}

/** Clears the count of one window of the mock ('minute' or 'day'). */
function mockNextWindow($window)
{
    return mockRequest('POST', '/__mock/rate-limit/next-window', array('window' => $window));
}

/** Sets the mock's KSeF auto-send mode: off, send or accept. */
function mockKsefAuto($mode)
{
    return mockRequest('POST', '/__mock/ksef-auto', array('mode' => $mode));
}

/** @return array the mock's status.json */
function mockStatus()
{
    $r = mockRequest('GET', '/__mock/status.json');

    return is_array($r['data']) ? $r['data'] : array();
}

/** @return array the rate_limit block of the mock's status.json */
function mockRateStatus()
{
    $status = mockStatus();

    return isset($status['rate_limit']) ? $status['rate_limit'] : array();
}

/** One cron run in this process, through the runner the endpoint uses. @return array its summary */
function runCron($module)
{
    require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCronRunner.php';

    return IfirmaCronRunner::build($module)->run();
}

/**
 * The sink also holds PrestaShop's own order mails, so the delivery mails are told apart by the document number in the
 * subject.
 *
 * @param string[] $numbers document numbers
 * @return int captured messages whose subject names one of the numbers
 */
function deliveryMailCount(array $numbers)
{
    $count = 0;
    foreach (mailbox() as $row) {
        foreach ($numbers as $number) {
            if ($number !== '' && strpos((string) $row['subject'], (string) $number) !== false) {
                $count++;
                break;
            }
        }
    }

    return $count;
}

/**
 * The delivery mails of one order's document: the subject names the document number and the body names the order
 * reference. The number alone is not enough, because the mock restarts its numbering with every reset and a mail of an
 * earlier scenario's document can carry the same number.
 *
 * @return int captured messages of this order's document (0 while the document has no number yet)
 */
function deliveryMailCountFor(Order $order)
{
    $receipt = IfirmaReceipt::getByOrder((int) $order->id);
    $number = $receipt ? (string) $receipt->ifirma_number : '';
    if ($number === '') {
        return 0;
    }
    $reference = (string) $order->reference;

    return count(mailFind(function ($mail) use ($number, $reference) {
        return strpos((string) $mail['subject'], $number) !== false
            && (strpos((string) (isset($mail['text']) ? $mail['text'] : ''), $reference) !== false
                || strpos((string) (isset($mail['html']) ? $mail['html'] : ''), $reference) !== false);
    }));
}

/** @return string[] the numbers of the documents of these orders */
function numbersOf(array $orders)
{
    $numbers = array();
    foreach ($orders as $order) {
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        if ($receipt && $receipt->isIssued()) {
            $numbers[] = (string) $receipt->ifirma_number;
        }
    }

    return $numbers;
}

/** One cron run through the shop's own front controller, over HTTP. @return array array('code', 'data', 'raw') */
function cronOverHttp()
{
    // through 127.0.0.1 with the shop domain as Host (SmokeWeb), as the guest and download scenarios do: the shop redirects
    // any other Host to its own domain
    $web = new SmokeWeb();
    $answer = $web->request('GET', '/index.php?fc=module&module=ifirma&controller=cron&token=' . rawurlencode((string) Configuration::get('IFIRMA_CRON_TOKEN')));
    $data = json_decode((string) $answer['body'], true);

    return array('code' => $answer['code'], 'data' => is_array($data) ? $data : null, 'raw' => $answer['body']);
}

/** Sleeps until one second into the next calendar minute, so every window of the guard starts fresh. */
function sleepToNextMinute()
{
    $wait = 61 - (time() % 60);
    smokeOut('INFO', 'waiting ' . $wait . ' s for the next minute');
    sleep($wait);
}

/**
 * Takes the leftovers of earlier runs out of the cron's work: documents that still wait for KSeF are marked not sent and
 * queued records are closed as errors, so the cron of this scenario only handles what the scenario made.
 */
function smokeClearCronWork()
{
    $table = '`' . _DB_PREFIX_ . 'ifirma_receipt`';
    Db::getInstance()->execute("UPDATE {$table} SET delivery_status = 'none' WHERE delivery_status = 'awaiting_ksef'");
    Db::getInstance()->execute("UPDATE {$table} SET status = 'error' WHERE status = 'queued'");
    // The statements above bypass ObjectModel. This process still holds the records it loaded in earlier scenarios, and the
    // status sync of a later cron run loads a record through that cache and saves every field of it back: the stale
    // 'awaiting_ksef' would be written over the row again and the cron would deliver the leftover.
    Cache::clean('objectmodel_IfirmaReceipt_*');
}

/** @return int the id of the newest module record, so a scenario can remove the records it created */
function smokeReceiptWatermark()
{
    return (int) Db::getInstance()->getValue('SELECT MAX(id_ifirma_receipt) FROM `' . _DB_PREFIX_ . 'ifirma_receipt`');
}

/**
 * Removes the records created after the watermark. A burst scenario leaves hundreds of records whose documents the mock
 * reset has dropped, and the status sync reads only a bounded number of records per run, so they would push the later
 * scenarios' own records out of the sync.
 *
 * @param int $watermark from smokeReceiptWatermark()
 */
function smokeForgetReceiptsAfter($watermark)
{
    Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE id_ifirma_receipt > ' . (int) $watermark);
}

/** @return int how many module records are queued */
function queuedCount()
{
    return (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE status = \'queued\'');
}

/**
 * Runs the cron once a minute until nothing is queued or the deadline passes.
 *
 * @param int $deadline unix time
 * @return int cron runs it took
 */
function drainQueue($module, $deadline)
{
    $runs = 0;
    while (queuedCount() > 0 && time() < $deadline) {
        sleepToNextMinute();
        $summary = runCron($module);
        $runs++;
        smokeOut('INFO', 'cron run ' . $runs . ': ' . json_encode(isset($summary['queue']) ? $summary['queue'] : $summary));
    }

    return $runs;
}

/**
 * @param Order[] $orders
 * @return array status => how many of the orders' newest records are in that status ('none' for no record)
 */
function statusCounts(array $orders)
{
    $counts = array();
    foreach ($orders as $order) {
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        $key = $receipt ? (string) $receipt->status : 'none';
        $counts[$key] = (isset($counts[$key]) ? $counts[$key] : 0) + 1;
    }

    return $counts;
}

/** Creates $count orders and moves each to the trigger status in a tight loop (the status hook issues or queues it). @return Order[] */
function burstOfOrders($count, $triggerState)
{
    $orders = array();
    for ($i = 0; $i < $count; $i++) {
        $order = createOrder();
        if ($order instanceof Order && Validate::isLoadedObject($order)) {
            $orders[] = $order;
        }
    }
    assertSame($count, count($orders), $count . ' orders created');
    foreach ($orders as $order) {
        changeState($order, $triggerState);
    }

    return $orders;
}

/** @return int the module's own count of requests today (the day counter row) */
function moduleDayCount()
{
    $stored = (string) smokeReadSetting('IFIRMA_RATE_DAY_WINDOW');
    $parts = explode(':', $stored, 2);

    return (count($parts) === 2 && $parts[0] === date('Ymd')) ? (int) $parts[1] : 0;
}

/** Asserts that each order ended with exactly one document in the mock and that nothing is in an error state. */
function assertEveryOrderHasOneDocument(array $orders, $label)
{
    $counts = statusCounts($orders);
    assertSame(count($orders), (int) (isset($counts['created']) ? $counts['created'] : 0) + (int) (isset($counts['paid']) ? $counts['paid'] : 0), $label . ': every order is issued');
    assertSame(0, (int) (isset($counts['error']) ? $counts['error'] : 0) + (int) (isset($counts['unknown']) ? $counts['unknown'] : 0)
        + (int) (isset($counts['duplicate_suspected']) ? $counts['duplicate_suspected'] : 0), $label . ': no order is an error or unknown', json_encode($counts));
    $ids = array();
    foreach ($orders as $order) {
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        if ($receipt) {
            $ids[(string) $receipt->ifirma_id] = true;
        }
    }
    assertSame(count($orders), count($ids), $label . ': every order has its own document');
    assertMockHoldsOneDocumentPerOrder($orders, $label);
}

/**
 * The mock was reset before the orders were made, so the documents it holds now are exactly the ones these orders made:
 * as many as there are orders, all with their own id and number, and each one the document of exactly one order's record.
 *
 * @param Order[] $orders
 */
function assertMockHoldsOneDocumentPerOrder(array $orders, $label)
{
    $mockIds = array();
    $mockNumbers = array();
    $documents = isset(mockStatus()['documents']) ? mockStatus()['documents'] : array();
    foreach ($documents as $doc) {
        if (empty($doc['deleted'])) {
            $mockIds[] = (string) $doc['FakturaId'];
            $mockNumbers[] = (string) $doc['PelnyNumer'];
        }
    }
    assertSame(count($orders), count($mockIds), $label . ': the mock holds exactly one document per order', count($mockIds) . ' documents for ' . count($orders) . ' orders');
    assertSame(array(count($mockIds), count($mockIds)), array(count(array_unique($mockIds)), count(array_unique($mockNumbers))), $label . ': the mock documents have distinct ids and numbers');
    $recordIds = array();
    foreach ($orders as $order) {
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        $recordIds[] = $receipt ? (string) $receipt->ifirma_id : '';
    }
    sort($mockIds);
    sort($recordIds);
    assertSame($mockIds, $recordIds, $label . ': each mock document is the document of exactly one order\'s record');
}

/**
 * Right after the status change and before any cron run each order has its record, issued or queued.
 *
 * @param Order[] $orders
 */
function assertEveryOrderIsRecordedAtOnce(array $orders, $label)
{
    $withRecord = 0;
    $unexpected = array();
    foreach ($orders as $order) {
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        if (!$receipt) {
            continue;
        }
        $withRecord++;
        if (!$receipt->isIssued() && (string) $receipt->status !== 'queued') {
            $unexpected[(int) $order->id] = (string) $receipt->status;
        }
    }
    assertSame(count($orders), $withRecord, $label . ': every order has a record at once', $withRecord . ' of ' . count($orders) . ' orders have a record');
    assertSame(array(), $unexpected, $label . ': each record is issued or queued at once');
}

/**
 * @param Order[] $orders
 * @return int[] order id => delivery e-mails of its document in the sink (the sink is read once)
 */
function deliveryMailCountsByOrder(array $orders)
{
    $mails = mailFind(function () {
        return true;
    });
    $counts = array();
    foreach ($orders as $order) {
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        $number = $receipt ? (string) $receipt->ifirma_number : '';
        $counts[(int) $order->id] = 0;
        if ($number === '') {
            continue;
        }
        foreach ($mails as $mail) {
            $mentionsOrder = strpos((string) (isset($mail['text']) ? $mail['text'] : ''), (string) $order->reference) !== false
                || strpos((string) (isset($mail['html']) ? $mail['html'] : ''), (string) $order->reference) !== false;
            if ($mentionsOrder && strpos((string) $mail['subject'], $number) !== false) {
                $counts[(int) $order->id]++;
            }
        }
    }

    return $counts;
}

function scenarioR($module, $triggerState)
{
    section('R. Burst issuance under the limits');
    smokeClearCronWork();
    mockReset();
    mailClear();
    $saved = array();
    foreach (array('IFIRMA_RATE_WINDOW', 'IFIRMA_RATE_DAY_WINDOW', 'IFIRMA_RATE_BACKOFF_UNTIL', 'IFIRMA_CRON_MAX_ITEMS', 'IFIRMA_KSEF_MODE',
        'IFIRMA_RATE_MAX_PER_DAY', 'IFIRMA_RATE_MAX_PER_MIN', 'IFIRMA_AUTO_CREATE_STATE', 'IFIRMA_SEND_COPY_MODE', 'IFIRMA_DUPLICATE_CHECK') as $key) {
        $saved[$key] = smokeReadSetting($key);
    }
    $deadline = time() + 8 * 60;
    $watermark = smokeReceiptWatermark();
    $limitsBefore = mockLimitsAndSwitches();
    $queuedBefore = queuedCount();
    try {
        smokeSetting('IFIRMA_CRON_MAX_ITEMS', 50);
        smokeSetting('IFIRMA_SEND_COPY_MODE', 'none');
        // Every order of the burst is the same buyer with the same lines and total, so the duplicate guard would (rightly)
        // take each document for the earlier order's. The guard is not what R tests, and the in-flight marker still
        // protects a mid-create crash; scenarios A, A2 and B cover the guard.
        smokeSetting('IFIRMA_DUPLICATE_CHECK', 0);
        smokeSetting('IFIRMA_KSEF_MODE', 'off');
        smokeSetting('IFIRMA_AUTO_CREATE_STATE', $triggerState);
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        smokeSetting('IFIRMA_RATE_DAY_WINDOW', '');
        smokeSetting('IFIRMA_RATE_BACKOFF_UNTIL', '');

        // R1: 50 orders in a row; the module's own cap queues the ones it cannot send, the mock never refuses
        $started = time();
        $orders = burstOfOrders(50, $triggerState);
        $counts = statusCounts($orders);
        assertEveryOrderIsRecordedAtOnce($orders, 'R1');
        assertTrue(isset($counts['queued']) && $counts['queued'] >= 1, 'R1: at least one order is queued by the cap', json_encode($counts));
        assertSame(0, (int) (isset($counts['error']) ? $counts['error'] : 0) + (int) (isset($counts['unknown']) ? $counts['unknown'] : 0), 'R1: none is an error or unknown', json_encode($counts));
        $runs = drainQueue($module, $deadline);
        assertSame(0, queuedCount(), 'R1: the queue is drained', $runs . ' cron runs');
        assertEveryOrderHasOneDocument($orders, 'R1');
        $rate = mockRateStatus();
        assertSame(0, (int) (isset($rate['rejected_total']) ? $rate['rejected_total'] : -1), 'R1: the mock refused no call');
        assertTrue(isset($rate['peak_minute']) && (int) $rate['peak_minute'] <= 80, 'R1: no minute above the cap of 80', json_encode($rate));
        assertSame(50, deliveryMailCount(numbersOf($orders)), 'R1: 50 delivery mails');
        $oneMailEach = array();
        foreach ($orders as $order) {
            $oneMailEach[(int) $order->id] = 1;
        }
        assertSame($oneMailEach, deliveryMailCountsByOrder($orders), 'R1: every customer received exactly one e-mail');
        smokeOut('INFO', 'R1 took ' . (time() - $started) . ' s, peak minute ' . (isset($rate['peak_minute']) ? $rate['peak_minute'] : '?'));

        // R2: the mock's per-minute limit is below the module's cap: one refusal, then the back-off holds the rest
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        smokeSetting('IFIRMA_RATE_BACKOFF_UNTIL', '');
        mockRateLimit(array('per_minute' => 30));
        $orders = burstOfOrders(20, $triggerState);
        $rate = mockRateStatus();
        assertTrue((int) $rate['rejected_total'] <= 1, 'R2: the mock refused at most one call', json_encode($rate));
        $counts = statusCounts($orders);
        assertTrue(isset($counts['queued']) && $counts['queued'] >= 1, 'R2: the refused orders are queued', json_encode($counts));
        assertSame(0, (int) (isset($counts['error']) ? $counts['error'] : 0) + (int) (isset($counts['unknown']) ? $counts['unknown'] : 0), 'R2: nothing is an error or unknown', json_encode($counts));
        mockRateLimit(array('per_minute' => 100));
        mockNextWindow('minute');
        smokeSetting('IFIRMA_RATE_BACKOFF_UNTIL', '');
        drainQueue($module, $deadline);
        assertEveryOrderHasOneDocument($orders, 'R2');

        // R3: the mock's day limit: one refusal, the orders are queued and the next cron run sends nothing
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        smokeSetting('IFIRMA_RATE_BACKOFF_UNTIL', '');
        $today = (int) mockRateStatus()['calls_today'];
        mockRateLimit(array('per_day' => $today + 15));
        $orders = burstOfOrders(10, $triggerState);
        assertTrue((int) mockRateStatus()['rejected_total'] <= 1, 'R3: the mock refused at most one call');
        assertTrue(queuedCount() >= 1, 'R3: the refused orders are queued');
        $before = (int) mockRateStatus()['calls_total'];
        runCron($module);
        assertSame($before, (int) mockRateStatus()['calls_total'], 'R3: a cron run during the back-off makes no call');
        mockNextWindow('day');
        mockRateLimit(array('per_day' => 15000));
        smokeSetting('IFIRMA_RATE_BACKOFF_UNTIL', '');
        drainQueue($module, $deadline);
        assertEveryOrderHasOneDocument($orders, 'R3');

        // R4: the module's own day cap: no refusal from the mock at all
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        smokeSetting('IFIRMA_RATE_BACKOFF_UNTIL', '');
        smokeSetting('IFIRMA_RATE_MAX_PER_DAY', moduleDayCount() + 10);
        $orders = burstOfOrders(10, $triggerState);
        assertSame(0, (int) mockRateStatus()['rejected_total'], 'R4: the mock refused nothing');
        assertTrue(queuedCount() >= 1, 'R4: the orders over the module cap are queued');
        smokeSetting('IFIRMA_RATE_DAY_WINDOW', '');
        smokeSetting('IFIRMA_RATE_MAX_PER_DAY', 14000);
        drainQueue($module, $deadline);
        assertEveryOrderHasOneDocument($orders, 'R4');

        // the same endpoint over HTTP, so the controller wiring is covered
        $http = cronOverHttp();
        if (in_array($http['code'], array(200), true)) {
            assertTrue(is_array($http['data']) && isset($http['data']['queue']) && isset($http['data']['rate_limited']), 'R: the cron URL answers the queue block', substr((string) $http['raw'], 0, 160));
        } else {
            skip('R: cron over HTTP', 'localhost answered HTTP ' . $http['code'] . ' (set PS_LOCAL_URL)');
        }
    } finally {
        smokeClearCronWork();
        smokeForgetReceiptsAfter($watermark);
        foreach ($saved as $key => $value) {
            smokeRestoreSetting($key, $value);
        }
        mockReset();
    }

    // what the scenarios after R see: the limits, caps, counters and back-off as before R, and no queue left over
    assertSame($limitsBefore, mockLimitsAndSwitches(), 'R: the mock\'s limits and switches are as before the scenario');
    $settingsAfter = array();
    foreach ($saved as $key => $value) {
        $settingsAfter[$key] = smokeReadSetting($key);
    }
    assertSame($saved, $settingsAfter, 'R: the module\'s caps, counters, back-off and item cap are as before the scenario');
    assertSame($queuedBefore, queuedCount(), 'R: no queued order is left behind');
}

/** @return array the mock's limits and switches, which a later scenario depends on */
function mockLimitsAndSwitches()
{
    $status = mockStatus();

    return array(
        'per_minute' => isset($status['rate_limit']['per_minute']) ? $status['rate_limit']['per_minute'] : null,
        'per_day' => isset($status['rate_limit']['per_day']) ? $status['rate_limit']['per_day'] : null,
        'switches' => isset($status['switches']) ? $status['switches'] : null,
    );
}

/** Creates a fresh order whose invoice address carries a NIP (or none) and returns array(order, address backup). */
function smokeBusinessOrder($nip)
{
    $order = createOrder();
    $address = new Address((int) $order->id_address_invoice);
    $backup = array('vat_number' => $address->vat_number, 'dni' => $address->dni);
    $address->vat_number = $nip;
    $address->update();

    return array($order, $address, $backup);
}

/** @return array the answer (code, body, headers, url) to the customer's download of the document, logged in as the owner */
function smokeDownloadAs(Order $order, $receipt)
{
    $customer = new Customer((int) $order->id_customer);
    $context = Context::getContext();
    $web = new SmokeWeb();
    $web->request('GET', $context->link->getPageLink('authentication'));
    $web->request('POST', $context->link->getPageLink('authentication'), array(
        'email' => $customer->email, 'password' => '123456789', 'submitLogin' => '1', 'back' => 'my-account',
    ));

    return $web->request('GET', $context->link->getModuleLink('ifirma', 'receipt', array('token' => $receipt->security_token)));
}

/** @return int the HTTP code of the customer's download of the document, logged in as the owner */
function smokeDownloadCode(Order $order, $receipt)
{
    $pdf = smokeDownloadAs($order, $receipt);

    return (int) $pdf['code'];
}

/** @return array the mock's stored document of this record (empty when the mock holds none with that id) */
function mockDocumentOf($receipt)
{
    $documents = isset(mockStatus()['documents']) ? mockStatus()['documents'] : array();
    foreach ($documents as $doc) {
        if ($receipt && (string) $doc['FakturaId'] === (string) $receipt->ifirma_id) {
            return $doc;
        }
    }

    return array();
}

function scenarioS($module)
{
    section('S. KSeF-confirmed delivery');
    smokeClearCronWork();
    mockReset();
    mailClear();
    $saved = array();
    foreach (array('IFIRMA_KSEF_MODE', 'IFIRMA_KSEF_SUBMITTER', 'IFIRMA_SEND_CHANNEL', 'IFIRMA_SEND_COPY_MODE', 'IFIRMA_RATE_WINDOW', 'IFIRMA_RATE_BACKOFF_UNTIL') as $key) {
        $saved[$key] = smokeReadSetting($key);
    }
    $backups = array();
    try {
        smokeSetting('IFIRMA_KSEF_MODE', 'b2b');
        smokeSetting('IFIRMA_KSEF_SUBMITTER', 'module');
        smokeSetting('IFIRMA_SEND_CHANNEL', 'shop_mail');
        smokeSetting('IFIRMA_SEND_COPY_MODE', 'none');
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        smokeSetting('IFIRMA_RATE_BACKOFF_UNTIL', '');
        $sendCalls = function ($receipt) {
            foreach (mockStatus()['documents'] as $doc) {
                if ((string) $doc['FakturaId'] === (string) $receipt->ifirma_id) {
                    return (int) $doc['ksef_send_calls'];
                }
            }

            return -1;
        };

        // S1: the module submits, KSeF accepts, the cron delivers once
        list($order, $address, $backup) = smokeBusinessOrder('5260001122');
        $backups[] = array($address, $backup);
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        assertTrue($result->ok && $receipt && $receipt->isIssued(), 'S1: the invoice is issued', $result->message);
        assertSame('awaiting_ksef', $receipt ? (string) $receipt->delivery_status : null, 'S1: delivery waits for KSeF');
        assertSame(0, deliveryMailCountFor($order), 'S1: no e-mail before acceptance');
        assertSame(1, $sendCalls($receipt), 'S1: the module sent the invoice to KSeF once');
        assertSame(404, smokeDownloadCode($order, $receipt), 'S1: the customer cannot download a waiting document');
        $accepted = mockRequest('POST', '/__mock/ksef/accept/' . rawurlencode((string) $receipt->ifirma_id));
        assertSame(200, $accepted['code'], 'S1: the mock accepts the invoice');
        runCron($module);
        $receipt = receiptOf($order);
        assertSame('sent', $receipt ? (string) $receipt->delivery_status : null, 'S1: one cron run stores the number and delivers');
        $mockDoc = mockDocumentOf($receipt);
        assertTrue(isset($mockDoc['KsefNumer']) && (string) $mockDoc['KsefNumer'] !== '', 'S1: the mock holds a KSeF number for the accepted invoice', json_encode($mockDoc));
        assertSame(isset($mockDoc['KsefNumer']) ? (string) $mockDoc['KsefNumer'] : '', $receipt ? (string) $receipt->ksef_number : null, 'S1: the record stores the KSeF number the mock reports');
        assertSame('PRZYJETA', $receipt ? (string) $receipt->ksef_status : null, 'S1: the record stores the accepted KSeF state');
        assertSame(1, deliveryMailCountFor($order), 'S1: exactly one e-mail after acceptance');
        assertSame(1, $sendCalls($receipt), 'S1: still one KSeF send call');
        assertSame(200, smokeDownloadCode($order, $receipt), 'S1: the customer can download after acceptance');

        // S2: iFirma sent it on its own: the module makes no send call
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        mockKsefAuto('send');
        list($order, $address, $backup) = smokeBusinessOrder('5260001122');
        $backups[] = array($address, $backup);
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        $ksefStep = null;
        foreach ($result->steps as $step) {
            if ($step['step'] === 'ksef') {
                $ksefStep = $step;
            }
        }
        assertSame('skipped', $ksefStep ? $ksefStep['status'] : null, 'S2: the ksef step is skipped');
        assertSame('already sent by iFirma', $ksefStep ? $ksefStep['message'] : null, 'S2: the reason names iFirma');
        assertSame(0, $sendCalls($receipt), 'S2: no KSeF send call by the module');
        assertSame('awaiting_ksef', $receipt ? (string) $receipt->delivery_status : null, 'S2: delivery waits');
        $mockDoc = mockDocumentOf($receipt);
        assertSame('WYSLANA', isset($mockDoc['KsefStatus']) ? (string) $mockDoc['KsefStatus'] : null, 'S2: the mock holds the KSeF state iFirma set on its own');
        assertSame('WYSLANA', $receipt ? (string) $receipt->ksef_status : null, 'S2: the record shows the KSeF state the mock reports');
        assertSame(0, deliveryMailCountFor($order), 'S2: no e-mail before acceptance');
        mockRequest('POST', '/__mock/ksef/accept/' . rawurlencode((string) $receipt->ifirma_id));
        runCron($module);
        assertSame('sent', (string) receiptOf($order)->delivery_status, 'S2: delivered after acceptance');
        assertSame(1, deliveryMailCountFor($order), 'S2: exactly one e-mail after iFirma\'s submission is accepted');
        assertSame(0, $sendCalls($receipt), 'S2: still no send call');

        // S3: the submitter is iFirma and iFirma has not sent yet (the mock's ksef-auto stays off, as when iFirma sends at a
        // scheduled hour): the module sends nothing and only waits; then the mock accepts and the cron delivers once
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        smokeSetting('IFIRMA_KSEF_SUBMITTER', 'ifirma');
        list($order, $address, $backup) = smokeBusinessOrder('5260001122');
        $backups[] = array($address, $backup);
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        $ksefStep = null;
        foreach ($result->steps as $step) {
            if ($step['step'] === 'ksef') {
                $ksefStep = $step;
            }
        }
        assertSame('sent by iFirma', $ksefStep ? $ksefStep['message'] : null, 'S3: the ksef step says iFirma sends (iFirma has not sent yet)');
        assertSame(0, $sendCalls($receipt), 'S3: no KSeF send call (iFirma has not sent yet)');
        assertSame('awaiting_ksef', $receipt ? (string) $receipt->delivery_status : null, 'S3: delivery waits (iFirma has not sent yet)');
        runCron($module);
        $receipt = receiptOf($order);
        assertSame('awaiting_ksef', $receipt ? (string) $receipt->delivery_status : null, 'S3: a cron run while iFirma has not sent delivers nothing');
        assertSame(0, deliveryMailCountFor($order), 'S3: no e-mail while waiting for iFirma');
        assertSame(0, $sendCalls($receipt), 'S3: the cron sends nothing to KSeF either');
        assertSame(404, smokeDownloadCode($order, $receipt), 'S3: the customer cannot download while waiting for iFirma');
        mockRequest('POST', '/__mock/ksef/accept/' . rawurlencode((string) $receipt->ifirma_id));
        runCron($module);
        $receipt = receiptOf($order);
        assertSame('sent', $receipt ? (string) $receipt->delivery_status : null, 'S3: delivered once iFirma\'s submission is accepted');
        $mockDoc = mockDocumentOf($receipt);
        assertTrue(isset($mockDoc['KsefNumer']) && (string) $mockDoc['KsefNumer'] !== '' && $receipt && (string) $receipt->ksef_number === (string) $mockDoc['KsefNumer'], 'S3: the record stores the KSeF number after acceptance', json_encode($mockDoc));
        assertSame(1, deliveryMailCountFor($order), 'S3: exactly one e-mail after acceptance');
        assertSame(0, $sendCalls($receipt), 'S3: the module never sent to KSeF');
        assertSame(200, smokeDownloadCode($order, $receipt), 'S3: the customer can download after acceptance');

        // S3 with the mock sending on its own (the variant the scenario had before): still no module send call
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        mockKsefAuto('send');
        list($order, $address, $backup) = smokeBusinessOrder('5260001122');
        $backups[] = array($address, $backup);
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        $ksefStep = null;
        foreach ($result->steps as $step) {
            if ($step['step'] === 'ksef') {
                $ksefStep = $step;
            }
        }
        assertSame('sent by iFirma', $ksefStep ? $ksefStep['message'] : null, 'S3: the ksef step says iFirma sends');
        assertSame(0, $sendCalls($receipt), 'S3: no KSeF send call');
        assertSame('awaiting_ksef', $receipt ? (string) $receipt->delivery_status : null, 'S3: delivery waits');
        mockKsefAuto('off');
        smokeSetting('IFIRMA_KSEF_SUBMITTER', 'module');

        // S4: KSeF rejects: no e-mail, the delivery keeps waiting, the feed lists it
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        list($order, $address, $backup) = smokeBusinessOrder('5260001122');
        $backups[] = array($address, $backup);
        IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        mockRequest('POST', '/__mock/ksef/reject/' . rawurlencode((string) $receipt->ifirma_id));
        runCron($module);
        $receipt = receiptOf($order);
        assertSame('ODRZUCONA', $receipt ? (string) $receipt->ksef_status : null, 'S4: the rejection is stored');
        assertSame('awaiting_ksef', $receipt ? (string) $receipt->delivery_status : null, 'S4: the delivery keeps waiting');
        assertSame(0, deliveryMailCountFor($order), 'S4: no e-mail for a rejected invoice');
        $kinds = array();
        foreach ((new IfirmaIssueFeed())->latest(1000) as $item) {
            if ((int) $item['id_order'] === (int) $order->id) {
                $kinds[] = $item['kind'];
            }
        }
        assertTrue(in_array('ksef_rejected', $kinds, true), 'S4: the issue feed lists the rejection', json_encode(array($kinds, $receipt->status, (int) $receipt->remote_missing, $receipt->delivery_status)));

        // S5: a document that does not go to KSeF is delivered at issuance
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        list($order, $address, $backup) = smokeBusinessOrder('');
        $backups[] = array($address, $backup);
        $result = IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        $receipt = receiptOf($order);
        assertSame('sent', $receipt ? (string) $receipt->delivery_status : null, 'S5: a private buyer is delivered at issuance');
        assertSame(1, deliveryMailCountFor($order), 'S5: one e-mail for the private buyer');
        assertSame(0, $sendCalls($receipt), 'S5: no KSeF send call for the private buyer');
        mockReset();
        mailClear();
        smokeSetting('IFIRMA_RATE_WINDOW', '');
        smokeSetting('IFIRMA_KSEF_MODE', 'off');
        list($order, $address, $backup) = smokeBusinessOrder('5260001122');
        $backups[] = array($address, $backup);
        IfirmaIssuancePipeline::build($module)->run($order, array('trigger' => 'manual'));
        assertSame('sent', (string) receiptOf($order)->delivery_status, 'S5: mode off delivers at issuance');
        assertSame(1, deliveryMailCountFor($order), 'S5: one e-mail in mode off');
        assertSame(0, $sendCalls(receiptOf($order)), 'S5: no KSeF send call in mode off');
    } finally {
        smokeClearCronWork();
        foreach (array_reverse($backups) as $pair) {
            $pair[0]->vat_number = $pair[1]['vat_number'];
            $pair[0]->dni = $pair[1]['dni'];
            $pair[0]->update();
        }
        foreach ($saved as $key => $value) {
            smokeRestoreSetting($key, $value);
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
        // The company request carries a NIP, so in KSeF mode b2b the invoice would wait for KSeF acceptance instead of being
        // delivered at once (scenario S covers that). C is about the data request, so KSeF is off while it runs.
        $ksefMode = smokeSetting('IFIRMA_KSEF_MODE', 'off');
        try {
            scenarioC($module, $actions, $triggerState);
        } finally {
            smokeRestoreSetting('IFIRMA_KSEF_MODE', $ksefMode);
        }
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
    'N' => function () use ($module, $actions) {
        scenarioN($module, $actions);
    },
    'O' => function () use ($module, $triggerState) {
        scenarioO($module, $triggerState);
    },
    'P' => function () use ($module, $triggerState) {
        scenarioP($module, $triggerState);
    },
    'Q' => function () use ($module, $actions) {
        scenarioQ($module, $actions);
    },
    'R' => function () use ($module, $triggerState) {
        scenarioR($module, $triggerState);
    },
    'S' => function () use ($module) {
        scenarioS($module);
    },
);

if (array_keys($scenarios) !== $smokeScenarioNames) {
    fwrite(STDERR, 'The scenario map (' . implode(',', array_keys($scenarios)) . ') and $smokeScenarioNames ('
        . implode(',', $smokeScenarioNames) . ") differ; keep them in sync.\n");
    exit(2);
}

section('Scenario selection');
echo $smokeSelection->describe() . "\n";
if ($smokeSelection->notSelected() !== array()) {
    echo 'not selected (a partial run): ' . implode(', ', $smokeSelection->notSelected()) . "\n";
}

$executedChecks = array();
foreach ($scenarios as $name => $fn) {
    if (!$smokeSelection->runs($name)) {
        continue;
    }
    $before = $GLOBALS['smoke']['ok'] + $GLOBALS['smoke']['fail'];
    try {
        $fn();
    } catch (Exception $e) {
        assertTrue(false, 'scenario ' . $name . ' ran without exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    } catch (Throwable $e) {
        assertTrue(false, 'scenario ' . $name . ' ran without error', get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
    $executedChecks[$name] = $GLOBALS['smoke']['ok'] + $GLOBALS['smoke']['fail'] - $before;
}

// ---------------------------------------------------------------- check floor

$floorFile = __DIR__ . '/check-floor.json';
$floorProblems = array();
if (getenv('SMOKE_WRITE_FLOOR') === '1') {
    try {
        $existingFloor = is_file($floorFile) ? SmokeCheckFloor::parse(file_get_contents($floorFile)) : array();
        file_put_contents($floorFile, SmokeCheckFloor::render($executedChecks, $GLOBALS['smoke']['fail'], $existingFloor));
        smokeOut('INFO', 'check floor written to tests/smoke/check-floor.json for ' . implode(', ', array_keys($executedChecks)));
    } catch (RuntimeException $e) {
        $floorProblems[] = 'check floor: ' . $e->getMessage();
    }
} else {
    // a missing or malformed floor file fails the run: a run whose check count is not verified is not green
    $floorProblems = SmokeCheckFloor::problems($executedChecks, is_file($floorFile) ? file_get_contents($floorFile) : null, $smokeSelection->selected());
}
foreach ($floorProblems as $problem) {
    assertTrue(false, $problem);
}

// ---------------------------------------------------------------- summary

$s = $GLOBALS['smoke'];
echo "\n=== Summary ===\n";
echo 'passed ' . $s['ok'] . ', failed ' . $s['fail'] . ', skipped ' . $s['skip'] . "\n";
echo $smokeSelection->describe() . "\n";
foreach ($s['failures'] as $label) {
    echo SmokeOutputMask::mask('  FAILED: ' . $label, $GLOBALS['smokeSecrets']) . "\n";
}
exit($s['fail'] > 0 ? 1 : 0);
