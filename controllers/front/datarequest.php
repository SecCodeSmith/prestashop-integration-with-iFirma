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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequestService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaInput.php';

/**
 * Customer form to request a change of the invoice data of an order.
 * All rules live in IfirmaDataRequestService.
 */
class IfirmaDatarequestModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ssl = true;

    /** @var array field => message */
    public $fieldErrors = array();

    /** @var array submitted values, to refill the form */
    public $submitted = array();

    /** @var Order|null */
    private $order;

    /** @var IfirmaDataRequestService */
    private $service;

    /**
     * @return IfirmaDataRequestService
     */
    private function service()
    {
        if ($this->service === null) {
            $this->service = IfirmaDataRequestService::build($this->module);
        }

        return $this->service;
    }

    /**
     * The `token` request value as a string; an array gives ''.
     *
     * @return string
     */
    public function readToken()
    {
        return IfirmaInput::scalar(Tools::getValue('token'));
    }

    /**
     * The `id_order` request value as an integer; an array gives 0.
     *
     * @return int
     */
    public function readOrderId()
    {
        return (int) IfirmaInput::scalar(Tools::getValue('id_order'));
    }

    /**
     * Loads the order; redirects away when it is not the customer's.
     *
     * @return Order
     */
    private function loadOrder()
    {
        if ($this->order === null) {
            $order = new Order($this->readOrderId());
            if (!Validate::isLoadedObject($order)
                || (int) $order->id_customer !== (int) $this->context->customer->id) {
                Tools::redirect($this->context->link->getPageLink('history', true));
            }
            $this->order = $order;
        }

        return $this->order;
    }

    private function requireLogin()
    {
        if (!$this->context->customer->isLogged()) {
            $back = $this->context->link->getModuleLink(
                $this->module->name,
                'datarequest',
                array('id_order' => $this->readOrderId()),
                true
            );
            Tools::redirect($this->context->link->getPageLink('authentication', true, null, array('back' => $back)));
        }
    }

    public function postProcess()
    {
        $this->requireLogin();

        $submit = Tools::getValue('submitIfirmaDataRequest');
        $cancel = Tools::getValue('cancelIfirmaDataRequest');
        if (!$submit && !$cancel) {
            return;
        }

        $order = $this->loadOrder();
        if (!IfirmaDataRequestService::isValidToken($this->readToken())) {
            $this->errors[] = $this->service()->reasonMessage('invalid_token');

            return;
        }

        try {
            if ($submit) {
                $input = $this->service()->collectInput();
                $this->submitted = $input;
                $this->service()->submit($order, $this->context->customer, $input);
            } else {
                $request = $this->service()->getPendingForOrder((int) $order->id);
                if (!$request) {
                    throw new IfirmaDataRequestException($this->service()->reasonMessage('not_pending'));
                }
                $this->service()->cancel($request, $this->context->customer);
            }
        } catch (IfirmaDataRequestException $e) {
            $this->errors[] = $e->getMessage();
            $this->fieldErrors = $e->errors;

            return;
        }

        Tools::redirect($this->context->link->getPageLink('order-detail', true, null, 'id_order=' . (int) $order->id));
    }

    public function initContent()
    {
        parent::initContent();
        $this->requireLogin();

        $order = $this->loadOrder();
        $eligibility = $this->service()->canRequest($order, $this->context->customer);
        $pending = $this->service()->getPendingForOrder((int) $order->id);
        $address = new Address((int) $order->id_address_invoice);

        $countries = array();
        if (method_exists('Country', 'getCountries')) {
            $countries = Country::getCountries((int) $this->context->language->id, true);
        }

        $values = $this->submitted;
        if (empty($values)) {
            $values = $this->service()->defaultsFromAddress($address);
        }

        $this->context->smarty->assign(array(
            'ifirma_order' => $order,
            'ifirma_eligibility' => $eligibility,
            'ifirma_reason_message' => $eligibility['allowed'] ? '' : $this->service()->reasonMessage($eligibility['reason']),
            'ifirma_pending' => $pending ? $this->service()->summarize($pending) : null,
            'ifirma_address' => $address,
            'ifirma_values' => $values,
            'ifirma_countries' => $countries,
            'ifirma_token' => Tools::getToken(false),
            'ifirma_errors' => $this->errors,
            'ifirma_field_errors' => $this->fieldErrors,
            'ifirma_form_action' => $this->context->link->getModuleLink($this->module->name, 'datarequest', array(), true),
            'ifirma_order_link' => $this->context->link->getPageLink('order-detail', true, null, 'id_order=' . (int) $order->id),
        ));

        $this->setTemplate('module:ifirma/views/templates/front/datarequest.tpl');
    }
}
