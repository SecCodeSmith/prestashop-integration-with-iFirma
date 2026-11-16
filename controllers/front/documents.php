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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaCustomerView.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequest.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';

/**
 * The "My invoices" page: the logged-in customer's own documents with their download links. Read-only: it has no
 * postProcess(), takes no request input and never builds an iFirma client.
 */
class IfirmaDocumentsModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        if (!$this->context->customer->isLogged()) {
            $this->redirectToLogin();
        }

        $idCustomer = (int) $this->context->customer->id;
        $view = new IfirmaCustomerView($this->module, $this->context->link);
        $this->context->smarty->assign('ifirma_account', $view->forAccount(
            (array) IfirmaReceipt::getByCustomer($idCustomer),
            IfirmaDataRequest::countPendingByCustomer($idCustomer)
        ));

        $this->setTemplate('module:ifirma/views/templates/front/documents.tpl');
    }

    /**
     * @return array the breadcrumb: Home, "Your account" where the core offers it, then "My invoices"
     */
    public function getBreadcrumbLinks()
    {
        $breadcrumb = parent::getBreadcrumbLinks();
        $title = $this->module->l('My invoices', 'documents');
        $account = $this->accountBreadcrumbLink();
        if ($account !== null) {
            $breadcrumb['links'][] = $account;
        }
        $breadcrumb['links'][] = array('title' => $title, 'url' => $this->context->link->getModuleLink($this->module->name, 'documents', array(), true));
        $breadcrumb['title'] = $title;

        return $breadcrumb;
    }

    /**
     * @return array|null the "Your account" link, null on a PrestaShop 1.7 without addMyAccountToBreadcrumb()
     */
    protected function accountBreadcrumbLink()
    {
        return method_exists($this, 'addMyAccountToBreadcrumb') ? $this->addMyAccountToBreadcrumb() : null;
    }

    /**
     * Sends a visitor who is not logged in to the login page and back here afterwards; protected so a test can
     * observe the redirect without the request ending.
     */
    protected function redirectToLogin()
    {
        $back = $this->context->link->getModuleLink($this->module->name, 'documents', array(), true);
        Tools::redirect($this->context->link->getPageLink('authentication', true, null, array('back' => $back)));
    }
}
