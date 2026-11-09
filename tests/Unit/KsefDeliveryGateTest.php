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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaKsefDeliveryGate.php';

/**
 * The one definition of "this document is held back from the customer until KSeF accepted it" (design D6).
 */
class KsefDeliveryGateTest extends TestCase
{
    /** @var IfirmaKsefDeliveryGate */
    private $gate;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, new ScriptedTransport());
        $this->gate = new IfirmaKsefDeliveryGate(new IfirmaKsefService($client, new Module()));
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'b2b');
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_address_invoice = 7;
        Order::register($o);
    }

    private function receipt($nip = '1234567890', $type = 'fakturakraj')
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->document_type = $type;
        $r->ifirma_id = '77';
        $r->ifirma_number = '7/2026';
        $r->status = 'created';
        $r->source = 'module';
        if ($nip !== null) {
            $r->request_payload = json_encode(array('Kontrahent' => array('Nazwa' => 'ACME', 'NIP' => $nip)));
        }

        return $r;
    }

    public function testABusinessInvoiceThatKsefHasNotAcceptedMustWait()
    {
        $this->assertTrue($this->gate->mustWait($this->receipt(), new Order(15)));
    }

    public function testAnAcceptedInvoiceDoesNotWait()
    {
        $r = $this->receipt();
        $r->ksef_number = 'N-1';

        $this->assertFalse($this->gate->mustWait($r, new Order(15)));
    }

    public function testAnAcceptedStatusWithoutANumberDoesNotWaitEither()
    {
        $r = $this->receipt();
        $r->ksef_status = 'PRZYJETA';

        $this->assertFalse($this->gate->mustWait($r, new Order(15)));
    }

    public function testAPrivateBuyerInB2bModeDoesNotWait()
    {
        $this->assertFalse($this->gate->mustWait($this->receipt(null), new Order(15)));
    }

    public function testEveryInvoiceWaitsInModeAll()
    {
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'all');

        $this->assertTrue($this->gate->mustWait($this->receipt(null), new Order(15)));
    }

    public function testNothingWaitsInModeOff()
    {
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'off');

        $this->assertFalse($this->gate->mustWait($this->receipt(), new Order(15)));
    }

    public function testNothingWaitsWhenTheChannelIsNone()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');

        $this->assertFalse($this->gate->mustWait($this->receipt(), new Order(15)));
    }

    public function testAnExternalDocumentIsNeverHeldBack()
    {
        $r = $this->receipt();
        $r->source = 'external';

        $this->assertFalse($this->gate->mustWait($r, new Order(15)));
    }

    public function testTheSubmitterDoesNotChangeWhatMustWait()
    {
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');

        $this->assertTrue($this->gate->mustWait($this->receipt(), new Order(15)));
    }

    public function testADocumentIsWaitingWhileItsDeliveryAwaitsKsefAndItIsNotAccepted()
    {
        $r = $this->receipt();
        $r->delivery_status = 'awaiting_ksef';
        $r->ksef_status = 'WYSLANA';

        $this->assertTrue($this->gate->isWaiting($r));
    }

    public function testAnAcceptedDocumentIsNoLongerWaiting()
    {
        $r = $this->receipt();
        $r->delivery_status = 'awaiting_ksef';
        $r->ksef_number = 'N-1';

        $this->assertFalse($this->gate->isWaiting($r));
    }

    /**
     * @return array[]
     */
    public function otherDeliveryStates()
    {
        return array('sent' => array('sent'), 'none' => array('none'), 'failed' => array('failed'), 'skipped' => array('skipped'), 'empty' => array(''));
    }

    /**
     * @dataProvider otherDeliveryStates
     */
    public function testADocumentWhoseDeliveryIsNotAwaitingIsNotWaiting($delivery)
    {
        $r = $this->receipt();
        $r->delivery_status = $delivery;

        $this->assertFalse($this->gate->isWaiting($r));
    }

    public function testIsWaitingReadsLocalFieldsOnlyAndMakesNoCall()
    {
        $r = $this->receipt();
        $r->delivery_status = 'awaiting_ksef';

        $this->gate->isWaiting($r);

        $this->assertSame(array(), Db::getInstance()->queries);
    }
    public function testTheWaitCanBeReadFromThePlainFieldsWithoutAnObject()
    {
        $this->assertTrue(IfirmaKsefDeliveryGate::isWaitingState('awaiting_ksef', 'WYSLANA', ''));
        $this->assertTrue(IfirmaKsefDeliveryGate::isWaitingState('awaiting_ksef', null, null));
        $this->assertFalse(IfirmaKsefDeliveryGate::isWaitingState('awaiting_ksef', '', 'N-1'));
        $this->assertFalse(IfirmaKsefDeliveryGate::isWaitingState('sent', 'WYSLANA', ''));
    }
}
