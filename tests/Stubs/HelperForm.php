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

class HelperForm
{
    public $module;
    public $name_controller;
    public $token;
    public $currentIndex;
    public $submit_action;
    public $default_form_language;
    public $fields_value;
    public $show_toolbar;
    public $allow_employee_form_lang;
    public $identifier;
    public $show_cancel_button;

    /** @var array|null last array passed to generateForm() */
    public static $lastForm;
    /** @var HelperForm|null helper that generated the last form (inspect ->fields_value) */
    public static $lastHelper;

    public static function reset()
    {
        self::$lastForm = null;
        self::$lastHelper = null;
    }

    public function generateForm($fieldsForm)
    {
        self::$lastForm = $fieldsForm;
        self::$lastHelper = $this;

        return '<form/>';
    }
}
