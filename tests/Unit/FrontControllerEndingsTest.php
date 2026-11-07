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
        Tools::$values = array('token' => 'tok123');

        $this->assertSame('429', $this->answer(new IfirmaTestEndingReceiptController()));
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
