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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/admin/AdminIfirmaController.php';

/**
 * AdminIfirmaController whose answers are recorded and do not end the request, so the code after each
 * answer (the early returns) runs too.
 */
class IfirmaTestAnswerRecordingController extends AdminIfirmaController
{
    /** @var array[] every JSON envelope */
    public $outputs = array();

    /** @var array[] status and text of every plain-text answer */
    public $texts = array();

    /** @var int[] */
    public $statuses = array();

    protected function output(array $payload)
    {
        $this->outputs[] = $payload;
    }

    protected function statusHeader($code)
    {
        $this->statuses[] = $code;
    }

    protected function textResponse($status, $text)
    {
        $this->texts[] = array($status, $text);
    }

    /** @return array the payload as output() would send it, after the entities of the messages are decoded */
    public function decoded(array $payload)
    {
        return $this->decodeMessages($payload);
    }

    /** @return IfirmaBulkRunner the runner the controller builds by itself */
    public function ownRunner()
    {
        return $this->bulkRunner();
    }
}

/** Transport that answers one fixed thing or throws. */
class IfirmaTestAnswerTransport
{
    /** @var Exception|null */
    public $throw;

    public function request($method, $url, array $headers, $body, $timeout)
    {
        if ($this->throw) {
            throw $this->throw;
        }

        return array('status' => 500, 'body' => '', 'error' => '');
    }
}

/**
 * The early returns after a refusal, the bulk input checks and the failures of the PDF download, which the
 * request-ending doubles of the other controller tests never reach.
 */
class AdminControllerAnswersTest extends TestCase
{
    use IfirmaWiringFixture;

