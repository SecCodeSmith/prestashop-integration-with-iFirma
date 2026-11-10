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

class AdminController
{
    public static $currentIndex = 'index.php?controller=AdminModules';

    // the list properties of the core class that the module controller sets (public here so tests read them)
    public $table;
    public $className;
    public $identifier;
    public $list_id;
    public $lang = false;
    public $allow_export = false;
    public $list_no_link = false;
    public $_defaultOrderBy = false;
    public $_defaultOrderWay = 'ASC';
    public $_select = '';
    public $_join = '';
    public $_where = '';
    public $fields_list = array();
    public $bulk_actions = array();
    public $toolbar_btn = array();
    public $page_header_toolbar_btn = array();
    public $_orderBy;
    public $_orderWay;
    public $_tmpTableFilter = '';
    public $redirect_after;
    public $action;
    public $display;

    public function processFilter()
    {
    }

    /**
     * The ORDER BY of the core list (without the cookie fallback and the validity exceptions of the real class):
     * the column goes through order_key and bqSQL, the direction through pSQL.
     */
    protected function getOrderByClause($orderBy, $orderDirection)
    {
        $this->_orderBy = $this->checkOrderBy($orderBy);
        $this->_orderWay = $this->checkOrderDirection($orderDirection);

        return ' ORDER BY '
            . ((str_replace('`', '', $this->_orderBy) == $this->identifier) ? 'a.' : '')
            . $this->_orderBy
            . ' '
            . $this->_orderWay
            . ($this->_tmpTableFilter ? ') tmpTable WHERE 1' . $this->_tmpTableFilter : '');
    }

    protected function checkOrderBy($orderBy)
    {
        if (empty($orderBy)) {
            $orderBy = $this->_orderBy ? $this->_orderBy : $this->_defaultOrderBy;
        }
        if (!isset($this->fields_list[$orderBy]['order_key']) && isset($this->fields_list[$orderBy]['filter_key'])) {
            $this->fields_list[$orderBy]['order_key'] = $this->fields_list[$orderBy]['filter_key'];
        }
        if (isset($this->fields_list[$orderBy]['order_key'])) {
            $orderBy = $this->fields_list[$orderBy]['order_key'];
        }
        if (preg_match('/[.!]/', $orderBy)) {
            $orderBySplit = preg_split('/[.!]/', $orderBy);
            $orderBy = bqSQL($orderBySplit[0]) . '.`' . bqSQL($orderBySplit[1]) . '`';
        } elseif ($orderBy) {
            $orderBy = bqSQL($orderBy);
        }

        return $orderBy;
    }

    protected function checkOrderDirection($orderDirection)
    {
        if (empty($orderDirection)) {
            $orderDirection = $this->_orderWay ? $this->_orderWay : $this->_defaultOrderWay;
        }

        return pSQL(Tools::strtoupper($orderDirection));
    }

    public function init()
    {
    }

    /** The core decides the action from the request; the tests set $action before the module override runs. */
    public function initProcess()
    {
    }

    public function initToolbar()
    {
        $this->toolbar_btn['new'] = array('href' => '#', 'desc' => 'Add new');
    }

    public function initPageHeaderToolbar()
    {
    }
}
