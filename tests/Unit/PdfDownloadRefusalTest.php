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
 * A PDF fetch the request limit refuses is a typed result that names the window and the seconds to wait; every
 * other failure stays a plain exception (spec ifirma-api-compliance "A refused request is reported and not
 * retried in place").
 */
class PdfDownloadRefusalTest extends TestCase
{
    use IfirmaWiringFixture;

    /** @var int */
    private $now;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->now = mktime(10, 15, 20, 11, 1, 2026);
    }

    private function service()
    {
        $now = $this->now;
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $client->setClock(function () use ($now) {
            return $now;
        });

        return new IfirmaReceiptService($client, new Module());
    }

    private function refusal(IfirmaReceiptService $service)
    {
        try {
            $service->fetchPdf($this->makeReceipt());
        } catch (IfirmaRateLimitedException $e) {
            return $e;
        }
        $this->fail('expected IfirmaRateLimitedException');
    }

    public function testTheMinuteCapRefusesWithTheSecondsUntilTheOldestRequestsAreSixtyOneSecondsOld()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', ($this->now - 21) . '=80');

        $e = $this->refusal($this->service());

        $this->assertSame(IfirmaRateDecision::WINDOW_MINUTE, $e->getWindow());
        $this->assertSame(40, $e->getRetryAfterSeconds());
        $this->assertSame(array(), $this->t->calls, 'no request is sent');
    }

    public function testTheDayCapRefusesWithAtMostAnHourToWait()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $this->now) . ':14000');

        $e = $this->refusal($this->service());

        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $e->getWindow());
        $this->assertSame(3600, $e->getRetryAfterSeconds());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testARunningBackoffRefusesWithTheSecondsLeftOfIt()
    {
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) ($this->now + 40));

        $e = $this->refusal($this->service());

        $this->assertSame(IfirmaRateDecision::WINDOW_BACKOFF, $e->getWindow());
        $this->assertSame(40, $e->getRetryAfterSeconds());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testAClientThatWasNotRefusedHasNothingToWaitFor()
    {
        $now = $this->now;
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $client->setClock(function () use ($now) {
            return $now;
        });

        $this->assertFalse($client->refusedBeforeSendingByRateLimit());
        $this->assertSame(0, $client->retryAfterSeconds());
    }

    public function testTheRefusalIsStillAnException()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':80');

        try {
            $this->service()->fetchPdf($this->makeReceipt());
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertInstanceOf(IfirmaRateLimitedException::class, $e);
            $this->assertStringContainsString('Could not download the PDF', $e->getMessage());
        }
    }

    public function testAnIfirmaLimitAnswerToASentRequestStaysAPlainException()
    {
        $this->t->queue('GET', '#\.pdf#', array(
            'status' => 429,
            'body' => '{"response":{"Kod":100,"Informacja":"Przekroczono limit zapytań"}}',
            'headers' => array('Retry-After' => '20'),
        ));

        try {
            $this->service()->fetchPdf($this->makeReceipt());
            $this->fail('expected an exception');
        } catch (IfirmaRateLimitedException $e) {
            $this->fail('the request was sent, so it is not a local refusal');
        } catch (Exception $e) {
            $this->assertCount(1, $this->t->calls);
        }
    }

    public function testTheCredentialsCooldownIsNotARateLimit()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($this->now - 60) . '|Bad hash');

        try {
            $this->service()->fetchPdf($this->makeReceipt());
            $this->fail('expected an exception');
        } catch (IfirmaRateLimitedException $e) {
            $this->fail('the credentials cool-down must keep the existing error path');
        } catch (Exception $e) {
            $this->assertStringContainsString('calls are paused', $e->getMessage());
        }
    }

    public function testAnIfirmaErrorAfterASentRequestStaysAPlainException()
    {
        $this->t->queue('GET', '#\.pdf#', array('status' => 404, 'body' => '{"response":{"Kod":404,"Informacja":"x"}}'));

        try {
            $this->service()->fetchPdf($this->makeReceipt());
            $this->fail('expected an exception');
        } catch (IfirmaRateLimitedException $e) {
            $this->fail('an iFirma error is not a refusal');
        } catch (Exception $e) {
            $this->assertCount(1, $this->t->calls);
        }
    }
}
