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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaNativeInvoiceLink.php';

/**
 * Scenario checklist (openspec fix-my-invoices-page-and-native-invoice-link, customer-native-invoice-link):
 *
 * - Orders the module handles: an issued document, a pending request without a record, a bare old order.
 * - Built-in invoice link serves the iFirma document: the owner of an issued, downloadable document.
 * - No PrestaShop invoice while the iFirma document is not available: KSeF wait, held request, removed in iFirma,
 *   verifying, failed, not issued yet, no token.
 * - Guests get no invoice for handled orders: a requester who is not the owner.
 * - Orders not handled by the module are unchanged: no record and no request, setting off.
 *
 * The lookups are doubles that record the order they are asked about, so a test can see that nothing is read
 * while the setting is off.
 */
class NativeInvoiceLinkTest extends TestCase
{
    const ORDER = 42;

    /** @var bool */
    private $settingOn = true;

    /** @var IfirmaReceipt|null */
    private $receipt;

    /** @var IfirmaDataRequest|null */
    private $pending;

    /** @var int[] orders the document lookup was asked about */
    private $receiptAsked = array();

    /** @var int[] orders the pending lookup was asked about */
    private $pendingAsked = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->settingOn = true;
        $this->receipt = null;
        $this->pending = null;
        $this->receiptAsked = array();
        $this->pendingAsked = array();
    }

    private function policy()
    {
        return new IfirmaNativeInvoiceLink(
            function () {
                return $this->settingOn;
            },
            function ($idOrder) {
                $this->receiptAsked[] = $idOrder;

                return $this->receipt;
            },
            function ($idOrder) {
                $this->pendingAsked[] = $idOrder;

                return $this->pending;
            },
            new IfirmaCustomerView(new Module(), Context::getContext()->link)
        );
    }

    private function receipt(array $props = array())
    {
        $receipt = new IfirmaReceipt();
        $defaults = array(
            'status' => 'created', 'ifirma_id' => 555, 'security_token' => 'tok123',
            'delivery_status' => 'none', 'ksef_status' => '', 'ksef_number' => '', 'remote_missing' => 0,
        );
        foreach (array_merge($defaults, $props) as $name => $value) {
            $receipt->$name = $value;
        }

        return $receipt;
    }

    private function pendingRequest()
    {
        $request = new IfirmaDataRequest();
        $request->status = IfirmaDataRequest::STATUS_PENDING;

        return $request;
    }

    public function testTheOwnerOfAnIssuedDocumentIsSentToTheProtectedDownloadOfItsStoredToken()
    {
        $this->receipt = $this->receipt();

        $outcome = $this->policy()->decide(self::ORDER, true);

        $this->assertTrue($outcome->isRedirect());
        $this->assertSame(
            Context::getContext()->link->getModuleLink('ifirma', 'receipt', array('token' => 'tok123'), true),
            $outcome->url()
        );
    }

    public function testAnOrderTheModuleDoesNotHandleIsLeftToPrestaShop()
    {
        $outcome = $this->policy()->decide(self::ORDER, true);

        $this->assertTrue($outcome->isPassThrough());
        $this->assertSame(array(self::ORDER), $this->receiptAsked);
        $this->assertSame(array(self::ORDER), $this->pendingAsked);
    }

    public function testASwitchedOffSettingLeavesAnIssuedOrderToPrestaShopAndReadsNothing()
    {
        $this->settingOn = false;
        $this->receipt = $this->receipt();

        $outcome = $this->policy()->decide(self::ORDER, true);

        $this->assertTrue($outcome->isPassThrough());
        $this->assertSame(array(), $this->receiptAsked);
        $this->assertSame(array(), $this->pendingAsked);
    }

    public function testAPendingRequestWithoutAnyRecordIsNotAvailableYet()
    {
        $this->pending = $this->pendingRequest();

        $this->assertTrue($this->policy()->decide(self::ORDER, true)->isNotAvailable());
    }

    public function testAPendingRequestHoldsBackAnIssuedDocument()
    {
        $this->receipt = $this->receipt();
        $this->pending = $this->pendingRequest();

        $this->assertTrue($this->policy()->decide(self::ORDER, true)->isNotAvailable());
    }

    /**
     * @return array[] name => receipt properties of a document that cannot be handed out
     */
    public static function documentsThatCannotBeHandedOut()
    {
        return array(
            'awaiting KSeF acceptance' => array(array('delivery_status' => 'awaiting_ksef', 'ksef_status' => 'pending')),
            'removed in iFirma' => array(array('remote_missing' => 1)),
            'failed' => array(array('status' => 'error')),
            'verifying after an unknown answer' => array(array('status' => 'unknown', 'ifirma_id' => 0)),
            'suspected duplicate' => array(array('status' => 'duplicate_suspected', 'ifirma_id' => 0)),
            'queued, not issued yet' => array(array('status' => 'queued', 'ifirma_id' => 0)),
            'held before issuing' => array(array('status' => 'held', 'ifirma_id' => 0)),
            'issued without a download token' => array(array('security_token' => '')),
        );
    }

    /**
     * @dataProvider documentsThatCannotBeHandedOut
     */
    public function testADocumentThatCannotBeHandedOutIsNotAvailableYet(array $props)
    {
        $this->receipt = $this->receipt($props);

        $this->assertTrue($this->policy()->decide(self::ORDER, true)->isNotAvailable());
    }

    public function testARequesterWhoIsNotTheOwnerGetsNoInvoiceForAnIssuedDocument()
    {
        $this->receipt = $this->receipt();

        $this->assertTrue($this->policy()->decide(self::ORDER, false)->isNotAvailable());
    }

    public function testARequesterWhoIsNotTheOwnerGetsNoInvoiceForAnOrderHeldForARequest()
    {
        $this->pending = $this->pendingRequest();

        $this->assertTrue($this->policy()->decide(self::ORDER, false)->isNotAvailable());
    }

    public function testARequesterWhoIsNotTheOwnerOfAnUnhandledOrderStillGetsPrestaShopsInvoice()
    {
        $this->assertTrue($this->policy()->decide(self::ORDER, false)->isPassThrough());
    }

    public function testTheProductionWiringReadsTheStoredSetting()
    {
        $production = IfirmaNativeInvoiceLink::create(new Module(), Context::getContext()->link);

        Configuration::updateValue('IFIRMA_NATIVE_INVOICE_LINK', 0);
        $this->assertTrue($production->decide(self::ORDER, true)->isPassThrough());
    }

    public function testTheProductionWiringLooksTheOrderUpInTheModuleTables()
    {
        $production = IfirmaNativeInvoiceLink::create(new Module(), Context::getContext()->link);

        Configuration::updateValue('IFIRMA_NATIVE_INVOICE_LINK', 1);
        $this->assertTrue($production->decide(self::ORDER, true)->isPassThrough(), 'no record and no request in the empty database');
    }

    public function testTheOrderIdIsCastToAnIntegerBeforeTheLookups()
    {
        $this->policy()->decide('42; DROP TABLE x', true);

        $this->assertSame(array(42), $this->receiptAsked);
        $this->assertSame(array(42), $this->pendingAsked);
    }
}
