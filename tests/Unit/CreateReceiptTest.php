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

class CreateReceiptTest extends TestCase
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaReceiptService */
    private $service;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->service = new IfirmaReceiptService($client, new Module());

        $c = new Customer();
        $c->id = 5;
        $c->email = 'a@example.test';
        Customer::register($c);
        $a = new Address();
        $a->id = 7;
        $a->firstname = 'Jan';
        $a->lastname = 'K';
        $a->id_country = 14;
        Address::register($a);
    }

    private function order()
    {
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = date('Y-m-d H:i:s');
        $o->total_paid_tax_incl = 10;
        $o->details = array(array(
            'product_name' => 'W',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 10,
            'unit_price_tax_excl' => 10,
            'tax_rate' => 0,
        ));

        return $o;
    }

    public function testSuccessStampsReference()
    {
        $this->t->queue('POST', '#fakturaparagon\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77"}}'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Wynik' => array(array('FakturaId' => 77, 'PelnyNumer' => '7/2026')),
        )))));

        $this->service->createReceipt($this->order());

        $rows = IfirmaTestState::objects('ifirma_receipt');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $row['order_reference']);
        $this->assertSame('module', $row['source']);
        $this->assertSame('created', $row['status']);
        $this->assertSame('7/2026', $row['ifirma_number']);
        $this->assertSame('77', (string) $row['ifirma_id']);
        $this->assertStringContainsString('PS-ORDER-15-ABCDEFGHI', $this->t->calls[0]['body']);
        $this->assertStringContainsString('NUMER_ZAMOWIENIA', $this->t->calls[0]['body']);
    }

    public function testSkipOptionReachesRequest()
    {
        $this->t->queue('POST', '#fakturaparagon\.json#', array('body' => '{"response":{"Kod":0,"Identyfikator":"1","PelnyNumer":"1/2026"}}'));
        $this->service->createReceipt($this->order(), array('skip_dodatkowe_uwagi' => true));
        $this->assertStringNotContainsString('DodatkoweUwagi', $this->t->calls[0]['body']);
        $this->assertStringContainsString('"Uwagi":"PS-ORDER-15-ABCDEFGHI"', $this->t->calls[0]['body']);
    }

    public function testRejectionSetsErrorStatus()
    {
        $this->t->queue('POST', '#fakturaparagon\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"bad"}}'));
        try {
            $this->service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('bad', $e->getMessage());
        }
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertSame('error', $row['status']);
    }

    public function testExistingIssuedRecordBlocksWithoutTransportCall()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->status = IfirmaReceipt::STATUS_CREATED;
        $r->ifirma_id = '5';
        $r->save();
        Db::getInstance()->whenGetValue('/ifirma_receipt/', (int) $r->id);

        try {
            $this->service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame(array(), $this->t->calls);
    }
}
