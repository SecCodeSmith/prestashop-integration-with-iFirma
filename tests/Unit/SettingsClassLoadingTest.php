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
 * IfirmaSettings (refactor-srp-splits 7.2) is loaded by ifirma.php, and its file loads the classes it names
 * (IfirmaApiClient, IfirmaDeliveryService, IfirmaDocumentSettings, IfirmaKsefService and
 * IfirmaPaymentMethodResolver) with require_once lines of its own. In production nothing else loads it, so a
 * lost line is a fatal "class not found" the first time the settings page is saved.
 *
 * No other test can see that. IfirmaSettingsTest and the golden test load the module in a process that has
 * already loaded most of the module, so a class missing from one require list is supplied by another. So:
 * - the file is read: it must name only classes whose files it requires itself (comments and strings left out);
 * - ifirma.php is loaded in a PHP process of its own after tests/bootstrap.php, which loads neither the settings
 *   class nor the module file, and the class must be there afterwards (same method as AdminPanelClassLoadingTest);
 * - the file is loaded alone in a PHP process that has the PrestaShop stubs and nothing of the module, and must
 *   bring in the classes it needs (same method as AdminActionClassLoadingTest).
 * Keep it so: this file must not require the class, directly or through anything else it uses.
 */
class SettingsClassLoadingTest extends TestCase
{
    /** @var string[] the classes IfirmaSettings.php must bring in besides itself */
    private static $needed = array(
        'IfirmaApiClient', 'IfirmaDeliveryService', 'IfirmaDocumentSettings', 'IfirmaKsefService', 'IfirmaPaymentMethodResolver',
    );

    private static function classFile()
    {
        return dirname(__DIR__, 2) . '/classes/IfirmaSettings.php';
    }

    /**
     * @param string $script PHP source, run with the PHP of this process; it must print one line of JSON
     * @return array the decoded JSON
     */
    private function inFreshProcess($script)
    {
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
     * @return string[] class name => name of the file under classes/ that declares it (without .php)
     */
    private static function declarations()
    {
        $declared = array();
        foreach (glob(dirname(__DIR__, 2) . '/classes/*.php') as $file) {
            if (preg_match_all('/^(?:abstract |final )?(?:class|interface|trait) (\w+)/m', file_get_contents($file), $found)) {
                foreach ($found[1] as $name) {
                    $declared[$name] = basename($file, '.php');
                }
            }
        }

        return $declared;
    }

    public function testTheFileRequiresTheFileOfEveryModuleClassItNames()
    {
        // A Windows checkout (core.autocrlf) has CRLF line endings; the pattern below anchors on LF.
        $source = str_replace("\r\n", "\n", file_get_contents(self::classFile()));
        preg_match_all("/^require_once dirname\\(__FILE__\\) \\. '\\/(\\w+)\\.php';\$/m", $source, $required);
        $declared = self::declarations();

        $needed = array();
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_STRING && isset($declared[$token[1]]) && $token[1] !== 'IfirmaSettings') {
                $needed[$declared[$token[1]]] = true;
            }
        }

        $named = array_keys($needed);
        sort($named);

        $this->assertSame(self::$needed, $named, 'the classes the file names: the scan, and this test, must agree');
        $this->assertSame(array(), array_values(array_diff($named, $required[1])), 'it names classes whose files it does not require_once');
    }

    public function testTheModuleFileLoadsTheClass()
    {
        $script = '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . ' $before = class_exists("IfirmaSettings", false);'
            . ' IfirmaTestModuleLoader::loadModule();'
            . ' echo json_encode(array($before, class_exists("IfirmaSettings", false)));';

        list($before, $after) = $this->inFreshProcess($script);

        $this->assertFalse($before, 'IfirmaSettings was loaded before the module: this test can no longer see a lost require_once');
        $this->assertTrue($after, 'ifirma.php does not load IfirmaSettings: its require_once line is missing');
    }

    public function testTheFileLoadsOnItsOwnAndBringsInWhatItNeeds()
    {
        $tests = dirname(__DIR__);
        $names = array_merge(array('IfirmaSettings'), self::$needed);
        $script = '<?php'
            . ' define("_PS_VERSION_", "8.1.0"); define("_DB_PREFIX_", "ps_"); define("_MYSQL_ENGINE_", "InnoDB");'
            . ' define("_PS_MODULE_DIR_", ' . var_export(_PS_MODULE_DIR_, true) . ');'
            . ' define("_PS_ROOT_DIR_", ' . var_export(_PS_ROOT_DIR_, true) . ');'
            . ' define("_PS_MAIL_DIR_", ' . var_export(_PS_MAIL_DIR_, true) . ');'
            . ' require_once ' . var_export($tests . '/vendor/autoload.php', true) . ';'
            . ' foreach (glob(' . var_export($tests . '/Support/*.php', true) . ') as $f) { require_once $f; }'
            . ' require_once ' . var_export($tests . '/Stubs/functions.php', true) . ';'
            . ' foreach (glob(' . var_export($tests . '/Stubs/*.php', true) . ') as $f) { require_once $f; }'
            . ' $names = ' . var_export($names, true) . ';'
            . ' $before = array(); foreach ($names as $n) { $before[$n] = class_exists($n, false); }'
            . ' require_once ' . var_export(self::classFile(), true) . ';'
            . ' $after = array(); foreach ($names as $n) { $after[$n] = class_exists($n, false); }'
            . ' echo json_encode(array($before, $after));';

        list($before, $after) = $this->inFreshProcess($script);

        $this->assertSame(array_fill_keys($names, false), $before, 'a class was loaded before the file under test');
        $this->assertSame(array_fill_keys($names, true), $after, 'IfirmaSettings.php does not bring in every class it needs');
    }
}
