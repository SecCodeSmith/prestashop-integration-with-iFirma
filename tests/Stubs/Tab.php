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

class Tab
{
    public $id;
    public $class_name;
    public $module;
    public $active;
    public $id_parent;
    public $name;

    /** @var array class_name => id for getIdFromClassName */
    public static $ids = [];
    public static $added = [];
    public static $deleted = [];

    public static function reset()
    {
        self::$ids = [];
        self::$added = [];
        self::$deleted = [];
    }

    public function __construct($id = null)
    {
        $this->id = $id;
    }

    public function add()
    {
        self::$added[] = $this;

        return true;
    }

    public function delete()
    {
        self::$deleted[] = $this;

        return true;
    }

    public static function getIdFromClassName($class)
    {
        return isset(self::$ids[$class]) ? self::$ids[$class] : 0;
    }
}
