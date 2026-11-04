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

class ModuleAdminController extends AdminController
{
    public $module;
    public $bootstrap;
    public $context;
    public $ajax = false;
    /** @var string[] */
    public $errors = [];

    /** Like core's init(): an ajax request needs the token of the controller's tab. */
    public function checkToken()
    {
        return Tools::getValue('token') === Tools::getAdminTokenLite('AdminIfirma');
    }

    /**
     * Mimics the core dispatch: rejects a request with a missing or wrong token before any
     * ajaxProcess<Action>() method is reached.
     *
     * @return bool true when the action was dispatched
     */
    public function dispatchAjax($action)
    {
        if (!$this->checkToken()) {
            $this->errors[] = 'Invalid token';

            return false;
        }
        $method = 'ajaxProcess' . $action;
        $this->$method();

        return true;
    }

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->module = Module::getInstanceByName('ifirma');
    }
}
