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
 * The files of the admin action classes (the facade IfirmaAdminActions, the three action classes and their base
 * class IfirmaActionSupport) load what they use with require_once lines of their own.
 *
 * No other test can see a lost line. The bootstrap loads IfirmaAdminActions.php, so by the time a test runs every
 * class of the chain is there, and a class that is loaded early supplies its dependencies to the ones after it.
 * So the tests do not look at what is loaded; they look at the files:
 * - every file names only classes whose files it requires itself (read from the code, comments and strings
 *   left out, so a lost line fails here whatever else loads the class);
 * - every file, loaded alone in a PHP process of its own that has the PrestaShop stubs and nothing of the module,
 *   declares its class and brings in the classes listed for it below. The child repeats the stub part of
 *   tests/bootstrap.php and none of its module requires.
 *
 * The second test also covers a class no code names. IfirmaCustomerActions only reaches IfirmaMatchLabels through
 * the module (`$this->module->matchLabels()`), but IfirmaAdminActions.php has always brought the class in, and
 * the tests that use it without loading the module rely on that.
 *
 * Keep it so: this file must not require the action classes, directly or through anything else it uses.
 */
class AdminActionClassLoadingTest extends TestCase
{
    /**
     * @return array[] class => array(class)
     */
    public function actionFiles()
    {
        $cases = array();
        foreach (array('IfirmaActionSupport', 'IfirmaReceiptActions', 'IfirmaCustomerActions', 'IfirmaDataRequestActions', 'IfirmaAdminActions') as $class) {
            $cases[$class] = array($class);
        }

        return $cases;
    }

    /**
     * @return array[] class => array(class, classes its file must bring in besides itself)
     */
    public function classesEachFileBringsIn()
    {
        return array(
            'IfirmaActionSupport' => array('IfirmaActionSupport', array('IfirmaDataRequestException')),
            'IfirmaReceiptActions' => array('IfirmaReceiptActions', array(
                'IfirmaActionSupport', 'IfirmaReceipt', 'IfirmaReceiptService', 'IfirmaIssuancePipeline', 'IfirmaDuplicateGuard',
                'IfirmaDeliveryService', 'IfirmaKsefService', 'IfirmaStatusSyncService',
            )),
            'IfirmaCustomerActions' => array('IfirmaCustomerActions', array(
                'IfirmaActionSupport', 'IfirmaMatchLabels', 'IfirmaContractorService', 'IfirmaCustomerContractorService',
                'IfirmaReceiptService', 'IfirmaDataRequestService',
            )),
            'IfirmaDataRequestActions' => array('IfirmaDataRequestActions', array(
                'IfirmaActionSupport', 'IfirmaDataRequest', 'IfirmaIssuancePipeline', 'IfirmaDataRequestService',
            )),
            'IfirmaAdminActions' => array('IfirmaAdminActions', array(
                'IfirmaReceiptActions', 'IfirmaCustomerActions', 'IfirmaDataRequestActions', 'IfirmaActionSupport',
                'IfirmaReceipt', 'IfirmaCustomerContractorService',
            )),
        );
    }

    private static function classFile($class)
    {
        return dirname(__DIR__, 2) . '/classes/' . $class . '.php';
    }

    /**
     * @return string[] class name => name of the file under classes/ that declares it (without .php)
     */
    private static function declarations()
    {
        $declared = array();
        foreach (glob(self::classFile('*')) as $file) {
            if (preg_match_all('/^(?:abstract |final )?(?:class|interface|trait) (\w+)/m', file_get_contents($file), $found)) {
                foreach ($found[1] as $name) {
                    $declared[$name] = basename($file, '.php');
                }
            }
        }

        return $declared;
    }

    /**
     * @param string $source PHP source
     * @return string[] the names beginning with Ifirma that the code (not its comments or strings) mentions
     */
    private static function namesIn($source)
    {
        $names = array();
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_STRING && strpos($token[1], 'Ifirma') === 0) {
                $names[$token[1]] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @dataProvider actionFiles
     */
    public function testTheFileRequiresTheFileOfEveryModuleClassItNames($class)
    {
        // A Windows checkout (core.autocrlf) has CRLF line endings; the pattern below anchors on LF.
        $source = str_replace("\r\n", "\n", file_get_contents(self::classFile($class)));
        preg_match_all("/^require_once dirname\\(__FILE__\\) \\. '\\/(\\w+)\\.php';\$/m", $source, $required);
        $declared = self::declarations();

        $needed = array();
        foreach (self::namesIn($source) as $name) {
            if ($name !== $class && isset($declared[$name])) {
                $needed[$declared[$name]] = true;
            }
        }

        $this->assertNotEmpty($needed, $class . '.php names no other module class: the scan found nothing to check');
        $this->assertSame(
            array(),
            array_values(array_diff(array_keys($needed), $required[1])),
            $class . '.php names classes whose files it does not require_once'
        );
    }

    /**
     * @dataProvider classesEachFileBringsIn
     * @param string   $class
     * @param string[] $brings
     */
    public function testTheFileLoadsOnItsOwnAndBringsInWhatItNeeds($class, array $brings)
    {
        $tests = dirname(__DIR__);
        $script = '<?php'
            . ' define("_PS_VERSION_", "8.1.0"); define("_DB_PREFIX_", "ps_"); define("_MYSQL_ENGINE_", "InnoDB");'
            . ' define("_PS_MODULE_DIR_", ' . var_export(_PS_MODULE_DIR_, true) . ');'
            . ' define("_PS_ROOT_DIR_", ' . var_export(_PS_ROOT_DIR_, true) . ');'
            . ' define("_PS_MAIL_DIR_", ' . var_export(_PS_MAIL_DIR_, true) . ');'
            . ' require_once ' . var_export($tests . '/vendor/autoload.php', true) . ';'
            . ' foreach (glob(' . var_export($tests . '/Support/*.php', true) . ') as $f) { require_once $f; }'
            . ' require_once ' . var_export($tests . '/Stubs/functions.php', true) . ';'
            . ' foreach (glob(' . var_export($tests . '/Stubs/*.php', true) . ') as $f) { require_once $f; }'
            . ' $names = ' . var_export(array_merge(array($class), $brings), true) . ';'
            . ' $before = array(); foreach ($names as $n) { $before[$n] = class_exists($n, false); }'
            . ' require_once ' . var_export(self::classFile($class), true) . ';'
            . ' $after = array(); foreach ($names as $n) { $after[$n] = class_exists($n, false); }'
            . ' echo json_encode(array($before, $after));';

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

        $this->assertSame(0, $status, $class . '.php does not load on its own: ' . $stderr . $stdout);
        list($before, $after) = json_decode($stdout, true);
        $names = array_merge(array($class), $brings);
        $this->assertSame(array_fill_keys($names, false), $before, 'a class was loaded before the file under test: ' . $stdout);
        $this->assertSame(array_fill_keys($names, true), $after, $class . '.php does not bring in every class it needs: ' . $stdout);
    }
}
