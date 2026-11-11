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

/** The admin controller whose answers are recorded and do not end the request. */
class IfirmaTestDownloadRecordingController extends AdminIfirmaController
{
    /** @var array[] status and text of every plain-text answer */
    public $texts = array();

    /** @var array[] seconds and text of every limit answer */
    public $limits = array();

    protected function textResponse($status, $text)
    {
        $this->texts[] = array($status, $text);
    }

    protected function refusedByRateLimit($retryAfterSeconds, $text)
    {
        $this->limits[] = array($retryAfterSeconds, $text);
    }

    public function sendLimit($seconds, $text)
    {
        $this->sendLimitAnswer($seconds, $text);
    }
}

/**
 * The admin PDF download at the request limit (spec ifirma-api-compliance "A refused request is reported and not
 * retried in place"): a 429 that names the window and carries the seconds to wait.
 */
class AdminDownloadRefusalTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Access::reset();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id = 1;
        Context::getContext()->employee->id_profile = 1;
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        IfirmaHttpOutput::useHeaderSink(null);
        Tools::reset();
    }

    private function download(array $request = array())
    {
        Tools::$values = array_merge(array('token' => 'admin-token-AdminIfirma', 'id_order' => 15), $request);
        $controller = new IfirmaTestDownloadRecordingController();
        $controller->ajaxProcessDownloadPdf();

        return $controller;
    }

    public function testTheMinuteCapAnswersTheLimitAnswerNamingTheMinuteLimit()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_RATE_WINDOW', (time() - 1) . '=80');

        $controller = $this->download();

        $this->assertSame(array(), $controller->texts);
        $this->assertCount(1, $controller->limits);
        list($seconds, $text) = $controller->limits[0];
        $this->assertGreaterThanOrEqual(1, $seconds);
        $this->assertLessThanOrEqual(61, $seconds);
        $this->assertSame('The PDF was not downloaded: the minute request limit is reached. Try again in a moment.', $text);
        $this->assertSame(array(), $this->t->calls, 'no request is sent to iFirma');
    }

    public function testTheDayCapAnswersTheLimitAnswerNamingTheDayLimit()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd') . ':14000');

        $controller = $this->download();

        list($seconds, $text) = $controller->limits[0];
        $this->assertLessThanOrEqual(3600, $seconds);
        $this->assertSame('The PDF was not downloaded: the day request limit is reached. Try again later.', $text);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testARunningBackoffAnswersWithTheTimeLeftOfIt()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) (time() + 40));

        $controller = $this->download();

        list($seconds, $text) = $controller->limits[0];
        $this->assertLessThanOrEqual(40, $seconds);
        $this->assertGreaterThanOrEqual(1, $seconds);
        $this->assertSame('The PDF was not downloaded: iFirma asked the module to wait before the next request. Try again shortly.', $text);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheLimitAnswerIsNotAnIfirmaErrorAndIsNotLogged()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi') . ':80');
        PrestaShopLogger::reset();

        $controller = $this->download();

        $this->assertSame(array(), $controller->texts, 'no 502 answer');
        $this->assertCount(0, PrestaShopLogger::$logs);
    }

    public function testAnEmployeeWithoutTheViewLevelStillGetsTheAccessDeniedAnswerFirst()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi') . ':80');
        Context::getContext()->employee->id_profile = 4;

        $controller = $this->download();

        $this->assertSame(array(), $controller->limits);
        $this->assertCount(1, $controller->texts);
        $this->assertSame(403, $controller->texts[0][0]);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testAnOrderWithoutADocumentIsStillNotFoundAtTheCap()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi') . ':80');

        $controller = $this->download();

        $this->assertSame(array(), $controller->limits);
        $this->assertSame(404, $controller->texts[0][0]);
    }

    public function testAnIfirmaErrorAfterASentRequestStaysTheServerErrorAnswer()
    {
        $this->makeReceipt();
        $this->t->queue('GET', '#\.pdf#', array('status' => 404, 'body' => '{"response":{"Kod":404,"Informacja":"x"}}'));

        $controller = $this->download();

        $this->assertSame(array(), $controller->limits);
        $this->assertSame(502, $controller->texts[0][0]);
    }

    public function testTheCredentialsCooldownStaysTheServerErrorAnswer()
    {
        $this->makeReceipt();
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', (time() - 60) . '|Bad hash');

        $controller = $this->download();

        $this->assertSame(array(), $controller->limits);
        $this->assertSame(502, $controller->texts[0][0]);
    }

    public function testTheLimitAnswerIsSentThroughTheHttpOutput()
    {
        $lines = array();
        IfirmaHttpOutput::useHeaderSink(function ($line) use (&$lines) {
            $lines[] = $line;
        });
        $controller = new IfirmaTestDownloadRecordingController();
        ob_start();
        ob_start(); // the answer discards the page buffer, so a level of its own comes first
        $controller->sendLimit(25, 'Try later.');
        $body = ob_get_clean();

        $this->assertSame('HTTP/1.1 429 Too Many Requests', $lines[0]);
        $this->assertContains('Retry-After: 25', $lines);
        $this->assertSame('Try later.', $body);
    }
}
