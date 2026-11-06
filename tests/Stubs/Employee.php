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

class Employee
{
    use IfirmaTestRegistryTrait {
        reset as protected resetRegistry;
    }

    public $id;
    public $firstname;
    public $lastname;
    public $email;
    public $active = 1;
    /** @var int profile id (1 = SuperAdmin in core); read by the customer panel permission check */
    public $id_profile = 1;

    /**
     * @var callable|null test seam of getEmployees(): it receives the $activeOnly argument and the rows the
     *      stub would return, and what it returns replaces them, so a test can count the calls, see the
     *      argument or return rows of its own. Cleared by reset().
     */
    public static $employeesHook;

    public static function reset()
    {
        self::resetRegistry();
        self::$employeesHook = null;
    }

    public function __construct($id = null)
    {
        $this->loadFromRegistry($id);
    }

    /**
     * Like core: rows hold id_employee, firstname and lastname only, ordered by lastname.
     *
     * @param bool $activeOnly
     * @return array[]
     */
    public static function getEmployees($activeOnly = true)
    {
        $rows = array();
        foreach (static::$instances as $e) {
            if ($activeOnly && (int) $e->active !== 1) {
                continue;
            }
            $rows[] = array(
                'id_employee' => $e->id,
                'firstname' => $e->firstname,
                'lastname' => $e->lastname,
            );
        }
        usort($rows, function ($a, $b) {
            return strcmp((string) $a['lastname'], (string) $b['lastname']);
        });
        if (static::$employeesHook !== null) {
            return call_user_func(static::$employeesHook, $activeOnly, $rows);
        }

        return $rows;
    }
}
