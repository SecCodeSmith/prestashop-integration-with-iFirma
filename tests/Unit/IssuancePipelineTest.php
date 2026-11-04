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

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        PrestaShopLogger::reset();
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

    private function buildPipeline(IfirmaApiClient $client, IfirmaDataRequestService $requests, Module $module)
    {
        $service = new IfirmaReceiptService($client, $module);

        return new IfirmaIssuancePipeline(
            $service,
            new IfirmaDuplicateGuard($client),
            new IfirmaDeliveryService($client, $service, $module),
            $requests,
            $module,
            null,
            new IfirmaKsefService($client, $module)
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

    private function stepNames(IfirmaPipelineResult $r)
    {
        $names = array();
        foreach ($r->steps as $s) {
            $names[] = $s['step'] . ':' . $s['status'];
        }

        return $names;
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
        $this->assertSame('sent', $r->delivery['status']);
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
        $this->t->queue('GET', '#\.pdf#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->pipeline->run($this->order());
        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('deliver', $r->stoppedAt);
        $this->assertSame('deliver:stopped', $this->stepNames($r)[5]);
        $this->assertSame('failed', $r->delivery['status']);
        $this->assertSame('failed', $this->dbRow()['delivery_status']);
        $this->assertStringContainsString('Delivery step failed', $this->pipeline->describe($r));
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

    public function testOrderLookupFailureInStatusHookIsSwallowed()
    {
        // no order registered under this id: the hook still must not throw
        $this->assertNull($this->pipeline->onOrderStatusChange(999, 4));
        $r = $this->pipeline->onOrderStatusChange(999, 5);
        $this->assertTrue($r === null || $r instanceof IfirmaPipelineResult);
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
