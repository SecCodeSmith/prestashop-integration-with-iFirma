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
 * So each test starts a PHP process of its own (PHP_BINARY, the script on its standard input).
 * The child starts from tests/bootstrap.php, which loads neither helper, loads ONE controller file
 * through IfirmaTestModuleLoader and reports whether the helper class was there before and after.
 * Keep it so: this file must not require the helpers, directly or through anything else it uses,
 * and a test must not load a second controller.
 *
 * PHPUnit's own isolation (@runInSeparateProcess with @preserveGlobalState disabled) does the same
 * but starts a whole PHPUnit child per test, and under pcov each child collects coverage again.
 */
class ControllerClassLoadingTest extends TestCase
{
    /**
     * @param string $load PHP statement that loads the controller, run after the bootstrap
     * @param string $class the helper class the controller must load
     * @return bool[] array(whether $class existed before $load, whether it exists after)
     */
    private function inFreshProcess($load, $class)
    {
        $exists = 'class_exists(' . var_export($class, true) . ', false)';
        $script = '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . ' $before = ' . $exists . ';'
            . ' ' . $load
            . ' echo json_encode(array($before, ' . $exists . '));';

        $process = proc_open(
            escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d error_reporting=-1',
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        $this->assertIsResource($process, 'cannot start ' . PHP_BINARY);
        fwrite($pipes[0], $script);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, 'the child process failed: ' . $stderr . $stdout);
        $state = json_decode($stdout, true);
        $this->assertIsArray($state, 'the child process said: ' . $stdout . $stderr);

        return $state;
    }

    /**
     * Fails when the helper is already loaded: the bootstrap (or a support file) loads it, and the
     * test could no longer see a controller that forgets to.
     */
    private function assertNotLoadedYet($class, $before)
    {
        $this->assertFalse($before, $class . ' was loaded before the controller: this test can no longer see a lost require_once');
    }

    private function assertLoadedBy($file, $class, $after)
    {
        $this->assertTrue($after, $file . ' does not load ' . $class . ': its require_once is missing');
    }

    public function testTheFrontReceiptControllerLoadsIfirmaHttpOutput()
    {
        list($before, $after) = $this->inFreshProcess("IfirmaTestModuleLoader::loadFrontController('receipt');", 'IfirmaHttpOutput');

        $this->assertNotLoadedYet('IfirmaHttpOutput', $before);
        $this->assertLoadedBy('controllers/front/receipt.php', 'IfirmaHttpOutput', $after);
    }

    public function testTheFrontCronControllerLoadsIfirmaHttpStatus()
    {
        list($before, $after) = $this->inFreshProcess("IfirmaTestModuleLoader::loadFrontController('cron');", 'IfirmaHttpStatus');

        $this->assertNotLoadedYet('IfirmaHttpStatus', $before);
        $this->assertLoadedBy('controllers/front/cron.php', 'IfirmaHttpStatus', $after);
    }

    public function testTheAdminControllerLoadsIfirmaHttpOutput()
    {
        list($before, $after) = $this->inFreshProcess('IfirmaTestModuleLoader::loadAdminController();', 'IfirmaHttpOutput');

        $this->assertNotLoadedYet('IfirmaHttpOutput', $before);
        $this->assertLoadedBy('controllers/admin/AdminIfirmaController.php', 'IfirmaHttpOutput', $after);
    }
}
