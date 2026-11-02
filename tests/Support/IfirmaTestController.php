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

/** Stand-in for Context::$controller (the current page controller). */
class IfirmaTestController
{
    public $php_self = 'index';
    /** @var array[] */
    public $stylesheets = [];
    /** @var array[] */
    public $css = [];

    public function registerStylesheet($id, $path, $params = [])
    {
        $this->stylesheets[] = ['id' => $id, 'path' => $path, 'params' => $params];
    }

    public function addCSS($path, $media = 'all')
    {
        $this->css[] = ['path' => $path, 'media' => $media];
    }
}
