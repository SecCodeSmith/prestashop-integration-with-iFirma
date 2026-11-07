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
 * IfirmaDownloadThrottle: one document every 30 s, ten downloads per customer per minute.
 */
class ReceiptDownloadThrottleTest extends TestCase
{
    /** @var int */
    public $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = strtotime('2026-11-07 10:00:00');
    }

    private function throttle()
    {
        $self = $this;

        return new IfirmaDownloadThrottle(function () use ($self) {
            return $self->now;
        });
    }

    private function receipt()
    {
        $receipt = new IfirmaReceipt();
        $receipt->id = 41;
        $receipt->id_customer = 5;

        return $receipt;
    }

    public function testFirstDownloadAllowed()
    {
        $this->assertTrue($this->throttle()->allows($this->receipt()));
    }

    public function testRepeatedDownloadOfOneDocumentRefused()
    {
        $throttle = $this->throttle();
        $receipt = $this->receipt();
        $throttle->stamp($receipt);

        $this->now += 5;

        $this->assertFalse($throttle->allows($receipt));
    }

    public function testWindowOverAllowsAgain()
    {
        $throttle = $this->throttle();
        $receipt = $this->receipt();
        $throttle->stamp($receipt);

        $this->now += 31;

        $this->assertTrue($throttle->allows($receipt));
    }

    public function testLoopOverManyDocumentsRefusedAtTen()
    {
        Db::getInstance()->whenGetValue('/SELECT COUNT\(\*\) FROM `\w*ifirma_receipt`/', 10);
        $this->assertFalse($this->throttle()->allows($this->receipt()));
    }

    public function testNineRecentDownloadsStillAllowed()
    {
        Db::getInstance()->whenGetValue('/SELECT COUNT\(\*\) FROM `\w*ifirma_receipt`/', 9);
        $this->assertTrue($this->throttle()->allows($this->receipt()));
    }

    public function testCountQueryIsScopedToTheCustomerAndTheWindow()
    {
        $this->throttle()->allows($this->receipt());

        $sql = implode("\n", Db::getInstance()->queries);
        $this->assertStringContainsString('id_customer = 5', $sql);
        $this->assertStringContainsString("last_download_at >= '2026-11-07 09:59:00'", $sql);
    }

    public function testStampWritesTheIdAndTheTimestamp()
    {
        $receipt = $this->receipt();
        $this->throttle()->stamp($receipt);

        $sql = Db::getInstance()->executed[0];
        $this->assertStringContainsString("SET last_download_at = '2026-11-07 10:00:00'", $sql);
        $this->assertStringContainsString('WHERE id_ifirma_receipt = 41', $sql);
        $this->assertSame('2026-11-07 10:00:00', $receipt->last_download_at);
    }

    public function testArrayTokenIsReadAsEmptyByTheReceiptController()
    {
        Tools::$values = array('token' => array('a', 'b'));
        $controller = IfirmaTestModuleLoader::loadFrontController('receipt');

        $this->assertSame('', $controller->readToken());
        Tools::$values = array('token' => 'abc123');
        $this->assertSame('abc123', $controller->readToken());
        Tools::reset();
    }

    public function testArrayTokenAndOrderIdAreReadAsEmptyByTheDataRequestController()
    {
        Tools::$values = array('token' => array('x'), 'id_order' => array(7));
        $controller = IfirmaTestModuleLoader::loadFrontController('datarequest');

        $this->assertSame('', $controller->readToken());
        $this->assertSame(0, $controller->readOrderId());
        Tools::$values = array('token' => 'tok', 'id_order' => '15');
        $this->assertSame('tok', $controller->readToken());
        $this->assertSame(15, $controller->readOrderId());
        Tools::reset();
    }
}
