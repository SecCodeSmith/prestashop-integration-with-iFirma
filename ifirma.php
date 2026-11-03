<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/IfirmaSchema.php';
require_once __DIR__ . '/classes/IfirmaApiClient.php';
require_once __DIR__ . '/classes/IfirmaReceipt.php';
require_once __DIR__ . '/classes/IfirmaPaymentMethodResolver.php';
require_once __DIR__ . '/classes/IfirmaDataRequest.php';
require_once __DIR__ . '/classes/IfirmaContractorMap.php';
require_once __DIR__ . '/classes/IfirmaContractorService.php';
require_once __DIR__ . '/classes/IfirmaContractorImport.php';
require_once __DIR__ . '/classes/IfirmaReceiptService.php';
require_once __DIR__ . '/classes/IfirmaDuplicateGuard.php';
require_once __DIR__ . '/classes/IfirmaDeliveryService.php';
require_once __DIR__ . '/classes/IfirmaStatusSyncService.php';
require_once __DIR__ . '/classes/IfirmaDataRequestService.php';
require_once __DIR__ . '/classes/IfirmaIssuancePipeline.php';
require_once __DIR__ . '/classes/IfirmaCustomerView.php';
require_once __DIR__ . '/classes/IfirmaAdminActions.php';

class Ifirma extends Module
{
    /** @var string */
    const STATUS_PAID = 'paid';

    /** @var bool */
    private static $adminOrderRendered = false;

