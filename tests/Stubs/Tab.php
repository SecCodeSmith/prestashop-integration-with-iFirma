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
    public $position;

    /** @var array class_name => id for getIdFromClassName, checked before $rows */
    public static $ids = [];
    /** @var array id => array('class_name', 'module', 'id_parent', 'name' (by id_lang), 'position', 'active') */
    public static $rows = [];
    public static $added = [];
    public static $deleted = [];

    public static function reset()
    {
        self::$ids = [];
        self::$rows = [];
        self::$added = [];
        self::$deleted = [];
    }

    public function __construct($id = null)
    {
        $this->id = $id;
        if ($id !== null && isset(self::$rows[(int) $id])) {
            $row = self::$rows[(int) $id];
            $this->class_name = $row['class_name'];
            $this->module = $row['module'];
            $this->id_parent = $row['id_parent'];
            $this->name = $row['name'];
            $this->position = $row['position'];
            $this->active = $row['active'];
        }
    }

    /** @return array the fields of this object, as $rows keeps them */
    private function row()
    {
        return array(
            'class_name' => $this->class_name,
            'module' => $this->module,
            'id_parent' => $this->id_parent,
            'name' => $this->name,
            'position' => $this->position,
            'active' => $this->active,
        );
    }

    public function add()
    {
        $ids = array_keys(self::$rows);
        $this->id = $ids ? max($ids) + 1 : 1;
        self::$rows[$this->id] = $this->row();
        self::$added[] = $this;

        return true;
    }

    public function update()
    {
        if (!$this->id) {
            return false;
        }
        self::$rows[(int) $this->id] = $this->row();

        return true;
    }

    public function save()
    {
        return $this->id ? $this->update() : $this->add();
    }

    public function delete()
    {
        self::$deleted[] = $this;
        unset(self::$rows[(int) $this->id]);

        return true;
    }

    public static function getIdFromClassName($class)
    {
        if (isset(self::$ids[$class])) {
            return self::$ids[$class];
        }
        foreach (self::$rows as $id => $row) {
            if ($row['class_name'] === $class) {
                return (int) $id;
            }
        }

        return 0;
    }
}
