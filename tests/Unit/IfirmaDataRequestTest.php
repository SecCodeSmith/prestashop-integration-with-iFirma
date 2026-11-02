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

class IfirmaDataRequestTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaDataRequest.php';
    }

    public function testSaveAndLoadRoundTrip()
    {
        $request = new IfirmaDataRequest();
        $request->id_order = 7;
        $request->id_customer = 3;
        $request->setRequestedData(array('company' => 'ACME', 'nip' => '1234567890'));
        $this->assertTrue($request->save());
        $this->assertSame(1, $request->id);

        $loaded = IfirmaDataRequest::getById(1);
        $this->assertInstanceOf(IfirmaDataRequest::class, $loaded);
        $this->assertSame(7, $loaded->id_order);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $loaded->status);
        $data = $loaded->getRequestedData();
        $this->assertSame('ACME', $data['company']);
        $this->assertNull(IfirmaDataRequest::getById(99));
        $this->assertNull(IfirmaDataRequest::getById(0));
    }

    public function testJsonAccessors()
    {
        $request = new IfirmaDataRequest();
        $this->assertSame(array(), $request->getRequestedData());
        $this->assertSame(array(), $request->getApprovedData());

        $request->setApprovedData(array('city' => "\u{141}\u{F3}d\u{17A}"));
        $this->assertSame(array('city' => "\u{141}\u{F3}d\u{17A}"), $request->getApprovedData());

        $request->requested_data = 'not json';
        $this->assertSame(array(), $request->getRequestedData());
    }

    public function testFindersUseScriptedDb()
    {
        $approved = new IfirmaDataRequest();
        $approved->id_order = 7;
        $approved->status = IfirmaDataRequest::STATUS_APPROVED;
        $approved->save();

        $db = Db::getInstance();
        $db->whenGetValue('/COUNT\(\*\)/', '2');
        $db->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', 0);
        $db->whenGetValue('/SELECT id_ifirma_data_request.*status = "approved"/s', 1);
        $this->assertNull(IfirmaDataRequest::getPendingByOrder(7));
        $this->assertSame(1, IfirmaDataRequest::getApprovedByOrder(7)->id);

        $this->assertSame(2, IfirmaDataRequest::countPendingByCustomer(3));

        $rows = array(array('id_ifirma_data_request' => 2), array('id_ifirma_data_request' => 1));
        $db->whenExecuteS('/FROM `ps_ifirma_data_request`/', $rows);
        $this->assertSame($rows, IfirmaDataRequest::getByOrder(7));
        $queries = $db->queries;
        $this->assertStringContainsString('ORDER BY id_ifirma_data_request DESC', end($queries));
    }
}
