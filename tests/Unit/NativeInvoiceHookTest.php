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
 * actionPDFInvoiceRender: the hook that makes PrestaShop's built-in invoice link serve the iFirma document.
 *
 * Scenario checklist (customer-native-invoice-link): the owner of an issued document is redirected to the
 * protected download; a held, KSeF-waiting or removed document and a guest get the generic "not available yet"
 * answer and the request ends; an order the module does not handle, and a switched-off setting, leave PrestaShop
 * to render its own invoice; a list that is not a list of invoices is not acted upon.
 *
 * The twin records the redirect and the end of the request instead of performing them.
 */
class NativeInvoiceHookTest extends TestCase
{
    use IfirmaWiringFixture;

    const NOT_AVAILABLE_TEXT = 'The invoice is not available yet. Please try again later or contact the shop.';

    /** @var object the module twin: records what the hook does to the request */
    private $twin;

    /** @var string[] header lines sent */
    private $lines;

    /** @var int */
    private $baseLevel;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Configuration::updateValue('IFIRMA_NATIVE_INVOICE_LINK', 1);
        Context::getContext()->controller = new PdfInvoiceControllerCore();
        Tools::$values['id_order'] = '15';
        $this->twin = new class extends Ifirma {
            /** @var string[] */
            public $redirects = array();

            /** @var int */
            public $ended = 0;

            protected function redirectTo($url)
            {
                $this->redirects[] = $url;
            }

            protected function endRequest()
            {
                ++$this->ended;
            }
        };
        $this->lines = array();
        $lines = &$this->lines;
        IfirmaHttpOutput::useHeaderSink(function ($line) use (&$lines) {
            $lines[] = $line;
        });
        $this->baseLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->baseLevel) {
            ob_end_clean();
        }
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function invoiceList($idOrder = 15)
    {
        $invoice = new stdClass();
        $invoice->id_order = $idOrder;

        return array($invoice);
    }

    /** Runs the hook on its own output buffer, which the not-available answer discards, never PHPUnit's. */
    private function render($params)
    {
        ob_start();
        ob_start();
        $this->twin->hookActionPDFInvoiceRender($params);
        $stillBuffered = ob_get_level() > $this->baseLevel + 1 ? ob_get_clean() : '';

        return ob_get_clean() . $stillBuffered;
    }

    private function logIn($idCustomer)
    {
        $customer = Context::getContext()->customer;
        $customer->id = $idCustomer;
        $customer->logged = true;
    }

    private function assertPrestaShopRendersItsOwnInvoice()
    {
        $this->assertSame(array(), $this->twin->redirects);
        $this->assertSame(0, $this->twin->ended);
        $this->assertSame(array(), $this->lines);
    }

    private function assertGenericNotAvailableAnswer($output)
    {
        $this->assertSame(array(), $this->twin->redirects);
        $this->assertSame(1, $this->twin->ended, 'the request ends so that PrestaShop renders no invoice');
        $this->assertSame('HTTP/1.1 404 Not Found', $this->lines[0]);
        $this->assertSame(self::NOT_AVAILABLE_TEXT, $output);
    }

    public function testTheOwnerOfAnIssuedDocumentIsRedirectedToTheProtectedDownload()
    {
        $this->makeReceipt();

        $output = $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertSame(array(Context::getContext()->link->getModuleLink('ifirma', 'receipt', array('token' => 'tok123'), true)), $this->twin->redirects);
        $this->assertSame(0, $this->twin->ended);
        $this->assertSame('', $output);
        $this->assertSame(array(), $this->lines);
    }

    public function testTheInvoiceListMayBeATraversableCollection()
    {
        $this->makeReceipt();

        $this->render(array('order_invoice_list' => new ArrayIterator($this->invoiceList())));

        $this->assertCount(1, $this->twin->redirects);
    }

    public function testAnOrderTheModuleDoesNotHandleIsLeftToPrestaShop()
    {
        $output = $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertSame('', $output);
        $this->assertPrestaShopRendersItsOwnInvoice();
    }

    public function testASwitchedOffSettingLeavesAnIssuedOrderToPrestaShop()
    {
        Configuration::updateValue('IFIRMA_NATIVE_INVOICE_LINK', 0);
        $this->makeReceipt();

        $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertPrestaShopRendersItsOwnInvoice();
    }

    public function testADocumentAwaitingKsefGetsTheGenericAnswerAndTheRequestEnds()
    {
        $this->makeReceipt(array('delivery_status' => IfirmaReceipt::DELIVERY_AWAITING_KSEF, 'ksef_status' => 'pending'));

        $this->assertGenericNotAvailableAnswer($this->render(array('order_invoice_list' => $this->invoiceList())));
    }

    public function testADocumentRemovedInIfirmaGetsTheGenericAnswer()
    {
        $this->makeReceipt(array('remote_missing' => 1));

        $this->assertGenericNotAvailableAnswer($this->render(array('order_invoice_list' => $this->invoiceList())));
    }

    public function testAnOrderHeldForADataRequestGetsTheGenericAnswer()
    {
        $this->makeReceipt();
        $request = new IfirmaDataRequest();
        $request->id_order = 15;
        $request->id_customer = 5;
        $request->status = IfirmaDataRequest::STATUS_PENDING;
        $request->save();

        $this->assertGenericNotAvailableAnswer($this->render(array('order_invoice_list' => $this->invoiceList())));
    }

    public function testAGuestWithTheSecureKeyGetsTheGenericAnswerNotTheDocument()
    {
        $this->makeReceipt();
        Context::getContext()->customer->logged = false;

        $this->assertGenericNotAvailableAnswer($this->render(array('order_invoice_list' => $this->invoiceList())));
    }

    public function testAGuestCustomerRecordIsNotTheOwnerEvenWhenItsIdMatches()
    {
        $this->makeReceipt();
        $customer = Context::getContext()->customer;
        $customer->id = 5;
        $customer->logged = false;

        $this->assertGenericNotAvailableAnswer($this->render(array('order_invoice_list' => $this->invoiceList())));
    }

    public function testALoggedInCustomerHoldingTheSecureKeyOfAnotherOrdersInvoiceGetsTheGenericAnswer()
    {
        $this->makeReceipt();
        $this->logIn(99);

        $this->assertGenericNotAvailableAnswer($this->render(array('order_invoice_list' => $this->invoiceList())));
    }

    public function testTheAnswerIsTheSameSentenceForEveryReason()
    {
        $this->makeReceipt();
        Context::getContext()->customer->logged = false;
        $guest = $this->render(array('order_invoice_list' => $this->invoiceList()));
        $this->lines = array();

        $this->logIn(99);
        $stranger = $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertSame($guest, $stranger);
    }

    /**
     * @return array[] name => hook params that are not a list with an invoice of an order
     */
    public static function craftedParams()
    {
        return array(
            'no parameters' => array(array()),
            'not an array of parameters' => array('order_invoice_list'),
            'a missing list' => array(array('other' => 1)),
            'a string' => array(array('order_invoice_list' => 'order_invoice_list')),
            'a number' => array(array('order_invoice_list' => 15)),
            'an object that is not a collection' => array(array('order_invoice_list' => new stdClass())),
            'an empty list' => array(array('order_invoice_list' => array())),
            'an element that is not an object' => array(array('order_invoice_list' => array(15))),
            'an element without an order id' => array(array('order_invoice_list' => array(new stdClass()))),
            'an order id of zero' => array(array('order_invoice_list' => array((object) array('id_order' => 0)))),
            'a negative order id' => array(array('order_invoice_list' => array((object) array('id_order' => -15)))),
            'an order that does not exist' => array(array('order_invoice_list' => array((object) array('id_order' => 9999)))),
        );
    }

    /**
     * @dataProvider craftedParams
     */
    public function testAListThatIsNotAListOfInvoicesPassesThroughUntouched($params)
    {
        $this->makeReceipt();

        $output = $this->render($params);

        $this->assertSame('', $output);
        $this->assertPrestaShopRendersItsOwnInvoice();
    }

    /**
     * The hook also runs when core attaches an invoice to a mail (checkout, status change) and in the back office.
     */
    public function testAnyRequestThatIsNotTheInvoiceLinkPassesThrough()
    {
        $this->makeReceipt();
        Context::getContext()->controller = new stdClass();

        $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertPrestaShopRendersItsOwnInvoice();
    }

    public function testARequestWithoutAControllerPassesThrough()
    {
        $this->makeReceipt();
        Context::getContext()->controller = null;

        $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertPrestaShopRendersItsOwnInvoice();
    }

    public function testTheBackOfficeHasNoCustomerAndNothingBreaks()
    {
        $this->makeReceipt();
        Context::getContext()->controller = null;
        Context::getContext()->customer = null;

        $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertPrestaShopRendersItsOwnInvoice();
    }

    public function testNoCustomerInTheInvoiceLinkRequestIsNotTheOwner()
    {
        $this->makeReceipt();
        Context::getContext()->customer = null;

        $this->assertGenericNotAvailableAnswer($this->render(array('order_invoice_list' => $this->invoiceList())));
    }

    public function testAListForAnotherOrderThanTheOneTheLinkNamesPassesThrough()
    {
        $this->makeReceipt();
        Tools::$values['id_order'] = '16';

        $this->render(array('order_invoice_list' => $this->invoiceList()));

        $this->assertPrestaShopRendersItsOwnInvoice();
    }

    public function testTheOrderIdIsReadFromTheFirstInvoiceOnly()
    {
        $this->makeReceipt();
        $other = (object) array('id_order' => 9999);

        $this->render(array('order_invoice_list' => array($this->invoiceList()[0], $other)));

        $this->assertCount(1, $this->twin->redirects);
    }

    public function testTheOrderIdIsCastToAnIntegerFromAnArrayOfStrings()
    {
        $this->makeReceipt();

        $this->render(array('order_invoice_list' => $this->invoiceList('15')));

        $this->assertCount(1, $this->twin->redirects);
    }

    public function testTheModuleClassRedirectsThroughTools()
    {
        $this->makeReceipt();

        $this->expectException(IfirmaTestRedirect::class);
        ob_start();
        try {
            (new Ifirma())->hookActionPDFInvoiceRender(array('order_invoice_list' => $this->invoiceList()));
        } finally {
            ob_end_clean();
        }
    }
}