    public function __construct()
    {
        $this->name = 'ifirma';
        $this->tab = 'billing_invoicing';
        $this->version = '1.1.0';
        $this->author = 'SCM Jakub Berechowskir';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => '9.99.99');
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('iFirma – Receipts & Invoices');
        $this->description = $this->l('Issue iFirma receipts/invoices from the order page, register payments and let customers download their document.');
        $this->confirmUninstall = $this->l('Remove all iFirma settings and the local receipt log? Documents already issued in iFirma are NOT affected.');
    }

    public function install()
    {
        return parent::install()
            && $this->installSql()
            && $this->installTab()
            && $this->registerHook('displayAdminOrder')
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('header')
            && $this->installDefaultConfiguration();
    }

    public function uninstall()
    {
        return $this->uninstallSql()
            && $this->uninstallTab()
            && $this->uninstallConfiguration()
            && parent::uninstall();
    }

    private function installSql()
    {
        return (bool) (include __DIR__ . '/sql/install.php');
    }

    private function uninstallSql()
    {
        return (bool) (include __DIR__ . '/sql/uninstall.php');
    }

    private function installDefaultConfiguration()
    {
        foreach (IfirmaSchema::configurationDefaults() as $key => $value) {
            if ($value === null) {
                $value = IfirmaSchema::generateCronToken();
            }
            Configuration::updateValue($key, $value);
        }

        return true;
    }

    private function uninstallConfiguration()
    {
        foreach (array_keys(IfirmaSchema::configurationDefaults()) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    private function installTab()
    {
        $tab = new Tab();
        $tab->class_name = 'AdminIfirma';
        $tab->module = $this->name;
        $tab->active = 1;
        $tab->id_parent = -1;

        $tab->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[$lang['id_lang']] = 'iFirma';
        }

        return $tab->add();
    }

    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminIfirma');
        if ($idTab) {
            $tab = new Tab($idTab);

            return $tab->delete();
        }

        return true;
    }

    public function getContent()
    {
        $output = '';

        $report = null;
        $errors = array();
        if (Tools::isSubmit('submitIfirmaRegenerateCron')) {
            $output .= $this->regenerateCronToken();
        } elseif (Tools::isSubmit('submitIfirma')) {
            $output .= $this->postProcessConfiguration();
        } elseif (Tools::isSubmit('submitIfirmaImportContractors')) {
            list($report, $errors) = $this->processContractorImport();
        } elseif (Tools::isSubmit('submitIfirmaResetContractorMap')) {
            $output .= $this->resetContractorMap();
        }

        return $output . $this->renderConfigForm() . $this->renderContractorsPanel($report, $errors);
    }

    /**
     * Test seam: callable(string $tmpName): bool replacing is_uploaded_file() for the CSV upload.
     *
     * @var callable|null
     */
    public static $uploadValidator = null;

    /** Maximum size of the contractor CSV upload (bytes). */
    const CONTRACTOR_CSV_MAX_BYTES = 5242880;

    /**
     * Handles the contractor CSV upload.
     *
     * @return array [report|null, list of error strings]
     */
    private function processContractorImport()
    {
        $file = isset($_FILES['IFIRMA_CONTRACTOR_CSV']) && is_array($_FILES['IFIRMA_CONTRACTOR_CSV'])
            ? $_FILES['IFIRMA_CONTRACTOR_CSV']
            : null;
        if ($file === null || empty($file['tmp_name']) || (isset($file['error']) && (int) $file['error'] !== 0)) {
            return array(null, array($this->l('No file was uploaded or the upload failed.')));
        }
        $tmp = (string) $file['tmp_name'];
        if (isset($file['size']) && (int) $file['size'] > self::CONTRACTOR_CSV_MAX_BYTES) {
            return array(null, array($this->l('The file is larger than 5 MB.')));
        }
        if (is_callable(self::$uploadValidator)) {
            $valid = (bool) call_user_func(self::$uploadValidator, $tmp);
        } else {
            $valid = !function_exists('is_uploaded_file') || is_uploaded_file($tmp);
        }
        $content = $valid && is_readable($tmp) ? file_get_contents($tmp) : false;
        if ($content === false) {
            return array(null, array($this->l('The uploaded file could not be read.')));
        }
        if (strlen($content) > self::CONTRACTOR_CSV_MAX_BYTES) {
            return array(null, array($this->l('The file is larger than 5 MB.')));
        }

        $import = new IfirmaContractorImport();
        $report = $import->import($content);

        $errors = array();
        foreach ($report['errors'] as $error) {
            if (preg_match('/^missing column: (.+)$/', $error, $m)) {
                $errors[] = sprintf($this->l('Missing column: %s. Nothing was imported.'), $m[1]);
            } elseif ($error === 'empty file' || $error === 'no contractor rows found') {
                $errors[] = $this->l('The file contains no contractor rows. Nothing was imported.');
            } else {
                $errors[] = $error;
            }
        }
        if (empty($report['rows'])) {
            return array(null, $errors);
        }
        $report['unmatched_text'] = implode(', ', $report['unmatched_rows']);

        return array($report, $errors);
    }

    /**
     * @return string HTML notice
     */
    private function resetContractorMap()
    {
        if ((int) Tools::getValue('confirm') !== 1) {
            return $this->displayError($this->l('The links were not removed: confirmation is missing.'));
        }
        IfirmaContractorMap::truncateAll();

        return $this->displayConfirmation($this->l('All customer to contractor links were removed.'));
    }

    /**
     * @param array|null $report
     * @param array      $errors
     * @return string HTML
     */
    private function renderContractorsPanel($report, array $errors)
    {
        try {
            $counts = IfirmaContractorMap::countByMatchType();
        } catch (Exception $e) {
            $counts = array();
        }
        $token = Tools::getAdminTokenLite('AdminModules');
        $this->context->smarty->assign(array(
            'ifirma_cm_counts' => $counts,
            'ifirma_cm_report' => $report,
            'ifirma_cm_errors' => $errors,
            'ifirma_cm_token' => $token,
            'ifirma_cm_action' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . $token,
        ));

        return $this->display(__FILE__, 'views/templates/admin/contractors.tpl');
    }

    /**
     * @param int $idCustomer
     * @return array|null identifier, matched_by, matched_by_label, name
     */
    private function contractorMapFor($idCustomer)
    {
        if ($idCustomer <= 0) {
            return null;
        }
        try {
            $map = IfirmaContractorMap::getByCustomer($idCustomer);
        } catch (Exception $e) {
            return null;
        }
        if (!$map) {
            return null;
        }

        return array(
            'identifier' => (string) $map->ifirma_identifier,
            'matched_by' => (string) $map->matched_by,
            'matched_by_label' => $this->matchedByLabel((string) $map->matched_by),
            'name' => (string) $map->ifirma_name,
        );
    }

    /**
     * @param string $matchedBy
     * @return string
     */
    private function matchedByLabel($matchedBy)
    {
        switch ($matchedBy) {
            case 'module':
                return $this->l('module contractor');
            case 'nip':
                return $this->l('NIP');
            case 'name':
                return $this->l('name');
            case 'email':
                return $this->l('e-mail');
            case 'import-nip':
                return $this->l('import, NIP');
            case 'import-email':
                return $this->l('import, e-mail');
            case 'import-name':
                return $this->l('import, name and postcode');
            case 'document':
                return $this->l('attached by iFirma to an issued document');
            case 'manual':
                return $this->l('manual link');
            default:
                return $matchedBy;
        }
    }

    /**
     * @return string[] allowed delivery channels
     */
    private function sendChannels()
    {
        return array('shop_mail', 'ifirma_mail', 'none');
    }

    /**
     * Validates and stores the settings form.
     *
     * @return string HTML notice
     */
    private function postProcessConfiguration()
    {
        $user = trim(Tools::getValue('IFIRMA_USER'));
        $keyFaktura = trim(Tools::getValue('IFIRMA_KEY_FAKTURA'));

        $errors = array();
        if ($user === '') {
            $errors[] = $this->l('The iFirma login is required.');
        }
        if ($keyFaktura !== '' && !ctype_xdigit($keyFaktura)) {
            $errors[] = $this->l('The invoice API key must be a hexadecimal string.');
        }

        $channel = trim((string) Tools::getValue('IFIRMA_SEND_CHANNEL'));
        if (!in_array($channel, $this->sendChannels(), true)) {
            $channel = 'shop_mail';
        }

        $windowDays = $this->parseInt(Tools::getValue('IFIRMA_DUPLICATE_WINDOW_DAYS'), 1, 365);
        if ($windowDays === null) {
            $errors[] = $this->l('The duplicate-check window must be a whole number of days between 1 and 365.');
        }

        $tolerance = str_replace(',', '.', trim((string) Tools::getValue('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE')));
        if ($tolerance === '' || !is_numeric($tolerance) || (float) $tolerance < 0) {
            $errors[] = $this->l('The amount tolerance must be a number greater than or equal to 0.');
            $tolerance = null;
        } else {
            $tolerance = rtrim(rtrim(sprintf('%.4F', (float) $tolerance), '0'), '.');
            if ($tolerance === '') {
                $tolerance = '0';
            }
        }

        $notify = trim((string) Tools::getValue('IFIRMA_NOTIFY_EMAIL'));
        if ($notify !== '' && !Validate::isEmail($notify)) {
            $errors[] = $this->l('The notification e-mail address is not valid.');
        }

        $syncInterval = $this->parseInt(Tools::getValue('IFIRMA_SYNC_INTERVAL_MIN'), 1, 1440);
        if ($syncInterval === null) {
            $errors[] = $this->l('The sync interval must be a whole number of minutes between 1 and 1440.');
        }

        $cronMax = $this->parseInt(Tools::getValue('IFIRMA_CRON_MAX_CALLS'), 1, 90);
        if ($cronMax === null) {
            $errors[] = $this->l('The cron call budget must be a whole number between 1 and 90.');
        }

        $rateMax = $this->parseInt(Tools::getValue('IFIRMA_RATE_MAX_PER_MIN'), 1, 100);
        if ($rateMax === null) {
            $errors[] = $this->l('The API rate limit must be a whole number between 1 and 100 calls per minute.');
        }

        $paymentMode = trim((string) Tools::getValue('IFIRMA_PAYMENT_METHOD_MODE'));
        if (!in_array($paymentMode, array(IfirmaPaymentMethodResolver::MODE_ORDER, IfirmaPaymentMethodResolver::MODE_FIXED), true)) {
            $errors[] = $this->l('The payment method mode must be "order" or "fixed".');
        }

        $paymentCode = strtoupper(trim((string) Tools::getValue('IFIRMA_PAYMENT_METHOD')));
        if (!isset(IfirmaPaymentMethodResolver::codes()[$paymentCode])) {
            $errors[] = $this->l('The fallback payment method must be one of the listed codes.');
        }

        $paymentMap = '';
        try {
            $parsedMap = IfirmaPaymentMethodResolver::parseMap((string) Tools::getValue('IFIRMA_PAYMENT_METHOD_MAP'));
            $mapLines = array();
            foreach ($parsedMap as $module => $code) {
                $mapLines[] = $module . '=' . $code;
            }
            $paymentMap = implode("\n", $mapLines);
        } catch (InvalidArgumentException $e) {
            $errors[] = $this->l('Payment method map') . ': ' . $e->getMessage();
        }

        if ($errors) {
            return $this->displayError(implode('<br>', $errors));
        }

        $text = trim((string) Tools::getValue('IFIRMA_SEND_TEXT'));
        $text = mb_substr($text, 0, IfirmaDeliveryService::TEXT_MAX);

        $baseUrl = trim(Tools::getValue('IFIRMA_BASE_URL'));
        Configuration::updateValue('IFIRMA_BASE_URL', $baseUrl !== '' ? $baseUrl : IfirmaApiClient::BASE_URL);
        Configuration::updateValue('IFIRMA_USER', $user);
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', $keyFaktura);
        Configuration::updateValue('IFIRMA_KEY_ABONENT', trim(Tools::getValue('IFIRMA_KEY_ABONENT')));
        Configuration::updateValue('IFIRMA_KEY_RACHUNEK', trim(Tools::getValue('IFIRMA_KEY_RACHUNEK')));
        Configuration::updateValue('IFIRMA_DOC_TYPE', Tools::getValue('IFIRMA_DOC_TYPE'));
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', $paymentCode);
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', $paymentMode);
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MAP', $paymentMap);
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', (int) (bool) Tools::getValue('IFIRMA_CONTRACTOR_SYNC'));
        Configuration::updateValue('IFIRMA_PAYMENT_TYPE_TOKEN', trim(Tools::getValue('IFIRMA_PAYMENT_TYPE_TOKEN')));
        Configuration::updateValue('IFIRMA_DEFAULT_UNIT', Tools::getValue('IFIRMA_DEFAULT_UNIT'));
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', (int) Tools::getValue('IFIRMA_AUTO_CREATE_STATE'));
        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', (int) Tools::getValue('IFIRMA_MARK_PAID_ON_CREATE'));
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', $channel);
        Configuration::updateValue('IFIRMA_SEND_TEXT', $text);
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', (int) (bool) Tools::getValue('IFIRMA_DUPLICATE_CHECK'));
        Configuration::updateValue('IFIRMA_DUPLICATE_WINDOW_DAYS', $windowDays);
        Configuration::updateValue('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', $tolerance);
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', (int) (bool) Tools::getValue('IFIRMA_ALLOW_DATA_REQUEST'));
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', $notify);
        Configuration::updateValue('IFIRMA_SYNC_INTERVAL_MIN', $syncInterval);
        Configuration::updateValue('IFIRMA_CRON_MAX_CALLS', $cronMax);
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', $rateMax);

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    /**
     * @return array select options: every iFirma payment method code as "CODE - label"
     */
    /**
     * @return int contractor sync switch, on unless explicitly disabled
     */
    private function contractorSyncValue()
    {
        $value = Configuration::get('IFIRMA_CONTRACTOR_SYNC');

        return ($value === false || $value === null) ? 1 : (int) (bool) $value;
    }

    private function paymentMethodOptions()
    {
        $options = array();
        foreach (IfirmaPaymentMethodResolver::codes() as $code => $label) {
            $options[] = array('id' => $code, 'name' => $code . ' - ' . $label);
        }

        return $options;
    }

    /**
     * @param mixed $value
     * @param int   $min
     * @param int   $max
     * @return int|null null when not a whole number inside the range
     */
    private function parseInt($value, $min, $max)
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }
        $int = (int) $value;

        return ($int >= $min && $int <= $max) ? $int : null;
    }

    /**
     * Replaces the cron token with a fresh one.
     *
     * @return string HTML notice
     */
    private function regenerateCronToken()
    {
        Configuration::updateValue('IFIRMA_CRON_TOKEN', IfirmaSchema::generateCronToken());

        return $this->displayConfirmation($this->l('A new cron token was generated. Update the URL in your crontab.'));
    }

    /**
     * @return string the stored cron token (generated on first use)
     */
    private function cronToken()
    {
        $token = (string) Configuration::get('IFIRMA_CRON_TOKEN');
        if ($token === '') {
            $token = IfirmaSchema::generateCronToken();
            Configuration::updateValue('IFIRMA_CRON_TOKEN', $token);
        }

        return $token;
    }

    /**
     * @return string
     */
    private function cronUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'cron', array('token' => $this->cronToken()), true);
    }

    /**
     * @param string $text
     * @return string
     */
    private function esc($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return IfirmaApiClient
     */
    private function buildApiClient()
    {
        return new IfirmaApiClient(
            Configuration::get('IFIRMA_USER'),
            array(
                'faktura' => Configuration::get('IFIRMA_KEY_FAKTURA'),
                'abonent' => Configuration::get('IFIRMA_KEY_ABONENT'),
                'rachunek' => Configuration::get('IFIRMA_KEY_RACHUNEK'),
            ),
            Configuration::get('IFIRMA_BASE_URL') ?: IfirmaApiClient::BASE_URL
        );
    }

    /**
     * Daily API limit box; nothing (and no request) without the abonent key.
     *
     * @return string HTML
     */
    private function renderLimitBox()
    {
        if (trim((string) Configuration::get('IFIRMA_KEY_ABONENT')) === '') {
            return '';
        }

        $limit = null;
        try {
            $limit = $this->buildApiClient()->getLimit();
        } catch (Exception $e) {
            $limit = null;
        } catch (Throwable $e) {
            $limit = null;
        }

        if (!is_array($limit)) {
            return '<div class="alert alert-warning">'
                . $this->esc($this->l('iFirma API limit is currently unavailable.')) . '</div>';
        }

        return '<div class="alert alert-info">'
            . $this->esc(sprintf(
                $this->l('iFirma API limit today: %1$d/%2$d'),
                (int) $limit['used'],
                (int) $limit['granted']
            ))
            . '</div>';
    }

    /**
     * @return string HTML with the cron URL, token and a crontab example
     */
    private function renderCronInfo()
    {
        $url = $this->cronUrl();

        return '<p><strong>' . $this->esc($this->l('Token:')) . '</strong> <code>'
            . $this->esc($this->cronToken()) . '</code></p>'
            . '<p><strong>' . $this->esc($this->l('URL:')) . '</strong> <code>' . $this->esc($url) . '</code></p>'
            . '<p>' . $this->esc($this->l('Example crontab entry (every 15 minutes):')) . '</p>'
            . '<pre>*/15 * * * * curl -fsS "' . $this->esc($url) . '" &gt;/dev/null</pre>'
            . '<p class="help-block">' . $this->esc($this->l('Anyone who knows the URL can start a synchronisation. Regenerate the token if it leaks.')) . '</p>';
    }

    /**
     * @return array
     */
    private function yesNo()
    {
        return array(
            array('id' => 'on', 'value' => 1, 'label' => $this->l('Yes')),
            array('id' => 'off', 'value' => 0, 'label' => $this->l('No')),
        );
    }

    private function renderConfigForm()
    {
        $orderStates = array(array('id' => 0, 'name' => $this->l('— Disabled (create manually only) —')));
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $orderStates[] = array('id' => (int) $state['id_order_state'], 'name' => $state['name']);
        }

        $inputs = array();

        $limitBox = $this->renderLimitBox();
        if ($limitBox !== '') {
            $inputs[] = array(
                'type' => 'html',
                'name' => 'IFIRMA_API_LIMIT',
                'html_content' => $limitBox,
            );
        }

        $inputs = array_merge($inputs, array(
            array(
                'type' => 'text',
                'label' => $this->l('API base URL'),
                'name' => 'IFIRMA_BASE_URL',
                'desc' => $this->l('Leave the default for production. Point to a mock server for testing, e.g. http://ifirma-mock:8001/iapi/'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('iFirma login'),
                'name' => 'IFIRMA_USER',
                'desc' => $this->l('Your iFirma account login (usually the e-mail address).'),
                'required' => true,
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API key: faktura'),
                'name' => 'IFIRMA_KEY_FAKTURA',
                'desc' => $this->l('Hexadecimal "faktura" key from iFirma → Konfiguracja → Abonent → API.'),
                'required' => true,
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API key: abonent'),
                'name' => 'IFIRMA_KEY_ABONENT',
                'desc' => $this->l('Optional "abonent" key (subscriber data).'),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Document type'),
                'name' => 'IFIRMA_DOC_TYPE',
                'options' => array(
                    'query' => array(
                        array('id' => 'fakturaparagon', 'name' => $this->l('Invoice to receipt (fakturaparagon)')),
                        array('id' => 'fakturakraj', 'name' => $this->l('Domestic VAT invoice (fakturakraj)')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Fallback payment method (SposobZaplaty)'),
                'name' => 'IFIRMA_PAYMENT_METHOD',
                'desc' => $this->l('Used when the mode is "fixed", or when the order\'s payment method cannot be mapped.'),
                'options' => array(
                    'query' => $this->paymentMethodOptions(),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Payment method mode'),
                'name' => 'IFIRMA_PAYMENT_METHOD_MODE',
                'options' => array(
                    'query' => array(
                        array('id' => IfirmaPaymentMethodResolver::MODE_ORDER, 'name' => $this->l('Follow the order\'s payment method (recommended)')),
                        array('id' => IfirmaPaymentMethodResolver::MODE_FIXED, 'name' => $this->l('Always use the fallback code')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'textarea',
                'label' => $this->l('Payment method map'),
                'name' => 'IFIRMA_PAYMENT_METHOD_MAP',
                'desc' => $this->l('One line per payment module: module=CODE, e.g. ps_checkpayment=PRZ or mygateway*=ELE. Built-in defaults cover ps_wirepayment, ps_checkpayment, ps_cashondelivery, PayPal, PayU, Przelewy24, tpay, DotPay and common card gateways.'),
                'rows' => 6,
                'cols' => 60,
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Register the customer as an iFirma contractor'),
                'name' => 'IFIRMA_CONTRACTOR_SYNC',
                'desc' => $this->l('Before issuing, the module creates or refreshes the contractor PS-C<customer id> in iFirma so documents are linked to one contractor per customer. Disable to let iFirma create a new contractor from each document.'),
                'is_bool' => true,
                'values' => $this->yesNo(),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Payment registration type'),
                'name' => 'IFIRMA_PAYMENT_TYPE_TOKEN',
                'desc' => $this->l('URL token used by the "register payment" endpoint, e.g. prz_faktura_kraj. Adjust to match your document/payment type.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Default unit'),
                'name' => 'IFIRMA_DEFAULT_UNIT',
                'desc' => $this->l('Unit label for product lines (e.g. szt.).'),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Auto-create on order status'),
                'name' => 'IFIRMA_AUTO_CREATE_STATE',
                'desc' => $this->l('Automatically issue a document when an order reaches this status.'),
                'options' => array(
                    'query' => $orderStates,
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Report order total as paid'),
                'name' => 'IFIRMA_MARK_PAID_ON_CREATE',
                'desc' => $this->l('Set the "paid" amount on the document to the order total on creation.'),
                'is_bool' => true,
                'values' => $this->yesNo(),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Delivery channel'),
                'name' => 'IFIRMA_SEND_CHANNEL',
                'desc' => $this->l('With the default channel, documents are e-mailed to customers automatically on the next trigger. Choose \'Do not send\' to disable.'),
                'options' => array(
                    'query' => array(
                        array('id' => 'shop_mail', 'name' => $this->l('Shop e-mail with PDF attached (default)')),
                        array('id' => 'ifirma_mail', 'name' => $this->l('iFirma e-mail (fakturakraj and other supported invoice types only; receipts fall back to shop e-mail)')),
                        array('id' => 'none', 'name' => $this->l('Do not send')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'textarea',
                'label' => $this->l('E-mail text'),
                'name' => 'IFIRMA_SEND_TEXT',
                'rows' => 4,
                'cols' => 60,
                'desc' => $this->l('Optional message added to the e-mail that delivers the document (up to 1000 characters).'),
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Duplicate check'),
                'name' => 'IFIRMA_DUPLICATE_CHECK',
                'desc' => $this->l('Look for an existing iFirma document for the order before issuing a new one.'),
                'is_bool' => true,
                'values' => $this->yesNo(),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Duplicate check window (days)'),
                'name' => 'IFIRMA_DUPLICATE_WINDOW_DAYS',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('How many days back iFirma documents are searched (1-365).'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Amount tolerance'),
                'name' => 'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Largest gross amount difference still treated as the same document (and as fully paid).'),
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Allow customers to request billing data changes'),
                'name' => 'IFIRMA_ALLOW_DATA_REQUEST',
                'desc' => $this->l('Customers can ask for different billing data before the document is issued.'),
                'is_bool' => true,
                'values' => $this->yesNo(),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Notification e-mail'),
                'name' => 'IFIRMA_NOTIFY_EMAIL',
                'desc' => $this->l('Receives a message about new data change requests. Leave empty to use the shop e-mail address.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Status sync interval (minutes)'),
                'name' => 'IFIRMA_SYNC_INTERVAL_MIN',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Minimum time between refreshes of a document when a customer opens the order page (1-1440).'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Cron call budget'),
                'name' => 'IFIRMA_CRON_MAX_CALLS',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Maximum iFirma API calls per cron run (1-90).'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API calls per minute'),
                'name' => 'IFIRMA_RATE_MAX_PER_MIN',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Local safety limit for iFirma API calls per minute (1-100).'),
            ),
            array(
                'type' => 'html',
                'label' => $this->l('Cron endpoint'),
                'name' => 'IFIRMA_CRON_INFO',
                'html_content' => $this->renderCronInfo(),
            ),
        ));

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('iFirma API credentials'),
                    'icon' => 'icon-cogs',
                ),
                'input' => $inputs,
                'submit' => array('title' => $this->l('Save')),
                'buttons' => array(
                    array(
                        'type' => 'submit',
                        'name' => 'submitIfirmaRegenerateCron',
                        'title' => $this->l('Regenerate cron token'),
                        'icon' => 'process-icon-refresh',
                        'class' => 'pull-left',
                    ),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitIfirma';
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->fields_value = array(
            'IFIRMA_BASE_URL' => Configuration::get('IFIRMA_BASE_URL') ?: IfirmaApiClient::BASE_URL,
            'IFIRMA_USER' => Configuration::get('IFIRMA_USER'),
            'IFIRMA_KEY_FAKTURA' => Configuration::get('IFIRMA_KEY_FAKTURA'),
            'IFIRMA_KEY_ABONENT' => Configuration::get('IFIRMA_KEY_ABONENT'),
            'IFIRMA_DOC_TYPE' => Configuration::get('IFIRMA_DOC_TYPE'),
            'IFIRMA_PAYMENT_METHOD' => Configuration::get('IFIRMA_PAYMENT_METHOD'),
            'IFIRMA_PAYMENT_METHOD_MODE' => Configuration::get('IFIRMA_PAYMENT_METHOD_MODE'),
            'IFIRMA_PAYMENT_METHOD_MAP' => Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'),
            'IFIRMA_CONTRACTOR_SYNC' => $this->contractorSyncValue(),
            'IFIRMA_PAYMENT_TYPE_TOKEN' => Configuration::get('IFIRMA_PAYMENT_TYPE_TOKEN'),
            'IFIRMA_DEFAULT_UNIT' => Configuration::get('IFIRMA_DEFAULT_UNIT'),
            'IFIRMA_AUTO_CREATE_STATE' => (int) Configuration::get('IFIRMA_AUTO_CREATE_STATE'),
            'IFIRMA_MARK_PAID_ON_CREATE' => (int) Configuration::get('IFIRMA_MARK_PAID_ON_CREATE'),
            'IFIRMA_SEND_CHANNEL' => Configuration::get('IFIRMA_SEND_CHANNEL') ?: 'shop_mail',
            'IFIRMA_SEND_TEXT' => Configuration::get('IFIRMA_SEND_TEXT'),
            'IFIRMA_DUPLICATE_CHECK' => (int) Configuration::get('IFIRMA_DUPLICATE_CHECK'),
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => (int) Configuration::get('IFIRMA_DUPLICATE_WINDOW_DAYS'),
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => Configuration::get('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE'),
            'IFIRMA_ALLOW_DATA_REQUEST' => (int) Configuration::get('IFIRMA_ALLOW_DATA_REQUEST'),
            'IFIRMA_NOTIFY_EMAIL' => Configuration::get('IFIRMA_NOTIFY_EMAIL'),
            'IFIRMA_SYNC_INTERVAL_MIN' => (int) Configuration::get('IFIRMA_SYNC_INTERVAL_MIN'),
            'IFIRMA_CRON_MAX_CALLS' => (int) Configuration::get('IFIRMA_CRON_MAX_CALLS'),
            'IFIRMA_RATE_MAX_PER_MIN' => (int) Configuration::get('IFIRMA_RATE_MAX_PER_MIN'),
        );

        return $helper->generateForm(array($fields_form));
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        return $this->renderAdminOrderPanel((int) $params['id_order']);
    }

    public function hookDisplayAdminOrder($params)
    {
        if ($this->isModernOrderPage()) {
            return '';
        }

        return $this->renderAdminOrderPanel((int) $params['id_order']);
    }

    /**
     * @return bool
     */
    private function isModernOrderPage()
    {
        return version_compare(_PS_VERSION_, '1.7.7.0', '>=');
    }

    /**
     * @param IfirmaReceipt|null $receipt
     * @return string one of not_issued, held, suspected, unknown, error, missing, paid, issued
     */
    private function adminState($receipt)
    {
        if (!$receipt) {
            return 'not_issued';
        }
        if ($receipt->status === IfirmaReceipt::STATUS_HELD) {
            return 'held';
        }
        if ($receipt->status === IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED) {
            return 'suspected';
        }
        if ($receipt->status === IfirmaReceipt::STATUS_UNKNOWN) {
            return 'unknown';
        }
        if ($receipt->status === IfirmaReceipt::STATUS_ERROR) {
            return 'error';
        }
        if ($receipt->isIssued()) {
            if ($receipt->remote_missing) {
                return 'missing';
            }

            return $receipt->status === IfirmaReceipt::STATUS_PAID ? 'paid' : 'issued';
        }

        return 'not_issued';
    }

    /**
     * @param string $state
     * @return string
     */
    private function adminStateLabel($state)
    {
        switch ($state) {
            case 'held':
                return $this->l('On hold (data change request pending)');
            case 'suspected':
                return $this->l('Suspected duplicate');
            case 'unknown':
                return $this->l('Outcome unknown - verify in iFirma');
            case 'error':
                return $this->l('Error');
            case 'missing':
                return $this->l('Missing in iFirma');
            case 'paid':
                return $this->l('Paid');
            case 'issued':
                return $this->l('Issued');
        }

        return $this->l('Not issued');
    }

    /**
     * @return array field name => label of the billing data request form
     */
    private function requestFieldLabels()
    {
        return array(
            'want_invoice_for_company' => $this->l('Invoice for a company'),
            'company' => $this->l('Company name'),
            'vat_number' => $this->l('VAT number (NIP)'),
            'firstname' => $this->l('First name'),
            'lastname' => $this->l('Last name'),
            'address1' => $this->l('Address'),
            'address2' => $this->l('Address (line 2)'),
            'postcode' => $this->l('Postcode'),
            'city' => $this->l('City'),
            'id_country' => $this->l('Country'),
            'customer_note' => $this->l('Note for the shop (optional)'),
        );
    }

    /**
     * @param int $idEmployee
     * @return string
     */
    private function employeeName($idEmployee)
    {
        $idEmployee = (int) $idEmployee;
        if ($idEmployee <= 0) {
            return '';
        }
        $employee = new Employee($idEmployee);
        $name = trim($employee->firstname . ' ' . $employee->lastname);

        return $name !== '' ? $name : '#' . $idEmployee;
    }

    /**
     * @param string $status
     * @return string
     */
    private function requestStatusLabel($status)
    {
        switch ($status) {
            case IfirmaDataRequest::STATUS_PENDING:
                return $this->l('Pending');
            case IfirmaDataRequest::STATUS_APPROVED:
                return $this->l('Approved');
            case IfirmaDataRequest::STATUS_REJECTED:
                return $this->l('Rejected');
            case IfirmaDataRequest::STATUS_CANCELLED:
                return $this->l('Cancelled');
        }

        return (string) $status;
    }

    private function renderAdminOrderPanel($idOrder)
    {
        if (self::$adminOrderRendered || !$idOrder) {
            return '';
        }
        self::$adminOrderRendered = true;

        $receipt = IfirmaReceipt::getByOrder($idOrder);
        $token = Tools::getAdminTokenLite('AdminIfirma');
        $order = new Order($idOrder);
        $requests = IfirmaDataRequestService::build($this);
        $delivery = IfirmaDeliveryService::build($this);

        $paymentMethod = '';
        $contractorNote = '';
        if ($receipt && $receipt->request_payload) {
            $sent = json_decode((string) $receipt->request_payload, true);
            if (is_array($sent) && isset($sent['SposobZaplaty'])) {
                $paymentMethod = (string) $sent['SposobZaplaty'];
            }
            if (is_array($sent) && isset($sent['_contractor']['action'])) {
                $contractorNote = $this->contractorActionLabel(
                    (string) $sent['_contractor']['action'],
                    isset($sent['_contractor']['message']) ? (string) $sent['_contractor']['message'] : ''
                );
            }
        }

        $state = $this->adminState($receipt);
        $isIssued = (bool) ($receipt && $receipt->isIssued());
        $status = $receipt ? (string) $receipt->status : '';

        // duplicate candidates
        $candidates = array();
        $resolution = null;
        if ($receipt) {
            $stored = $receipt->getDuplicateCandidates();
            if (isset($stored['candidates']) && is_array($stored['candidates'])) {
                $candidates = $stored['candidates'];
                $resolution = isset($stored['resolution']) ? $stored['resolution'] : null;
            }
        }

        // data change requests
        $pending = $requests->getPendingForOrder($idOrder);
        $pendingSummary = null;
        $diff = array();
        $fields = array();
        if ($pending) {
            $pendingSummary = $requests->summarize($pending);
            $pendingSummary['customer_note'] = (string) $pending->customer_note;
            if (Validate::isLoadedObject($order)) {
                $diff = $requests->buildDiff($pending->getRequestedData(), new Address((int) $order->id_address_invoice));
            }
            $labels = $this->requestFieldLabels();
            $requested = $pending->getRequestedData();
            foreach ($requests->fields() as $name) {
                $fields[] = array(
                    'name' => $name,
                    'label' => isset($labels[$name]) ? $labels[$name] : $name,
                    'value' => isset($requested[$name]) && !is_array($requested[$name]) ? (string) $requested[$name] : '',
                    'type' => $name === 'want_invoice_for_company' ? 'checkbox' : 'text',
                );
            }
        }
        $history = array();
        foreach (IfirmaDataRequest::getByOrder($idOrder) as $row) {
            $request = IfirmaDataRequest::getById((int) $row['id_ifirma_data_request']);
            if (!$request) {
                continue;
            }
            $entry = $requests->summarize($request);
            $entry['status_label'] = $this->requestStatusLabel($request->status);
            $entry['employee'] = $this->employeeName($request->resolved_by);
            $history[] = $entry;
        }

        $contractorMap = null;
        if ($order && Validate::isLoadedObject($order)) {
            $contractorMap = $this->contractorMapFor((int) $order->id_customer);
        }

        $this->context->smarty->assign(array(
            'ifirma_contractor_map' => $contractorMap,
            'ifirma_id_order' => $idOrder,
            'ifirma_receipt' => $receipt,
            'ifirma_payment_method' => $paymentMethod,
            'ifirma_contractor_note' => $contractorNote,
            'ifirma_request_payload' => $receipt ? (string) $receipt->request_payload : '',
            'ifirma_response_payload' => $receipt ? (string) $receipt->response_payload : '',
            'ifirma_module_version' => (string) $this->version,
            'ifirma_has_receipt' => (bool) $receipt,
            'ifirma_is_issued' => $isIssued,
            'ifirma_is_external' => (bool) ($receipt && $receipt->isExternal()),
            'ifirma_configured' => $this->isConfigured(),
            'ifirma_state' => $state,
            'ifirma_state_label' => $this->adminStateLabel($state),
            'ifirma_can_create' => !$isIssued && $status !== IfirmaReceipt::STATUS_HELD,
            'ifirma_can_issue_anyway' => !$isIssued && in_array($status, array(
                IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED,
                IfirmaReceipt::STATUS_UNKNOWN,
                IfirmaReceipt::STATUS_ERROR,
            ), true),
            'ifirma_has_pending_request' => (bool) $pending,
            'ifirma_candidates' => $candidates,
            'ifirma_resolution' => $resolution,
            'ifirma_delivery_text' => $receipt ? $delivery->describeDelivery($receipt) : '',
            'ifirma_gross' => Validate::isLoadedObject($order) ? (float) $order->total_paid_tax_incl : 0.0,
            'ifirma_pending_request' => $pendingSummary,
            'ifirma_request_diff' => $diff,
            'ifirma_request_fields' => $fields,
            'ifirma_request_history' => $history,
            'ifirma_ajax_url' => $this->context->link->getAdminLink('AdminIfirma', false)
                . '&token=' . $token,
            'ifirma_token' => $token,
        ));

        return $this->display(__FILE__, 'views/templates/hook/admin_order_panel.tpl');
    }

    /**
     * @param string $action
     * @param string $message
     * @return string
     */
    private function contractorActionLabel($action, $message)
    {
        switch ($action) {
            case 'created':
                $label = $this->l('created');
                break;
            case 'updated':
                $label = $this->l('updated');
                break;
            case 'kept':
                $label = $this->l('kept');
                break;
            case 'fallback':
                $label = $this->l('fallback');
                break;
            default:
                return '';
        }

        if ($action === 'fallback' && $message !== '') {
            $label .= ' (' . $message . ')';
        }

        return $label;
    }

    public function hookHeader($params)
    {
        if (method_exists($this->context->controller, 'registerStylesheet')) {
            $this->context->controller->registerStylesheet(
                'module-ifirma',
                'modules/' . $this->name . '/views/css/ifirma.css',
                array('media' => 'all', 'priority' => 150)
            );
        } else {
            $this->context->controller->addCSS($this->_path . 'views/css/ifirma.css', 'all');
        }
    }

    public function hookDisplayCustomerAccount($params)
    {
        if (!$this->context->customer->isLogged()) {
            return '';
        }

        $idCustomer = (int) $this->context->customer->id;
        $account = $this->customerView()->forAccount(
            (array) IfirmaReceipt::getByCustomer($idCustomer),
            IfirmaDataRequest::countPendingByCustomer($idCustomer)
        );
        if (empty($account['visible'])) {
            return '';
        }

        $this->context->smarty->assign('ifirma_account', $account);

        return $this->display(__FILE__, 'views/templates/hook/customer_account.tpl');
    }

    /**
     * @return IfirmaCustomerView
     */
    private function customerView()
    {
        return new IfirmaCustomerView($this, $this->context->link);
    }

    /**
     * @param int $idState
     * @return string
     */
    private function orderStateName($idState)
    {
        if ($idState <= 0) {
            return '';
        }
        $state = new OrderState((int) $idState, (int) $this->context->language->id);
        $name = $state->name;
        if (is_array($name)) {
            $lang = (int) $this->context->language->id;
            $name = isset($name[$lang]) ? $name[$lang] : reset($name);
        }

        return (string) $name;
    }

    public function hookDisplayOrderDetail($params)
    {
        if (!$this->isConfigured() || !isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return '';
        }

        $order = $params['order'];
        $idCustomer = (int) $this->context->customer->id;
        if ($idCustomer <= 0 || (int) $order->id_customer !== $idCustomer) {
            return '';
        }

        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        if ($receipt && (int) $receipt->id_customer !== $idCustomer) {
            return '';
        }

        if ($receipt && $receipt->isIssued()) {
            // never throws; refreshes the stored remote state when it is stale
            if (IfirmaStatusSyncService::build($this)->opportunisticSync($receipt)) {
                $receipt = new IfirmaReceipt((int) $receipt->id);
            }
        }

        $pending = IfirmaDataRequestService::build($this)->getPendingForOrder((int) $order->id);
        $triggerState = (int) Configuration::get('IFIRMA_AUTO_CREATE_STATE');

        $view = $this->customerView()->forOrder($order, $receipt, $pending, array(
            'data_request_enabled' => (bool) Configuration::get('IFIRMA_ALLOW_DATA_REQUEST'),
            'auto_issue_enabled' => $triggerState > 0,
            'trigger_state_name' => $this->orderStateName($triggerState),
            'tolerance' => Configuration::get('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE'),
            'has_other_pending' => false,
        ));

        $this->context->smarty->assign('ifirma_view', $view);

        return $this->display(__FILE__, 'views/templates/hook/order_detail.tpl');
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!isset($params['id_order']) || !isset($params['newOrderStatus']) || !is_object($params['newOrderStatus'])) {
            return;
        }

        try {
            IfirmaIssuancePipeline::build($this)->onOrderStatusChange(
                (int) $params['id_order'],
                (int) $params['newOrderStatus']->id
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog('iFirma status hook failed for order ' . (int) $params['id_order'] . ': ' . $e->getMessage(), 2);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('iFirma status hook failed for order ' . (int) $params['id_order'] . ': ' . $e->getMessage(), 2);
        }
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return (bool) Configuration::get('IFIRMA_USER') && (bool) Configuration::get('IFIRMA_KEY_FAKTURA');
    }
}
