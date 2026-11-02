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

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->module = Module::getInstanceByName('ifirma');
    }
}
