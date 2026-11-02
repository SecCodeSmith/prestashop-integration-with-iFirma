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
 * Every translatable string of the module must exist in translations/pl.php.
 *
 * Scope: ifirma.php, every file in classes/, controllers/ (->l('...' with an optional file key as the second
 * argument, otherwise the lowercase class name), views/templates/ ({l s='...' mod='ifirma'}).
 * Keys follow PrestaShop: md5() of the string with every ' written as \'.
 */
class TranslationCoverageTest extends TestCase
{
    private static function root()
    {
        return dirname(__DIR__, 2) . '/';
    }

    private static function quoted()
    {
        $bs = chr(92);

        return "((?:[^'" . $bs . $bs . "]|" . $bs . $bs . ".)*)";
    }

    /** @return string[] module-relative paths */
    private static function files($dir, $ext)
    {
        $out = array();
        $base = self::root() . $dir;
        if (!is_dir($base)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && substr($file->getFilename(), -strlen($ext)) === $ext && $file->getFilename() !== 'index.php') {
                $out[] = $dir . '/' . ltrim(str_replace(chr(92), '/', substr($file->getPathname(), strlen($base))), '/');
            }
        }
        sort($out);

        return $out;
    }

    /** @return array file => fixed key (null: derive) */
    private static function sources()
    {
        $sources = array('ifirma.php' => 'ifirma');
        foreach (array_merge(self::files('classes', '.php'), self::files('controllers', '.php'), self::files('views/templates', '.tpl')) as $f) {
            $sources[$f] = null;
        }

        return $sources;
    }

    public function stringProvider()
    {
        $cases = array();
        foreach (self::sources() as $file => $fixed) {
            $content = file_get_contents(self::root() . $file);
            if (substr($file, -4) === '.tpl') {
                preg_match_all('/\{l s=\'' . self::quoted() . '\'\s+mod=\'ifirma\'(?:\s+js=1)?\}/', $content, $m, PREG_SET_ORDER);
                $key = basename($file, '.tpl');
                $hits = array();
                foreach ($m as $hit) {
                    $hits[] = array($key, $hit[1]);
                }
            } else {
                preg_match_all('/->l\(\s*\'' . self::quoted() . '\'(?:\s*,\s*\'([A-Za-z0-9_]+)\')?/', $content, $m, PREG_SET_ORDER);
                $hits = array();
                foreach ($m as $hit) {
                    $key = $fixed !== null ? $fixed : (!empty($hit[2]) ? $hit[2] : strtolower(basename($file, '.php')));
                    $hits[] = array($key, $hit[1]);
                }
            }
            foreach ($hits as $hit) {
                $string = stripslashes($hit[1]);
                $cases[$file . ' :: ' . $string] = array($hit[0], $string);
            }
        }

        return $cases;
    }

    private static function loadTranslations()
    {
        global $_MODULE;
        $_MODULE = array();
        include self::root() . 'translations/pl.php';

        return $_MODULE;
    }

    public function testSourcesProduceStrings()
    {
        $this->assertGreaterThan(200, count($this->stringProvider()));
    }

    /**
     * @dataProvider stringProvider
     */
    public function testStringIsTranslated($fileKey, $string)
    {
        $translations = self::loadTranslations();
        $key = '<{ifirma}prestashop>' . $fileKey . '_' . md5(str_replace("'", "\'", $string));
        $this->assertArrayHasKey($key, $translations, 'Missing pl translation for: ' . $string);
        $this->assertNotSame('', trim($translations[$key]));
    }
}
