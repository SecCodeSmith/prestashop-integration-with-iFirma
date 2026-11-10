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
 * A shipped file that uses IfirmaPayloadBuilder, IfirmaDocumentSettings or IfirmaPaymentMethodResolver
 * requires the file of that class itself (refactor-srp-splits 5.3, 5.4). The resolver is here because the
 * payload builder took over the one use the receipt service had of it.
 *
 * No test run can see a lost require_once line: tests/bootstrap.php loads most class files before the
 * first test, and a class loaded by one file is there for every file loaded after it. So this test reads
 * the sources. A use is a mention of the class name in code (comments and strings do not count).
 */
class ClassFileRequiresTest extends TestCase
{
    private static function root()
    {
        return dirname(__DIR__, 2) . '/';
    }

    /** @return string[] module-relative paths of the PHP files the module ships and runs */
    private static function shippedFiles()
    {
        $files = array('ifirma.php');
        foreach (array('classes', 'controllers') as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && substr($file->getFilename(), -4) === '.php' && $file->getFilename() !== 'index.php') {
                    $files[] = $dir . str_replace(chr(92), '/', substr($file->getPathname(), strlen(self::root() . $dir)));
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param string $file module-relative path
     * @return array array(the code of the file without its comments, the names it mentions in code)
     */
    private static function codeOf($file)
    {
        $code = '';
        $names = array();
        foreach (token_get_all(file_get_contents(self::root() . $file)) as $token) {
            if (!is_array($token)) {
                $code .= $token;
                continue;
            }
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            if ($token[0] === T_STRING) {
                $names[$token[1]] = true;
            }
        }

        return array($code, $names);
    }

    public function classesWithAFileOfTheirOwn()
    {
        return array(
            'the payload builder' => array('IfirmaPayloadBuilder'),
            'the document settings' => array('IfirmaDocumentSettings'),
            'the payment method resolver' => array('IfirmaPaymentMethodResolver'),
            'the permission helper' => array('IfirmaPermission'),
            'the bulk runner' => array('IfirmaBulkRunner'),
            'the issue feed' => array('IfirmaIssueFeed'),
            'the request input reader' => array('IfirmaInput'),
            'the order grid indicator' => array('IfirmaOrderGridIndicator'),
            'the shop link' => array('IfirmaShopLink'),
            'the data request auto-accept criteria' => array('IfirmaDataRequestAutoAccept'),
            'the guest code service' => array('IfirmaGuestCodeService'),
            'the request kind' => array('IfirmaRequestKind'),
        );
    }

    /**
     * @dataProvider classesWithAFileOfTheirOwn
     */
    public function testEveryFileThatUsesTheClassRequiresIt($class)
    {
        $this->assertFileExists(self::root() . 'classes/' . $class . '.php');

        $users = array();
        $missing = array();
        foreach (self::shippedFiles() as $file) {
            if ($file === 'classes/' . $class . '.php') {
                continue;
            }
            list($code, $names) = self::codeOf($file);
            if (!isset($names[$class])) {
                continue;
            }
            $users[] = $file;
            if (!preg_match('~require_once\s[^;]*/' . $class . '\.php[\'"]\s*;~', $code)) {
                $missing[] = $file;
            }
        }

        $this->assertNotEmpty($users, 'no file uses ' . $class . ': the test sees nothing');
        $this->assertSame(array(), $missing, 'these files use ' . $class . ' but do not require_once classes/' . $class . '.php');
    }
}
