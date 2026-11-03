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

// End-to-end check of contractor linking on the Docker shop: seed an "old" contractor in the mock,
// import a CSV that names the demo customer, verify the mapping, then issue a document and verify
// it was created with the mapped identifier (no PS-C contractor created).
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
function check($cond, $label, $detail = '')
{
    global $fail;
    echo '[' . ($cond ? ' OK ' : 'FAIL') . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
    if (!$cond) {
        $fail++;
    }
}
$mockHost = getenv('IFIRMA_MOCK_HOST') ?: 'http://ifirma-mock:8001';
function mockPost($url, array $body)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => array('Content-Type: application/json')));
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, $out);
}

// demo customer 2 (John DOE) and its address
$customer = new Customer(2);
$idAddress = (int) $db->getValue('SELECT id_address FROM ' . _DB_PREFIX_ . 'address WHERE id_customer = 2 AND deleted = 0 ORDER BY id_address');
$address = new Address($idAddress);
echo "customer 2: {$customer->firstname} {$customer->lastname} <{$customer->email}> postcode {$address->postcode}\n";

// 1. seed an "old" contractor in the mock with its own identifier (not PS-C2)
list($code, $out) = mockPost($mockHost . '/__mock/contractors', array(
    'Identyfikator' => 'OLD-DOE-01', 'Nazwa' => $customer->firstname . ' ' . $customer->lastname,
    'Email' => $customer->email, 'KodPocztowy' => $address->postcode, 'Miejscowosc' => $address->city,
    'Ulica' => $address->address1, 'Kraj' => 'Polska', 'KodKraju' => 'PL', 'OsobaFizyczna' => true,
));
check($code === 200, 'old contractor seeded in the mock', 'HTTP ' . $code . ' ' . substr((string) $out, 0, 120));

// 2. import a CSV that lists that contractor (matched by e-mail)
IfirmaContractorMap::unlinkCustomer(2);
$csv = "\xEF\xBB\xBFIdentyfikator;Nazwa;NIP;E-mail;Kod pocztowy\n"
    . "OLD-DOE-01;" . $customer->firstname . ' ' . $customer->lastname . ";;" . $customer->email . ";" . $address->postcode . "\n"
    . "OTHER-01;Nobody Known;;nobody@example.invalid;00-000\n";
$import = new IfirmaContractorImport();
$parsed = $import->parse($csv);
check(empty($parsed['errors']) && count($parsed['rows']) === 2, 'CSV parsed (2 rows, no errors)', json_encode($parsed['errors']));
$report = $import->match($parsed['rows']);
$import->apply($report);
check((int) $report['linked'] >= 1, 'import linked the demo customer', json_encode(array('linked' => $report['linked'], 'by' => $report['by'], 'unmatched' => $report['unmatched'])));
$map = IfirmaContractorMap::getByCustomer(2);
check($map && $map->ifirma_identifier === 'OLD-DOE-01', 'mapping stored for customer 2', $map ? $map->ifirma_identifier . ' / ' . $map->matched_by : 'none');

// 3. issue a document for the newest order of customer 2 without a receipt
$idOrder = (int) $db->getValue('SELECT o.id_order FROM ' . _DB_PREFIX_ . 'orders o LEFT JOIN ' . _DB_PREFIX_ . 'ifirma_receipt r ON r.id_order = o.id_order WHERE o.id_customer = 2 AND r.id_ifirma_receipt IS NULL ORDER BY o.id_order DESC');
check($idOrder > 0, 'found an order of customer 2 without a receipt', (string) $idOrder);
if ($idOrder > 0) {
    IfirmaDataRequestService::build($module); // ensure classes loaded
    $receipt = IfirmaReceiptService::build($module)->createReceipt(new Order($idOrder), array('skip_delivery' => true));
    $payload = json_decode((string) $receipt->request_payload, true);
    $sentId = isset($payload['Kontrahent']['Identyfikator']) ? $payload['Kontrahent']['Identyfikator'] : '(absent)';
    check($receipt->isIssued(), 'document issued', $receipt->status . ' ' . $receipt->last_error);
    check($sentId === 'OLD-DOE-01', 'document used the mapped contractor', 'Identyfikator sent: ' . $sentId . '; _contractor: ' . json_encode(isset($payload['_contractor']) ? $payload['_contractor'] : null));
}
echo ($fail ? 'IMPORT_PROBE_FAILED ' . $fail : 'IMPORT_PROBE_OK') . "\n";
exit($fail ? 1 : 0);
