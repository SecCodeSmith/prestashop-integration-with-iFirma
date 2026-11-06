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

namespace PrestaShop\PrestaShop\Core\Grid\Column\Type\Common;

/**
 * Stand-in for the Symfony grid HtmlColumn: keeps what the module sets, so a test can read it back.
 */
class HtmlColumn
{
    public $id;
    public $name = '';
    public $options = array();

    public function __construct($id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function setOptions(array $options)
    {
        $this->options = $options;

        return $this;
    }
}
