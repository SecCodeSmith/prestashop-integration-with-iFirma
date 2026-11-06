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
 * The admin order panel is split over two class files that nothing but the module loads:
 * IfirmaAdminOrderPanelView (required by ifirma.php) and IfirmaAdminPanelPresenter (required by the view
 * and by ifirma.php). In production nothing else loads them, so a lost require_once line is a fatal
 * "class not found" on the first back-office order page.
 *
 * No other test can see that. AdminPanelPresenterTest and the golden test load the module in a process
 * that has already loaded everything, so a class missing from one require list is supplied by another.
 * Same method as ControllerClassLoadingTest: each test starts a PHP process of its own (PHP_BINARY, the
 * script on its standard input), loads ONE entry point after tests/bootstrap.php, which loads neither
 * class, and reports whether the classes were there before and after.
 * Keep it so: this file must not require the classes, directly or through anything else it uses.
 */
class AdminPanelClassLoadingTest extends TestCase
{
    /**
     * @param string   $load    PHP statement that loads the code under test, run after the bootstrap
     * @param string[] $classes
     * @return array[] array(class => whether it existed before $load), array(class => whether it exists after)
     */
    private function inFreshProcess($load, array $classes)
    {
        $exists = 'array_combine(' . var_export($classes, true) . ', array_map(function ($c) { return class_exists($c, false); }, '
            . var_export($classes, true) . '))';
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
     * Fails when the bootstrap (or a support file) already loads a class: the test could no longer see an
     * entry point that forgets to.
     */
    private function assertNoneLoadedYet(array $before)
    {
        foreach ($before as $class => $loaded) {
            $this->assertFalse($loaded, $class . ' was loaded before the entry point: this test can no longer see a lost require_once');
        }
    }

    public function testTheModuleFileLoadsTheViewAndThePresenter()
    {
        $classes = array('IfirmaAdminOrderPanelView', 'IfirmaAdminPanelPresenter');

        list($before, $after) = $this->inFreshProcess('IfirmaTestModuleLoader::loadModule();', $classes);

        $this->assertNoneLoadedYet($before);
        $this->assertSame(array('IfirmaAdminOrderPanelView' => true, 'IfirmaAdminPanelPresenter' => true), $after, 'ifirma.php does not load them: its require_once lines are missing');
    }

    public function testTheViewFileLoadsThePresenter()
    {
        $classes = array('IfirmaAdminPanelPresenter');

        list($before, $after) = $this->inFreshProcess("require _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAdminOrderPanelView.php';", $classes);

        $this->assertNoneLoadedYet($before);
        $this->assertSame(array('IfirmaAdminPanelPresenter' => true), $after, 'classes/IfirmaAdminOrderPanelView.php does not load the presenter: its require_once is missing');
    }
}
