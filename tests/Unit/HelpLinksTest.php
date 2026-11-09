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
 * Scenario: an administrator opens the module configuration and finds a documentation and a
 * support link (PrestaShop Addons technical validation, spec marketplace-readiness).
 */
class HelpLinksTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    public function testRenderShowsBothLinksWithTheirLabels()
    {
        $html = (new IfirmaHelpLinks('https://docs.example.test/', 'https://support.example.test/'))
            ->render('Documentation', 'Support');

        $this->assertStringContainsString('<a href="https://docs.example.test/"', $html);
        $this->assertStringContainsString('>Documentation</a>', $html);
        $this->assertStringContainsString('<a href="https://support.example.test/"', $html);
        $this->assertStringContainsString('>Support</a>', $html);
    }

    public function testLinksOpenInANewTabWithoutLeakingTheOpener()
    {
        $html = (new IfirmaHelpLinks())->render('Documentation', 'Support');

        $this->assertSame(2, substr_count($html, 'target="_blank" rel="noopener noreferrer"'));
    }

    public function testLabelsAndUrlsAreEscaped()
    {
        $html = (new IfirmaHelpLinks('https://x.test/?a=1&b="2"', 'https://y.test/'))
            ->render('<script>alert(1)</script>', 'Support');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('a=1&amp;b=&quot;2&quot;', $html);
    }

    public function testDefaultLinksPointAtTheProjectDocumentationAndSupportPages()
    {
        $html = (new IfirmaHelpLinks())->render('Documentation', 'Support');

        $this->assertStringContainsString(IfirmaHelpLinks::DOCUMENTATION_URL, $html);
        $this->assertStringContainsString(IfirmaHelpLinks::SUPPORT_URL, $html);
        $this->assertStringStartsWith('https://', IfirmaHelpLinks::DOCUMENTATION_URL);
        $this->assertStringStartsWith('https://', IfirmaHelpLinks::SUPPORT_URL);
    }

    public function testTheConfigurationPageShowsTheLinks()
    {
        $module = IfirmaTestModuleLoader::loadModule();

        $page = $module->getContent();

        $this->assertStringContainsString(IfirmaHelpLinks::DOCUMENTATION_URL, $page);
        $this->assertStringContainsString(IfirmaHelpLinks::SUPPORT_URL, $page);
    }
}
