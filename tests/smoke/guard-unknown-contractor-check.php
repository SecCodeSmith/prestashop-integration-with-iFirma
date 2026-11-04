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
// Smoke check: reproduces the production failure (2026-11-02) "Zatrzymano na kroku guard (inconclusive): Kod 201 Nie znaleziono
// kontrahenta" against the mock: (1) the list call with an unknown kontrahent filter is rejected with
// Kod 201, (2) the guard treats it as "no documents" and proceeds, (3) the pipeline issues the document.
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
function buildClient()
{
    $baseUrl = Configuration::get('IFIRMA_BASE_URL');
    return new IfirmaApiClient(Configuration::get('IFIRMA_USER'), array(
        'faktura' => Configuration::get('IFIRMA_KEY_FAKTURA'),
        'abonent' => Configuration::get('IFIRMA_KEY_ABONENT'),
        'rachunek' => Configuration::get('IFIRMA_KEY_RACHUNEK'),
    ), $baseUrl ?: IfirmaApiClient::BASE_URL);
}
function check($cond, $label, $detail = '')
{
    global $fail;
    echo '[' . ($cond ? ' OK ' : 'FAIL') . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
    if (!$cond) {
        $fail++;
    }
}

$mockHost = getenv('IFIRMA_MOCK_HOST') ?: 'http://ifirma-mock:8001';
$ch = curl_init($mockHost . '/__mock/reset');
curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
$out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
check($code === 200, 'mock state reset (no contractors, no documents)', 'HTTP ' . $code);

// 1. the raw list call with an unknown contractor filter is rejected with Kod 201 (live-API behaviour)
$client = buildClient();
$rows = $client->listInvoices(array('dataOd' => date('Y-m-d', time() - 86400 * 30), 'dataDo' => date('Y-m-d'), 'iloscNaStronie' => 50, 'kontrahent' => 'PS-C999999'));
check($rows === null && (int) $client->getLastKod() === 201, 'list with unknown kontrahent is rejected with Kod 201', 'kod=' . var_export($client->getLastKod(), true) . ' error=' . $client->getLastError());

// 2. pick the newest order without a receipt whose customer has no mapping and no PS-C contractor in the mock
$idOrder = (int) $db->getValue('SELECT o.id_order FROM ' . _DB_PREFIX_ . 'orders o LEFT JOIN ' . _DB_PREFIX_ . 'ifirma_receipt r ON r.id_order = o.id_order WHERE (r.id_ifirma_receipt IS NULL OR r.ifirma_id = "") ORDER BY o.id_order DESC');
if ($idOrder <= 0) {
    // Every order already carries a document from earlier runs: recycle the newest one. Its module
    // receipt row is dropped so the flow under test starts from a clean order (test shop only).
    $idOrder = (int) $db->getValue('SELECT MAX(id_order) FROM ' . _DB_PREFIX_ . 'orders');
    if ($idOrder > 0) {
        $db->delete('ifirma_receipt', 'id_order = ' . (int) $idOrder);
        echo "recycled order $idOrder (previous receipt row removed)
";
    }
}
check($idOrder > 0, 'found an order without an issued receipt', (string) $idOrder);
if ($idOrder > 0) {
    $order = new Order($idOrder);
    IfirmaContractorMap::unlinkCustomer((int) $order->id_customer);
    $probe = buildClient();
    $probe->get('kontrahenci/id/' . rawurlencode('PS-C' . (int) $order->id_customer) . '.json', 'faktura');
    check((int) $probe->getLastKod() !== 0, 'customer ' . (int) $order->id_customer . ' has no PS-C contractor in the mock yet', 'kod=' . var_export($probe->getLastKod(), true));

    // 3. the guard alone: must proceed, not stop as inconclusive
    $guard = IfirmaDuplicateGuard::build($module);
    $g = $guard->check($order);
    check($g->outcome === IfirmaGuardResult::OUTCOME_PROCEED, 'guard proceeds although the kontrahent filter was rejected', 'outcome=' . $g->outcome . ' queries=' . $g->queries . ' error=' . $g->error);

    // 4. the whole pipeline issues the document
    $pipeline = IfirmaIssuancePipeline::build($module);
    $result = $pipeline->run($order, array('skip_delivery' => true));
    check($result->ok && $result->outcome === 'issued', 'pipeline outcome is issued', 'outcome=' . $result->outcome . ' message=' . $result->message);
    $receipt = IfirmaReceipt::getByOrder($idOrder);
    $payload = $receipt ? json_decode((string) $receipt->request_payload, true) : null;
    check($receipt && $receipt->isIssued(), 'receipt stored as issued', $receipt ? $receipt->status . ' ' . $receipt->ifirma_number : 'none');
    check(is_array($payload) && isset($payload['IdentyfikatorKontrahenta']) && $payload['IdentyfikatorKontrahenta'] === 'PS-C' . (int) $order->id_customer, 'document referenced the freshly created PS-C contractor', is_array($payload) ? json_encode(isset($payload['_contractor']) ? $payload['_contractor'] : null) : 'no payload');
}
echo ($fail ? 'GUARD_PROBE_FAILED ' . $fail : 'GUARD_PROBE_OK') . "\n";
exit($fail ? 1 : 0);
