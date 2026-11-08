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

class Db
{
    /** @var Db|null */
    protected static $instance;
    protected static $insertId = 0;

    /** @var string[] every SQL string passed to executeS/getValue/getRow/execute */
    public $queries = [];
    /** @var string[] SQL passed to execute() only */
    public $executed = [];
    /** @var array[] insert/update/delete calls */
    public $inserts = [];
    public $updates = [];
    public $deletes = [];
    /** @var array[] each: ['kind','regex','result'] */
    protected $scripts = [];
    /** @var string[] statements on the IFIRMA_RATE_WINDOW row; kept apart from $queries so tests that assert the queries of a flow are not told about the guard */
    public $rateQueries = [];
    /** @var int rows the last execute() affected */
    protected $affected = 1;

    public static function getInstance($master = true)
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function reset()
    {
        self::$instance = null;
        self::$insertId = 0;
    }

    /** Script a result for ALL query methods (coerced per method). */
    public function when($regex, $result)
    {
        return $this->script('any', $regex, $result);
    }

    public function whenExecuteS($regex, $result)
    {
        return $this->script('executeS', $regex, $result);
    }

    public function whenGetValue($regex, $result)
    {
        return $this->script('getValue', $regex, $result);
    }

    public function whenGetRow($regex, $result)
    {
        return $this->script('getRow', $regex, $result);
    }

    public function whenExecute($regex, $result)
    {
        return $this->script('execute', $regex, $result);
    }

    protected function script($kind, $regex, $result)
    {
        foreach ($this->scripts as $i => $s) {
            if ($s['kind'] === $kind && $s['regex'] === $regex) {
                $this->scripts[$i]['result'] = $result;

                return $this;
            }
        }
        $this->scripts[] = ['kind' => $kind, 'regex' => $regex, 'result' => $result];

        return $this;
    }

    protected function record($sql, $executed)
    {
        if (strpos($sql, 'IFIRMA_RATE_WINDOW') !== false) {
            $this->rateQueries[] = $sql;

            return;
        }
        $this->queries[] = $sql;
        if ($executed) {
            $this->executed[] = $sql;
        }
    }

    /** @return array [found(bool), result] */
    protected function lookup($method, $sql)
    {
        foreach ($this->scripts as $s) {
            if (($s['kind'] === 'any' || $s['kind'] === $method) && preg_match($s['regex'], $sql, $m)) {
                $r = $s['result'];
                if ($r instanceof Closure) {
                    $r = $r($sql, $m);
                }

                return [true, $r];
            }
        }

        return [false, null];
    }

    public function executeS($sql, $array = true, $useCache = true)
    {
        $this->queries[] = $sql;
        list($found, $r) = $this->lookup('executeS', $sql);
        if (!$found || !is_array($r)) {
            return [];
        }

        return $r;
    }

    public function getValue($sql, $useCache = true)
    {
        $this->record($sql, false);
        list($found, $r) = $this->lookup('getValue', $sql);
        if (!$found && preg_match('/^SELECT (GET|RELEASE)_LOCK\(/', $sql)) {
            // an unscripted advisory lock is free and granted, as on a database nobody else uses
            return '1';
        }
        if (!$found && preg_match("/FROM `?\w*configuration`? WHERE `?name`? = '(\w+)'/", $sql, $m)) {
            // an unscripted read of one configuration row answers from the Configuration stub
            return Configuration::hasKey($m[1]) ? Configuration::get($m[1]) : false;
        }
        if (!$found) {
            return false;
        }
        if (is_array($r)) {
            $row = reset($r);
            if (is_array($row)) {
                return reset($row);
            }

            return $row;
        }

        return $r;
    }

    public function getRow($sql, $useCache = true)
    {
        $this->queries[] = $sql;
        list($found, $r) = $this->lookup('getRow', $sql);
        if (!$found || !is_array($r)) {
            return false;
        }
        $first = reset($r);

        return is_array($first) ? $first : $r;
    }

    public function execute($sql, $useCache = true)
    {
        $this->record($sql, true);
        list($found, $r) = $this->lookup('execute', $sql);
        if ($found) {
            // an integer scripts the number of affected rows (the statement itself succeeded)
            $this->affected = is_int($r) ? $r : ((bool) $r ? 1 : 0);

            return is_int($r) ? true : (bool) $r;
        }
        $this->affected = $this->emulateRateWindow($sql);

        return true;
    }

    public function insert($table, $data, $nullValues = false, $useCache = true, $type = 1, $addPrefix = true)
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];

        return true;
    }

    public function update($table, $data, $where = '', $limit = 0, $nullValues = false, $useCache = true, $addPrefix = true)
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];

        return true;
    }

    public function delete($table, $where = '', $limit = 0, $useCache = true, $addPrefix = true)
    {
        $this->deletes[] = ['table' => $table, 'where' => $where];

        return true;
    }

    public function Insert_ID()
    {
        return ++self::$insertId;
    }

    public function Affected_Rows()
    {
        return $this->affected;
    }

    /**
     * Runs the atomic IFIRMA_RATE_WINDOW counter statement of IfirmaApiClient against the Configuration stub,
     * the way MySQL would; any other statement affects one row.
     *
     * @return int affected rows
     */
    protected function emulateRateWindow($sql)
    {
        if (!preg_match("/SET `value` = IF\(.*CONCAT\('(\d{12}):'.*name` = 'IFIRMA_RATE_WINDOW'.*< (\d+)\)/s", $sql, $m)) {
            return 1;
        }
        if (!Configuration::hasKey('IFIRMA_RATE_WINDOW')) {
            return 0;
        }
        $parts = explode(':', (string) Configuration::get('IFIRMA_RATE_WINDOW'), 2);
        $head = $parts[0];
        $tail = count($parts) === 2 ? $parts[1] : $parts[0];
        if ($head === $m[1]) {
            if ((int) $tail >= (int) $m[2]) {
                return 0;
            }
            Configuration::$values['IFIRMA_RATE_WINDOW'] = $m[1] . ':' . ((int) $tail + 1);
        } else {
            Configuration::$values['IFIRMA_RATE_WINDOW'] = $m[1] . ':1';
        }

        return 1;
    }

    public function getMsgError()
    {
        return '';
    }

    public function escape($string, $htmlOk = false, $bqSql = false)
    {
        return addslashes((string) $string);
    }
}
