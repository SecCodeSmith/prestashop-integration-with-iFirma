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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaNativeInvoiceOutcome.php';

class NativeInvoiceOutcomeTest extends TestCase
{
    public function testPassThroughLeavesPrestaShopToRenderItsOwnInvoice()
    {
        $outcome = IfirmaNativeInvoiceOutcome::passThrough();

        $this->assertTrue($outcome->isPassThrough());
        $this->assertFalse($outcome->isRedirect());
        $this->assertFalse($outcome->isNotAvailable());
        $this->assertSame('', $outcome->url());
    }

    public function testRedirectCarriesTheDownloadUrl()
    {
        $outcome = IfirmaNativeInvoiceOutcome::redirectTo('https://shop.test/module/ifirma/receipt?token=abc');

        $this->assertTrue($outcome->isRedirect());
        $this->assertFalse($outcome->isPassThrough());
        $this->assertFalse($outcome->isNotAvailable());
        $this->assertSame('https://shop.test/module/ifirma/receipt?token=abc', $outcome->url());
    }

    public function testNotAvailableCarriesNoUrl()
    {
        $outcome = IfirmaNativeInvoiceOutcome::notAvailable();

        $this->assertTrue($outcome->isNotAvailable());
        $this->assertFalse($outcome->isPassThrough());
        $this->assertFalse($outcome->isRedirect());
        $this->assertSame('', $outcome->url());
    }

    public function testARedirectWithoutAUrlIsRefused()
    {
        $this->expectException(InvalidArgumentException::class);

        IfirmaNativeInvoiceOutcome::redirectTo('');
    }
}
