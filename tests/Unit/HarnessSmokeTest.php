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

class HarnessSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    public function testReceiptSaveAndLoad()
    {
        $receipt = new IfirmaReceipt();
        $receipt->id_order = 5;
        $this->assertTrue($receipt->save());
        $this->assertSame(1, $receipt->id);

        $loaded = new IfirmaReceipt(1);
        $this->assertSame(5, $loaded->id_order);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testGetByOrderUsesScriptedDb()
    {
        $receipt = new IfirmaReceipt();
        $receipt->id_order = 5;
        $receipt->save();

        Db::getInstance()->when('/SELECT id_ifirma_receipt FROM/', 1);
        $found = IfirmaReceipt::getByOrder(5);

        $this->assertInstanceOf(IfirmaReceipt::class, $found);
        $this->assertSame(5, $found->id_order);
        $this->assertNull((function () {
            Db::getInstance()->when('/SELECT id_ifirma_receipt FROM/', false);

            return IfirmaReceipt::getByOrder(99);
        })());
    }

    public function testConfigurationRoundTrip()
    {
        $this->assertFalse(Configuration::get('IFIRMA_X'));
        Configuration::updateValue('IFIRMA_X', 'v');
        $this->assertSame('v', Configuration::get('IFIRMA_X'));
        Configuration::deleteByName('IFIRMA_X');
        $this->assertFalse(Configuration::hasKey('IFIRMA_X'));
    }

    public function testMailSendIsRecorded()
    {
        $this->assertTrue(Mail::Send(1, 'tpl', 'Subject', ['{a}' => 'b'], 'a@b.test'));
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('a@b.test', Mail::$sent[0]['to']);
        $this->assertSame('Subject', Mail::$sent[0]['subject']);

        Mail::$result = false;
        $this->assertFalse(Mail::Send(1, 'tpl', 'S', [], 'c@d.test'));
    }

    public function testModuleAndControllersLoad()
    {
        $module = IfirmaTestModuleLoader::loadModule();
        $this->assertSame('ifirma', $module->name);
        $this->assertFalse($module->isConfigured());
        $this->assertInstanceOf('AdminIfirmaController', IfirmaTestModuleLoader::loadAdminController());
        $this->assertInstanceOf('ModuleFrontController', IfirmaTestModuleLoader::loadFrontController('receipt'));
    }
}
