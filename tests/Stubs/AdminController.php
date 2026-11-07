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
    public $redirect_after;

    public function processFilter()
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
