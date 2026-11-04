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
    use IfirmaTestRegistryTrait;

    public $id;
    public $firstname;
    public $lastname;
    public $email;
    public $active = 1;
    /** @var int profile id (1 = SuperAdmin in core); read by the customer panel permission check */
    public $id_profile = 1;

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

        return $rows;
    }
}
