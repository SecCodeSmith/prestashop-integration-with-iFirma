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

class ObjectModel
{
    const TYPE_INT = 1;
    const TYPE_BOOL = 2;
    const TYPE_STRING = 3;
    const TYPE_FLOAT = 4;
    const TYPE_DATE = 5;
    const TYPE_HTML = 6;
    const TYPE_NOTHING = 7;
    const TYPE_SQL = 8;

    public $id;

    /** @var array table => id => field array */
    public static $registry = [];

    public static function reset()
    {
        self::$registry = [];
    }

    public function __construct($id = null, $idLang = null, $idShop = null)
    {
        if ($id) {
            $def = static::$definition;
            if (isset(self::$registry[$def['table']][$id])) {
                foreach (self::$registry[$def['table']][$id] as $field => $value) {
                    if (property_exists($this, $field)) {
                        $this->$field = $value;
                    }
                }
                $this->id = (int) $id;
                $this->{$def['primary']} = (int) $id;
            }
        }
    }

    public function save($nullValues = false, $autoDate = true)
    {
        return $this->id ? $this->update($nullValues) : $this->add($autoDate, $nullValues);
    }

    public function add($autoDate = true, $nullValues = false)
    {
        $def = static::$definition;
        $table = $def['table'];
        $next = 1;
        if (!empty(self::$registry[$table])) {
            $next = max(array_keys(self::$registry[$table])) + 1;
        }
        $this->id = $next;
        $this->{$def['primary']} = $next;
        $now = date('Y-m-d H:i:s');
        if ($autoDate) {
            foreach (['date_add', 'date_upd'] as $f) {
                if (isset($def['fields'][$f]) && property_exists($this, $f) && empty($this->$f)) {
                    $this->$f = $now;
                }
            }
        }
        $this->store();

        return true;
    }

    public function update($nullValues = false)
    {
        $def = static::$definition;
        if (isset($def['fields']['date_upd']) && property_exists($this, 'date_upd')) {
            $this->date_upd = date('Y-m-d H:i:s');
        }
        $this->store();

        return true;
    }

    public function delete()
    {
        $table = static::$definition['table'];
        unset(self::$registry[$table][$this->id]);

        return true;
    }

    protected function store()
    {
        $def = static::$definition;
        $row = [];
        foreach (array_keys($def['fields']) as $field) {
            $row[$field] = property_exists($this, $field) ? $this->$field : null;
        }
        self::$registry[$def['table']][$this->id] = $row;
    }
}
