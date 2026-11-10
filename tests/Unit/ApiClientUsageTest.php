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
 * ifirma-api-compliance "Every request kind counts toward the limits" (a status list, a document detail and a PDF
 * download count like a create) and api-usage-accounting "Sent requests are counted per kind and day".
 */
class ApiClientUsageTest extends TestCase
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $c;

    /** @var int */
    public $now;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 15, 20, 11, 10, 2026);
        $this->t = new ScriptedTransport();
        $this->t->queue('GET', '#faktury\.json#', function () {
            return array('status' => 200, 'body' => '{"response":{"Kod":0,"Wynik":[]}}');
        });
        $this->t->queue('GET', '#fakturakraj/7\.json#', function () {
            return array('status' => 200, 'body' => '{"response":{"Kod":0}}');
        });
        $this->t->queue('GET', '#fakturakraj/7\.pdf#', function () {
            return array('status' => 200, 'body' => '%PDF-1.4 data');
        });
        $this->t->queue('POST', '#fakturakraj\.json#', function () {
            return array('status' => 201, 'body' => '{"response":{"Kod":0,"Identyfikator":7}}');
        });
        $this->c = $this->client();
    }

    private function client($usage = null, array $keys = null)
    {
        $c = new IfirmaApiClient(
            'u@example.test',
            $keys !== null ? $keys : array('faktura' => str_repeat('ab', 16)),
            'http://ifirma-mock:8001/iapi/',
            30,
            $this->t,
            null,
            $usage
        );
        $self = $this;
        $c->setClock(function () use ($self) {
            return $self->now;
        });

        return $c;
    }

    private function listDocuments()
    {
        return $this->c->listInvoices(array('dataOd' => '2026-11-01', 'dataDo' => '2026-11-10', 'iloscNaStronie' => 100));
    }

    private function minute()
    {
        return date('YmdHi', $this->now);
    }

    private function day()
    {
        return date('Ymd', $this->now);
    }

    private function minuteCount()
    {
        return (int) substr((string) Configuration::get('IFIRMA_RATE_WINDOW'), 13);
    }

    private function dayCount()
    {
        return (int) substr((string) Configuration::get('IFIRMA_RATE_DAY_WINDOW'), 9);
    }

    private function byKind()
    {
        $self = $this;
        $usage = new IfirmaApiUsage(function () use ($self) {
            return $self->now;
        });

        return $usage->todayByKind();
    }

    // ---- every request kind counts toward both windows -------------------------------------------------

    public function testAStatusListRequestRaisesBothWindowsByOne()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':5');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':40');

        $this->assertSame(array(), $this->listDocuments());

        $this->assertSame(6, $this->minuteCount());
        $this->assertSame(41, $this->dayCount());
    }

    public function testADocumentDetailRequestRaisesBothWindowsByOne()
    {
        $this->assertNotNull($this->c->getDetail('fakturakraj', '7'));

        $this->assertSame(1, $this->minuteCount());
        $this->assertSame(1, $this->dayCount());
    }

    public function testAPdfDownloadRaisesBothWindowsByOne()
    {
        $this->assertSame('%PDF-1.4 data', $this->c->getBinary('fakturakraj/7.pdf', 'faktura'));

        $this->assertSame(1, $this->minuteCount());
        $this->assertSame(1, $this->dayCount());
    }

    public function testTwoPdfDownloadsOfOneDeliveryRaiseBothWindowsByTwo()
    {
        $this->c->getBinary('fakturakraj/7.pdf', 'faktura');
        $this->c->getBinary('fakturakraj/7.pdf', 'faktura');

        $this->assertSame(2, $this->minuteCount());
        $this->assertSame(2, $this->dayCount());
    }

    public function testAStatusListRequestAtTheMinuteCapIsRefusedAndNothingChanges()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':80');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':40');

        $this->assertNull($this->listDocuments());

        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(80, $this->minuteCount());
        $this->assertSame(40, $this->dayCount());
    }

    public function testAPdfDownloadAtTheDayCapIsRefusedAndTheDayCountDoesNotChange()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', '50');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':50');

        $this->assertFalse($this->c->getBinary('fakturakraj/7.pdf', 'faktura'));

        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $this->c->rateLimitWindow());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(50, $this->dayCount());
    }

    public function testADocumentDetailRequestAtTheMinuteCapIsRefusedWithoutASend()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':80');

        $this->assertNull($this->c->getDetail('fakturakraj', '7'));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(80, $this->minuteCount());
    }

    // ---- the kind is recorded where the request is counted ---------------------------------------------

    public function testAStatusRequestIsRecordedUnderStatusAndNothingElse()
    {
        $this->listDocuments();

        $counts = $this->byKind();
        $this->assertSame(1, $counts['status']);
        $this->assertSame(1, array_sum($counts));
    }

    public function testADocumentDetailIsRecordedAsStatus()
    {
        $this->c->getDetail('fakturakraj', '7');

        $this->assertSame(1, $this->byKind()['status']);
    }

    public function testAPdfDownloadIsRecordedUnderPdf()
    {
        $this->c->getBinary('fakturakraj/7.pdf', 'faktura');

        $this->assertSame(1, $this->byKind()['pdf']);
    }

    public function testARequestRefusedByTheGuardRecordsNothing()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', $this->minute() . ':80');

        $this->listDocuments();

        $this->assertSame(0, array_sum($this->byKind()));
    }

    public function testAMissingKeyRecordsNothing()
    {
        $this->c = $this->client(null, array('faktura' => ''));

        $this->assertNull($this->listDocuments());

        $this->assertSame(0, array_sum($this->byKind()));
    }

    public function testAnInvalidKeyRecordsNothing()
    {
        $this->c = $this->client(null, array('faktura' => 'not-hex'));

        $this->assertNull($this->listDocuments());

        $this->assertSame(0, array_sum($this->byKind()));
    }

    public function testTheAuthCooldownRecordsNothing()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|Bad hash');

        $this->assertNull($this->listDocuments());

        $this->assertTrue($this->c->isAuthBlocked());
        $this->assertSame(0, array_sum($this->byKind()));
    }

    public function testARequestAnsweredWithAnErrorIsStillRecorded()
    {
        $this->t->queue('GET', '#fakturakraj/9\.json#', array('status' => 500, 'body' => '{"response":{"Kod":500,"Informacja":"boom"}}'));

        $this->assertNull($this->c->getDetail('fakturakraj', '9'));

        $this->assertSame(1, $this->byKind()['status']);
    }

    public function testARequestThatGotNoAnswerIsStillRecorded()
    {
        $this->t->queue('GET', '#fakturakraj/9\.json#', array('status' => 0, 'body' => '', 'error' => 'timeout'));

        $this->assertNull($this->c->getDetail('fakturakraj', '9'));

        $this->assertSame(1, $this->byKind()['status']);
    }

    public function testARequestAnsweredWithIfirmasLimitAnswerIsStillRecorded()
    {
        $this->t->queue('GET', '#fakturakraj/9\.json#', array(
            'status' => 429,
            'body' => '{"response":{"Kod":100,"Informacja":"Przekroczono limit zapytań"}}',
            'headers' => array('Retry-After' => '20'),
        ));

        $this->assertNull($this->c->getDetail('fakturakraj', '9'));

        $this->assertTrue($this->c->isRateLimited());
        $this->assertSame(1, $this->byKind()['status']);
    }

    public function testAfterAMixedSequenceTheKindsAddUpToTheDayWindow()
    {
        $this->c->post('fakturakraj.json', 'faktura', array('a' => 1));
        $this->c->post('fakturakraj.json', 'faktura', array('a' => 2));
        $this->listDocuments();
        $this->c->getDetail('fakturakraj', '7');
        $this->listDocuments();
        $this->c->getBinary('fakturakraj/7.pdf', 'faktura');

        $counts = $this->byKind();
        $this->assertSame(2, $counts['document']);
        $this->assertSame(3, $counts['status']);
        $this->assertSame(1, $counts['pdf']);
        $this->assertSame(6, array_sum($counts));
        $this->assertSame(6, $this->dayCount());
    }

    public function testTheClientRecordsThroughTheRecorderItWasGiven()
    {
        $recorder = new IfirmaApiUsageSpy();
        $this->c = $this->client($recorder);

        $this->c->post('fakturakraj.json', 'faktura', array('a' => 1));
        $this->c->getBinary('fakturakraj/7.pdf', 'faktura');

        $this->assertSame(array('document', 'pdf'), $recorder->kinds);
        $this->assertSame(0, array_sum($this->byKind()));
    }

    public function testALeadingSlashOnTheEndpointDoesNotChangeTheKind()
    {
        $this->c->get('/fakturakraj/7.json', 'faktura');

        $this->assertSame(1, $this->byKind()['status']);
    }
}

/** Recorder double with the contract of IfirmaApiUsage::record(). */
class IfirmaApiUsageSpy
{
    /** @var string[] */
    public $kinds = array();

    public function record($kind)
    {
        $this->kinds[] = $kind;
    }
}