    const PROFILE = 4;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        Access::reset();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id = 1;
        Context::getContext()->employee->id_profile = 1;
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        Tools::reset();
    }

    private function controller(array $request)
    {
        Tools::$values = array_merge(array('token' => 'admin-token-AdminIfirma', 'id_order' => 15, 'id_customer' => 5), $request);

        return new IfirmaTestAnswerRecordingController();
    }

    /** The employee of the context holds exactly these roles under profile 4. */
    private function employeeHolding(array $roles)
    {
        Context::getContext()->employee->id_profile = self::PROFILE;
        foreach ($roles as $role) {
            Access::grant($role, self::PROFILE);
        }
    }

    public function testARefusedActionAnswersOnceAndDoesNotRunItsBody()
    {
        $this->employeeHolding(array());
        $controller = $this->controller(array());

        $controller->ajaxProcessCustomerCheck();

        $this->assertSame(array(403), $controller->statuses);
        $this->assertCount(1, $controller->outputs);
        $this->assertFalse($controller->outputs[0]['success']);
    }

    /** @return array action names that need the customer-edit right on top of the view level */
    public static function customerEditActions()
    {
        return array(
            'link' => array('CustomerLink'),
            'unlink' => array('CustomerUnlink'),
            'register' => array('CustomerRegister'),
        );
    }

    /** @dataProvider customerEditActions */
    public function testAnEmployeeWhoMayNotEditCustomersIsToldSoOnceAndTheActionStops($action)
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
        $controller = $this->controller(array('identifier' => 'KOW-01'));

        $controller->{'ajaxProcess' . $action}();

        $this->assertSame(array(403), $controller->statuses);
        $this->assertCount(1, $controller->outputs);
        $this->assertFalse($controller->outputs[0]['success']);
        $this->assertSame('You do not have permission to edit customers.', $controller->outputs[0]['message']);
        $this->assertSame(array(), $this->t->calls, 'iFirma is not asked');
    }

    public function testAnEmployeeWithoutAProfileIsRefusedAtTheViewLevelBeforeTheEditCheck()
    {
        Context::getContext()->employee->id_profile = 0;
        $controller = $this->controller(array('identifier' => 'KOW-01'));

        $controller->ajaxProcessCustomerLink();

        $this->assertSame(array(403), $controller->statuses);
        $this->assertCount(1, $controller->outputs);
        $this->assertSame('You do not have permission to see iFirma documents.', $controller->outputs[0]['message']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testCanEditCustomersIsFalseWhenTheEmployeeHasNoProfile()
    {
        // a profile of 0 (or null) fails the view check first, so the edit check is reached only by calling it directly
        $method = new ReflectionMethod('AdminIfirmaController', 'canEditCustomers');
        $method->setAccessible(true);
        $controller = $this->controller(array());

        Context::getContext()->employee->id_profile = 0;
        $this->assertFalse($method->invoke($controller));

        Context::getContext()->employee->id_profile = null;
        $this->assertFalse($method->invoke($controller));
    }

    public function testTheControllerBuildsItsOwnBulkRunner()
    {
        $this->assertInstanceOf('IfirmaBulkRunner', $this->controller(array())->ownRunner());
    }

    public function testMessagesComeOutDecodedAtTheTopAndInsideTheResultsButTheCellKeepsItsEntities()
    {
        $controller = $this->controller(array());

        $out = $controller->decoded(array(
            'success' => false,
            'message' => 'Stopped at the &quot;guard&quot; step &amp; more',
            'data' => array(
                'cell' => '<a title="&quot;x&quot;">&quot;</a>',
                'url' => 'https://x.test/?a=1&amp;b=2',
                'count' => 3,
                'results' => array(
                    array('id' => 1, 'message' => 'Held &quot;one&quot;', 'kind_label' => 'It&#039;s'),
                ),
            ),
            'receipt' => array(),
        ));

        $this->assertSame('Stopped at the "guard" step & more', $out['message']);
        $this->assertSame('Held "one"', $out['data']['results'][0]['message']);
        $this->assertSame("It's", $out['data']['results'][0]['kind_label']);
        $this->assertSame('<a title="&quot;x&quot;">&quot;</a>', $out['data']['cell']);
        $this->assertSame('https://x.test/?a=1&amp;b=2', $out['data']['url']);
        $this->assertSame(3, $out['data']['count']);
    }

    public function testAnUnknownBulkKindAnswersOnce()
    {
        $controller = $this->controller(array('kind' => 'delete', 'ids' => array(1)));

        $controller->ajaxProcessBulkRun();

        $this->assertCount(1, $controller->outputs);
        $this->assertSame('Unknown bulk action.', $controller->outputs[0]['message']);
    }

    public function testAScalarBulkIdsValueAnswersOnce()
    {
        $controller = $this->controller(array('kind' => 'issue', 'ids' => '5'));

        $controller->ajaxProcessBulkRun();

        $this->assertCount(1, $controller->outputs);
        $this->assertSame('The ids must be a list.', $controller->outputs[0]['message']);
    }

    public function testASelectionWithoutAUsableIdAnswersOnce()
    {
        $controller = $this->controller(array('kind' => 'issue', 'ids' => array('0', array(3), 'x', -2)));

        $controller->ajaxProcessBulkRun();

        $this->assertCount(1, $controller->outputs);
        $this->assertFalse($controller->outputs[0]['success']);
        $this->assertSame('Select at least one row.', $controller->outputs[0]['message']);
    }

    public function testAModuleWithoutLabelHelpersGivesEmptyLabelLists()
    {
        $controller = $this->controller(array());
        $controller->module = new stdClass();

        foreach (array('gridLabels', 'kindLabels') as $name) {
            $method = new ReflectionMethod($controller, $name);
            $method->setAccessible(true);
            $this->assertSame(array(), $method->invoke($controller), $name);
        }
    }

    // ---- the PDF download -------------------------------------------------------------------------------------

    public function testPdfDownloadWithoutTheViewLevelAnswers403Once()
    {
        $this->employeeHolding(array());
        $controller = $this->controller(array());

        $controller->ajaxProcessDownloadPdf();

        $this->assertCount(1, $controller->texts);
        $this->assertSame(403, $controller->texts[0][0]);
    }

    public function testPdfDownloadOfAnOrderWithoutADocumentAnswers404Once()
    {
        $controller = $this->controller(array('id_order' => 15));

        $controller->ajaxProcessDownloadPdf();

        $this->assertCount(1, $controller->texts);
        $this->assertSame(404, $controller->texts[0][0]);
    }

    public function testAnArrayOrderIdIsNotReadAsOne()
    {
        $this->makeReceipt(array('id_order' => 1));
        $controller = $this->controller(array('id_order' => array(1)));

        $this->assertSame(0, $this->callPrivate($controller, 'orderId'));
        $controller->ajaxProcessDownloadPdf();

        $this->assertSame(404, $controller->texts[0][0]);
    }

    public function testAnArrayCustomerIdAndRequestIdAreNotReadAsOne()
    {
        $controller = $this->controller(array('id_customer' => array(1)));
        $this->assertSame(0, $this->callPrivate($controller, 'customerId'));

        $this->employeeHolding(array(IfirmaPermission::ROLE_UPDATE));
        foreach (array('ajaxProcessApproveRequest', 'ajaxProcessRejectRequest') as $method) {
            $controller = $this->controller(array('id_request' => array(1)));
            $controller->$method();
            $this->assertFalse($controller->outputs[0]['success'], $method);
        }
    }

    public function testPdfDownloadByReceiptIdNeedsTheMatchingOrder()
    {
        $receipt = $this->makeReceipt(array('id_order' => 15));
        IfirmaApiClient::setDefaultTransport(new IfirmaTestAnswerTransport());

        $other = $this->controller(array('id_order' => 16, 'id_ifirma_receipt' => $receipt->id));
        $other->ajaxProcessDownloadPdf();
        $this->assertSame(404, $other->texts[0][0]);

        $unknown = $this->controller(array('id_order' => 15, 'id_ifirma_receipt' => 9999));
        $unknown->ajaxProcessDownloadPdf();
        $this->assertSame(404, $unknown->texts[0][0]);

        $match = $this->controller(array('id_order' => 15, 'id_ifirma_receipt' => $receipt->id));
        $match->ajaxProcessDownloadPdf();
        $this->assertSame(502, $match->texts[0][0], 'the record is found and the download is attempted');
    }

    private function callPrivate($object, $method)
    {
        $m = new ReflectionMethod($object, $method);
        $m->setAccessible(true);

        return $m->invoke($object);
    }

    public function testPdfDownloadThatIFirmaRefusesAnswers502WithTheModuleMessage()
    {
        $this->makeReceipt(array('id_order' => 15));
        IfirmaApiClient::setDefaultTransport(new IfirmaTestAnswerTransport());
        $controller = $this->controller(array('id_order' => 15));

        $controller->ajaxProcessDownloadPdf();

        $this->assertCount(1, $controller->texts);
        $this->assertSame(502, $controller->texts[0][0]);
        $this->assertStringStartsWith('Could not download the PDF', $controller->texts[0][1]);
        $this->assertCount(0, PrestaShopLogger::$logs, 'a module message is not logged');
    }

    public function testPdfDownloadThatBreaksUnexpectedlyAnswers502WithTheGenericMessageAndLogsTheCause()
    {
        $this->makeReceipt(array('id_order' => 15));
        $transport = new IfirmaTestAnswerTransport();
        $transport->throw = new RuntimeException('secret detail');
        IfirmaApiClient::setDefaultTransport($transport);
        $controller = $this->controller(array('id_order' => 15));

        $controller->ajaxProcessDownloadPdf();

        $this->assertCount(1, $controller->texts);
        $this->assertSame(502, $controller->texts[0][0]);
        $this->assertSame('Internal error; details are in the PrestaShop log.', $controller->texts[0][1]);
        $this->assertStringNotContainsString('secret detail', $controller->texts[0][1]);
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertStringContainsString('RuntimeException: secret detail', PrestaShopLogger::$logs[0]['message']);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
    }
}
