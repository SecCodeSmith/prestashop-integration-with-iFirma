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

class Module
{
    public $name;
    public $tab;
    public $version;
    public $author;
    public $displayName;
    public $description;
    public $confirmUninstall;
    public $need_instance;
    public $ps_versions_compliancy;
    public $bootstrap;
    public $_path;
    public $context;
    public $id;

    /** @var Module[] name => instance; tests set this for getInstanceByName */
    public static $instances = [];
    /** @var array list of [template, smartyVars] */
    public static $displayed = [];
    /** @var string[] hooks registered via registerHook */
    public static $hooks = [];

    public static function reset()
    {
        self::$instances = [];
        self::$displayed = [];
        self::$hooks = [];
    }

    public function __construct($name = null, $context = null)
    {
        $this->context = $context ?: Context::getContext();
        $this->_path = '/modules/ifirma/';
    }

    public function l($string, $specific = null, $locale = null)
    {
        return $string;
    }

    public function display($file, $template, $cacheId = null, $compileId = null)
    {
        self::$displayed[] = [$template, Context::getContext()->smarty->vars];

        return '<rendered:' . $template . '>';
    }

    public function registerHook($hookName, $shopList = null)
    {
        self::$hooks[] = $hookName;

        return true;
    }

    public function isRegisteredInHook($hook)
    {
        return in_array($hook, self::$hooks, true);
    }

    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    public function displayError($error)
    {
        return '<error>' . $error . '</error>';
    }

    public function displayConfirmation($string)
    {
        return '<confirmation>' . $string . '</confirmation>';
    }

    public static function getInstanceByName($moduleName)
    {
        return isset(self::$instances[$moduleName]) ? self::$instances[$moduleName] : false;
    }
}
