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

class IssuancePipelineTest extends TestCase
{
    const PDF = "%PDF-1.4\nfake body\n%%EOF";

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaReceiptService */
    private $service;

    /** @var IfirmaIssuancePipeline */
    private $pipeline;

    /** @var int[] seconds requested from the injected sleeper */
    private $waits = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        PrestaShopLogger::reset();
        $this->waits = array();
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 5);
        // the contractor round-trip is irrelevant here; ContractorServiceTest and the sync tests below cover it
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        Configuration::updateValue('IFIRMA_USER', 'u@example.com');
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', str_repeat('1', 64));
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', 1);

        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $module = new Module();
        $this->service = new IfirmaReceiptService($this->client, $module);
        $this->pipeline = $this->buildPipeline($this->client, new IfirmaDataRequestService($module), $module);

        $cur = new Currency();
        $cur->id = 1;
        $cur->iso_code = 'PLN';
        Currency::register($cur);
        $c = new Customer();
        $c->id = 5;
        $c->email = 'jan@example.test';
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        Customer::register($c);
        $a = new Address();
        $a->id = 7;
        $a->id_customer = 5;
        $a->firstname = 'Jan';
        $a->lastname = 'K';
        $a->id_country = 14;
        Address::register($a);
        Order::register($this->order());

        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function ($sql, $m) {
            $found = 0;
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $id > $found) {
                    $found = $id;
                }
            }

            return $found ?: false;
        });
    }

    private function buildPipeline(IfirmaApiClient $client, IfirmaDataRequestService $requests, Module $module, ?IfirmaKsefService $ksef = null)
    {
        $service = new IfirmaReceiptService($client, $module);
        $waits = &$this->waits;

        return new IfirmaIssuancePipeline(
            $service,
            new IfirmaDuplicateGuard($client),
            new IfirmaDeliveryService($client, $service, $module, null, function ($seconds) use (&$waits) {
                $waits[] = $seconds;
            }),
            $requests,
            $module,
            null,
            $ksef ?: new IfirmaKsefService($client, $module)
        );
    }

    private function order()
    {
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_shop = 1;
        $o->id_lang = 1;
        $o->id_currency = 1;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = date('Y-m-d H:i:s', time() - 86400);
        $o->total_paid_tax_incl = 123.00;
        $o->details = array(array(
            'product_name' => 'W',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 123,
            'unit_price_tax_excl' => 123,
            'tax_rate' => 0,
        ));

        return $o;
    }

    private function listBody(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows))));
    }

    private function row(array $over = array())
    {
        return array_merge(array(
            'FakturaId' => 77,
            'IdentyfikatorKontrahenta' => 'PS-C5',
            'PelnyNumer' => '7/2026',
            'DataWystawienia' => date('Y-m-d'),
            'Brutto' => 123.00,
            'Zaplacono' => 0,
            'KontrahentNazwa' => 'Jan K',
            'Rodzaj' => 'prz_faktura_kraj',
            'Waluta' => 'PLN',
            'CzySzkic' => false,
            'CzyWyslano' => false,
        ), $over);
    }

    /** Guard list that finds no candidate (a non-matching amount). */
    private function queueGuardNone()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(array('FakturaId' => 1, 'Brutto' => 5)))));
    }

    private function queueCreateOk($type = 'fakturakraj')
    {
        $this->t->queue('POST', '#' . $type . '\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77"}}'));
    }

    private function queueNumber()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row())));
    }

    private function queuePdf()
    {
        $this->t->queue('GET', '#\.pdf#', array('body' => self::PDF));
    }

    private function queueFullSuccess()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();
    }

    private function posts()
    {
        $p = array();
        foreach ($this->t->calls as $c) {
            if ($c['method'] === 'POST') {
                $p[] = $c;
            }
        }

        return $p;
    }

    private function dbRow()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return reset($rows);
    }

    /** @return string[] op (add|update) of each receipt write since the last reset, oldest first */
    private function receiptWriteOps()
    {
        return array_column(IfirmaTestState::writes('ifirma_receipt'), 'op');
    }

    /** A pipeline whose first data-request lookup (the hold step) throws. */
    private function brokenHoldPipeline()
    {
        $module = new Module();
        $broken = new class($module) extends IfirmaDataRequestService {
            public function hasPendingForOrder($idOrder)
            {
                throw new RuntimeException('db exploded');
            }
        };

        return $this->buildPipeline($this->client, $broken, $module);
    }

    /** A pipeline whose first data-request lookup (the hold step) raises $failure, an Exception or an Error. */
    private function pipelineWhoseHoldCheckRaises(Throwable $failure)
    {
        $requests = $this->getMockBuilder(IfirmaDataRequestService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('hasPendingForOrder'))
            ->getMock();
        $requests->method('hasPendingForOrder')->willThrowException($failure);

        return $this->buildPipeline($this->client, $requests, new Module());
    }

    /** Leaves an unknown-outcome row for the order (the create request timed out), then clears the write log. */
    private function leaveUnknownRow()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->pipeline->run($this->order());
        $this->assertSame('unknown', $this->dbRow()['status']);
        IfirmaTestState::clearWrites();
    }

    private function stepNames(IfirmaPipelineResult $r)
    {
        $names = array();
        foreach ($r->steps as $s) {
            $names[] = $s['step'] . ':' . $s['status'];
        }

        return $names;
    }

    /**
     * The four values of the delivery result the pipeline kept, keyed as deliver() reported them before it
     * returned an object.
     *
     * @return array array('ok' => bool, 'status' => string, 'channel' => string, 'message' => string)
     */
    private function deliveryFields(IfirmaPipelineResult $r)
    {
        $this->assertInstanceOf(IfirmaDeliveryResult::class, $r->delivery);

        return array(
            'ok' => $r->delivery->isSent(),
            'status' => $r->delivery->getStatus(),
            'channel' => $r->delivery->getChannel(),
            'message' => $r->delivery->getMessage(),
        );
    }

    private function pendingRequest()
    {
        $req = new IfirmaDataRequest();
        $req->id_order = 15;
        $req->id_customer = 5;
        $req->save();
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', (int) $req->id);

        return $req;
    }

    // ---- trigger -------------------------------------------------------------------------

    public function testTriggerStatusReachedRunsOnce()
    {
        $this->queueFullSuccess();
        $r = $this->pipeline->onOrderStatusChange(15, 5);

        $this->assertInstanceOf('IfirmaPipelineResult', $r);
        $this->assertSame('status', $r->trigger);
        $this->assertSame('issued', $r->outcome);
        $this->assertCount(1, $this->posts());
    }

    public function testTriggerDisabledDoesNothing()
    {
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 0);
        $this->assertNull($this->pipeline->onOrderStatusChange(15, 5));
        $this->assertNull($this->pipeline->onOrderStatusChange(15, 0));
        $this->assertCount(0, $this->t->calls);
    }

    public function testOtherStatusDoesNothing()
    {
        $this->assertNull($this->pipeline->onOrderStatusChange(15, 4));
        $this->assertCount(0, $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testCredentialsMissingSendsNothing()
    {
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', '');
        $this->assertNull($this->pipeline->onOrderStatusChange(15, 5));
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', str_repeat('1', 64));
        Configuration::updateValue('IFIRMA_USER', '');
        $this->assertNull($this->pipeline->onOrderStatusChange(15, 5));
        $this->assertCount(0, $this->t->calls);
    }

    // ---- order and recording -------------------------------------------------------------

    public function testSuccessfulRunStepsInOrderAndRecording()
    {
        $this->queueFullSuccess();
        $r = $this->pipeline->run($this->order(), array('trigger' => 'status'));

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(
            array('hold:ok', 'guard:ok', 'create:ok', 'number:ok', 'ksef:skipped', 'deliver:ok'),
            $this->stepNames($r)
        );
        $row = $this->dbRow();
        $this->assertSame('7/2026', $row['ifirma_number']);
        $this->assertSame('module', $row['source']);
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('sent', $r->delivery->getStatus());
        $this->assertStringContainsString('7/2026', $this->pipeline->describe($r));
    }

    public function testUnknownContractorFilterDoesNotBlockIssuance()
    {
        // guard: kontrahent filter rejected with Kod 201, unfiltered window empty, then the number lookup
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta o zadanym identyfikatorze"}}'));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array()));
        $this->queueNumber();
        $this->queueCreateOk();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order(), array('trigger' => 'status'));

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(
            array('hold:ok', 'guard:ok', 'create:ok', 'number:ok', 'ksef:skipped', 'deliver:ok'),
            $this->stepNames($r)
        );
        $this->assertCount(1, $this->posts());
        $this->assertSame('7/2026', $this->dbRow()['ifirma_number']);
    }

    /** Detail of a document of the buyer without the order reference: same sale date and product line as order(). */
    private function detailNoRef()
    {
        return array('body' => json_encode(array('response' => array(
            'Uwagi' => 'other',
            'DataSprzedazy' => date('Y-m-d', time() - 86400),
            'FormatDatySprzedazy' => 'DZN',
            'Pozycje' => array(array('NazwaPelna' => 'W', 'Ilosc' => 1, 'CenaJednostkowa' => 123.0)),
        ))));
    }

    public function testGuardSuspectedStopsLaterSteps()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row())));
        $this->t->queue('GET', '#fakturakraj/77\.json#', $this->detailNoRef());

        $r = $this->pipeline->run($this->order());

        $this->assertFalse($r->ok);
        $this->assertSame('suspected', $r->outcome);
        $this->assertSame('guard', $r->stoppedAt);
        $this->assertSame(array('hold:ok', 'guard:stopped'), $this->stepNames($r));
        $this->assertCount(0, $this->posts());
        $this->assertCount(0, Mail::$sent);
        $row = $this->dbRow();
        $this->assertSame('duplicate_suspected', $row['status']);
        $this->assertNotSame('', $row['last_error']);
        $this->assertStringContainsString('guard', $this->pipeline->describe($r));
    }

    public function testSameAmountDocumentOfAnotherContractorDoesNotBlock()
    {
        // guard: the only listed document has the order's gross but belongs to another buyer
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row(array(
            'IdentyfikatorKontrahenta' => 'PS-C9',
            'NIPKontrahenta' => '5555555555',
            'KontrahentNazwa' => 'Anna Nowak',
        )))));
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame(
            array('hold:ok', 'guard:ok', 'create:ok', 'number:ok', 'ksef:skipped', 'deliver:ok'),
            $this->stepNames($r)
        );
        $this->assertCount(1, $this->posts());
        $this->assertSame('77', (string) $this->dbRow()['ifirma_id']);
        // no detail was requested for the other buyer's document
        foreach ($this->t->calls as $c) {
            $this->assertStringNotContainsString('fakturakraj/77.json', $c['url']);
        }
    }

    public function testCreateRejectedRecordsErrorAndSkipsLaterSteps()
    {
        $this->queueGuardNone();
        // Kod 200 with DodatkoweUwagi in the payload is retried once; the retry is rejected too
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":200,"Informacja":"bad structure"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":200,"Informacja":"bad structure"}}'));

        $r = $this->pipeline->run($this->order());
        $this->assertSame('error', $r->outcome);
        $this->assertSame('create', $r->stoppedAt);
        $this->assertSame(array('hold:ok', 'guard:ok', 'create:stopped'), $this->stepNames($r));
        $this->assertCount(2, $this->posts());
        $this->assertCount(0, Mail::$sent);
        $row = $this->dbRow();
        $this->assertSame('error', $row['status']);
        $this->assertSame('bad structure', $row['last_error']);
    }

    public function testAlreadyIssuedSkipsEverything()
    {
        $this->queueFullSuccess();
        $this->pipeline->run($this->order());
        $before = count($this->t->calls);

        $r = $this->pipeline->run($this->order());
        $this->assertTrue($r->ok);
        $this->assertSame('already_issued', $r->outcome);
        $this->assertCount($before, $this->t->calls);
        $this->assertSame(
            array('hold:skipped', 'guard:skipped', 'create:skipped', 'number:skipped', 'ksef:skipped', 'deliver:skipped'),
            $this->stepNames($r)
        );
    }

    public function testFailedDeliveryKeepsIssuedOutcome()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        for ($i = 0; $i < 3; $i++) {
            $this->t->queue('GET', '#\.pdf#', array('status' => 0, 'error' => 'timeout'));
        }

        $r = $this->pipeline->run($this->order());
        $this->assertSame(array(3, 7), $this->waits);
        $pdfCalls = 0;
        foreach ($this->t->calls as $call) {
            if (strpos($call['url'], '.pdf') !== false) {
                $pdfCalls++;
            }
        }
        $this->assertSame(3, $pdfCalls);
        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('deliver', $r->stoppedAt);
        $this->assertSame('deliver:stopped', $this->stepNames($r)[5]);
        $this->assertSame('failed', $r->delivery->getStatus());
        $this->assertSame('failed', $this->dbRow()['delivery_status']);
        $this->assertStringContainsString('Delivery step failed', $this->pipeline->describe($r));
    }

    public function testPdfNotReadyOnFirstAttemptStillDelivers()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->t->queue('GET', '#\.pdf#', array('body' => json_encode(array('response' => array('Kod' => 404, 'Informacja' => 'Nie znaleziono dokumentu')))));
        $this->t->queue('GET', '#\.pdf#', array('body' => self::PDF));

        $r = $this->pipeline->run($this->order());
        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('deliver:ok', $this->stepNames($r)[5]);
        $this->assertSame(array(3), $this->waits);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('sent', $this->dbRow()['delivery_status']);
    }

    public function testSkipDelivery()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $r = $this->pipeline->run($this->order(), array('skip_delivery' => true));
        $this->assertTrue($r->ok);
        $this->assertSame('deliver:skipped', $this->stepNames($r)[5]);
        $this->assertCount(0, Mail::$sent);
    }

    // ---- what the pipeline keeps of the delivery: the deliver step and IfirmaPipelineResult::$delivery ----

    public function testDeliverStepOfASentDocumentCarriesTheStatus()
    {
        $this->queueFullSuccess();

        $r = $this->pipeline->run($this->order(), array('trigger' => 'status'));

        $this->assertSame(array('step' => 'deliver', 'status' => 'ok', 'message' => 'sent'), $r->steps[5]);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(
            array('ok' => true, 'status' => 'sent', 'channel' => 'shop_mail', 'message' => ''),
            $this->deliveryFields($r)
        );
    }

    public function testDeliverStepOfASkippedDeliveryCarriesTheStatus()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();

        $r = $this->pipeline->run($this->order());

        $this->assertTrue($r->ok);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(array('step' => 'deliver', 'status' => 'ok', 'message' => 'skipped'), $r->steps[5]);
        $this->assertSame(
            array('ok' => true, 'status' => 'skipped', 'channel' => 'none', 'message' => ''),
            $this->deliveryFields($r)
        );
        $this->assertCount(0, Mail::$sent);
    }

    public function testDeliverStepOfAnAdoptedExternalDocumentCarriesTheSkipReason()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->pipeline->run($this->order());

        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row())));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}}'));
        $r = $this->pipeline->run($this->order(), array('trigger' => 'manual'));

        $this->assertSame('adopted', $r->outcome);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(
            array('step' => 'deliver', 'status' => 'ok', 'message' => 'external document - not auto-delivered'),
            $r->steps[5]
        );
        $this->assertSame(
            array('ok' => true, 'status' => 'skipped', 'channel' => 'shop_mail', 'message' => 'external document - not auto-delivered'),
            $this->deliveryFields($r)
        );
    }

    public function testDeliverStepOfAFailedDeliveryCarriesTheFailureMessage()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        for ($i = 0; $i < 3; $i++) {
            $this->t->queue('GET', '#\.pdf#', array('status' => 0, 'error' => 'timeout'));
        }

        $r = $this->pipeline->run($this->order());

        // the one message the receipt records is the one the step, the result and the admin text carry
        $message = $this->dbRow()['delivery_error'];
        $this->assertStringStartsWith('Could not download the PDF after 3 attempts: ', $message);
        $this->assertStringContainsString('timeout', $message);
        $this->assertSame(array('step' => 'deliver', 'status' => 'stopped', 'message' => $message), $r->steps[5]);
        $this->assertSame('deliver', $r->stoppedAt);
        $this->assertSame(
            array('ok' => false, 'status' => 'failed', 'channel' => 'shop_mail', 'message' => $message),
            $this->deliveryFields($r)
        );
        $this->assertSame('Document issued: 7/2026 Delivery step failed: ' . $message, $this->pipeline->describe($r));
    }

    private function businessAddress()
    {
        $a = new Address();
        $a->id = 7;
        $a->id_customer = 5;
        $a->firstname = 'Jan';
        $a->lastname = 'K';
        $a->company = 'ACME';
        $a->vat_number = '1234567890';
        $a->id_country = 14;
        Address::register($a);
    }

    public function testBusinessOrderSubmitsToKsef()
    {
        $this->businessAddress();
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->t->queue('POST', '#ksef/send/77\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Faktura wyslana"}}'));
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame(
            array('hold:ok', 'guard:ok', 'create:ok', 'number:ok', 'ksef:ok', 'deliver:ok'),
            $this->stepNames($r)
        );
        $this->assertSame('Faktura wyslana', $r->steps[4]['message']);
        $this->assertSame('WYSLANA', $this->dbRow()['ksef_status']);
    }

    public function testPrivateBuyerSkipsKsef()
    {
        $this->queueFullSuccess();
        $r = $this->pipeline->run($this->order());

        $this->assertSame('ksef:skipped', $this->stepNames($r)[4]);
        $this->assertSame('private buyer (B2B mode)', $r->steps[4]['message']);
        $this->assertSame('', $this->dbRow()['ksef_status']);
    }

    public function testKsefDisabledSkipsKsef()
    {
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'off');
        $this->businessAddress();
        $this->queueFullSuccess();
        $r = $this->pipeline->run($this->order());

        $this->assertSame('ksef:skipped', $this->stepNames($r)[4]);
        $this->assertSame('KSeF disabled', $r->steps[4]['message']);
    }

    public function testSkipKsefOption()
    {
        $this->businessAddress();
        $this->queueFullSuccess();
        $r = $this->pipeline->run($this->order(), array('skip_ksef' => true));

        $this->assertSame('ksef:skipped', $this->stepNames($r)[4]);
        $this->assertSame('', $this->dbRow()['ksef_status']);
    }

    public function testKsefFailureDoesNotStopDelivery()
    {
        $this->businessAddress();
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->t->queue('POST', '#ksef/send#', array('body' => '{"response":{"Kod":200,"Informacja":"KSeF niedostepny"}}'));
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(
            array('hold:ok', 'guard:ok', 'create:ok', 'number:ok', 'ksef:failed', 'deliver:ok'),
            $this->stepNames($r)
        );
        $this->assertStringContainsString('KSeF', $r->message);
        $this->assertStringContainsString('KSeF: ', $r->message);
        $this->assertSame('sent', $this->dbRow()['delivery_status']);
        $this->assertSame('BLAD', $this->dbRow()['ksef_status']);
        $this->assertStringContainsString('KSeF submission failed', $this->pipeline->describe($r));
    }

    public function testNumberNotYetAvailableStaysIssued()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array()));
        $r = $this->pipeline->run($this->order(), array('skip_delivery' => true));
        $this->assertTrue($r->ok);
        $this->assertSame('number:ok', $this->stepNames($r)[3]);
        $this->assertSame('number not yet available', $r->steps[3]['message']);
    }

    // ---- hold and resume -----------------------------------------------------------------

    public function testHoldSendsNothingAndRecordsReason()
    {
        $req = $this->pendingRequest();

        $r = $this->pipeline->onOrderStatusChange(15, 5);
        $this->assertSame('held', $r->outcome);
        $this->assertFalse($r->ok);
        $this->assertSame('hold', $r->stoppedAt);
        $this->assertSame(array('hold:stopped'), $this->stepNames($r));
        $this->assertCount(0, $this->t->calls);
        $row = $this->dbRow();
        $this->assertSame('held', $row['status']);
        $this->assertSame('held: data request #' . $req->id . ' pending', $row['last_error']);
        $this->assertStringContainsString('hold', $this->pipeline->describe($r));
    }

    public function testHoldWritesTheNewRowOnce()
    {
        $req = $this->pendingRequest();

        $r = $this->pipeline->run($this->order());

        $this->assertSame('held', $r->outcome);
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('add'), array_column($writes, 'op'));
        $this->assertSame($r->receipt->id, $writes[0]['id']);
        $this->assertSame('held', $writes[0]['row']['status']);
        $this->assertSame('held: data request #' . $req->id . ' pending', $writes[0]['row']['last_error']);
        $this->assertSame(15, (int) $writes[0]['row']['id_order']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $writes[0]['row']['order_reference']);
    }

    public function testHoldUpdatesTheExistingUnissuedRowOnce()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_ERROR;
        $old->last_error = 'earlier failure';
        $old->save();
        $req = $this->pendingRequest();
        IfirmaTestState::clearWrites();

        $r = $this->pipeline->run($this->order());

        $this->assertSame('held', $r->outcome);
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('update'), array_column($writes, 'op'));
        $this->assertSame((int) $old->id, $writes[0]['id']);
        $this->assertSame('held', $writes[0]['row']['status']);
        $this->assertSame('held: data request #' . $req->id . ' pending', $writes[0]['row']['last_error']);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testResumeWithTriggerInHistoryRuns()
    {
        $req = $this->pendingRequest();
        $this->pipeline->run($this->order());
        $this->assertSame('held', $this->dbRow()['status']);

        // request resolved: nothing pending any more
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', false);
        $o = $this->order();
        $o->current_state = 2;
        $o->history = array(array('id_order_state' => 1), array('id_order_state' => 5));
        Order::register($o);
        $this->queueFullSuccess();

        $this->assertTrue($this->pipeline->shouldResume($o));
        $r = $this->pipeline->resumeAfterRequest($req);
        $this->assertInstanceOf('IfirmaPipelineResult', $r);
        $this->assertSame('resume', $r->trigger);
        $this->assertSame('issued', $r->outcome);
        $this->assertCount(1, $this->posts());
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
        $this->assertSame('created', $this->dbRow()['status']);
    }

    public function testResumeWithoutTriggerInHistoryDoesNothing()
    {
        $req = $this->pendingRequest();
        $o = $this->order();
        $o->current_state = 2;
        $o->history = array(array('id_order_state' => 1), array('id_order_state' => 2));
        Order::register($o);
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', false);

        $this->assertFalse($this->pipeline->shouldResume($o));
        $this->assertNull($this->pipeline->resumeAfterRequest($req));
        $this->assertCount(0, $this->t->calls);
    }

    public function testResumeNotWhenAutoIssueDisabled()
    {
        $req = $this->pendingRequest();
        $o = $this->order();
        $o->current_state = 5;
        Order::register($o);
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 0);
        $this->assertFalse($this->pipeline->shouldResume($o));
        $this->assertNull($this->pipeline->resumeAfterRequest($req));
    }

    // ---- unknown outcome -----------------------------------------------------------------

    public function testCreateTimeoutIsUnknownWithSinglePost()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->pipeline->run($this->order());
        $this->assertSame('unknown', $r->outcome);
        $this->assertSame('create', $r->stoppedAt);
        $this->assertFalse($r->ok);
        $this->assertCount(1, $this->posts());
        $row = $this->dbRow();
        $this->assertSame('unknown', $row['status']);
        $this->assertStringContainsString('timeout', $row['last_error']);
        $this->assertCount(0, Mail::$sent);
    }

    public function testLaterRunAdoptsDocumentFoundByGuard()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->pipeline->run($this->order());
        $this->assertCount(1, $this->posts());

        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row())));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}}'));
        $r = $this->pipeline->run($this->order(), array('trigger' => 'manual'));

        $this->assertSame('adopted', $r->outcome);
        $this->assertTrue($r->ok);
        $this->assertCount(1, $this->posts());
        $this->assertSame(
            array('hold:ok', 'guard:ok', 'create:skipped', 'number:skipped', 'ksef:skipped', 'deliver:ok'),
            $this->stepNames($r)
        );
        $this->assertSame('external', $this->dbRow()['source']);
        $this->assertSame('skipped', $this->dbRow()['delivery_status']);
        $this->assertCount(0, Mail::$sent);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testLaterRunCreatesWhenNothingFound()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->pipeline->run($this->order());

        $this->queueFullSuccess();
        $r = $this->pipeline->run($this->order());
        $this->assertSame('issued', $r->outcome);
        $this->assertCount(2, $this->posts());
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testLocallyRefusedCreateIsErrorNotUnknown()
    {
        $client = new IfirmaApiClient('u@example.com', array('faktura' => ''), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $service = new IfirmaReceiptService($client, new Module());
        try {
            $service->createReceipt($this->order());
            $this->fail('expected exception');
        } catch (Exception $e) {
            $this->assertFalse($client->lastRequestWasSent());
        }
        $this->assertSame('error', $this->dbRow()['status']);
        $this->assertCount(0, $this->t->calls);
    }

    // ---- DodatkoweUwagi retry ------------------------------------------------------------

    public function testDodatkoweUwagiRejectedRetriesOnce()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Pole dodatkoweuwagi jest niepoprawne"}}'));
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());
        $posts = $this->posts();
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('DodatkoweUwagi', $posts[0]['body']);
        $this->assertStringNotContainsString('DodatkoweUwagi', $posts[1]['body']);
        $this->assertStringContainsString('"Uwagi":"PS-ORDER-15-ABCDEFGHI"', $posts[1]['body']);
        $this->assertSame('issued', $r->outcome);
        $this->assertStringNotContainsString('DodatkoweUwagi', $this->dbRow()['request_payload']);
    }

    public function testOtherRejectionsAreNotRetried()
    {
        foreach (array(
            '{"response":{"Kod":201,"Informacja":"NIP invalid"}}',
        ) as $body) {
            $this->setUp();
            $this->queueGuardNone();
            $this->t->queue('POST', '#fakturakraj\.json#', array('body' => $body));
            $r = $this->pipeline->run($this->order());
            $this->assertSame('error', $r->outcome);
            $this->assertCount(1, $this->posts());
        }
    }

    public function testFailedRetryEndsInError()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"DodatkoweUwagi invalid"}}'));
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"DodatkoweUwagi still invalid"}}'));

        $r = $this->pipeline->run($this->order());
        $this->assertSame('error', $r->outcome);
        $this->assertCount(2, $this->posts());
        $this->assertSame('error', $this->dbRow()['status']);
        $this->assertStringContainsString('still invalid', $this->dbRow()['last_error']);
    }

    // ---- failures never break the status change ------------------------------------------

    public function testExceptionIsLoggedRecordedAndNotThrown()
    {
        $module = new Module();
        $broken = new class($module) extends IfirmaDataRequestService {
            public function hasPendingForOrder($idOrder)
            {
                throw new RuntimeException('db exploded');
            }
        };
        $pipeline = $this->buildPipeline($this->client, $broken, $module);

        $r = $pipeline->onOrderStatusChange(15, 5);
        $this->assertSame('error', $r->outcome);
        $this->assertFalse($r->ok);
        $this->assertSame('db exploded', $r->message);
        $this->assertSame('hold', $r->stoppedAt);
        $this->assertNotEmpty(PrestaShopLogger::$logs);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
        $this->assertStringContainsString('db exploded', PrestaShopLogger::$logs[0]['message']);
        $row = $this->dbRow();
        $this->assertSame('db exploded', $row['last_error']);
        $this->assertSame('error', $row['status']);
        $this->assertCount(0, $this->t->calls);
    }

    public function testExceptionOnANewOrderWritesTheErrorRowOnce()
    {
        $r = $this->brokenHoldPipeline()->run($this->order());

        $this->assertSame('error', $r->outcome);
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('add'), array_column($writes, 'op'));
        $this->assertSame($r->receipt->id, $writes[0]['id']);
        $this->assertSame('error', $writes[0]['row']['status']);
        $this->assertSame('db exploded', $writes[0]['row']['last_error']);
    }

    public function testExceptionOnAnExistingRowKeepsItsStatusAndRecordsTheMessage()
    {
        $this->leaveUnknownRow();
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $id = (int) key($rows);

        $r = $this->brokenHoldPipeline()->run($this->order());

        $this->assertSame('error', $r->outcome);
        $this->assertSame('db exploded', $r->message);
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('update'), array_column($writes, 'op'));
        $this->assertSame($id, $writes[0]['id']);
        $this->assertSame($id, $r->receipt->id);
        // the document may exist at iFirma: an error that is not about the create keeps the unknown status
        $this->assertSame('unknown', $writes[0]['row']['status']);
        $this->assertSame('db exploded', $writes[0]['row']['last_error']);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testAFailureWhileRecordingTheErrorIsSwallowed()
    {
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function () {
            throw new RuntimeException('receipt table unavailable');
        });

        $r = $this->pipeline->run($this->order());

        $this->assertFalse($r->ok);
        $this->assertSame('error', $r->outcome);
        $this->assertSame('receipt table unavailable', $r->message);
        $this->assertSame('hold', $r->stoppedAt);
        $this->assertNull($r->receipt);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('receipt table unavailable', PrestaShopLogger::$logs[0]['message']);
    }

    public function testAnErrorWhileRecordingTheErrorIsSwallowed()
    {
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function () {
            throw new Error('storage gone');
        });

        $r = $this->pipeline->run($this->order());

        $this->assertFalse($r->ok);
        $this->assertSame('error', $r->outcome);
        $this->assertSame('storage gone', $r->message);
        $this->assertSame('hold', $r->stoppedAt);
        $this->assertNull($r->receipt);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertCount(0, $this->t->calls);
    }

    public function testOrderLookupFailureInStatusHookIsSwallowed()
    {
        // no order registered under this id: the hook still must not throw
        $this->assertNull($this->pipeline->onOrderStatusChange(999, 4));
        $r = $this->pipeline->onOrderStatusChange(999, 5);
        $this->assertTrue($r === null || $r instanceof IfirmaPipelineResult);
    }

    // ---- every guard catches both an Exception and an Error -----------------------------

    /**
     * The pipeline guards its entry points and two of its steps with one catch of Throwable each. The
     * tests below throw an Exception and an Error at one guard each and assert what the guard makes of
     * them. The Exception side of the run's own guard is pinned above, by
     * testExceptionIsLoggedRecordedAndNotThrown() and testExceptionOnANewOrderWritesTheErrorRowOnce();
     * its Error side is the first test here.
     *
     * @return array name => array(Throwable)
     */
    public function throwables()
    {
        return array(
            'Exception' => array(new RuntimeException('exception raised')),
            'Error' => array(new TypeError('error raised')),
        );
    }

    public function testAnErrorFromAStepIsLoggedRecordedAndNotThrown()
    {
        $r = $this->pipelineWhoseHoldCheckRaises(new TypeError('hold check broke'))->run($this->order());

        $this->assertFalse($r->ok);
        $this->assertSame(IfirmaPipelineResult::OUTCOME_ERROR, $r->outcome);
        $this->assertSame('hold check broke', $r->message);
        $this->assertSame(IfirmaIssuancePipeline::STEP_HOLD, $r->stoppedAt);
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame('iFirma pipeline: hold check broke', PrestaShopLogger::$logs[0]['message']);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('add'), array_column($writes, 'op'));
        $this->assertSame('error', $writes[0]['row']['status']);
        $this->assertSame('hold check broke', $writes[0]['row']['last_error']);
        $this->assertSame($writes[0]['id'], $r->receipt->id);
        $this->assertCount(0, $this->t->calls);
    }

    /** @dataProvider throwables */
    public function testAThrowableWhileRecordingTheFailureKeepsTheOriginalFailure(Throwable $recordingFailure)
    {
        // the hold check fails first; the receipt lookup that then records the failure fails as well
        $lookups = 0;
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function () use (&$lookups, $recordingFailure) {
            if (++$lookups > 1) {
                throw $recordingFailure;
            }

            return false;
        });

        $r = $this->pipelineWhoseHoldCheckRaises(new RuntimeException('hold check broke'))->run($this->order());

        $this->assertSame(2, $lookups);
        $this->assertFalse($r->ok);
        $this->assertSame(IfirmaPipelineResult::OUTCOME_ERROR, $r->outcome);
        $this->assertSame('hold check broke', $r->message);
        $this->assertSame(IfirmaIssuancePipeline::STEP_HOLD, $r->stoppedAt);
        $this->assertNull($r->receipt);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        // only the original failure is logged; the one from the best-effort recording is dropped silently
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame('iFirma pipeline: hold check broke', PrestaShopLogger::$logs[0]['message']);
    }

    /** @dataProvider throwables */
    public function testTheStatusHookLogsAndSwallowsAThrowableFromTheRun(Throwable $failure)
    {
        // run() promises never to throw; the hook still shields the status change from a broken promise
        $pipeline = $this->getMockBuilder(IfirmaIssuancePipeline::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('run'))
            ->getMock();
        $pipeline->expects($this->once())->method('run')->willThrowException($failure);

        $this->assertNull($pipeline->onOrderStatusChange(15, 5));

        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame('iFirma pipeline: ' . $failure->getMessage(), PrestaShopLogger::$logs[0]['message']);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
    }

    /** @dataProvider throwables */
    public function testTheResumeLogsAndSwallowsAThrowableFromTheReceiptLookup(Throwable $failure)
    {
        $request = new IfirmaDataRequest();
        $request->id_order = 15;
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function () use ($failure) {
            throw $failure;
        });

        $this->assertNull($this->pipeline->resumeAfterRequest($request));

        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame('iFirma pipeline resume: ' . $failure->getMessage(), PrestaShopLogger::$logs[0]['message']);
        $this->assertSame(3, PrestaShopLogger::$logs[0]['severity']);
        $this->assertCount(0, $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    /** @dataProvider throwables */
    public function testAThrowableFromTheKsefStepFailsOnlyThatStep(Throwable $failure)
    {
        $ksef = $this->getMockBuilder(IfirmaKsefService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('skipReason', 'submit'))
            ->getMock();
        $ksef->method('skipReason')->willReturn('');
        $ksef->method('submit')->willThrowException($failure);
        $module = new Module();
        $pipeline = $this->buildPipeline($this->client, new IfirmaDataRequestService($module), $module, $ksef);
        $this->queueFullSuccess();

        $r = $pipeline->run($this->order());

        // like a refused submission, a throwing one fails the KSeF step only: the run is issued and delivered
        $this->assertTrue($r->ok);
        $this->assertSame(IfirmaPipelineResult::OUTCOME_ISSUED, $r->outcome);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(
            array('hold:ok', 'guard:ok', 'create:ok', 'number:ok', 'ksef:failed', 'deliver:ok'),
            $this->stepNames($r)
        );
        $this->assertSame($failure->getMessage(), $r->steps[4]['message']);
        $this->assertSame('document issued KSeF: ' . $failure->getMessage(), $r->message);
        $this->assertStringContainsString('KSeF submission failed: ' . $failure->getMessage(), $pipeline->describe($r));
        $this->assertSame('sent', $this->dbRow()['delivery_status']);
    }

    public function testIfirmaUnreachableAtGuardIsInconclusive()
    {
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'connection refused'));

        $r = $this->pipeline->onOrderStatusChange(15, 5);
        $this->assertSame('inconclusive', $r->outcome);
        $this->assertSame('guard', $r->stoppedAt);
        $this->assertCount(0, $this->posts());
        $row = $this->dbRow();
        $this->assertSame('error', $row['status']);
        $this->assertStringContainsString('connection refused', $row['last_error']);
    }

    public function testInconclusiveGuardWritesTheNewErrorRowOnce()
    {
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'connection refused'));

        $r = $this->pipeline->run($this->order());

        $this->assertSame('inconclusive', $r->outcome);
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('add'), array_column($writes, 'op'));
        $this->assertSame($r->receipt->id, $writes[0]['id']);
        $this->assertSame('error', $writes[0]['row']['status']);
        $this->assertStringContainsString('connection refused', $writes[0]['row']['last_error']);
        $this->assertSame($r->message, $writes[0]['row']['last_error']);
        $this->assertSame(15, (int) $writes[0]['row']['id_order']);
    }

    public function testInconclusiveGuardKeepsTheStatusOfAnExistingRow()
    {
        $this->leaveUnknownRow();
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $id = (int) key($rows);

        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'connection refused'));
        $r = $this->pipeline->run($this->order());

        $this->assertSame('inconclusive', $r->outcome);
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('update'), array_column($writes, 'op'));
        $this->assertSame($id, $writes[0]['id']);
        $this->assertSame($id, $r->receipt->id);
        // the earlier create may have reached iFirma: a failed lookup must not turn unknown into error
        $this->assertSame('unknown', $writes[0]['row']['status']);
        $this->assertStringContainsString('connection refused', $writes[0]['row']['last_error']);
        $this->assertSame($r->message, $writes[0]['row']['last_error']);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testDisabledDuplicateCheckSkipsTheGuardStep()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', 0);
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame(
            array('hold:ok', 'guard:skipped', 'create:ok', 'number:ok', 'ksef:skipped', 'deliver:ok'),
            $this->stepNames($r)
        );
        $this->assertSame('duplicate check disabled', $r->steps[1]['message']);
        // no document list before the create
        $this->assertSame('POST', $this->t->calls[0]['method']);
        $this->assertCount(1, $this->posts());
    }

    public function testExceptionFromTheDeliveryStopsOnlyTheDeliverStep()
    {
        $module = new Module();
        $service = new IfirmaReceiptService($this->client, $module);
        $delivery = new class($this->client, $service, $module) extends IfirmaDeliveryService {
            public function deliver(IfirmaReceipt $receipt, ?Order $order = null, array $options = array())
            {
                throw new RuntimeException('mail server down');
            }
        };
        $pipeline = new IfirmaIssuancePipeline(
            $service,
            new IfirmaDuplicateGuard($this->client),
            $delivery,
            new IfirmaDataRequestService($module),
            $module,
            null,
            new IfirmaKsefService($this->client, $module)
        );
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();

        $r = $pipeline->run($this->order());

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('deliver', $r->stoppedAt);
        $this->assertSame('deliver:stopped', $this->stepNames($r)[5]);
        $this->assertSame('mail server down', $r->steps[5]['message']);
        $this->assertSame(
            array('ok' => false, 'status' => 'failed', 'channel' => '', 'message' => 'mail server down'),
            $this->deliveryFields($r)
        );
        $this->assertStringContainsString('Delivery step failed: mail server down', $pipeline->describe($r));
        // the delivery never ran, so it recorded nothing
        $this->assertSame('none', $this->dbRow()['delivery_status']);
        $this->assertSame(array('add'), $this->receiptWriteOps());
    }

    public function testAnErrorFromTheDeliveryFailsTheWholeRunAsAnError()
    {
        // Unlike an Exception, a PHP Error escapes the deliver step and reaches fail(),
        // which turns the already issued run into outcome 'error'.
        $module = new Module();
        $service = new IfirmaReceiptService($this->client, $module);
        $delivery = new class($this->client, $service, $module) extends IfirmaDeliveryService {
            public function deliver(IfirmaReceipt $receipt, ?Order $order = null, array $options = array())
            {
                throw new Error('mail transport crashed');
            }
        };
        $pipeline = new IfirmaIssuancePipeline(
            $service,
            new IfirmaDuplicateGuard($this->client),
            $delivery,
            new IfirmaDataRequestService($module),
            $module,
            null,
            new IfirmaKsefService($this->client, $module)
        );
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();

        $r = $pipeline->run($this->order());

        $this->assertFalse($r->ok);
        $this->assertSame(IfirmaPipelineResult::OUTCOME_ERROR, $r->outcome);
        $this->assertSame('error', $r->outcome);
        $this->assertSame('deliver', $r->stoppedAt);
    }

    public function testRateLimitedGuardCreatesNoRow()
    {
        $fixed = 1700000000;
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 1);
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $fixed) . ':1');
        $this->client->setClock(function () use ($fixed) {
            return $fixed;
        });

        $r = $this->pipeline->run($this->order());
        $this->assertSame('rate_limited', $r->outcome);
        $this->assertSame('guard', $r->stoppedAt);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_receipt'));
        $this->assertCount(0, $this->t->calls);
    }

    // ---- describe: the one-line admin message ----------------------------------------------

    public function testDescribeWordsAnAdoptedDocumentApartFromAnIssuedOne()
    {
        $receipt = new IfirmaReceipt();
        $receipt->ifirma_number = '7/2026';
        $words = array();
        foreach (array('adopted', 'issued', 'already_issued') as $outcome) {
            $r = new IfirmaPipelineResult();
            $r->ok = true;
            $r->outcome = $outcome;
            $r->receipt = $receipt;
            $words[$outcome] = $this->pipeline->describe($r);
        }

        $this->assertSame(
            array(
                'adopted' => 'Existing iFirma document adopted: 7/2026',
                'issued' => 'Document issued: 7/2026',
                'already_issued' => 'Document issued: 7/2026',
            ),
            $words
        );
    }

    public function testDescribeNamesTheStepAndTheOutcomeOfAStoppedRun()
    {
        $req = $this->pendingRequest();

        $r = $this->pipeline->run($this->order());

        $this->assertSame('held', $r->outcome);
        $this->assertSame(
            'Stopped at the "hold" step (held): held: data request #' . $req->id . ' pending',
            $this->pipeline->describe($r)
        );
    }

    // ---- manual trigger, bypass, doc type, payment ---------------------------------------

    public function testManualTriggerRunsIdenticalSteps()
    {
        $this->queueFullSuccess();
        $status = $this->pipeline->run($this->order(), array('trigger' => 'status'));

        $this->setUp();
        $this->queueFullSuccess();
        $manual = $this->pipeline->run($this->order(), array('trigger' => 'manual'));

        $this->assertSame('manual', $manual->trigger);
        $this->assertSame($this->stepNames($status), $this->stepNames($manual));
        $this->assertSame($status->outcome, $manual->outcome);
    }

    public function testManualDefaultTrigger()
    {
        $this->assertSame('manual', $this->pipeline->run($this->order())->trigger);
    }

    public function testBypassGuardSkipsListAndRecordsResolution()
    {
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order(), array(
            'trigger' => 'issue_anyway',
            'bypass_guard' => true,
            'id_employee' => 3,
        ));

        $this->assertSame('issued', $r->outcome);
        $this->assertSame('POST', $this->t->calls[0]['method']);
        $this->assertStringContainsString('fakturakraj.json', $this->t->calls[0]['url']);
        // only the number lookup lists documents; the guard did not
        $lists = 0;
        foreach ($this->t->calls as $c) {
            if (strpos($c['url'], 'faktury.json') !== false) {
                ++$lists;
            }
        }
        $this->assertSame(1, $lists);
        $data = json_decode($this->dbRow()['duplicate_candidates'], true);
        $this->assertSame('issue_anyway', $data['resolution']['action']);
        $this->assertSame(3, $data['resolution']['id_employee']);
    }

    public function testDocumentTypeSettingRespected()
    {
        Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturakraj');
        $this->queueGuardNone();
        $this->queueCreateOk('fakturakraj');
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());
        $this->assertSame('issued', $r->outcome);
        $posts = $this->posts();
        $this->assertStringContainsString('fakturakraj.json', $posts[0]['url']);
        $this->assertSame('fakturakraj', $this->dbRow()['document_type']);
    }

    public function testUpdateStatusPaidWorksForAdoptedDocument()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row())));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}}'));
        $r = $this->pipeline->run($this->order());
        $this->assertSame('adopted', $r->outcome);

        $this->t->queue('POST', '#faktury/wplaty/#', array('body' => '{"response":{"Kod":0,"Informacja":"ok"}}'));
        $receipt = $this->service->updateStatusPaid($r->receipt, 123.00);

        $this->assertSame('paid', $receipt->status);
        $posts = $this->posts();
        $this->assertCount(1, $posts);
        $this->assertStringContainsString('faktury/wplaty/prz_faktura_kraj/77.json', $posts[0]['url']);
        $this->assertSame('external', $this->dbRow()['source']);
    }

    public function testUpdateStatusPaidRefusedWithoutIssuedDocument()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->status = IfirmaReceipt::STATUS_ERROR;
        $r->save();
        $this->expectException(Exception::class);
        $this->service->updateStatusPaid($r, 10);
    }
}
