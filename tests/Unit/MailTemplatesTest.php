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
 * module-mail-branding: every e-mail template of the module carries the shop logo, the shop name and the
 * shop link of the merchant (the module's own placeholder, because Mail::Send() overwrites {shop_url}).
 */
class MailTemplatesTest extends TestCase
{
    /** @return string absolute path of the mails folder, with a trailing slash */
    private static function mailsDir()
    {
        return dirname(__DIR__, 2) . '/mails/';
    }

    /** @return string[] file names of one language folder, without index.php */
    private static function filesOf($iso)
    {
        $files = array();
        foreach (scandir(self::mailsDir() . $iso) as $file) {
            if ($file !== '.' && $file !== '..' && $file !== 'index.php') {
                $files[] = $file;
            }
        }
        sort($files);

        return $files;
    }

    public function htmlTemplates()
    {
        $sets = array();
        foreach (array('en', 'pl') as $iso) {
            foreach (self::filesOf($iso) as $file) {
                if (substr($file, -5) === '.html') {
                    $sets[$iso . '/' . $file] = array($iso . '/' . $file);
                }
            }
        }

        return $sets;
    }

    public function textTemplates()
    {
        $sets = array();
        foreach (array('en', 'pl') as $iso) {
            foreach (self::filesOf($iso) as $file) {
                if (substr($file, -4) === '.txt') {
                    $sets[$iso . '/' . $file] = array($iso . '/' . $file);
                }
            }
        }

        return $sets;
    }

    /**
     * @dataProvider htmlTemplates
     */
    public function testEveryHtmlTemplateShowsTheLogoTheNameAndALinkToTheShop($path)
    {
        $html = file_get_contents(self::mailsDir() . $path);

        $this->assertStringContainsString('{shop_logo}', $html);
        $this->assertStringContainsString('{shop_name}', $html);
        $this->assertMatchesRegularExpression('/href="\{ifirma_shop_link\}"/', $html);
        $this->assertStringNotContainsString('{shop_url}', $html, 'Mail::Send() overwrites {shop_url}');
    }

    /**
     * @dataProvider textTemplates
     */
    public function testEveryTextTemplateNamesTheShopAndEndsWithItsLinkWithoutAnImage($path)
    {
        $text = file_get_contents(self::mailsDir() . $path);
        $lines = preg_split('/\r\n|\n|\r/', rtrim($text));

        $this->assertSame('{shop_name}', $lines[0]);
        $this->assertSame('{ifirma_shop_link}', end($lines));
        $this->assertStringNotContainsString('cid:', $text);
        $this->assertStringNotContainsString('<img', $text);
        $this->assertStringNotContainsString('{shop_logo}', $text);
    }

    public function testEnglishAndPolishShipTheSameFiles()
    {
        $this->assertSame(self::filesOf('en'), self::filesOf('pl'));
    }

    public function dataRequestReceivedFiles()
    {
        return array(
            'en html' => array('en/data_request_received.html'),
            'en txt' => array('en/data_request_received.txt'),
            'pl html' => array('pl/data_request_received.html'),
            'pl txt' => array('pl/data_request_received.txt'),
        );
    }

    /**
     * @dataProvider dataRequestReceivedFiles
     */
    public function testTheMailToTheMerchantHasALineForTheAutomaticAcceptance($path)
    {
        $this->assertStringContainsString('{auto_line}', file_get_contents(self::mailsDir() . $path));
    }
}
