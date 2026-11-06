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

/**
 * The setup of the createReceipt() scenarios that CreateReceiptGoldenTest and CreateReceiptOutcomeTest
 * repeat: the reset of the test state, the contractor map, the client and service under test, the
 * customer 5 and address 7 of the order, the order 15 itself and the switch that turns contractor
 * sync on (with country 14 registered). CreateReceiptTest has a similar setup of its own, with an
 * order dated today, and keeps it.
 *
 * The using test calls setUpCreateReceiptScenario() from its setUp(); it adds anything else it needs
 * there, such as its own builder.
 */
trait IfirmaCreateReceiptScenario
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaReceiptService */
    private $service;

    protected function setUpCreateReceiptScenario()
    {
        IfirmaTestState::resetAll();
        $this->scriptContractorMapDb();
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
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
        $o->date_add = '2026-03-04 10:00:00';
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

    private function enableSync()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 1);
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'pl';
        $country->name = 'Polska';
        Country::register($country);
    }
}
