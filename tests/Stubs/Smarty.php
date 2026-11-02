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

class Smarty
{
    /** @var array assigned template variables */
    public $vars = [];

    public function assign($key, $value = null)
    {
        if (is_array($key)) {
            $this->vars = array_merge($this->vars, $key);
        } else {
            $this->vars[$key] = $value;
        }
    }

    public function fetch($template = null)
    {
        return '';
    }

    public function clearAssign($key)
    {
        foreach ((array) $key as $k) {
            unset($this->vars[$k]);
        }
    }
}
