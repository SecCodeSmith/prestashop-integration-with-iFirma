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

namespace PrestaShop\PrestaShop\Core\Grid\Filter;

/**
 * Stand-in for the Symfony grid Filter (same constructor and accessors as PrestaShop 8.0 to 9.x): keeps what the
 * module sets, so a test can read it back.
 */
class Filter
{
    private $name;
    private $type;
    private $typeOptions = array();
    private $associatedColumn;

    public function __construct($name, $filterFormType)
    {
        $this->name = $name;
        $this->type = $filterFormType;
    }

    public function getType()
    {
        return $this->type;
    }

    public function setTypeOptions(array $filterTypeOptions)
    {
        $this->typeOptions = $filterTypeOptions;

        return $this;
    }

    public function getTypeOptions()
    {
        return $this->typeOptions;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setAssociatedColumn($columnId)
    {
        $this->associatedColumn = $columnId;

        return $this;
    }

    public function getAssociatedColumn()
    {
        return $this->associatedColumn;
    }
}
