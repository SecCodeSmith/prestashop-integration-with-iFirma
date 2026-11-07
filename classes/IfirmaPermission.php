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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The three permission levels of the module, read from the roles of its own admin tab
 * (ROLE_MOD_TAB_ADMINIFIRMA_READ, _CREATE and _UPDATE) for the profile of an employee.
 *
 * The SuperAdmin profile is always granted all three. An employee who is missing, or whose profile
 * is missing, is granted nothing. The role _DELETE has no effect: the module never deletes a
 * document, so no level reads it.
 */
class IfirmaPermission
{
    const ROLE_READ = 'ROLE_MOD_TAB_ADMINIFIRMA_READ';
    const ROLE_CREATE = 'ROLE_MOD_TAB_ADMINIFIRMA_CREATE';
    const ROLE_UPDATE = 'ROLE_MOD_TAB_ADMINIFIRMA_UPDATE';

    /**
     * @param object|null $employee
     * @return bool the employee may see the module's documents (the view level)
     */
    public static function canView($employee)
    {
        return self::granted($employee, self::ROLE_READ);
    }

    /**
     * @param object|null $employee
     * @return bool the employee may issue documents (the issue level)
     */
    public static function canIssue($employee)
    {
        return self::granted($employee, self::ROLE_CREATE);
    }

    /**
     * @param object|null $employee
     * @return bool the employee may change or resend documents (the manage level)
     */
    public static function canManage($employee)
    {
        return self::granted($employee, self::ROLE_UPDATE);
    }

    /**
     * @param object|null $employee
     * @param string      $role
     * @return bool
     */
    private static function granted($employee, $role)
    {
        if (!is_object($employee) || !isset($employee->id_profile)) {
            return false;
        }
        $idProfile = (int) $employee->id_profile;
        if ($idProfile <= 0) {
            return false;
        }
        if ($idProfile === self::superAdminProfile()) {
            return true;
        }

        return (bool) Access::isGranted($role, $idProfile);
    }

    /** @return int the SuperAdmin profile id */
    private static function superAdminProfile()
    {
        return defined('_PS_ADMIN_PROFILE_') ? (int) _PS_ADMIN_PROFILE_ : 1;
    }
}
