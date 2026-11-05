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

/**
 * Makes the reads of one table fail the way a failing query does (false), for a PHPUnit TestCase whose
 * data provider names the table without its prefix. A failed read is still recorded in the queries of
 * the Db stub; every other statement runs on the Db stub as usual.
 */
trait IfirmaFailingDbReads
{
    /**
     * Makes the reads of the given table fail the way a failing query does: false.
     *
     * @param string $table without prefix
     */
    private function failingRead($table)
    {
        $db = new class extends Db {
            /** @var string */
            public $failing = '';

            public function executeS($sql, $array = true, $useCache = true)
            {
                if (strpos($sql, $this->failing) !== false) {
                    $this->queries[] = $sql;

                    return false;
                }

                return parent::executeS($sql, $array, $useCache);
            }

            public function install()
            {
                self::$instance = $this;
            }
        };
        $db->failing = '`ps_' . $table . '`';
        $db->install();
    }
}
