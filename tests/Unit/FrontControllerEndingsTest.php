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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/front/receipt.php';

/** Thrown by the receipt controller twin instead of ending the request. */
class IfirmaTestFrontEnding extends Exception
{
}

/** The front receipt controller whose request-ending answers throw instead of calling header() and exit. */
class IfirmaTestEndingReceiptController extends IfirmaReceiptModuleFrontController
{
    protected function notFound()
    {
        throw new IfirmaTestFrontEnding('404');
    }

    protected function tooManyRequests()
    {
        throw new IfirmaTestFrontEnding('429');
    }

    protected function serverError()
    {
        throw new IfirmaTestFrontEnding('502');
    }
}

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/front/datarequest.php';

/** The data request controller that records the issuance resumes instead of building the pipeline. */
class IfirmaTestResumingDataRequestController extends IfirmaDatarequestModuleFrontController
{
    /** @var IfirmaDataRequest[] */
    public $resumed = array();

    protected function resumeIssuance(IfirmaDataRequest $request)
    {
        $this->resumed[] = $request;
    }
}

/** The data request controller that runs the real resumeIssuance() against a pipeline it is given. */
class IfirmaTestPipelineDataRequestController extends IfirmaDatarequestModuleFrontController
{
    /** @var IfirmaIssuancePipeline */
    public $stubPipeline;

    protected function pipeline()
    {
        return $this->stubPipeline;
    }
}

/** A pipeline that records its resumes, and raises when told to. */
class IfirmaTestRecordingPipeline extends IfirmaIssuancePipeline
{
    /** @var IfirmaDataRequest[] */
    public $resumed = array();

    /** @var bool */
    public $raise = false;

    public function __construct()
    {
    }

    public function resumeAfterRequest(IfirmaDataRequest $request)
    {
        $this->resumed[] = $request;
        if ($this->raise) {
            throw new RuntimeException('pipeline down');
        }

        return null;
    }
}

/**
 * The front controllers' guards: the login redirect and the answers that end the request.
 */
class FrontControllerEndingsTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
    }

    protected function tearDown(): void
    {
        Tools::reset();
    }

    private function answer(IfirmaTestEndingReceiptController $controller)
    {
        try {
            $controller->initContent();
        } catch (IfirmaTestFrontEnding $e) {
            return $e->getMessage();
        }

        return 'streamed';
    }

    public function testAnEmptyTokenAnswersNotFound()
    {
        Tools::$values = array('token' => '');

        $this->assertSame('404', $this->answer(new IfirmaTestEndingReceiptController()));
    }

    public function testADocumentDownloadedLessThanHalfAMinuteAgoAnswersTooManyRequests()
    {
        $receipt = $this->makeReceipt(array('security_token' => 'tok123', 'last_download_at' => date('Y-m-d H:i:s')));
        Db::getInstance()->whenGetValue('/WHERE security_token = "tok123"/', (int) $receipt->id);
        // the conditional UPDATE of the throttle finds no row to claim: the document is inside its window
        Db::getInstance()->whenExecute('/UPDATE `\w*ifirma_receipt`\s+SET last_download_at/', 0);
        Tools::$values = array('token' => 'tok123');

        $this->assertSame('429', $this->answer(new IfirmaTestEndingReceiptController()));
    }

    public function testAFailedPdfFetchLogsTheReasonAndAnswersServerError()
    {
        $receipt = $this->makeReceipt(array('security_token' => 'tok123'));
        Db::getInstance()->whenGetValue('/WHERE security_token = "tok123"/', (int) $receipt->id);
        // the conditional UPDATE of the throttle claims the document
        Db::getInstance()->whenExecute('/UPDATE `\w*ifirma_receipt`\s+SET last_download_at/', 1);
        $this->t->queue('GET', '#\.pdf#', array('status' => 404, 'body' => '{"response":{"Kod":404,"Informacja":"Nie znaleziono dokumentu"}}'));
        Tools::$values = array('token' => 'tok123');
        PrestaShopLogger::reset();

        $this->assertSame('502', $this->answer(new IfirmaTestEndingReceiptController()));
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertStringContainsString('iFirma front download failed', PrestaShopLogger::$logs[0]['message']);
    }

    public function testAnAnonymousVisitorToTheReceiptIsSentToTheLoginWithTheTokenAsTheReturnTarget()
    {
        Context::getContext()->customer->logged = false;
        Tools::$values = array('token' => 'tok123');
        $controller = new IfirmaTestEndingReceiptController();

        try {
            $controller->initContent();
            $this->fail('expected a redirect');
        } catch (IfirmaTestRedirect $e) {
            $this->assertStringContainsString('authentication', $e->url);
            $this->assertStringContainsString('token%3Dtok123', $e->url);
        }
    }

    /** Posts a private person's address fix for order 15 as its logged-in owner; returns the controller. */
    private function postDataRequest(IfirmaDatarequestModuleFrontController $controller = null)
    {
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'PL';
        Country::register($country);
        Tools::$values = array(
            'id_order' => '15',
            'token' => IfirmaTestState::formToken(),
            'submitIfirmaDataRequest' => '1',
            'firstname' => 'Jan',
            'lastname' => 'K',
            'address1' => 'Nowa 2',
            'postcode' => '00-002',
            'city' => 'Warszawa',
            'id_country' => '14',
        );
        $controller = $controller ?: new IfirmaTestResumingDataRequestController();
        try {
            $controller->postProcess();
            $this->fail('expected the redirect to the order page');
        } catch (IfirmaTestRedirect $e) {
            $this->assertStringContainsString('order-detail', $e->url);
        }

        return $controller;
    }

    public function testARequestApprovedOnSubmissionResumesTheIssuanceOnce()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe');

        $controller = $this->postDataRequest();

        $this->assertCount(1, $controller->resumed);
        $this->assertTrue($controller->resumed[0]->isAutoApproved());
        $this->assertSame(15, (int) $controller->resumed[0]->id_order);
    }

    public function testTheApprovedRequestIsHandedToThePipeline()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe');
        $controller = new IfirmaTestPipelineDataRequestController();
        $controller->stubPipeline = new IfirmaTestRecordingPipeline();

        $this->postDataRequest($controller);

        $this->assertCount(1, $controller->stubPipeline->resumed);
        $this->assertTrue($controller->stubPipeline->resumed[0]->isApproved());
    }

    public function testAFailingPipelineNeverReachesTheCustomer()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe');
        $controller = new IfirmaTestPipelineDataRequestController();
        $controller->stubPipeline = new IfirmaTestRecordingPipeline();
        $controller->stubPipeline->raise = true;

        $this->postDataRequest($controller);

        $this->assertCount(1, $controller->stubPipeline->resumed);
        $this->assertSame(array(), $controller->errors);
    }

    public function testTheDefaultPipelineIsBuiltFromTheModule()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe');
        $controller = new class extends IfirmaDatarequestModuleFrontController {
            public function built()
            {
                return $this->pipeline();
            }
        };
        $controller->module = $this->module;

        $this->assertInstanceOf(IfirmaIssuancePipeline::class, $controller->built());
    }

    public function testAPendingRequestDoesNotResumeTheIssuance()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'none');

        $controller = $this->postDataRequest();

        $this->assertSame(array(), $controller->resumed);
    }

    public function testAnAnonymousVisitorToTheDataRequestIsSentToTheLoginWithTheOrderAsTheReturnTarget()
    {
        Context::getContext()->customer->logged = false;
        Tools::$values = array('id_order' => '15');
        $controller = IfirmaTestModuleLoader::loadFrontController('datarequest');

        try {
            $controller->postProcess();
            $this->fail('expected a redirect');
        } catch (IfirmaTestRedirect $e) {
            $this->assertStringContainsString('authentication', $e->url);
            $this->assertStringContainsString('id_order%3D15', $e->url);
        }
    }
}
