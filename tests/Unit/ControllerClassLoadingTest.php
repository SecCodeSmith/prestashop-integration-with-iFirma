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

/**
 * The controllers load their HTTP helper class with a require_once of their own: in production
 * nothing else loads IfirmaHttpOutput (front receipt controller, AdminIfirmaController) or
 * IfirmaHttpStatus (front cron controller) before such a controller runs, so a lost line is a
 * fatal "class not found" on the first request that reaches the helper.
 *
 * No other test can see that. HttpOutputTest and HttpStatusTest require the helpers themselves
 * (PHPUnit includes every test file before the first test runs), and a controller file loaded
 * early in a process supplies the helper to the controllers loaded after it.
 *
 * So each test here runs in a process of its own, with the global state not preserved: the
 * child would otherwise include the parent's files again, the two helper tests among them. The
 * child starts from tests/bootstrap.php, which loads neither helper, loads ONE controller file
 * and checks that the helper is there. Keep it so: this file must not require the helpers,
 * directly or through anything else it uses, and a test must not load a second controller.
 */
class ControllerClassLoadingTest extends TestCase
{
    /**
     * Fails when the helper is already loaded: the test is then not isolated (or a support file
     * loads the helper), and it could no longer see a controller that forgets to.
     */
    private function assertNotLoadedYet($class)
    {
        $this->assertFalse(
            class_exists($class, false),
            $class . ' was loaded before the controller: this test can no longer see a lost require_once'
        );
    }

    private function assertLoadedBy($file, $class)
    {
        $this->assertTrue(
            class_exists($class, false),
            $file . ' does not load ' . $class . ': its require_once is missing'
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheFrontReceiptControllerLoadsIfirmaHttpOutput()
    {
        $this->assertNotLoadedYet('IfirmaHttpOutput');

        IfirmaTestModuleLoader::loadFrontController('receipt');

        $this->assertLoadedBy('controllers/front/receipt.php', 'IfirmaHttpOutput');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheFrontCronControllerLoadsIfirmaHttpStatus()
    {
        $this->assertNotLoadedYet('IfirmaHttpStatus');

        IfirmaTestModuleLoader::loadFrontController('cron');

        $this->assertLoadedBy('controllers/front/cron.php', 'IfirmaHttpStatus');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheAdminControllerLoadsIfirmaHttpOutput()
    {
        $this->assertNotLoadedYet('IfirmaHttpOutput');

        IfirmaTestModuleLoader::loadAdminController();

        $this->assertLoadedBy('controllers/admin/AdminIfirmaController.php', 'IfirmaHttpOutput');
    }
}
