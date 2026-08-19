<?php
/**
 * iFirma – Receipts & Invoices for PrestaShop.
 *
 * Two-way integration with the iFirma accounting service (www.ifirma.pl):
 *   - Feature 3: a "Create Receipt" button on the order page issues a document in iFirma.
 *   - Feature 1: the issued document (PDF) is fetched back and exposed in the
 *                registered customer's account / order detail (the "return connection").
 *   - Feature 2: an "Update status" button registers the payment in iFirma.
 *
 * Compatible with PrestaShop 1.7.x, 8.x and 9.x (PHP 7.2 – 8.x).
 *
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/IfirmaApiClient.php';
require_once __DIR__ . '/classes/IfirmaReceipt.php';
require_once __DIR__ . '/classes/IfirmaReceiptService.php';

class Ifirma extends Module
{
    /** @var string status shown to admins for a paid document */
    const STATUS_PAID = 'paid';

    /** @var bool guard so the admin-order panel is not rendered twice */
    private static $adminOrderRendered = false;

    public function __construct()
    {
        $this->name = 'ifirma';
        $this->tab = 'billing_invoicing';
        $this->version = '1.0.0';
        $this->author = 'SCM Jakub Berechowskir';
        $this->need_instance = 0;
        // Compatible with PrestaShop 1.7.x, 8.x and 9.x.
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => '9.99.99');
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('iFirma – Receipts & Invoices');
        $this->description = $this->l('Issue iFirma receipts/invoices from the order page, register payments and let customers download their document.');
        $this->confirmUninstall = $this->l('Remove all iFirma settings and the local receipt log? Documents already issued in iFirma are NOT affected.');
    }

    /* ------------------------------------------------------------------ */
    /* Install / uninstall                                                */
    /* ------------------------------------------------------------------ */

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
        Configuration::updateValue('IFIRMA_BASE_URL', IfirmaApiClient::BASE_URL);
        Configuration::updateValue('IFIRMA_USER', '');
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', '');
        Configuration::updateValue('IFIRMA_KEY_ABONENT', '');
        Configuration::updateValue('IFIRMA_KEY_RACHUNEK', '');
        Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturaparagon');
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', 'PRZ');
        Configuration::updateValue('IFIRMA_PAYMENT_TYPE_TOKEN', 'prz_faktura_kraj');
        Configuration::updateValue('IFIRMA_DEFAULT_UNIT', 'szt.');
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 0);
        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', 1);

        return true;
    }

    private function uninstallConfiguration()
    {
        foreach (array(
            'IFIRMA_BASE_URL',
            'IFIRMA_USER', 'IFIRMA_KEY_FAKTURA', 'IFIRMA_KEY_ABONENT', 'IFIRMA_KEY_RACHUNEK',
            'IFIRMA_DOC_TYPE', 'IFIRMA_PAYMENT_METHOD', 'IFIRMA_PAYMENT_TYPE_TOKEN',
            'IFIRMA_DEFAULT_UNIT', 'IFIRMA_AUTO_CREATE_STATE', 'IFIRMA_MARK_PAID_ON_CREATE',
        ) as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * Hidden back-office tab so AdminIfirmaController gets a valid security token.
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->class_name = 'AdminIfirma';
        $tab->module = $this->name;
        $tab->active = 1;
        // Hidden from the menu (parent -1); it only serves AJAX from the order page.
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

    /* ------------------------------------------------------------------ */
    /* Configuration page                                                 */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitIfirma')) {
            $output .= $this->postProcessConfiguration();
        }

        return $output . $this->renderConfigForm();
    }

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

        if ($errors) {
            return $this->displayError(implode('<br>', $errors));
        }

        $baseUrl = trim(Tools::getValue('IFIRMA_BASE_URL'));
        Configuration::updateValue('IFIRMA_BASE_URL', $baseUrl !== '' ? $baseUrl : IfirmaApiClient::BASE_URL);
        Configuration::updateValue('IFIRMA_USER', $user);
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', $keyFaktura);
        Configuration::updateValue('IFIRMA_KEY_ABONENT', trim(Tools::getValue('IFIRMA_KEY_ABONENT')));
        Configuration::updateValue('IFIRMA_KEY_RACHUNEK', trim(Tools::getValue('IFIRMA_KEY_RACHUNEK')));
        Configuration::updateValue('IFIRMA_DOC_TYPE', Tools::getValue('IFIRMA_DOC_TYPE'));
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', Tools::getValue('IFIRMA_PAYMENT_METHOD'));
        Configuration::updateValue('IFIRMA_PAYMENT_TYPE_TOKEN', trim(Tools::getValue('IFIRMA_PAYMENT_TYPE_TOKEN')));
        Configuration::updateValue('IFIRMA_DEFAULT_UNIT', Tools::getValue('IFIRMA_DEFAULT_UNIT'));
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', (int) Tools::getValue('IFIRMA_AUTO_CREATE_STATE'));
        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', (int) Tools::getValue('IFIRMA_MARK_PAID_ON_CREATE'));

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    private function renderConfigForm()
    {
        $orderStates = array(array('id' => 0, 'name' => $this->l('— Disabled (create manually only) —')));
        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $orderStates[] = array('id' => (int) $state['id_order_state'], 'name' => $state['name']);
        }

        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('iFirma API credentials'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
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
                        'label' => $this->l('Payment method (SposobZaplaty)'),
                        'name' => 'IFIRMA_PAYMENT_METHOD',
                        'options' => array(
                            'query' => array(
                                array('id' => 'PRZ', 'name' => $this->l('Bank transfer (PRZ)')),
                                array('id' => 'GTK', 'name' => $this->l('Cash (GTK)')),
                                array('id' => 'KAR', 'name' => $this->l('Card (KAR)')),
                                array('id' => 'POB', 'name' => $this->l('Cash on delivery (POB)')),
                            ),
                            'id' => 'id',
                            'name' => 'name',
                        ),
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
                        'values' => array(
                            array('id' => 'on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                ),
                'submit' => array('title' => $this->l('Save')),
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
            'IFIRMA_PAYMENT_TYPE_TOKEN' => Configuration::get('IFIRMA_PAYMENT_TYPE_TOKEN'),
            'IFIRMA_DEFAULT_UNIT' => Configuration::get('IFIRMA_DEFAULT_UNIT'),
            'IFIRMA_AUTO_CREATE_STATE' => (int) Configuration::get('IFIRMA_AUTO_CREATE_STATE'),
            'IFIRMA_MARK_PAID_ON_CREATE' => (int) Configuration::get('IFIRMA_MARK_PAID_ON_CREATE'),
        );

        return $helper->generateForm(array($fields_form));
    }

    /* ------------------------------------------------------------------ */
    /* Admin order page (Feature 2 + 3)                                   */
    /* ------------------------------------------------------------------ */

    /**
     * PrestaShop 1.7.7+ / 8.x – the panel is rendered here, at the bottom of the
     * main column of the order detail page (its intended, tidy location).
     */
    public function hookDisplayAdminOrderMainBottom($params)
    {
        return $this->renderAdminOrderPanel((int) $params['id_order']);
    }

    /**
     * Legacy order page (1.7.0–1.7.6). On 1.7.7+ this hook is still fired for
     * backward compatibility but renders in a deprecated zone at the top of the
     * page, so we skip it there and let displayAdminOrderMainBottom win.
     */
    public function hookDisplayAdminOrder($params)
    {
        if ($this->isModernOrderPage()) {
            return '';
        }

        return $this->renderAdminOrderPanel((int) $params['id_order']);
    }

    /**
     * @return bool true on the refactored order page (PrestaShop 1.7.7+ / 8.x)
     */
    private function isModernOrderPage()
    {
        return version_compare(_PS_VERSION_, '1.7.7.0', '>=');
    }

    private function renderAdminOrderPanel($idOrder)
    {
        // Only render once per page even when both admin-order hooks fire.
        if (self::$adminOrderRendered || !$idOrder) {
            return '';
        }
        self::$adminOrderRendered = true;

        $receipt = IfirmaReceipt::getByOrder($idOrder);
        $token = Tools::getAdminTokenLite('AdminIfirma');

        $this->context->smarty->assign(array(
            'ifirma_id_order' => $idOrder,
            'ifirma_receipt' => $receipt,
            'ifirma_has_receipt' => (bool) $receipt,
            'ifirma_is_issued' => ($receipt && $receipt->isIssued()),
            'ifirma_configured' => $this->isConfigured(),
            'ifirma_ajax_url' => $this->context->link->getAdminLink('AdminIfirma', false)
                . '&token=' . $token,
            'ifirma_token' => $token,
        ));

        return $this->display(__FILE__, 'views/templates/hook/admin_order_panel.tpl');
    }

    /* ------------------------------------------------------------------ */
    /* Customer account & order detail (Feature 1 – return connection)    */
    /* ------------------------------------------------------------------ */

    /**
     * Load the front-office stylesheet.
     */
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

        $receipts = IfirmaReceipt::getByCustomer((int) $this->context->customer->id);
        if (!$receipts) {
            return '';
        }

        $this->context->smarty->assign(array(
            'ifirma_receipts' => $this->decorateReceipts($receipts),
            'ifirma_count' => count($receipts),
        ));

        return $this->display(__FILE__, 'views/templates/hook/customer_account.tpl');
    }

    public function hookDisplayOrderDetail($params)
    {
        if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return '';
        }

        $order = $params['order'];
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);

        if (!$receipt || !$receipt->isIssued()) {
            return '';
        }

        // Never expose another customer's document.
        if ((int) $receipt->id_customer !== (int) $this->context->customer->id) {
            return '';
        }

        $rows = $this->decorateReceipts(array(array(
            'id_ifirma_receipt' => (int) $receipt->id,
            'ifirma_number' => $receipt->ifirma_number,
            'ifirma_id' => $receipt->ifirma_id,
            'status' => $receipt->status,
            'document_type' => $receipt->document_type,
            'date_add' => $receipt->date_add,
            'security_token' => $receipt->security_token,
        )));

        $this->context->smarty->assign('ifirma_receipt', $rows[0]);

        return $this->display(__FILE__, 'views/templates/hook/order_detail.tpl');
    }

    /**
     * Attach a signed front-office download URL to each receipt row.
     *
     * @param array $rows
     * @return array
     */
    private function decorateReceipts(array $rows)
    {
        $out = array();
        foreach ($rows as $row) {
            $row['download_url'] = $this->context->link->getModuleLink(
                $this->name,
                'receipt',
                array('token' => $row['security_token']),
                true
            );
            $out[] = $row;
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Automatic issuing on status change                                 */
    /* ------------------------------------------------------------------ */

    public function hookActionOrderStatusPostUpdate($params)
    {
        $triggerState = (int) Configuration::get('IFIRMA_AUTO_CREATE_STATE');
        if (!$triggerState || !$this->isConfigured()) {
            return;
        }

        $newState = isset($params['newOrderStatus']) ? $params['newOrderStatus'] : null;
        if (!$newState || (int) $newState->id !== $triggerState) {
            return;
        }

        $idOrder = (int) $params['id_order'];
        $existing = IfirmaReceipt::getByOrder($idOrder);
        if ($existing && $existing->isIssued()) {
            return;
        }

        try {
            $service = IfirmaReceiptService::build($this);
            $service->createReceipt(new Order($idOrder));
        } catch (Exception $e) {
            // Never break the order workflow; the failure is logged on the receipt row.
            PrestaShopLogger::addLog('iFirma auto-create failed for order ' . $idOrder . ': ' . $e->getMessage(), 2);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @return bool true when the minimum credentials are set.
     */
    public function isConfigured()
    {
        return (bool) Configuration::get('IFIRMA_USER') && (bool) Configuration::get('IFIRMA_KEY_FAKTURA');
    }
}
