<?php
/**
 * @author    SCM Jakub Berechowski
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
require_once __DIR__ . '/classes/IfirmaMatchLabels.php';
require_once __DIR__ . '/classes/IfirmaContractorService.php';
require_once __DIR__ . '/classes/IfirmaContractorImport.php';
require_once __DIR__ . '/classes/IfirmaReceiptService.php';
require_once __DIR__ . '/classes/IfirmaDocumentSettings.php';
require_once __DIR__ . '/classes/IfirmaDuplicateGuard.php';
require_once __DIR__ . '/classes/IfirmaDeliveryService.php';
require_once __DIR__ . '/classes/IfirmaKsefService.php';
require_once __DIR__ . '/classes/IfirmaSettings.php';
require_once __DIR__ . '/classes/IfirmaStatusSyncService.php';
require_once __DIR__ . '/classes/IfirmaDataRequestNotifier.php';
require_once __DIR__ . '/classes/IfirmaDataRequestService.php';
require_once __DIR__ . '/classes/IfirmaIssuancePipeline.php';
require_once __DIR__ . '/classes/IfirmaCustomerView.php';
require_once __DIR__ . '/classes/IfirmaAdminPanelPresenter.php';
require_once __DIR__ . '/classes/IfirmaAdminOrderPanelView.php';
require_once __DIR__ . '/classes/IfirmaCustomerContractorService.php';
require_once __DIR__ . '/classes/IfirmaAdminActions.php';
require_once __DIR__ . '/classes/IfirmaOrderGridIndicator.php';
require_once __DIR__ . '/classes/IfirmaOrderGridStateFilter.php';
require_once __DIR__ . '/classes/IfirmaPermission.php';
require_once __DIR__ . '/classes/IfirmaIssueFeed.php';
require_once __DIR__ . '/classes/IfirmaBulkRunner.php';
require_once __DIR__ . '/classes/IfirmaHelpLinks.php';
require_once __DIR__ . '/classes/IfirmaInput.php';
require_once __DIR__ . '/classes/IfirmaApiUsage.php';
require_once __DIR__ . '/classes/IfirmaApiUsagePresenter.php';
require_once __DIR__ . '/classes/IfirmaRemoteLimit.php';
require_once __DIR__ . '/classes/IfirmaRequestKind.php';
require_once __DIR__ . '/classes/IfirmaRateDecision.php';
require_once __DIR__ . '/classes/IfirmaRemoteLimitPresenter.php';
require_once __DIR__ . '/classes/IfirmaLimitCheck.php';
require_once __DIR__ . '/classes/IfirmaLimitCheckAction.php';

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
        $this->version = '1.9.1';
        $this->author = 'SCM Jakub Berechowski';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => '9.99.99');
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
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->registerHook('displayAdminCustomers')
            && $this->registerHook('displayCustomerAccount')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('header')
            && $this->registerHook('actionOrderGridDefinitionModifier')
            && $this->registerHook('actionOrderGridQueryBuilderModifier')
            && $this->registerHook('actionOrderGridPresenterModifier')
            && $this->registerHook('actionAdminControllerSetMedia')
            && $this->installDefaultConfiguration();
    }

    public function uninstall()
    {
        Db::getInstance()->delete('configuration', "name LIKE 'IFIRMA\\_SEEN\\_%'");

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
                try {
                    $value = IfirmaSchema::generateCronToken();
                } catch (Exception $e) {
                    // no cron token without a secure random generator: the module is not installed half way
                    PrestaShopLogger::addLog('iFirma: ' . $e->getMessage() . '; the module was not installed', 3);

                    return false;
                }
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

    /**
     * The id of the Orders menu the iFirma tab goes under: AdminParentOrders, else the parent of AdminOrders.
     * Returns -1 (the hidden tab) when neither exists.
     *
     * @return int
     */
    private function ordersTabParentId()
    {
        $idParent = (int) Tab::getIdFromClassName('AdminParentOrders');
        if ($idParent) {
            return $idParent;
        }
        $idOrders = (int) Tab::getIdFromClassName('AdminOrders');
        if ($idOrders) {
            $ordersTab = new Tab($idOrders);
            if ((int) $ordersTab->id_parent) {
                return (int) $ordersTab->id_parent;
            }
        }

        return -1;
    }

    /**
     * Adds the iFirma tab under the Orders menu (D2). Without an Orders menu it stays hidden and the
     * shop log says so.
     *
     * @return bool
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->class_name = 'AdminIfirma';
        $tab->module = $this->name;
        $tab->active = 1;
        $tab->id_parent = $this->ordersTabParentId();

        $tab->name = array();
        foreach (Language::getLanguages(false) as $lang) {
            // a Polish back office gets the Polish name; a tab has no translation domain of its own
            $isPolish = isset($lang['iso_code']) && $lang['iso_code'] === 'pl';
            $tab->name[$lang['id_lang']] = $isPolish ? 'Dokumenty iFirma' : 'iFirma documents';
        }
        if ($tab->id_parent === -1) {
            PrestaShopLogger::addLog('iFirma: Orders menu not found, tab installed hidden', 2);
        }

        return $tab->add();
    }

    /**
     * Public entry for the 1.5.0 upgrade, which creates the tab on a shop where it is missing.
     *
     * @return bool
     */
    public function installVisibleTab()
    {
        return $this->installTab();
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
        $limitCheck = null;
        if (Tools::isSubmit('submitIfirmaRegenerateCron')) {
            $output .= $this->regenerateCronToken();
        } elseif (Tools::isSubmit(IfirmaRemoteLimitPresenter::SUBMIT)) {
            $limitCheck = $this->checkRemoteLimit();
        } elseif (Tools::isSubmit('submitIfirma')) {
            $output .= $this->postProcessConfiguration();
        } elseif (Tools::isSubmit('submitIfirmaImportContractors')) {
            list($report, $errors) = $this->processContractorImport();
        } elseif (Tools::isSubmit('submitIfirmaResetContractorMap')) {
            $output .= $this->resetContractorMap();
        }

        return $this->authBlockBanner() . $this->storedBaseUrlWarning() . $output
            . $this->renderLimitBox($limitCheck) . $this->renderUsageBox()
            . $this->renderConfigForm() . $this->renderContractorsPanel($report, $errors) . $this->helpLinks();
    }

    /**
     * @return string HTML, the documentation and support links
     */
    private function helpLinks()
    {
        return (new IfirmaHelpLinks())->render($this->l('Documentation'), $this->l('Support'));
    }

    /**
     * The warning that says the stored API base URL is refused at run time (see IfirmaApiClient::fromConfiguration()).
     *
     * @return string HTML, '' when no URL is stored or the stored one is acceptable
     */
    private function storedBaseUrlWarning()
    {
        $stored = trim((string) Configuration::get('IFIRMA_BASE_URL'));
        if ($stored === '' || IfirmaSettings::isAcceptableBaseUrl($stored)) {
            return '';
        }

        return $this->displayWarning(sprintf(
            $this->l('The stored API base URL "%s" is not accepted, so the module talks to the iFirma production address. Save a valid URL below.'),
            $this->esc($stored)
        ));
    }

    /**
     * The warning that says iFirma calls are paused after a rejected credential, while the pause runs.
     *
     * @return string HTML, '' when no pause runs
     */
    private function authBlockBanner()
    {
        try {
            $client = IfirmaApiClient::fromConfiguration();
            $message = method_exists($client, 'getAuthBlockMessage') ? (string) $client->getAuthBlockMessage() : '';
        } catch (Throwable $e) {
            return '';
        }

        return $message === '' ? '' : $this->displayWarning($this->esc($message));
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
     * The words for the codes that say how a customer came to be linked to an iFirma contractor, in
     * the back-office language. The translation scanner only finds l() calls in this file, so the
     * literals stay here and IfirmaMatchLabels receives them translated.
     *
     * @return IfirmaMatchLabels
     */
    public function matchLabels()
    {
        return new IfirmaMatchLabels(array(
            IfirmaContractorMap::BY_MODULE => $this->l('module contractor'),
            IfirmaContractorMap::BY_NIP => $this->l('NIP'),
            IfirmaContractorMap::BY_NAME => $this->l('name'),
            IfirmaContractorMap::BY_EMAIL => $this->l('e-mail'),
            IfirmaContractorMap::BY_IMPORT_NIP => $this->l('import, NIP'),
            IfirmaContractorMap::BY_IMPORT_EMAIL => $this->l('import, e-mail'),
            IfirmaContractorMap::BY_IMPORT_NAME => $this->l('import, name and postcode'),
            IfirmaContractorMap::BY_DOCUMENT => $this->l('attached by iFirma to an issued document'),
            IfirmaContractorMap::BY_MANUAL => $this->l('manual link'),
        ));
    }

    /**
     * Validates and stores the settings form.
     *
     * @return string HTML notice
     */
    private function postProcessConfiguration()
    {
        $input = array();
        foreach (array_keys(IfirmaSettings::fields()) as $key) {
            $input[$key] = Tools::getValue($key);
        }
        foreach (self::$secretKeys as $key) {
            $input[$key] = $this->secretInput($key, $input[$key]);
        }

        $settings = IfirmaSettings::fromInput($input, function () {
            return $this->activeEmployeeIds();
        });
        if ($settings['errors']) {
            return $this->displayError(implode('<br>', $this->settingErrors($settings['errors'])));
        }

        foreach ($settings['values'] as $key => $value) {
            Configuration::updateValue($key, $value);
        }
        // new settings, new chance: the pause after a rejected credential ends with a successful save
        Configuration::deleteByName('IFIRMA_AUTH_FAILURE');

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    /** @var string[] the settings that hold an API key: the form never shows them */
    private static $secretKeys = array('IFIRMA_KEY_FAKTURA', 'IFIRMA_KEY_ABONENT', 'IFIRMA_KEY_RACHUNEK');

    /**
     * What an API key becomes on save. The form shows no stored key, so an empty field means "keep it", and
     * only the "remove" box clears it; a value that was typed replaces it.
     *
     * @param string $key
     * @param mixed  $posted
     * @return mixed the value to validate
     */
    private function secretInput($key, $posted)
    {
        $empty = $posted === false || $posted === null || (is_string($posted) && trim($posted) === '');
        if (!$empty) {
            return $posted;
        }
        if (Tools::getValue('clear_' . $key . '_on')) {
            return '';
        }

        return (string) Configuration::get($key);
    }

    /**
     * @return int[] the ids of the active employees
     */
    private function activeEmployeeIds()
    {
        $ids = array();
        foreach (Employee::getEmployees(true) as $row) {
            $ids[] = (int) $row['id_employee'];
        }

        return $ids;
    }

    /**
     * The messages of the settings that failed, in the order the form reports them. IfirmaSettings knows the rules
     * and has no words of its own: the translation scanner only finds l() literals in this file, so every message
     * stays here, keyed by the setting it is about.
     *
     * @param array $failed setting key => detail, the errors of IfirmaSettings::fromInput()
     * @return string[]
     */
    private function settingErrors(array $failed)
    {
        $messages = array(
            'IFIRMA_BASE_URL' => $this->l('The API base URL must be an absolute https URL; http is accepted only for a local host or a shop in developer mode.'),
            'IFIRMA_USER' => $this->l('The iFirma login is required.'),
            'IFIRMA_KEY_FAKTURA' => $this->l('The invoice API key must be a hexadecimal string.'),
            'IFIRMA_KEY_ABONENT' => $this->l('The abonent API key must be a hexadecimal string.'),
            'IFIRMA_KEY_RACHUNEK' => $this->l('The receipt API key must be a hexadecimal string.'),
            'IFIRMA_SEND_COPY_EMPLOYEE' => $this->l('Choose an active employee for the delivery copy.'),
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => $this->l('The duplicate-check window must be a whole number of days between 1 and 365.'),
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => $this->l('The amount tolerance must be a number greater than or equal to 0.'),
            'IFIRMA_NOTIFY_EMAIL' => $this->l('The notification e-mail address is not valid.'),
            'IFIRMA_SYNC_INTERVAL_MIN' => $this->l('The sync interval must be a whole number of minutes between 1 and 1440.'),
            'IFIRMA_CRON_MAX_CALLS' => $this->l('The cron call budget must be a whole number between 1 and 90.'),
            'IFIRMA_CRON_MAX_ITEMS' => $this->l('The cron item limit must be a whole number between 1 and 50.'),
            'IFIRMA_RATE_MAX_PER_MIN' => $this->l('The API rate limit must be a whole number between 1 and 100 calls per minute.'),
            'IFIRMA_RATE_MAX_PER_DAY' => $this->l('The API daily limit must be a whole number between 1 and 15000 calls per day.'),
            'IFIRMA_PAYMENT_METHOD_MODE' => $this->l('The payment method mode must be "order" or "fixed".'),
            'IFIRMA_PAYMENT_METHOD' => $this->l('The fallback payment method must be one of the listed codes.'),
            'IFIRMA_PAYMENT_METHOD_MAP' => $this->l('Payment method map'),
            'IFIRMA_DOC_TEMPLATE' => $this->l('The document template name must be one line of at most 100 characters.'),
            'IFIRMA_ORDER_STAMP_PREFIX' => $this->l('The order stamp prefix must be 1 to 32 characters: letters, digits, dot, underscore or hyphen.'),
            'IFIRMA_SHOP_URL' => $this->l('The shop webpage must be an absolute http or https address without a user name or password.'),
        );

        $errors = array();
        foreach ($messages as $key => $message) {
            if (isset($failed[$key])) {
                // the detail is empty, except for the payment method map: it lists the bad lines
                $errors[] = $failed[$key] === '' ? $message : $message . ': ' . $failed[$key];
            }
        }

        return $errors;
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
     * Replaces the cron token with a fresh one.
     *
     * @return string HTML notice
     */
    private function regenerateCronToken()
    {
        try {
            $token = IfirmaSchema::generateCronToken();
        } catch (Exception $e) {
            return $this->displayError($this->l('The cron token could not be regenerated: the secure random generator is unavailable.'));
        }
        Configuration::updateValue('IFIRMA_CRON_TOKEN', $token);

        return $this->displayConfirmation($this->l('A new cron token was generated. Update the URL in your crontab.'));
    }

    /**
     * @return string the stored cron token (generated on first use)
     */
    private function cronToken()
    {
        $token = (string) Configuration::get('IFIRMA_CRON_TOKEN');
        if ($token === '') {
            try {
                $token = IfirmaSchema::generateCronToken();
            } catch (Exception $e) {
                return '';
            }
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
        return IfirmaApiClient::fromConfiguration();
    }

    /**
     * The limit box: the last stored reading and the button that reads a fresh one. Opening the page sends no request.
     *
     * @param IfirmaLimitCheck|null $check the outcome of the click that produced this page
     * @return string HTML
     */
    private function renderLimitBox($check)
    {
        $token = Tools::getAdminTokenLite('AdminModules');
        $presenter = new IfirmaRemoteLimitPresenter(
            $this->remoteLimitLabels(),
            AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . $token,
            $token
        );

        return $this->hasAbonentKey() ? $presenter->render(IfirmaRemoteLimit::stored(), $check) : $presenter->renderWithoutKey();
    }

    /**
     * The click on "Check the remaining iFirma limit". PrestaShop checks the back-office token before getContent() runs
     * (and, on 8.1, the right to configure the module); the check itself still requires a POST, the module's configure
     * permission (9.1 does not check it for this page) and the abonent key.
     *
     * @return IfirmaLimitCheck|null null when the request was not allowed and nothing was asked
     */
    private function checkRemoteLimit()
    {
        $module = $this;
        $action = new IfirmaLimitCheckAction(
            IfirmaRemoteLimit::forClient($this->buildApiClient()),
            function () use ($module) {
                return IfirmaInput::isPost() && $module->hasValidAdminToken() && $module->getPermission('configure') && $module->hasAbonentKey();
            },
            function ($message) {
                PrestaShopLogger::addLog($message, IfirmaLimitCheckAction::LOG_ERROR);
            }
        );

        return $action->run();
    }

    /**
     * @return bool
     */
    private function hasValidAdminToken()
    {
        // PrestaShop checks the token before getContent() unless tokens in admin URLs are switched off: do not rely on it alone
        return hash_equals(Tools::getAdminTokenLite('AdminModules'), (string) Tools::getValue('token'));
    }

    /**
     * @return bool
     */
    private function hasAbonentKey()
    {
        return trim((string) Configuration::get('IFIRMA_KEY_ABONENT')) !== '';
    }

    /**
     * The "Module requests" box: what the module counted itself, read from the database only.
     *
     * @return string HTML
     */
    private function renderUsageBox()
    {
        $usage = new IfirmaApiUsage();

        return (new IfirmaApiUsagePresenter($this->usageLabels()))->render($usage->windows(), $usage->todayByKind(), $usage->backoffUntil());
    }

    /**
     * @return array the words of the limit box, as IfirmaRemoteLimitPresenter takes them
     */
    private function remoteLimitLabels()
    {
        return array(
            'title' => $this->l('iFirma request limit'),
            'not_checked' => $this->l('The remaining limit has not been checked yet.'),
            'reading' => $this->l('Used %1$d of %2$d requests, %3$d remaining (read %4$s).'),
            'earlier_day' => $this->l('Last reading, from %1$s: used %2$d of %3$d requests. It is not a figure for today.'),
            'nothing_left' => $this->l('No request is left for today: the module sends no request to iFirma until tomorrow.'),
            'unavailable' => $this->l('iFirma API limit is currently unavailable.'),
            'refused' => array(
                IfirmaRateDecision::WINDOW_MINUTE => $this->l('The limit was not checked because the module reached its limit of requests per minute. Nothing was sent to iFirma.'),
                IfirmaRateDecision::WINDOW_DAY => $this->l('The limit was not checked because the module reached its limit of requests per day. Nothing was sent to iFirma.'),
                IfirmaRateDecision::WINDOW_BACKOFF => $this->l('The limit was not checked because iFirma asked the module to wait. Nothing was sent to iFirma.'),
                IfirmaLimitCheck::WINDOW_AUTH => $this->l('The limit was not checked because iFirma rejected the credentials a moment ago and requests are paused. Nothing was sent to iFirma.'),
            ),
            'button' => $this->l('Check the remaining iFirma limit'),
            'one_request' => $this->l('One click sends one request to iFirma and counts toward the limit.'),
            'no_key' => $this->l('To read the remaining iFirma limit, enter the abonent API key and save.'),
        );
    }

    /**
     * @return array the words of the "Module requests" box, as IfirmaApiUsagePresenter takes them
     */
    private function usageLabels()
    {
        return array(
            'title' => $this->l('Module requests'),
            'intro' => $this->l('Requests this module sent to iFirma, counted by the module itself. Opening this page sends none.'),
            'window_minute' => $this->l('Current minute'),
            'window_day' => $this->l('Today'),
            'of' => $this->l('%1$d of %2$d'),
            'by_kind' => $this->l('Today by kind of request'),
            'kinds' => array(
                IfirmaRequestKind::KIND_DOCUMENT => $this->l('Creating documents'),
                IfirmaRequestKind::KIND_STATUS => $this->l('Status checks (document lists and details)'),
                IfirmaRequestKind::KIND_PDF => $this->l('PDF downloads'),
                IfirmaRequestKind::KIND_EMAIL => $this->l('Sending documents by e-mail through iFirma'),
                IfirmaRequestKind::KIND_KSEF => $this->l('KSeF submissions'),
                IfirmaRequestKind::KIND_PAYMENT => $this->l('Payments'),
                IfirmaRequestKind::KIND_CONTRACTOR => $this->l('Contractors'),
                IfirmaRequestKind::KIND_LIMIT => $this->l('Limit checks'),
                IfirmaRequestKind::KIND_OTHER => $this->l('Other requests'),
            ),
            'other_clients' => $this->l('Other clients of the account (the account count is higher than this module sent)'),
            'backoff' => $this->l('iFirma asked the module to wait: no request is sent until %s.'),
        );
    }

    /**
     * @return string HTML with the cron URL, token and a crontab example
     */
    private function renderCronInfo()
    {
        $url = $this->cronUrl();
        $plainUrl = $this->context->link->getModuleLink($this->name, 'cron', array(), true);

        return '<p><strong>' . $this->esc($this->l('Token:')) . '</strong> <code>'
            . $this->esc($this->cronToken()) . '</code></p>'
            . '<p><strong>' . $this->esc($this->l('URL:')) . '</strong> <code>' . $this->esc($url) . '</code></p>'
            . '<p>' . $this->esc($this->l('Example crontab entry (every 15 minutes):')) . '</p>'
            . '<pre>*/15 * * * * curl -fsS "' . $this->esc($url) . '" &gt;/dev/null</pre>'
            . '<p>' . $this->esc($this->l('Or send the token in a header, so it stays out of web server logs:')) . '</p>'
            . '<pre>*/15 * * * * curl -fsS -H "X-Ifirma-Token: ' . $this->esc($this->cronToken()) . '" "'
            . $this->esc($plainUrl) . '" &gt;/dev/null</pre>'
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

    /**
     * The password input of an API key and the box that removes the stored key. The input is always empty (the
     * page never carries a key); the description says when a key is stored.
     *
     * @param string $key
     * @param string $label
     * @param string $desc
     * @param bool   $required the key is needed to work: required only while none is stored
     * @return array[] the two form inputs
     */
    private function secretInputs($key, $label, $desc, $required)
    {
        $stored = trim((string) Configuration::get($key)) !== '';
        if ($stored) {
            $desc .= ' ' . $this->l('A key is stored; leave the field empty to keep it.');
        }
        $input = array(
            'type' => 'password',
            'label' => $label,
            'name' => $key,
            'desc' => $desc,
            'autocomplete' => false,
        );
        if ($required && !$stored) {
            $input['required'] = true;
        }

        return array(
            $input,
            array(
                'type' => 'checkbox',
                'name' => 'clear_' . $key,
                'values' => array(
                    'query' => array(array('id' => 'on', 'name' => $this->l('Remove the stored key'))),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
        );
    }

    private function renderConfigForm()
    {
        $orderStates = array(array('id' => 0, 'name' => $this->l('— Disabled (create manually only) —')));
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $orderStates[] = array('id' => (int) $state['id_order_state'], 'name' => $state['name']);
        }

        $copyEmployees = array(array('id_employee' => 0, 'name' => $this->l('choose an employee')));
        foreach (Employee::getEmployees(true) as $employee) {
            $copyEmployees[] = array(
                'id_employee' => (int) $employee['id_employee'],
                'name' => trim($employee['firstname'] . ' ' . $employee['lastname']),
            );
        }

        $inputs = array(
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
            // never a stored key in the page: the password input is empty and an empty field keeps the key
        );
        $inputs = array_merge(
            $inputs,
            $this->secretInputs(
                'IFIRMA_KEY_FAKTURA',
                $this->l('API key: faktura'),
                $this->l('Hexadecimal "faktura" key from iFirma → Konfiguracja → Abonent → API.'),
                true
            ),
            $this->secretInputs(
                'IFIRMA_KEY_ABONENT',
                $this->l('API key: abonent'),
                $this->l('Optional "abonent" key (subscriber data).'),
                false
            ),
            $this->secretInputs(
                'IFIRMA_KEY_RACHUNEK',
                $this->l('API key: rachunek'),
                $this->l('Optional "rachunek" key (receipts).'),
                false
            )
        );
        $inputs = array_merge($inputs, array(
            array(
                'type' => 'select',
                'label' => $this->l('Document type'),
                'name' => 'IFIRMA_DOC_TYPE',
                'desc' => $this->l('Invoice to receipt (fakturaparagon) is disabled for now; every document is a domestic VAT invoice.'),
                'options' => array(
                    'query' => array(
                        array('id' => 'fakturakraj', 'name' => $this->l('Domestic VAT invoice (fakturakraj)')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Recipient signature (RodzajPodpisuOdbiorcy)'),
                'name' => 'IFIRMA_SIGNATURE_TYPE',
                'desc' => $this->l('Printed signature line on the document. Online sales normally use "without recipient signature".'),
                'options' => array(
                    'query' => array(
                        array('id' => 'BPO', 'name' => $this->l('BPO - without recipient signature')),
                        array('id' => 'BWO', 'name' => $this->l('BWO - without recipient and issuer signature')),
                        array('id' => 'OUP', 'name' => $this->l('OUP - person authorised to receive the invoice')),
                        array('id' => 'UPO', 'name' => $this->l('UPO - written authorisation')),
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
                'type' => 'text',
                'label' => $this->l('Document template'),
                'name' => 'IFIRMA_DOC_TEMPLATE',
                'desc' => $this->l('The name of the print template in your iFirma account. The template, including the logo it prints, is defined in the iFirma account. Leave empty for the iFirma default.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Order stamp prefix'),
                'name' => 'IFIRMA_ORDER_STAMP_PREFIX',
                'class' => 'fixed-width-xl',
                'desc' => $this->l('The first segment of the order stamp written into the document comments. Each shop that shares one iFirma account needs its own prefix: set it before the shop issues its first document. Default: PS-ORDER.'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Shop webpage'),
                'name' => 'IFIRMA_SHOP_URL',
                'desc' => $this->l('Used as the link in the module e-mails, printed in the document comments and as the first part of PDF file names. Empty means the address of the shop itself.'),
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
                'type' => 'select',
                'label' => $this->l('Copy of the delivery e-mail'),
                'name' => 'IFIRMA_SEND_COPY_MODE',
                'desc' => $this->l('A blind copy (BCC) of every document e-mail sent through shop mail, including Send again. E-mails sent by iFirma itself are never copied.'),
                'options' => array(
                    'query' => array(
                        array('id' => 'none', 'name' => $this->l('No copy')),
                        array('id' => 'shop', 'name' => $this->l('Shop contact e-mail (PS_SHOP_EMAIL)')),
                        array('id' => 'employee', 'name' => $this->l('A back-office employee')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('Employee for the copy'),
                'name' => 'IFIRMA_SEND_COPY_EMPLOYEE',
                'desc' => $this->l('Used only with mode "A back-office employee". The employee must be active and have a valid e-mail.'),
                'options' => array(
                    'query' => $copyEmployees,
                    'id' => 'id_employee',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('KSeF submission (B2B e-invoicing)'),
                'name' => 'IFIRMA_KSEF_MODE',
                'desc' => $this->l('A submission to KSeF cannot be withdrawn. B2B invoices are legally required in KSeF; iFirma may also submit automatically depending on the account settings.'),
                'options' => array(
                    'query' => array(
                        array('id' => 'b2b', 'name' => $this->l('B2B only: buyers with a NIP')),
                        array('id' => 'all', 'name' => $this->l('Every invoice')),
                        array('id' => 'off', 'name' => $this->l('Never (manual button only)')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
            ),
            array(
                'type' => 'select',
                'label' => $this->l('KSeF submitter'),
                'name' => 'IFIRMA_KSEF_SUBMITTER',
                'desc' => $this->l('Who sends the invoices to KSeF. Choose iFirma when your iFirma account submits API invoices to KSeF on its own: the module then never calls the KSeF send endpoint and only waits for the state iFirma reports.'),
                'options' => array(
                    'query' => array(
                        array('id' => 'module', 'name' => $this->l('The module sends them')),
                        array('id' => 'ifirma', 'name' => $this->l('iFirma sends them (the module waits)')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
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
                'type' => 'select',
                'label' => $this->l('Accept data change requests automatically'),
                'name' => 'IFIRMA_DATA_REQUEST_AUTO_ACCEPT',
                'desc' => $this->l('Safe approves requests that keep the company or private choice, the NIP and the company name and change only the name, address, postcode, city or country. All approves every valid request without review.'),
                'options' => array(
                    'query' => array(
                        array('id' => 'none', 'name' => $this->l('Never (review every request)')),
                        array('id' => 'safe', 'name' => $this->l('Safe changes only')),
                        array('id' => 'all', 'name' => $this->l('Every valid request')),
                    ),
                    'id' => 'id',
                    'name' => 'name',
                ),
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
                'label' => $this->l('Cron items per job'),
                'name' => 'IFIRMA_CRON_MAX_ITEMS',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Most queued orders, KSeF submissions and deliveries one cron run handles per job (1-50).'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API calls per minute'),
                'name' => 'IFIRMA_RATE_MAX_PER_MIN',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Local safety limit for iFirma API calls per minute (1-100).'),
            ),
            array(
                'type' => 'text',
                'label' => $this->l('API calls per day'),
                'name' => 'IFIRMA_RATE_MAX_PER_DAY',
                'class' => 'fixed-width-sm',
                'desc' => $this->l('Local safety limit for iFirma API calls per day (1-15000). The account limit is shared with every other program that uses the iFirma API.'),
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
            'IFIRMA_KEY_FAKTURA' => '',
            'IFIRMA_KEY_ABONENT' => '',
            'IFIRMA_KEY_RACHUNEK' => '',
            'IFIRMA_DOC_TYPE' => IfirmaDocumentSettings::normalizeDocumentType(Configuration::get('IFIRMA_DOC_TYPE')),
            'IFIRMA_SIGNATURE_TYPE' => IfirmaDocumentSettings::normalizeSignatureType(Configuration::get('IFIRMA_SIGNATURE_TYPE')),
            'IFIRMA_PAYMENT_METHOD' => Configuration::get('IFIRMA_PAYMENT_METHOD'),
            'IFIRMA_PAYMENT_METHOD_MODE' => Configuration::get('IFIRMA_PAYMENT_METHOD_MODE'),
            'IFIRMA_PAYMENT_METHOD_MAP' => Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'),
            'IFIRMA_CONTRACTOR_SYNC' => $this->contractorSyncValue(),
            'IFIRMA_PAYMENT_TYPE_TOKEN' => Configuration::get('IFIRMA_PAYMENT_TYPE_TOKEN'),
            'IFIRMA_DEFAULT_UNIT' => Configuration::get('IFIRMA_DEFAULT_UNIT'),
            'IFIRMA_AUTO_CREATE_STATE' => (int) Configuration::get('IFIRMA_AUTO_CREATE_STATE'),
            'IFIRMA_MARK_PAID_ON_CREATE' => (int) Configuration::get('IFIRMA_MARK_PAID_ON_CREATE'),
            'IFIRMA_DOC_TEMPLATE' => (string) Configuration::get('IFIRMA_DOC_TEMPLATE'),
            'IFIRMA_ORDER_STAMP_PREFIX' => Configuration::get('IFIRMA_ORDER_STAMP_PREFIX') ?: 'PS-ORDER',
            'IFIRMA_SHOP_URL' => (string) Configuration::get('IFIRMA_SHOP_URL'),
            'IFIRMA_SEND_CHANNEL' => Configuration::get('IFIRMA_SEND_CHANNEL') ?: 'shop_mail',
            'IFIRMA_SEND_TEXT' => Configuration::get('IFIRMA_SEND_TEXT'),
            'IFIRMA_SEND_COPY_MODE' => Configuration::get('IFIRMA_SEND_COPY_MODE') ?: 'none',
            'IFIRMA_SEND_COPY_EMPLOYEE' => (int) Configuration::get('IFIRMA_SEND_COPY_EMPLOYEE'),
            'IFIRMA_KSEF_MODE' => IfirmaKsefService::normalizeMode(Configuration::get('IFIRMA_KSEF_MODE')),
            'IFIRMA_KSEF_SUBMITTER' => IfirmaKsefService::normalizeSubmitter(Configuration::get('IFIRMA_KSEF_SUBMITTER')),
            'IFIRMA_DUPLICATE_CHECK' => (int) Configuration::get('IFIRMA_DUPLICATE_CHECK'),
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => (int) Configuration::get('IFIRMA_DUPLICATE_WINDOW_DAYS'),
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => Configuration::get('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE'),
            'IFIRMA_ALLOW_DATA_REQUEST' => (int) Configuration::get('IFIRMA_ALLOW_DATA_REQUEST'),
            'IFIRMA_DATA_REQUEST_AUTO_ACCEPT' => Configuration::get('IFIRMA_DATA_REQUEST_AUTO_ACCEPT') ?: 'none',
            'IFIRMA_NOTIFY_EMAIL' => Configuration::get('IFIRMA_NOTIFY_EMAIL'),
            'IFIRMA_SYNC_INTERVAL_MIN' => (int) Configuration::get('IFIRMA_SYNC_INTERVAL_MIN'),
            'IFIRMA_CRON_MAX_CALLS' => (int) Configuration::get('IFIRMA_CRON_MAX_CALLS'),
            'IFIRMA_CRON_MAX_ITEMS' => (int) Configuration::get('IFIRMA_CRON_MAX_ITEMS'),
            'IFIRMA_RATE_MAX_PER_MIN' => (int) Configuration::get('IFIRMA_RATE_MAX_PER_MIN'),
            'IFIRMA_RATE_MAX_PER_DAY' => (int) Configuration::get('IFIRMA_RATE_MAX_PER_DAY'),
        );

        return $helper->generateForm(array($fields_form));
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        return $this->renderAdminOrderPanel((int) $params['id_order']);
    }

    /**
     * Back-office customer page (Symfony customer view). Renders local data
     * only: the link state, the local counts and the pre-filled search form. Every iFirma call
     * is triggered afterwards by the panel's script through the AdminIfirma controller.
     *
     * @param array $params id_customer, or customer (Customer object)
     * @return string
     */
    public function hookDisplayAdminCustomers($params)
    {
        if (!IfirmaPermission::canView($this->context->employee)) {
            return '';
        }
        $idCustomer = 0;
        if (is_array($params) && isset($params['id_customer'])) {
            $idCustomer = (int) $params['id_customer'];
        } elseif (is_array($params) && isset($params['customer']) && is_object($params['customer'])) {
            $idCustomer = (int) $params['customer']->id;
        }
        if ($idCustomer <= 0) {
            return '';
        }
        $customer = new Customer($idCustomer);
        if (!Validate::isLoadedObject($customer)) {
            return '';
        }

        $service = IfirmaCustomerContractorService::build($this);
        $link = $service->linkState($idCustomer);
        $matchLabels = $this->matchLabels();

        $availability = $service->registerAvailability($idCustomer);
        $canRegister = $availability['available'];
        $registerReason = '';
        if ($availability['reason'] === IfirmaCustomerContractorService::REGISTER_DISABLED) {
            $registerReason = $this->l('Contractor registration is disabled in the module settings.');
        } elseif ($availability['reason'] === IfirmaCustomerContractorService::REGISTER_NO_ORDERS) {
            $registerReason = $this->l('Registration needs at least one order of this customer.');
        }

        $token = Tools::getAdminTokenLite('AdminIfirma');
        $this->context->smarty->assign(array(
            'ifirma_customer_id' => $idCustomer,
            'ifirma_customer_configured' => $this->isConfigured(),
            'ifirma_customer_link' => $link,
            'ifirma_customer_match_label' => $link['linked'] ? $matchLabels->label((string) $link['matched_by']) : '',
            // JSON with <, >, & and quotes hex-escaped so it is safe inside the inline script
            'ifirma_customer_match_labels_json' => json_encode($matchLabels->labels(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'ifirma_customer_counts' => $service->localCounts($idCustomer),
            'ifirma_customer_criteria' => $service->defaultCriteria($idCustomer),
            'ifirma_customer_can_register' => $canRegister,
            'ifirma_customer_register_reason' => $registerReason,
            // Register, Link and Unlink change customer data: the controller asks for the customers edit role
            'ifirma_customer_can_edit' => $this->canEditCustomers(),
            'ifirma_customer_ajax_url' => $this->context->link->getAdminLink('AdminIfirma', false) . '&token=' . $token,
            // the back office does not load the module stylesheet by itself; the template links it
            'ifirma_customer_css_url' => $this->_path . 'views/css/ifirma.css?v=' . rawurlencode((string) $this->version),
        ));

        return $this->display(__FILE__, 'views/templates/hook/admin_customer_panel.tpl');
    }

    /**
     * @return bool the employee holds the customers edit role (the SuperAdmin profile always does)
     */
    private function canEditCustomers()
    {
        $employee = $this->context->employee;
        $idProfile = is_object($employee) && isset($employee->id_profile) ? (int) $employee->id_profile : 0;
        if ($idProfile <= 0) {
            return false;
        }
        $superAdmin = defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1;

        return $idProfile === $superAdmin || (bool) Access::isGranted('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', $idProfile);
    }

    /**
     * The words of the admin order panel in the back-office language. The translation scanner only finds the
     * l() calls in this file, so the literals stay here and the panel classes receive them translated.
     *
     * @return array state, request_status, request_fields and contractor words, as IfirmaAdminPanelPresenter takes them
     */
    private function adminPanelLabels()
    {
        return array(
            'state' => $this->adminStateLabels(),
            'request_status' => $this->requestStatusLabels(),
            'request_resolver' => $this->requestResolverLabels(),
            'request_fields' => $this->requestFieldLabels(),
            'contractor' => $this->contractorNoteLabels(),
        );
    }

    /**
     * @return string[] IfirmaAdminPanelPresenter::STATE_* => label
     */
    private function adminStateLabels()
    {
        return array(
            IfirmaAdminPanelPresenter::STATE_HELD => $this->l('On hold (data change request pending)'),
            IfirmaAdminPanelPresenter::STATE_QUEUED => $this->l('Queued for issuance'),
            IfirmaAdminPanelPresenter::STATE_SUSPECTED => $this->l('Suspected duplicate'),
            IfirmaAdminPanelPresenter::STATE_UNKNOWN => $this->l('Outcome unknown - verify in iFirma'),
            IfirmaAdminPanelPresenter::STATE_ERROR => $this->l('Error'),
            IfirmaAdminPanelPresenter::STATE_MISSING => $this->l('Missing in iFirma'),
            IfirmaAdminPanelPresenter::STATE_PAID => $this->l('Paid'),
            IfirmaAdminPanelPresenter::STATE_ISSUED => $this->l('Issued'),
            IfirmaAdminPanelPresenter::STATE_NOT_ISSUED => $this->l('Not issued'),
        );
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
     * @return string[] auto-accept mode => label of the automatic resolver of a data request
     */
    private function requestResolverLabels()
    {
        return array(
            'safe' => $this->l('automatic (safe fields)'),
            'all' => $this->l('automatic (all)'),
        );
    }

    /**
     * @return string[] data request status => label
     */
    private function requestStatusLabels()
    {
        return array(
            IfirmaDataRequest::STATUS_PENDING => $this->l('Pending'),
            IfirmaDataRequest::STATUS_APPROVED => $this->l('Approved'),
            IfirmaDataRequest::STATUS_REJECTED => $this->l('Rejected'),
            IfirmaDataRequest::STATUS_CANCELLED => $this->l('Cancelled'),
        );
    }

    /**
     * @param int $idOrder
     * @return string HTML; '' for no order or when the panel was rendered already on this request
     */
    private function renderAdminOrderPanel($idOrder)
    {
        if (self::$adminOrderRendered || !$idOrder) {
            return '';
        }
        if (!IfirmaPermission::canView($this->context->employee)) {
            return '';
        }
        self::$adminOrderRendered = true;

        $token = Tools::getAdminTokenLite('AdminIfirma');
        $view = new IfirmaAdminOrderPanelView(
            new IfirmaAdminPanelPresenter($this->adminPanelLabels()),
            $this->matchLabels(),
            IfirmaDataRequestService::build($this),
            IfirmaDeliveryService::build($this),
            IfirmaKsefService::build($this)
        );
        $this->context->smarty->assign($view->build($idOrder, array(
            'version' => (string) $this->version,
            // tells a merchant (and us) which build is installed when the version number did not change
            'build_stamp' => date('Y-m-d H:i', (int) filemtime(__FILE__)),
            'configured' => $this->isConfigured(),
            'ajax_url' => $this->context->link->getAdminLink('AdminIfirma', false) . '&token=' . $token,
            'token' => $token,
            'can_issue' => IfirmaPermission::canIssue($this->context->employee),
            'can_manage' => IfirmaPermission::canManage($this->context->employee),
        )));

        return $this->display(__FILE__, 'views/templates/hook/admin_order_panel.tpl');
    }

    /**
     * @return string[] the words of the contractor note of the admin order panel, by purpose: the actions, the
     *                  sentences of the structured notes and the words of the read-back search
     */
    private function contractorNoteLabels()
    {
        return array(
            'created' => $this->l('created'),
            'updated' => $this->l('updated'),
            'kept' => $this->l('kept'),
            'fallback' => $this->l('fallback'),
            'assigned' => $this->l('created (iFirma assigned identifier %s)'),
            'lookup_failed' => $this->l('fallback (lookup of %1$s failed: %2$s)'),
            'update_failed' => $this->l('fallback (update of %1$s failed: %2$s)'),
            'create_rejected' => $this->l('fallback (contractor create rejected: %s)'),
            'reference_rejected' => $this->l('fallback (reference %1$s rejected: %2$s)'),
            'readback_failed' => $this->l('fallback (contractor created but not found: %1$s; search: %2$s)'),
            'ambiguous' => $this->l('ambiguous'),
            'search_failed' => $this->l('failed: %s'),
            'found_nothing' => $this->l('found nothing'),
            'attached' => $this->l('iFirma attached contractor %s'),
        );
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

    /**
     * Orders list: the "iFirma" column (an HtmlColumn before the actions). No-op without the Symfony grid.
     */
    public function hookActionOrderGridDefinitionModifier($params)
    {
        if (!IfirmaPermission::canView($this->context->employee)) {
            return;
        }
        if (!$this->hasGrid() || !isset($params['definition']) || !is_object($params['definition'])) {
            return;
        }
        $columnClass = 'PrestaShop\\PrestaShop\\Core\\Grid\\Column\\Type\\Common\\HtmlColumn';
        $column = new $columnClass(IfirmaOrderGridIndicator::FIELD);
        $column->setName($this->l('iFirma'))->setOptions(array(
            'field' => IfirmaOrderGridIndicator::FIELD,
            'sortable' => true,
            'clickable' => true,
        ));
        $params['definition']->getColumns()->addBefore('actions', $column);
        $this->addStateFilter($params['definition']);

        $bulkClass = 'PrestaShop\\PrestaShop\\Core\\Grid\\Action\\Bulk\\Type\\ButtonBulkAction';
        if (IfirmaPermission::canIssue($this->context->employee)
            && class_exists($bulkClass)
            && method_exists($params['definition'], 'getBulkActions')
        ) {
            $bulk = new $bulkClass('ifirma_issue');
            $bulk->setName($this->l('Issue iFirma documents'))->setOptions(array(
                'class' => 'js-ifirma-bulk-issue',
                'attributes' => array('data-kind' => 'issue'),
            ));
            $params['definition']->getBulkActions()->add($bulk);
        }
    }

    /**
     * The choice filter of the "iFirma" column: one choice per document state. Skipped where the grid has no filter
     * collection or the filter class (older cores, tests).
     *
     * @param object $definition the Orders grid definition
     */
    private function addStateFilter($definition)
    {
        $filterClass = 'PrestaShop\\PrestaShop\\Core\\Grid\\Filter\\Filter';
        if (!class_exists($filterClass) || !method_exists($definition, 'getFilters')) {
            return;
        }
        $filter = new $filterClass(IfirmaOrderGridStateFilter::FIELD, 'Symfony\\Component\\Form\\Extension\\Core\\Type\\ChoiceType');
        $filter->setTypeOptions(array(
            'required' => false,
            'choices' => IfirmaOrderGridStateFilter::choices($this->stateFilterLabels()),
            'translation_domain' => false,
        ))->setAssociatedColumn(IfirmaOrderGridIndicator::FIELD);
        $definition->getFilters()->add($filter);
    }

    /** @return string[] IfirmaDocumentStateRank label key => the word of that state in the filter */
    private function stateFilterLabels()
    {
        return self::decodeWords(array(
            'state_error' => $this->l('Issuance failed'),
            'state_unknown' => $this->l('Unknown outcome'),
            'state_duplicate_suspected' => $this->l('Suspected duplicate'),
            'state_held' => $this->l('Held (data change request)'),
            'state_queued' => $this->l('Queued (iFirma limit)'),
            'state_none' => $this->l('No document yet'),
            'state_issued' => $this->l('Issued, not sent'),
            'state_awaiting_ksef' => $this->l('Waiting for KSeF'),
            'state_sent' => $this->l('Sent'),
            'state_paid' => $this->l('Paid'),
        ));
    }

    /**
     * Orders list: the newest module record of each order joins the search query, and the count query only while a
     * state filter is chosen (an unfiltered list keeps its count query as it is).
     */
    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        if (!IfirmaPermission::canView($this->context->employee)) {
            return;
        }
        if (!$this->hasGrid() || !isset($params['search_query_builder']) || !is_object($params['search_query_builder'])) {
            return;
        }
        $join = IfirmaOrderGridIndicator::joinSql('o');
        $params['search_query_builder']->leftJoin('o', $join['table'], $join['alias'], $join['condition']);
        foreach (IfirmaOrderGridIndicator::selectSql() as $select) {
            $params['search_query_builder']->addSelect($select);
        }
        $criteria = isset($params['search_criteria']) ? $params['search_criteria'] : null;
        $this->filterByTheColumn($params['search_query_builder'], isset($params['count_query_builder']) ? $params['count_query_builder'] : null, $criteria);
        $this->sortByTheColumn($params['search_query_builder'], $criteria);
    }

    /**
     * Only an integer from 1 to 10 filters the list; any other value changes neither query. The same condition goes to
     * the count query, so the total and the paging cover the filtered orders only.
     *
     * @param object      $searchQuery the search query, which already holds the newest-record join
     * @param object|null $countQuery
     * @param object|null $criteria    the grid's search criteria
     */
    private function filterByTheColumn($searchQuery, $countQuery, $criteria)
    {
        $rank = IfirmaOrderGridStateFilter::rankFrom($criteria);
        if ($rank === null) {
            return;
        }
        IfirmaOrderGridStateFilter::apply($searchQuery, $rank);
        if (!is_object($countQuery)) {
            return;
        }
        $join = IfirmaOrderGridIndicator::joinSql('o');
        $countQuery->leftJoin('o', $join['table'], $join['alias'], $join['condition']);
        IfirmaOrderGridStateFilter::apply($countQuery, $rank);
    }

    /**
     * The core sorts the Orders list only by its own whitelist and adds no ORDER BY for any other field, so the sort of the
     * iFirma column is this hook's alone. The direction goes through a whitelist, never into the SQL as given; orders in the
     * same state are listed newest first. The count query is not touched, so the paging cannot change.
     *
     * @param object      $searchQuery
     * @param object|null $criteria    the grid's search criteria
     */
    private function sortByTheColumn($searchQuery, $criteria)
    {
        if (!is_object($criteria) || !method_exists($criteria, 'getOrderBy')
            || $criteria->getOrderBy() !== IfirmaOrderGridIndicator::FIELD) {
            return;
        }
        $way = strtolower((string) $criteria->getOrderWay()) === 'desc' ? 'DESC' : 'ASC';
        $searchQuery->orderBy(IfirmaOrderGridIndicator::SORT_ALIAS, $way);
        $searchQuery->addOrderBy('o.id_order', 'DESC');
    }

    /**
     * Orders list: the icon cell of every presented record, built from the two selected values. PrestaShop 8 and 9
     * present the records as a RecordCollection (an object with all()), so the rows are taken out, filled and handed
     * back in a collection of the same class; a plain array (older presenters, tests) is filled in place.
     */
    public function hookActionOrderGridPresenterModifier($params)
    {
        if (!IfirmaPermission::canView($this->context->employee)) {
            return;
        }
        if (!isset($params['presented_grid']['data']['records'])) {
            return;
        }
        $records = $params['presented_grid']['data']['records'];
        $collection = is_object($records) && method_exists($records, 'all');
        if (!$collection && !is_array($records)) {
            return;
        }
        $rows = $collection ? (array) $records->all() : $records;
        $labels = $this->gridCellLabels();
        $canIssue = IfirmaPermission::canIssue($this->context->employee);
        foreach ($rows as $i => $record) {
            $rows[$i][IfirmaOrderGridIndicator::FIELD] = IfirmaOrderGridIndicator::cell((array) $record, $labels, $canIssue);
        }
        if ($collection) {
            $class = get_class($records);
            $rows = new $class($rows);
        }
        $params['presented_grid']['data']['records'] = $rows;
    }

    /**
     * The words of the iFirma cell in the Orders list.
     *
     * @return string[] paid, issued, issue, and the words of the states error, unknown, duplicate_suspected, held
     */
    public function gridCellLabels()
    {
        return self::decodeWords(array(
            'invoice_issued' => $this->l('Invoice issued'),
            'invoice_sent' => $this->l('Invoice sent'),
            'invoice_paid' => $this->l('Invoice paid'),
            'invoice_ksef' => $this->l('Invoice waiting for KSeF'),
            'receipt_issued' => $this->l('Receipt issued'),
            'receipt_sent' => $this->l('Receipt sent'),
            'receipt_paid' => $this->l('Receipt paid'),
            'receipt_ksef' => $this->l('Receipt waiting for KSeF'),
            'delivery_sent_invoice' => $this->l('Sent to the customer: %s'),
            'delivery_sent_receipt' => $this->l('Receipt sent to the customer: %s'),
            'delivery_none_invoice' => $this->l('Not sent yet'),
            'delivery_none_receipt' => $this->l('Receipt not sent yet'),
            'delivery_failed' => $this->l('Delivery failed'),
            'unpaid_invoice' => $this->l('Unpaid'),
            'unpaid_receipt' => $this->l('Receipt unpaid'),
            'ksef_accepted' => $this->l('KSeF: accepted (%s)'),
            'ksef_progress' => $this->l('KSeF: sent, awaiting confirmation'),
            'ksef_waiting' => $this->l('KSeF: waiting to be sent'),
            'ksef_error' => $this->l('KSeF: error'),
            'ksef_rejected' => $this->l('KSeF: rejected by KSeF'),
            'state_none' => $this->l('No document yet'),
            'state_error' => $this->l('Issuance failed'),
            'state_unknown' => $this->l('Unknown outcome'),
            'state_duplicate_suspected' => $this->l('Suspected duplicate'),
            'state_held' => $this->l('Held (data change request)'),
            'state_queued' => $this->l('Queued (iFirma limit)'),
            'click_to_issue' => $this->l('click to issue'),
        ));
    }

    /**
     * @return string[] IfirmaIssueFeed::KIND_* => the label of that kind of problem
     */
    public function issueKindLabels()
    {
        return self::decodeWords(array(
            IfirmaIssueFeed::KIND_HELD => $this->l('Held by a data request'),
            IfirmaIssueFeed::KIND_ERROR => $this->l('Issuance failed'),
            IfirmaIssueFeed::KIND_UNKNOWN => $this->l('Unknown outcome'),
            IfirmaIssueFeed::KIND_SUSPECTED => $this->l('Suspected duplicate'),
            IfirmaIssueFeed::KIND_QUEUED_STALE => $this->l('Queued for issuance'),
            IfirmaIssueFeed::KIND_MISSING => $this->l('Missing in iFirma'),
            IfirmaIssueFeed::KIND_KSEF_FAILED => $this->l('KSeF failed'),
            IfirmaIssueFeed::KIND_KSEF_REJECTED => $this->l('Rejected by KSeF'),
            IfirmaIssueFeed::KIND_KSEF_OVERDUE => $this->l('KSeF confirmation overdue'),
            IfirmaIssueFeed::KIND_DELIVERY_FAILED => $this->l('Delivery failed'),
        ));
    }

    /**
     * Module::l() HTML-escapes what it translates, the scripts render the words as text: decode them.
     *
     * @param mixed $words a string or a (nested) array of strings
     *
     * @return mixed the same shape with every string entity-decoded
     */
    private static function decodeWords($words)
    {
        if (is_array($words)) {
            foreach ($words as $key => $word) {
                $words[$key] = self::decodeWords($word);
            }

            return $words;
        }

        return is_string($words) ? html_entity_decode($words, ENT_QUOTES, 'UTF-8') : $words;
    }

    /**
     * Back office: the stylesheet and the notification script for everyone who may view the module, the bulk
     * script on the Orders list and the documents list for those who may issue or manage, and the
     * `ifirmaAdmin` variable the scripts read (URL, levels, batch sizes, poll interval and every word).
     */
    public function hookActionAdminControllerSetMedia($params)
    {
        $employee = $this->context->employee;
        if (!IfirmaPermission::canView($employee)) {
            return;
        }
        $canIssue = IfirmaPermission::canIssue($employee);
        $canManage = IfirmaPermission::canManage($employee);

        $current = $this->context->controller;
        $name = '';
        if (is_object($current) && isset($current->controller_name) && is_string($current->controller_name)) {
            $name = $current->controller_name;
        }
        if ($name === '') {
            $given = Tools::getValue('controller');
            $name = is_string($given) ? $given : '';
        }

        if (is_object($current) && method_exists($current, 'addCSS') && method_exists($current, 'addJS')) {
            $current->addCSS($this->_path . 'views/css/ifirma-admin.css', 'all');
            $current->addJS($this->_path . 'views/js/admin/ifirma-notifications.js');
            if (($canIssue || $canManage) && in_array($name, array('AdminOrders', 'AdminIfirma'), true)) {
                $current->addJS($this->_path . 'views/js/admin/ifirma-bulk.js');
            }
        }

        $batchSizes = array();
        foreach (IfirmaBulkRunner::kinds() as $kind) {
            $batchSizes[$kind] = IfirmaBulkRunner::batchSize($kind);
        }
        Media::addJsDef(array('ifirmaAdmin' => array(
            'ajaxUrl' => $this->context->link->getAdminLink('AdminIfirma', true),
            'controller' => $name,
            'canView' => true,
            'canIssue' => $canIssue,
            'canManage' => $canManage,
            'batchSizes' => $batchSizes,
            'pollInterval' => 120000,
            'listUrl' => $this->context->link->getAdminLink('AdminIfirma', true, array(), array('ifirma_preset' => 'attention')),
            'words' => self::decodeWords($this->adminScriptWords()),
        )));
    }

    /**
     * @return array the words of the back-office scripts, as `ifirmaAdmin.words` (the scripts hold no text of their own)
     */
    private function adminScriptWords()
    {
        return array(
            'tab_title' => $this->l('iFirma'),
            'no_issues' => $this->l('No document issues.'),
            'see_all' => $this->l('All documents that need attention'),
            'issue_action' => $this->l('Issue iFirma document'),
            'issuing' => $this->l('Issuing…'),
            'confirm_issue' => $this->l('Issue an iFirma document for this order?'),
            'confirm_bulk_issue' => $this->l('Issue iFirma documents for the %d selected orders?'),
            'confirm_bulk_retry' => $this->l('Retry the issuance of the %d selected documents?'),
            'confirm_bulk_resend' => $this->l('Send the %d selected documents again?'),
            'confirm_bulk_refresh' => $this->l('Refresh the %d selected documents from iFirma?'),
            'nothing_selected' => $this->l('Select at least one row.'),
            'running' => $this->l('Running… %1$d of %2$d'),
            'stopped_rate_limited' => $this->l('Stopped: the rate guard refused a request. Run again in a minute for the remaining rows.'),
            'stopped_auth_blocked' => $this->l('Stopped: iFirma rejected the credentials; see the module settings.'),
            'request_failed' => $this->l('Request failed. Check the browser console and PrestaShop logs.'),
            'summary_title' => $this->l('Bulk run summary'),
            'col_order' => $this->l('Order'),
            'col_outcome' => $this->l('Outcome'),
            'col_message' => $this->l('Message'),
            'close' => $this->l('Close'),
            'outcomes' => array(
                'issued' => $this->l('Issued'),
                'adopted' => $this->l('Adopted'),
                'suspected' => $this->l('Suspected duplicate'),
                'held' => $this->l('Held'),
                'failed' => $this->l('Failed'),
                'sent' => $this->l('Sent'),
                'refreshed' => $this->l('Refreshed'),
                'skipped' => $this->l('Skipped'),
                'not_attempted' => $this->l('Not attempted'),
                'unknown' => $this->l('Unknown, reload the list'),
            ),
            'kinds' => $this->issueKindLabels(),
        );
    }

    /** @return bool whether the Symfony grid classes are loaded (PrestaShop 8/9 back office) */
    private function hasGrid()
    {
        return class_exists('PrestaShop\\PrestaShop\\Core\\Grid\\Column\\Type\\Common\\HtmlColumn');
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

    public function hookDisplayOrderDetail($params)
    {
        if (!$this->isConfigured() || !isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return '';
        }

        $order = $params['order'];
        $idCustomer = (int) $this->context->customer->id;
        if ($idCustomer <= 0 && !$this->context->customer->isLogged() && $this->isGuestTrackingPage()) {
            return $this->guestOrderDetail($order);
        }
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

        $customerView = $this->customerView();
        $view = $customerView->forOrder($order, $receipt, $pending, $customerView->orderOptions());

        $this->context->smarty->assign('ifirma_view', $view);

        return $this->display(__FILE__, 'views/templates/hook/order_detail.tpl');
    }

    /**
     * @return bool whether the page being served is the guest tracking page, which PrestaShop opens only
     *              after matching the reference and the e-mail of the order itself
     */
    private function isGuestTrackingPage()
    {
        $controller = isset($this->context->controller) ? $this->context->controller : null;

        return is_object($controller) && isset($controller->php_self) && $controller->php_self === 'guest-tracking';
    }

    /**
     * The block of the guest tracking page: the state of the document, never the document itself.
     *
     * @param Order $order
     * @return string
     */
    private function guestOrderDetail($order)
    {
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        $pending = IfirmaDataRequestService::build($this)->getPendingForOrder((int) $order->id);

        $customerView = $this->customerView();
        $view = $customerView->forGuestOrder($order, $receipt, $pending, $customerView->orderOptions());

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
