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

use PHPUnit\Framework\TestCase;

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPermission.php';

/**
 * IfirmaPermission: each level is granted by its own role of the tab, the SuperAdmin profile by
 * default, and nothing without an employee.
 */
class PermissionTest extends TestCase
{
    const SALESMAN = 5;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    /** @return Employee an employee of the given profile */
    private function employee($idProfile)
    {
        $employee = new Employee();
        $employee->id_profile = $idProfile;

        return $employee;
    }

    public function testTheRoleNamesAreTheTabRoles()
    {
        $this->assertSame('ROLE_MOD_TAB_ADMINIFIRMA_READ', IfirmaPermission::ROLE_READ);
        $this->assertSame('ROLE_MOD_TAB_ADMINIFIRMA_CREATE', IfirmaPermission::ROLE_CREATE);
        $this->assertSame('ROLE_MOD_TAB_ADMINIFIRMA_UPDATE', IfirmaPermission::ROLE_UPDATE);
    }

    public function testViewIsGrantedByTheReadRoleOnly()
    {
        Access::grant(IfirmaPermission::ROLE_READ, self::SALESMAN);
        $employee = $this->employee(self::SALESMAN);

        $this->assertTrue(IfirmaPermission::canView($employee));
        $this->assertFalse(IfirmaPermission::canIssue($employee));
        $this->assertFalse(IfirmaPermission::canManage($employee));
    }

    public function testIssueIsGrantedByTheCreateRoleOnly()
    {
        Access::grant(IfirmaPermission::ROLE_CREATE, self::SALESMAN);
        $employee = $this->employee(self::SALESMAN);

        $this->assertFalse(IfirmaPermission::canView($employee));
        $this->assertTrue(IfirmaPermission::canIssue($employee));
        $this->assertFalse(IfirmaPermission::canManage($employee));
    }

    public function testManageIsGrantedByTheUpdateRoleOnly()
    {
        Access::grant(IfirmaPermission::ROLE_UPDATE, self::SALESMAN);
        $employee = $this->employee(self::SALESMAN);

        $this->assertFalse(IfirmaPermission::canView($employee));
        $this->assertFalse(IfirmaPermission::canIssue($employee));
        $this->assertTrue(IfirmaPermission::canManage($employee));
    }

    public function testSuperAdminIsGrantedAllThreeWithoutAnyRow()
    {
        $employee = $this->employee(1);

        $this->assertTrue(IfirmaPermission::canView($employee));
        $this->assertTrue(IfirmaPermission::canIssue($employee));
        $this->assertTrue(IfirmaPermission::canManage($employee));
    }

    public function testADeleteRoleGivesNothing()
    {
        Access::grant('ROLE_MOD_TAB_ADMINIFIRMA_DELETE', self::SALESMAN);
        $employee = $this->employee(self::SALESMAN);

        $this->assertFalse(IfirmaPermission::canView($employee));
        $this->assertFalse(IfirmaPermission::canIssue($employee));
        $this->assertFalse(IfirmaPermission::canManage($employee));
    }

    public function testNoEmployeeIsGrantedNothing()
    {
        Access::grant(IfirmaPermission::ROLE_READ, 1);

        $this->assertFalse(IfirmaPermission::canView(null));
        $this->assertFalse(IfirmaPermission::canIssue(null));
        $this->assertFalse(IfirmaPermission::canManage(null));
    }

    public function testAnEmployeeWithoutAProfileIsGrantedNothing()
    {
        $employee = new stdClass();
        $this->assertFalse(IfirmaPermission::canView($employee));

        $employee->id_profile = null;
        $this->assertFalse(IfirmaPermission::canIssue($employee));

        $employee->id_profile = 0;
        $this->assertFalse(IfirmaPermission::canManage($employee));
    }
}
