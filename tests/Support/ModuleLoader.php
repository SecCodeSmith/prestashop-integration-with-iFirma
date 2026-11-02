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

/** Loads the module main file / controllers on demand (bootstrap does not require them). */
class IfirmaTestModuleLoader
{
    /** Requires ifirma.php once and returns a new Ifirma (also stored in Module::$instances['ifirma']). */
    public static function loadModule()
    {
        require_once _PS_MODULE_DIR_ . 'ifirma/ifirma.php';
        $module = new Ifirma();
        Module::$instances['ifirma'] = $module;

        return $module;
    }

    /** @return AdminIfirmaController */
    public static function loadAdminController()
    {
        require_once _PS_MODULE_DIR_ . 'ifirma/controllers/admin/AdminIfirmaController.php';

        return new AdminIfirmaController();
    }

    /**
     * @param string $name front controller file name without .php (e.g. 'receipt')
     * @return ModuleFrontController
     */
    public static function loadFrontController($name)
    {
        require_once _PS_MODULE_DIR_ . 'ifirma/controllers/front/' . $name . '.php';
        $class = 'Ifirma' . ucfirst($name) . 'ModuleFrontController';

        return new $class();
    }
}
