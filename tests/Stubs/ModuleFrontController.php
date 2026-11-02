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

class ModuleFrontController
{
    public $module;
    public $context;
    public $ajax = false;
    public $errors = [];
    public $success = [];
    /** @var string|null last template passed to setTemplate() */
    public $template;
    /** @var string[] */
    public $templates = [];

    public function __construct()
    {
        $this->context = Context::getContext();
        $this->module = Module::getInstanceByName('ifirma');
    }

    public function initContent()
    {
    }

    public function setTemplate($template, $params = [], $locale = null)
    {
        $this->template = $template;
        $this->templates[] = $template;
    }
}
