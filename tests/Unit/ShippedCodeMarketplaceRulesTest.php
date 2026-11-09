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
 * Scenario: shipped PHP follows the marketplace code rules (spec marketplace-readiness).
 * Given the PHP files of the module package, when they are tokenised, then each has the
 * _PS_VERSION_ guard (translations and index.php aside) and none uses error suppression,
 * eval, base64_decode or debug output.
 */
class ShippedCodeMarketplaceRulesTest extends TestCase
{
    const SHIPPED_DIRECTORIES = array('classes', 'controllers', 'mails', 'sql', 'upgrade', 'views');
    const FORBIDDEN_FUNCTIONS = array('base64_decode', 'var_dump', 'print_r', 'var_export', 'die');

    public static function shippedPhpFiles()
    {
        $root = dirname(__DIR__, 2);
        $files = array($root . '/ifirma.php');
        foreach (self::SHIPPED_DIRECTORIES as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        $cases = array();
        foreach ($files as $file) {
            $cases[substr($file, strlen($root) + 1)] = array($file);
        }

        return $cases;
    }

    /**
     * @dataProvider shippedPhpFiles
     */
    public function testShippedFileHasThePrestashopGuard($file)
    {
        if (basename($file) === 'index.php' || basename(dirname($file)) === 'translations') {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertMatchesRegularExpression('/defined\(\s*\'_PS_VERSION_\'\s*\)/', file_get_contents($file));
    }

    /**
     * @dataProvider shippedPhpFiles
     */
    public function testShippedFileSuppressesNoErrors($file)
    {
        $this->assertSame(array(), $this->tokenLines($file, function ($token) {
            return $token === '@';
        }));
    }

    /**
     * @dataProvider shippedPhpFiles
     */
    public function testShippedFileHasNoEvalOrDebugOutput($file)
    {
        $forbidden = self::FORBIDDEN_FUNCTIONS;

        $this->assertSame(array(), $this->tokenLines($file, function ($token) use ($forbidden) {
            if (is_array($token) && $token[0] === T_EVAL) {
                return true;
            }

            return is_array($token) && in_array($token[0], array(T_STRING, T_EXIT), true)
                && in_array(strtolower($token[1]), $forbidden, true);
        }));
    }

    /**
     * @param string $file
     * @return int[] line numbers of the tokens the predicate accepts
     */
    private function tokenLines($file, callable $matches)
    {
        $lines = array();
        $line = 1;
        foreach (token_get_all(file_get_contents($file)) as $token) {
            if ($matches($token)) {
                $lines[] = is_array($token) ? $token[2] : $line;
            }
            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }

        return $lines;
    }
}
