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

    /** @var array|null the fields an update is limited to (core: setFieldsToUpdate()), null for all of them */
    protected $update_fields = null;

    public function setFieldsToUpdate(?array $fields)
    {
        $this->update_fields = $fields;
    }

    public function getFieldsToUpdate(): ?array
    {
        return $this->update_fields;
    }

    /** As the core: copies the keys of a row that are properties of the object. */
    public function hydrate(array $data, $idLang = null)
    {
        $def = static::$definition;
        if (isset($data[$def['primary']])) {
            $this->id = (int) $data[$def['primary']];
        }
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    /** @var array table => id => field array */
    public static $registry = [];

    /**
     * @var array[] every persisted write, oldest first: ['op' => 'add'|'update', 'table', 'id',
     *              'row' => the stored field array]; one entry per save()
     */
    public static $writeLog = [];

    public static function reset()
    {
        self::$registry = [];
        self::$writeLog = [];
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
        $this->store('add');

        return true;
    }

    public function update($nullValues = false)
    {
        $def = static::$definition;
        if (isset($def['fields']['date_upd']) && property_exists($this, 'date_upd')) {
            $this->date_upd = date('Y-m-d H:i:s');
            if (is_array($this->update_fields) && count($this->update_fields)) {
                $this->update_fields['date_upd'] = true;
            }
        }
        $this->store('update');

        return true;
    }

    public function delete()
    {
        $table = static::$definition['table'];
        unset(self::$registry[$table][$this->id]);

        return true;
    }

    protected function store($op)
    {
        $def = static::$definition;
        // as the core's formatFields(): an update limited to some fields writes only those, the rest of the row stays
        $limited = $op === 'update' && is_array($this->update_fields);
        $row = $limited && isset(self::$registry[$def['table']][$this->id]) ? self::$registry[$def['table']][$this->id] : [];
        $columns = [];
        foreach (array_keys($def['fields']) as $field) {
            if ($limited && empty($this->update_fields[$field])) {
                if (!array_key_exists($field, $row)) {
                    $row[$field] = null;
                }
                continue;
            }
            $columns[] = $field;
            $row[$field] = property_exists($this, $field) ? $this->$field : null;
        }
        self::$registry[$def['table']][$this->id] = $row;
        self::$writeLog[] = ['op' => $op, 'table' => $def['table'], 'id' => $this->id, 'row' => $row, 'columns' => $columns];
    }
}
