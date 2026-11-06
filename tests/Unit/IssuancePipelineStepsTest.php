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
 * Pins what a run of IfirmaIssuancePipeline leaves on its IfirmaPipelineResult and asks of its collaborators,
 * beyond what IssuancePipelineTest asserts: on every outcome the ok flag, the outcome, the stopping step, the
 * message, the whole step log with its messages, and the receipt, guard and delivery the result carries; the
 * calls each step makes, with their arguments and in their order; the guard outcomes the guard step treats
 * alike; what a KSeF or delivery failure does to the result; and the step that is named when a throwable
 * escapes the run, which depends on how many steps had been logged by then.
 *
 * Two kinds of test share the file. The end-to-end ones run the real collaborators over the scripted
 * transport and compare the whole result of a run. The others script every collaborator, so that a state the
 * real ones rarely produce (a guard that adopts a document without a receipt, a KSeF service that throws) is
 * reachable, and record every call they get.
 *
 * Written against execute() before it was split into stepHold(), stepGuard(), stepCreate(), stepNumber(),
 * stepKsef() and stepDeliver() (refactor-srp-splits 6.2), so that the split cannot change any of it unnoticed.
 */
class IssuancePipelineStepsTest extends TestCase
{
    const PDF = "%PDF-1.4\nfake body\n%%EOF";

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaIssuancePipeline the real collaborators over the scripted transport */
    private $pipeline;

    /** @var array[] collaborator calls in the order they were made: array(name, argument, ...) */
    private $trace = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        PrestaShopLogger::reset();
        $this->trace = array();
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 5);
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        Configuration::updateValue('IFIRMA_USER', 'u@example.com');
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', str_repeat('1', 64));
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'shop_mail');
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', 1);

        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->pipeline = $this->pipelineOf();

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
        $this->address(array());
        Order::register($this->order());

        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', $this->receiptFinder());
    }

    // ---- scaffolding ---------------------------------------------------------------------------

    /** The Db stub's answer to "the latest receipt row of order N", as the other pipeline tests give it. */
    private function receiptFinder()
    {
        return function ($sql, $m) {
            $found = 0;
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $id > $found) {
                    $found = $id;
                }
            }

            return $found ?: false;
        };
    }

    /** Makes the $call-th receipt lookup (the $call-th run of the finder) raise $failure; the others answer as usual. */
    private function lookupFailingOn($call, Throwable $failure)
    {
        $finder = $this->receiptFinder();
        $calls = 0;
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function ($sql, $m) use (&$calls, $call, $failure, $finder) {
            if (++$calls === $call) {
                throw $failure;
            }

            return $finder($sql, $m);
        });
    }

    /**
     * A pipeline over the real collaborators; any of them can be replaced.
     *
     * @param array $with guard, delivery, requests, service, ksef
     */
    private function pipelineOf(array $with = array())
    {
        $module = new Module();
        $service = isset($with['service']) ? $with['service'] : new IfirmaReceiptService($this->client, $module);

        return new IfirmaIssuancePipeline(
            $service,
            isset($with['guard']) ? $with['guard'] : new IfirmaDuplicateGuard($this->client),
            isset($with['delivery']) ? $with['delivery'] : new IfirmaDeliveryService($this->client, $service, $module, null, function ($seconds) {
            }),
            isset($with['requests']) ? $with['requests'] : new IfirmaDataRequestService($module),
            $module,
            null,
            isset($with['ksef']) ? $with['ksef'] : new IfirmaKsefService($this->client, $module)
        );
    }

    /** The recorder the doubles call: appends array(name, argument, ...) to $this->trace. */
    private function tracer()
    {
        return function ($name) {
            $this->trace[] = func_get_args();
        };
    }

    /** @return string[] the names of the traced calls, in order */
    private function traced()
    {
        return array_map(function ($call) {
            return $call[0];
        }, $this->trace);
    }

    /** @return array[] the arguments (without the name) of each traced call of that name, in order */
    private function callsOf($name)
    {
        $calls = array();
        foreach ($this->trace as $call) {
            if ($call[0] === $name) {
                $calls[] = array_slice($call, 1);
            }
        }

        return $calls;
    }

    /**
     * A pipeline whose collaborators answer as scripted and record every call they get in $this->trace.
     *
     * Script keys, all optional: pending (bool, the hold check), pendingRequest (what the pipeline is then
     * handed as the pending request), override (the data override of the create), guard (IfirmaGuardResult),
     * create (the receipt createReceipt returns; by default a saved issued one), resolution (what
     * recordResolution returns; by default the receipt), skipReason (string), submit (array), delivery
     * (IfirmaDeliveryResult). A Throwable as the value is thrown instead of returned.
     *
     * @return IfirmaIssuancePipeline
     */
    private function scripted(array $script = array())
    {
        $script = array_merge(array(
            'pending' => false,
            'pendingRequest' => null,
            'override' => null,
            'guard' => new IfirmaGuardResult(IfirmaGuardResult::OUTCOME_PROCEED),
            'create' => null,
            'resolution' => null,
            'skipReason' => '',
            'submit' => array('ok' => true, 'message' => ''),
            'delivery' => new IfirmaDeliveryResult(true, 'sent', 'shop_mail', ''),
        ), $script);
        $tracer = $this->tracer();
        $answer = function ($value) {
            if ($value instanceof Throwable) {
                throw $value;
            }

            return $value;
        };

        $requests = $this->getMockBuilder(IfirmaDataRequestService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('hasPendingForOrder', 'getPendingForOrder', 'getOverrideForOrder'))
            ->getMock();
        $requests->method('hasPendingForOrder')->willReturnCallback(function ($idOrder) use ($tracer, $answer, $script) {
            $tracer('requests.hasPending', $idOrder);

            return $answer($script['pending']);
        });
        $requests->method('getPendingForOrder')->willReturnCallback(function ($idOrder) use ($tracer, $answer, $script) {
            $tracer('requests.getPending', $idOrder);

            return $answer($script['pendingRequest']);
        });
        $requests->method('getOverrideForOrder')->willReturnCallback(function ($idOrder) use ($tracer, $answer, $script) {
            $tracer('requests.getOverride', $idOrder);

            return $answer($script['override']);
        });

        $guard = $this->getMockBuilder(IfirmaDuplicateGuard::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('check', 'recordResolution'))
            ->getMock();
        $guard->method('check')->willReturnCallback(function (Order $order, array $options = array()) use ($tracer, $answer, $script) {
            $tracer('guard.check', $order, $options);

            return $answer($script['guard']);
        });
        $guard->method('recordResolution')->willReturnCallback(function (IfirmaReceipt $receipt, $action, $idEmployee = null) use ($tracer, $answer, $script) {
            $tracer('guard.recordResolution', $receipt, $action, $idEmployee);

            return $script['resolution'] === null ? $receipt : $answer($script['resolution']);
        });

        $service = $this->getMockBuilder(IfirmaReceiptService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('createReceipt'))
            ->getMock();
        $service->method('createReceipt')->willReturnCallback(function (Order $order, array $options = array()) use ($tracer, $answer, $script) {
            $tracer('service.createReceipt', $order, $options);

            return $script['create'] === null ? $this->issuedReceipt(true) : $answer($script['create']);
        });

        $ksef = $this->getMockBuilder(IfirmaKsefService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('skipReason', 'submit'))
            ->getMock();
        $ksef->method('skipReason')->willReturnCallback(function (IfirmaReceipt $receipt, ?Order $order = null) use ($tracer, $answer, $script) {
            $tracer('ksef.skipReason', $receipt, $order);

            return $answer($script['skipReason']);
        });
        $ksef->method('submit')->willReturnCallback(function (IfirmaReceipt $receipt, ?Order $order = null, $force = false) use ($tracer, $answer, $script) {
            $tracer('ksef.submit', $receipt, $order, $force);

            return $answer($script['submit']);
        });

        $delivery = $this->getMockBuilder(IfirmaDeliveryService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('deliver'))
            ->getMock();
        $delivery->method('deliver')->willReturnCallback(function (IfirmaReceipt $receipt, ?Order $order = null, array $options = array()) use ($tracer, $answer, $script) {
            $tracer('delivery.deliver', $receipt, $order, $options);

            return $answer($script['delivery']);
        });

        return new IfirmaIssuancePipeline($service, $guard, $delivery, $requests, new Module(), null, $ksef);
    }

    /** An issued receipt of order 15, as createReceipt or the guard hands it over. */
    private function issuedReceipt($save = false, array $over = array())
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->id_customer = 5;
        $r->id_shop = 1;
        $r->document_type = 'fakturakraj';
        $r->ifirma_id = '77';
        $r->ifirma_number = '7/2026';
        $r->status = IfirmaReceipt::STATUS_CREATED;
        foreach ($over as $field => $value) {
            $r->$field = $value;
        }
        if ($save) {
            $r->save();
        }

        return $r;
    }

    private function guardAnswer($outcome, array $over = array())
    {
        $g = new IfirmaGuardResult($outcome);
        foreach ($over as $field => $value) {
            $g->$field = $value;
        }

        return $g;
    }

    private function address(array $over)
    {
        $a = new Address();
        $a->id = 7;
        $a->id_customer = 5;
        $a->firstname = 'Jan';
        $a->lastname = 'K';
        $a->id_country = 14;
        foreach ($over as $field => $value) {
            $a->$field = $value;
        }
        Address::register($a);
    }

    private function businessAddress()
    {
        $this->address(array('company' => 'ACME', 'vat_number' => '1234567890'));
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

    private function queueCreateOk()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77"}}'));
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

    /** What a later run's guard finds after an earlier create timed out: the document, carrying the order reference. */
    private function queueAdoption()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row())));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}}'));
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

    /** Leaves an unknown-outcome row for the order (the create request timed out). */
    private function leaveUnknownRow()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));
        $this->pipelineOf()->run($this->order());
        $this->assertSame('unknown', $this->dbRow()['status']);
        IfirmaTestState::clearWrites();
        $this->t->calls = array();
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

    /** @return string[] 'METHOD path' of each request that reached the transport, in order */
    private function requests()
    {
        $requests = array();
        foreach ($this->t->calls as $call) {
            $requests[] = $call['method'] . ' ' . parse_url($call['url'], PHP_URL_PATH);
        }

        return $requests;
    }

    /** @return string[] 'step:status:message' of each entry of the step log */
    private function stepLines(IfirmaPipelineResult $r)
    {
        $lines = array();
        foreach ($r->steps as $s) {
            $lines[] = $s['step'] . ':' . $s['status'] . ':' . $s['message'];
        }

        return $lines;
    }

    /** The receipt a result carries, as far as the pipeline decides it: '#id status source number'. */
    private function receiptOf(IfirmaPipelineResult $r)
    {
        if ($r->receipt === null) {
            return null;
        }
        $this->assertInstanceOf(IfirmaReceipt::class, $r->receipt);

        return sprintf('#%d %s %s %s', (int) $r->receipt->id, $r->receipt->status, $r->receipt->source, $r->receipt->ifirma_number);
    }

    /** The delivery a result carries: 'ok|failed' '|' status '|' channel '|' message. */
    private function deliveryOf(IfirmaPipelineResult $r)
    {
        if ($r->delivery === null) {
            return null;
        }
        $this->assertInstanceOf(IfirmaDeliveryResult::class, $r->delivery);

        return ($r->delivery->isSent() ? 'ok' : 'failed') . '|' . $r->delivery->getStatus() . '|' . $r->delivery->getChannel() . '|' . $r->delivery->getMessage();
    }

    /** Everything a run left on its result, as one comparable array. */
    private function snapshot(IfirmaPipelineResult $r)
    {
        return array(
            'ok' => $r->ok,
            'outcome' => $r->outcome,
            'stoppedAt' => $r->stoppedAt,
            'message' => $r->message,
            'trigger' => $r->trigger,
            'steps' => $this->stepLines($r),
            'receipt' => $this->receiptOf($r),
            'guard' => $r->guard === null ? null : $r->guard->outcome,
            'delivery' => $this->deliveryOf($r),
        );
    }

    // ---- the whole result of a run, with the real collaborators ------------------------------------

    public function testAnIssuedRunLeavesTheWholeResultAndAsksIfirmaInTheOrderOfTheSteps()
    {
        $this->queueFullSuccess();

        $r = $this->pipeline->run($this->order(), array('trigger' => 'status'));

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => null,
            'message' => 'document issued',
            'trigger' => 'status',
            'steps' => array(
                'hold:ok:',
                'guard:ok:',
                'create:ok:',
                'number:ok:',
                'ksef:skipped:private buyer (B2B mode)',
                'deliver:ok:sent',
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'proceed',
            'delivery' => 'ok|sent|shop_mail|',
        ), $this->snapshot($r));
        $this->assertSame(array('step' => 'hold', 'status' => 'ok', 'message' => ''), $r->steps[0]);
        $this->assertSame(array('step' => 'deliver', 'status' => 'ok', 'message' => 'sent'), $r->steps[5]);
        $this->assertSame(
            array('GET /iapi/faktury.json', 'POST /iapi/fakturakraj.json', 'GET /iapi/faktury.json', 'GET /iapi/fakturakraj/77.pdf'),
            $this->requests()
        );
        $this->assertCount(1, Mail::$sent);
    }

    public function testAnIssuedRunOfABusinessBuyerSubmitsToKsefBeforeItDelivers()
    {
        $this->businessAddress();
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->t->queue('POST', '#ksef/send/77\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Faktura wyslana"}}'));
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => null,
            'message' => 'document issued',
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:ok:',
                'create:ok:',
                'number:ok:',
                'ksef:ok:Faktura wyslana',
                'deliver:ok:sent',
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'proceed',
            'delivery' => 'ok|sent|shop_mail|',
        ), $this->snapshot($r));
        $this->assertSame(
            array('GET /iapi/faktury.json', 'POST /iapi/fakturakraj.json', 'GET /iapi/faktury.json', 'POST /iapi/fakturakraj/ksef/send/77.json', 'GET /iapi/fakturakraj/77.pdf'),
            $this->requests()
        );
    }

    public function testAKsefFailureLeavesTheRunIssuedAndEndsItsMessageWithTheError()
    {
        $this->businessAddress();
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->t->queue('POST', '#ksef/send#', array('body' => '{"response":{"Kod":200,"Informacja":"KSeF niedostepny"}}'));
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());

        // the wording is the KSeF service's, which it also keeps on the receipt; the pipeline passes it on
        $error = $this->dbRow()['ksef_error'];
        $this->assertStringContainsString('KSeF niedostepny', $error);
        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => null,
            'message' => 'document issued KSeF: ' . $error,
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:ok:',
                'create:ok:',
                'number:ok:',
                'ksef:failed:' . $error,
                'deliver:ok:sent',
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'proceed',
            'delivery' => 'ok|sent|shop_mail|',
        ), $this->snapshot($r));
    }

    public function testARunOfAnOrderThatIsAlreadyIssuedSkipsEveryStepAndTouchesNothing()
    {
        $this->queueFullSuccess();
        $this->pipeline->run($this->order());
        $this->t->calls = array();
        IfirmaTestState::clearWrites();
        Mail::$sent = array();

        $r = $this->pipeline->run($this->order(), array('trigger' => 'resume'));

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'already_issued',
            'stoppedAt' => null,
            'message' => 'document already issued',
            'trigger' => 'resume',
            'steps' => array(
                'hold:skipped:document already issued',
                'guard:skipped:document already issued',
                'create:skipped:document already issued',
                'number:skipped:document already issued',
                'ksef:skipped:document already issued',
                'deliver:skipped:document already issued',
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'local_issued',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array(), $this->requests());
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertSame(array(), Mail::$sent);
    }

    public function testARunThatAdoptsTheDocumentFoundAtIfirmaSkipsTheCreateAndKeepsGoing()
    {
        $this->leaveUnknownRow();
        $this->queueAdoption();

        $r = $this->pipeline->run($this->order(), array('trigger' => 'manual'));

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'adopted',
            'stoppedAt' => null,
            'message' => 'existing document adopted',
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:ok:existing document adopted',
                'create:skipped:adopted document',
                'number:skipped:adopted document',
                'ksef:skipped:private buyer (B2B mode)',
                'deliver:ok:external document - not auto-delivered',
            ),
            'receipt' => '#1 created external 7/2026',
            'guard' => 'adopt',
            'delivery' => 'ok|skipped|shop_mail|external document - not auto-delivered',
        ), $this->snapshot($r));
        $this->assertSame(array('GET /iapi/faktury.json', 'GET /iapi/fakturakraj/77.json'), $this->requests());
    }

    public function testAHeldRunStopsAtTheHoldStepWithTheNewHeldRow()
    {
        $req = $this->pendingRequest();

        $r = $this->pipeline->run($this->order(), array('trigger' => 'resume'));

        $reason = 'held: data request #' . $req->id . ' pending';
        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'held',
            'stoppedAt' => 'hold',
            'message' => $reason,
            'trigger' => 'resume',
            'steps' => array('hold:stopped:' . $reason),
            'receipt' => '#1 held module ',
            'guard' => null,
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array(), $this->requests());
        $this->assertSame(array('add'), $this->receiptWriteOps());
    }

    public function testASuspectedRunStopsAtTheGuardStepWithTheSuspectedRow()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->row())));
        $this->t->queue('GET', '#fakturakraj/77\.json#', $this->detailNoRef());

        $r = $this->pipeline->run($this->order());

        $reason = $this->dbRow()['last_error'];
        $this->assertStringContainsString('Suspected duplicate', $reason);
        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'suspected',
            'stoppedAt' => 'guard',
            'message' => $reason,
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:stopped:' . $reason),
            'receipt' => '#1 duplicate_suspected module ',
            'guard' => 'suspected',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array('add'), $this->receiptWriteOps());
        $this->assertSame(array('GET /iapi/faktury.json', 'GET /iapi/fakturakraj/77.json'), $this->requests());
    }

    public function testAnInconclusiveRunStopsAtTheGuardStepWithTheNewErrorRow()
    {
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'connection refused'));

        $r = $this->pipeline->run($this->order());

        $error = $r->guard->error;
        $this->assertStringContainsString('connection refused', $error);
        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'inconclusive',
            'stoppedAt' => 'guard',
            'message' => $error,
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:stopped:' . $error),
            'receipt' => '#1 error module ',
            'guard' => 'inconclusive',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame($error, $this->dbRow()['last_error']);
        $this->assertSame(array('add'), $this->receiptWriteOps());
    }

    public function testARateLimitedRunStopsAtTheGuardStepWithoutAReceipt()
    {
        $fixed = 1700000000;
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 1);
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $fixed) . ':1');
        $this->client->setClock(function () use ($fixed) {
            return $fixed;
        });

        $r = $this->pipeline->run($this->order());

        $error = $r->guard->error;
        $this->assertStringContainsString('rate limit', $error);
        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'rate_limited',
            'stoppedAt' => 'guard',
            'message' => $error,
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:stopped:' . $error),
            'receipt' => null,
            'guard' => 'inconclusive',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    public function testAnUnknownRunStopsAtTheCreateStepWithTheUnknownRow()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->pipeline->run($this->order());

        $error = 'Could not reach iFirma: cURL error: timeout';
        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'unknown',
            'stoppedAt' => 'create',
            'message' => $error,
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:ok:', 'create:stopped:' . $error),
            'receipt' => '#1 unknown module ',
            'guard' => 'proceed',
            'delivery' => null,
        ), $this->snapshot($r));
    }

    public function testARejectedCreateStopsAtTheCreateStepWithTheErrorRow()
    {
        $this->queueGuardNone();
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"NIP invalid"}}'));

        $r = $this->pipeline->run($this->order());

        $error = 'iFirma rejected the document: NIP invalid';
        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'error',
            'stoppedAt' => 'create',
            'message' => $error,
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:ok:', 'create:stopped:' . $error),
            'receipt' => '#1 error module ',
            'guard' => 'proceed',
            'delivery' => null,
        ), $this->snapshot($r));
    }

    public function testAFailedDeliveryLeavesTheRunIssuedAndStoppedAtTheDeliverStep()
    {
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        for ($i = 0; $i < 3; $i++) {
            $this->t->queue('GET', '#\.pdf#', array('status' => 0, 'error' => 'timeout'));
        }

        $r = $this->pipeline->run($this->order());

        $failure = $this->dbRow()['delivery_error'];
        $this->assertStringStartsWith('Could not download the PDF after 3 attempts: ', $failure);
        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => 'deliver',
            'message' => 'document issued',
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:ok:',
                'create:ok:',
                'number:ok:',
                'ksef:skipped:private buyer (B2B mode)',
                'deliver:stopped:' . $failure,
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'proceed',
            'delivery' => 'failed|failed|shop_mail|' . $failure,
        ), $this->snapshot($r));
    }

    public function testASkippedDeliveryWithAFailedKsefSubmissionEndsTheMessageWithTheKsefError()
    {
        $this->businessAddress();
        $this->queueGuardNone();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->t->queue('POST', '#ksef/send#', array('body' => '{"response":{"Kod":200,"Informacja":"KSeF niedostepny"}}'));

        $r = $this->pipeline->run($this->order(), array('skip_delivery' => true));

        $error = $this->dbRow()['ksef_error'];
        $this->assertStringContainsString('KSeF niedostepny', $error);
        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => null,
            'message' => 'document issued KSeF: ' . $error,
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:ok:',
                'create:ok:',
                'number:ok:',
                'ksef:failed:' . $error,
                'deliver:skipped:delivery skipped by caller',
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'proceed',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertCount(0, Mail::$sent);
    }

    public function testADisabledDuplicateCheckLogsTheGuardStepAsSkippedAndKeepsTheGuardResult()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', 0);
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => null,
            'message' => 'document issued',
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:skipped:duplicate check disabled',
                'create:ok:',
                'number:ok:',
                'ksef:skipped:private buyer (B2B mode)',
                'deliver:ok:sent',
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'disabled',
            'delivery' => 'ok|sent|shop_mail|',
        ), $this->snapshot($r));
    }

    public function testAnIssueAnywayRunLogsTheBypassedGuardAndStoresTheResolutionOnTheNewDocument()
    {
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->pipeline->run($this->order(), array('trigger' => 'issue_anyway', 'bypass_guard' => true, 'id_employee' => 3));

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => null,
            'message' => 'document issued',
            'trigger' => 'issue_anyway',
            'steps' => array(
                'hold:ok:',
                'guard:ok:bypassed',
                'create:ok:',
                'number:ok:',
                'ksef:skipped:private buyer (B2B mode)',
                'deliver:ok:sent',
            ),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'bypassed',
            'delivery' => 'ok|sent|shop_mail|',
        ), $this->snapshot($r));
        $resolution = json_decode($this->dbRow()['duplicate_candidates'], true)['resolution'];
        $this->assertSame('issue_anyway', $resolution['action']);
        $this->assertSame(3, $resolution['id_employee']);
    }

    // ---- the calls of a run and their order ----------------------------------------------------------

    public function testARunAsksEachCollaboratorOnceInTheOrderOfTheStepsWithTheOrderItWasGiven()
    {
        $order = $this->order();
        $override = array('company' => 'ACME');
        $pipeline = $this->scripted(array('override' => $override));

        $r = $pipeline->run($order);

        $this->assertSame(
            array(
                'requests.hasPending',
                'guard.check',
                'requests.getOverride',
                'service.createReceipt',
                'ksef.skipReason',
                'ksef.submit',
                'delivery.deliver',
            ),
            $this->traced()
        );
        $receipt = $r->receipt;
        $this->assertInstanceOf(IfirmaReceipt::class, $receipt);
        $this->assertSame(array(array(15)), $this->callsOf('requests.hasPending'));
        $this->assertSame(array(array(15)), $this->callsOf('requests.getOverride'));
        $this->assertSame(array(array($order, array('bypass' => false))), $this->callsOf('guard.check'));
        $this->assertSame(array(array($order, array('override' => $override))), $this->callsOf('service.createReceipt'));
        $this->assertSame(array(array($receipt, $order)), $this->callsOf('ksef.skipReason'));
        $this->assertSame(array(array($receipt, $order, false)), $this->callsOf('ksef.submit'));
        $this->assertSame(array(array($receipt, $order, array())), $this->callsOf('delivery.deliver'));
        $this->assertTrue($r->ok);
        $this->assertSame('document issued', $r->message);
    }

    public function testTheReceiptTheCreateReturnsIsTheOneKsefAndDeliveryGet()
    {
        $created = $this->issuedReceipt(false, array('ifirma_number' => '9/2026'));
        $delivered = new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        $pipeline = $this->scripted(array('create' => $created, 'delivery' => $delivered));

        $r = $pipeline->run($this->order());

        $this->assertSame($created, $r->receipt);
        $this->assertSame($created, $this->callsOf('ksef.skipReason')[0][0]);
        $this->assertSame($created, $this->callsOf('ksef.submit')[0][0]);
        $this->assertSame($created, $this->callsOf('delivery.deliver')[0][0]);
        $this->assertSame($delivered, $r->delivery);
    }

    public function testTheOrderIdIsAskedForAsAnInteger()
    {
        $order = $this->order();
        $order->id = '15';

        $this->scripted()->run($order);

        $this->assertSame(array(array(15)), $this->callsOf('requests.hasPending'));
        $this->assertSame(array(array(15)), $this->callsOf('requests.getOverride'));
    }

    public function testAnIssuedReceiptSkipsTheHoldStepWithoutAskingForADataRequestAndTheGuardEndsTheRun()
    {
        $issued = $this->issuedReceipt(true);
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_LOCAL_ISSUED, array('receipt' => $issued))));

        $r = $pipeline->run($this->order());

        $this->assertSame(array('guard.check'), $this->traced());
        $this->assertSame('hold:skipped:document already issued', $this->stepLines($r)[0]);
        $this->assertSame($issued, $r->receipt);
    }

    public function testAnIssuedReceiptDoesNotStopTheHoldStepEvenWhenTheGuardLetsTheRunThrough()
    {
        // the stop belongs to the guard: a hold step that finds an issued document only logs it
        $this->issuedReceipt(true);
        $pipeline = $this->scripted();

        $r = $pipeline->run($this->order());

        $this->assertSame(
            array('guard.check', 'requests.getOverride', 'service.createReceipt', 'ksef.skipReason', 'ksef.submit', 'delivery.deliver'),
            $this->traced()
        );
        $this->assertSame('hold:skipped:document already issued', $this->stepLines($r)[0]);
        $this->assertSame('issued', $r->outcome);
    }

    public function testAPendingDataRequestHoldsTheRunBeforeTheGuardIsAsked()
    {
        $req = new IfirmaDataRequest();
        $req->id = 8;
        $pipeline = $this->scripted(array('pending' => true, 'pendingRequest' => $req));

        $r = $pipeline->run($this->order());

        $this->assertSame(array('requests.hasPending', 'requests.getPending'), $this->traced());
        $this->assertSame(array(array(15)), $this->callsOf('requests.getPending'));
        $this->assertSame('held', $r->outcome);
        $this->assertSame('held: data request #8 pending', $r->message);
        $this->assertSame(array('add'), $this->receiptWriteOps());
    }

    public function testAHeldRunWhoseRequestIsGoneNamesRequestZero()
    {
        $pipeline = $this->scripted(array('pending' => true, 'pendingRequest' => null));

        $r = $pipeline->run($this->order());

        $this->assertSame('held: data request #0 pending', $r->message);
        $this->assertSame(array('hold:stopped:held: data request #0 pending'), $this->stepLines($r));
        $this->assertSame('held: data request #0 pending', $this->dbRow()['last_error']);
        $this->assertSame('held', $this->dbRow()['status']);
    }

    public function testAHeldRunUpdatesTheExistingUnissuedRowInsteadOfAddingOne()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_UNKNOWN;
        $old->last_error = 'timeout';
        $old->save();
        IfirmaTestState::clearWrites();
        $req = new IfirmaDataRequest();
        $req->id = 8;
        $pipeline = $this->scripted(array('pending' => true, 'pendingRequest' => $req));

        $r = $pipeline->run($this->order());

        $this->assertSame(array('update'), $this->receiptWriteOps());
        $this->assertSame('#1 held module ', $this->receiptOf($r));
        $this->assertSame('held: data request #8 pending', $this->dbRow()['last_error']);
    }

    public function testNothingPendingLogsTheHoldStepAsOkAndAsksTheGuard()
    {
        $r = $this->scripted(array('pending' => false))->run($this->order());

        $this->assertSame(array('requests.hasPending', 'guard.check'), array_slice($this->traced(), 0, 2));
        $this->assertSame('hold:ok:', $this->stepLines($r)[0]);
    }

    // ---- the guard step -------------------------------------------------------------------------------

    /** @return array label => array(options, bypass) */
    public function bypassOptions()
    {
        return array(
            'absent' => array(array(), false),
            'false' => array(array('bypass_guard' => false), false),
            'zero' => array(array('bypass_guard' => 0), false),
            'zero as a string' => array(array('bypass_guard' => '0'), false),
            'empty string' => array(array('bypass_guard' => ''), false),
            'null' => array(array('bypass_guard' => null), false),
            'empty list' => array(array('bypass_guard' => array()), false),
            'true' => array(array('bypass_guard' => true), true),
            'one' => array(array('bypass_guard' => 1), true),
            'one as a string' => array(array('bypass_guard' => '1'), true),
            'a word' => array(array('bypass_guard' => 'yes'), true),
        );
    }

    /** @dataProvider bypassOptions */
    public function testTheGuardIsAskedWithABooleanBypassFlag(array $options, $bypass)
    {
        $this->scripted()->run($this->order(), $options);

        $calls = $this->callsOf('guard.check');
        $this->assertCount(1, $calls);
        $this->assertSame(array('bypass' => $bypass), $calls[0][1]);
    }

    public function testTheTriggerIsNotPassedToTheGuard()
    {
        $this->scripted()->run($this->order(), array('trigger' => 'issue_anyway', 'id_employee' => 3, 'skip_ksef' => true));

        $this->assertSame(array('bypass' => false), $this->callsOf('guard.check')[0][1]);
    }

    /** @return array label => array(guard outcome, line of the guard step, whether a resolution is recorded) */
    public function guardOutcomesThatLetTheRunThrough()
    {
        return array(
            'proceed' => array(IfirmaGuardResult::OUTCOME_PROCEED, 'guard:ok:', false),
            'bypassed' => array(IfirmaGuardResult::OUTCOME_BYPASSED, 'guard:ok:bypassed', true),
            'disabled' => array(IfirmaGuardResult::OUTCOME_DISABLED, 'guard:skipped:duplicate check disabled', false),
            'not an outcome' => array('not_an_outcome', 'guard:ok:', false),
        );
    }

    /** @dataProvider guardOutcomesThatLetTheRunThrough */
    public function testTheGuardOutcomesThatLetTheRunThroughLogTheGuardStepWithTheirOwnWords($outcome, $line, $resolves)
    {
        $r = $this->scripted(array('guard' => $this->guardAnswer($outcome)))->run($this->order());

        $this->assertSame(
            array('hold:ok:', $line, 'create:ok:', 'number:ok:', 'ksef:ok:', 'deliver:ok:sent'),
            $this->stepLines($r)
        );
        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame($outcome, $r->guard->outcome);
        $calls = array('requests.hasPending', 'guard.check', 'requests.getOverride', 'service.createReceipt');
        if ($resolves) {
            $calls[] = 'guard.recordResolution';
        }
        $this->assertSame(array_merge($calls, array('ksef.skipReason', 'ksef.submit', 'delivery.deliver')), $this->traced());
    }

    public function testALocalIssuedGuardAnswerEndsTheRunAfterTheGuardWithFiveSkippedSteps()
    {
        $issued = $this->issuedReceipt(false, array('source' => 'external'));
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_LOCAL_ISSUED, array('receipt' => $issued))));

        $r = $pipeline->run($this->order(), array('trigger' => 'resume'));

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'already_issued',
            'stoppedAt' => null,
            'message' => 'document already issued',
            'trigger' => 'resume',
            'steps' => array(
                'hold:ok:',
                'guard:skipped:document already issued',
                'create:skipped:document already issued',
                'number:skipped:document already issued',
                'ksef:skipped:document already issued',
                'deliver:skipped:document already issued',
            ),
            'receipt' => '#0 created external 7/2026',
            'guard' => 'local_issued',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame($issued, $r->receipt);
        $this->assertSame(array('requests.hasPending', 'guard.check'), $this->traced());
    }

    public function testAnAdoptGuardAnswerSkipsCreateAndNumberButStillGoesToKsefAndDelivery()
    {
        $adopted = $this->issuedReceipt(false, array('source' => 'external'));
        $order = $this->order();
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_ADOPT, array('receipt' => $adopted))));

        $r = $pipeline->run($order, array('id_employee' => 3));

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'adopted',
            'stoppedAt' => null,
            'message' => 'existing document adopted',
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:ok:existing document adopted',
                'create:skipped:adopted document',
                'number:skipped:adopted document',
                'ksef:ok:',
                'deliver:ok:sent',
            ),
            'receipt' => '#0 created external 7/2026',
            'guard' => 'adopt',
            'delivery' => 'ok|sent|shop_mail|',
        ), $this->snapshot($r));
        $this->assertSame($adopted, $r->receipt);
        $this->assertSame(
            array('requests.hasPending', 'guard.check', 'ksef.skipReason', 'ksef.submit', 'delivery.deliver'),
            $this->traced()
        );
        $this->assertSame(array(array($adopted, $order)), $this->callsOf('ksef.skipReason'));
        $this->assertSame(array(array($adopted, $order, array())), $this->callsOf('delivery.deliver'));
    }

    public function testASuspectedGuardAnswerEndsTheRunWithTheLastErrorOfItsReceipt()
    {
        $suspected = $this->issuedReceipt(false, array('ifirma_id' => '', 'status' => 'duplicate_suspected', 'last_error' => 'Two candidates'));
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_SUSPECTED, array('receipt' => $suspected))));

        $r = $pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'suspected',
            'stoppedAt' => 'guard',
            'message' => 'Two candidates',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:stopped:Two candidates'),
            'receipt' => '#0 duplicate_suspected module 7/2026',
            'guard' => 'suspected',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame($suspected, $r->receipt);
        $this->assertSame(array('requests.hasPending', 'guard.check'), $this->traced());
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    public function testASuspectedGuardAnswerWithoutALastErrorStopsWithAnEmptyMessage()
    {
        $suspected = $this->issuedReceipt(false, array('last_error' => null));
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_SUSPECTED, array('receipt' => $suspected))));

        $r = $pipeline->run($this->order());

        $this->assertSame('', $r->message);
        $this->assertSame('hold:ok:', $this->stepLines($r)[0]);
        $this->assertSame('guard:stopped:', $this->stepLines($r)[1]);
    }

    public function testASuspectedGuardAnswerWithoutAReceiptStopsWithTheDefaultMessage()
    {
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_SUSPECTED)));

        $r = $pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'suspected',
            'stoppedAt' => 'guard',
            'message' => 'suspected duplicate',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:stopped:suspected duplicate'),
            'receipt' => null,
            'guard' => 'suspected',
            'delivery' => null,
        ), $this->snapshot($r));
    }

    public function testARateLimitedGuardAnswerEndsTheRunWithTheGuardErrorAndNoReceipt()
    {
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(
            IfirmaGuardResult::OUTCOME_INCONCLUSIVE,
            array('rateLimited' => true, 'error' => 'rate limit guard: 80 requests in the current minute')
        )));

        $r = $pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'rate_limited',
            'stoppedAt' => 'guard',
            'message' => 'rate limit guard: 80 requests in the current minute',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:stopped:rate limit guard: 80 requests in the current minute'),
            'receipt' => null,
            'guard' => 'inconclusive',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array('requests.hasPending', 'guard.check'), $this->traced());
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    public function testARateLimitedGuardAnswerWithoutAnErrorStopsWithAnEmptyMessage()
    {
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(
            IfirmaGuardResult::OUTCOME_INCONCLUSIVE,
            array('rateLimited' => true, 'error' => null)
        )));

        $r = $pipeline->run($this->order());

        $this->assertSame('', $r->message);
        $this->assertSame('rate_limited', $r->outcome);
        $this->assertSame(array('hold:ok:', 'guard:stopped:'), $this->stepLines($r));
    }

    public function testAnInconclusiveGuardAnswerRecordsTheErrorOnANewRowAndEndsTheRun()
    {
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(
            IfirmaGuardResult::OUTCOME_INCONCLUSIVE,
            array('error' => 'list request failed')
        )));

        $r = $pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'inconclusive',
            'stoppedAt' => 'guard',
            'message' => 'list request failed',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:stopped:list request failed'),
            'receipt' => '#1 error module ',
            'guard' => 'inconclusive',
            'delivery' => null,
        ), $this->snapshot($r));
        $row = $this->dbRow();
        $this->assertSame('error', $row['status']);
        $this->assertSame('list request failed', $row['last_error']);
        $this->assertSame(15, (int) $row['id_order']);
        $this->assertSame(5, (int) $row['id_customer']);
        $this->assertSame(1, (int) $row['id_shop']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $row['order_reference']);
        $this->assertSame(array('add'), $this->receiptWriteOps());
        $this->assertSame(array('requests.hasPending', 'guard.check'), $this->traced());
    }

    public function testAnInconclusiveGuardAnswerKeepsTheStatusOfAnExistingRow()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_UNKNOWN;
        $old->last_error = 'timeout';
        $old->save();
        IfirmaTestState::clearWrites();
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(
            IfirmaGuardResult::OUTCOME_INCONCLUSIVE,
            array('error' => 'list request failed')
        )));

        $r = $pipeline->run($this->order());

        $this->assertSame('#1 unknown module ', $this->receiptOf($r));
        $this->assertSame('inconclusive', $r->outcome);
        $this->assertSame('list request failed', $r->message);
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $this->assertSame('unknown', $this->dbRow()['status']);
        $this->assertSame('list request failed', $this->dbRow()['last_error']);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testAnInconclusiveGuardAnswerWithoutAnErrorRecordsAnEmptyOne()
    {
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(
            IfirmaGuardResult::OUTCOME_INCONCLUSIVE,
            array('error' => null)
        )));

        $r = $pipeline->run($this->order());

        $this->assertSame('', $r->message);
        $this->assertSame('', $this->dbRow()['last_error']);
        $this->assertSame('error', $this->dbRow()['status']);
        $this->assertSame(array('hold:ok:', 'guard:stopped:'), $this->stepLines($r));
    }

    public function testAnInconclusiveGuardAnswerWithoutAnErrorKeepsAnEmptyErrorOnAnExistingRow()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_UNKNOWN;
        $old->last_error = 'timeout';
        $old->save();
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(
            IfirmaGuardResult::OUTCOME_INCONCLUSIVE,
            array('error' => null)
        )));

        $pipeline->run($this->order());

        $this->assertSame('', $this->dbRow()['last_error']);
        $this->assertSame('unknown', $this->dbRow()['status']);
    }

    // ---- the create step ------------------------------------------------------------------------------

    public function testTheCreateGetsTheDataOverrideOfTheOrderAndNothingElse()
    {
        $override = array('company' => 'ACME', 'nip' => '1234567890');

        $this->scripted(array('override' => $override))->run($this->order());

        $this->assertSame(array('override' => $override), $this->callsOf('service.createReceipt')[0][1]);
    }

    public function testACreateWithoutAnOverrideGetsANullOverride()
    {
        $this->scripted(array('override' => null))->run($this->order());

        $this->assertSame(array('override' => null), $this->callsOf('service.createReceipt')[0][1]);
    }

    public function testAnExceptionFromTheCreateStopsTheRunAsAnErrorAndReadsTheRowAgain()
    {
        $pipeline = $this->scripted(array('create' => new RuntimeException('bad structure')));

        $r = $pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'error',
            'stoppedAt' => 'create',
            'message' => 'bad structure',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:ok:', 'create:stopped:bad structure'),
            'receipt' => null,
            'guard' => 'proceed',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array('requests.hasPending', 'guard.check', 'requests.getOverride', 'service.createReceipt'), $this->traced());
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    /** @return array label => array(row status, outcome) */
    public function rowStatusesAfterAFailedCreate()
    {
        return array(
            'unknown row' => array(IfirmaReceipt::STATUS_UNKNOWN, 'unknown'),
            'error row' => array(IfirmaReceipt::STATUS_ERROR, 'error'),
            'held row' => array(IfirmaReceipt::STATUS_HELD, 'error'),
            'created row' => array(IfirmaReceipt::STATUS_CREATED, 'error'),
        );
    }

    /** @dataProvider rowStatusesAfterAFailedCreate */
    public function testAFailedCreateIsUnknownOnlyWhenTheRowItLeftIsUnknown($status, $outcome)
    {
        $row = new IfirmaReceipt();
        $row->id_order = 15;
        $row->status = $status;
        $service = $this->getMockBuilder(IfirmaReceiptService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(array('createReceipt'))
            ->getMock();
        $service->method('createReceipt')->willReturnCallback(function () use ($row) {
            // what the real service does before it raises: it leaves its row behind
            $row->save();
            throw new RuntimeException('timeout');
        });
        $this->queueGuardNone();

        $r = $this->pipelineOf(array('service' => $service))->run($this->order());

        $this->assertSame($outcome, $r->outcome);
        $this->assertSame('create', $r->stoppedAt);
        $this->assertSame('timeout', $r->message);
        $this->assertSame('#1 ' . $status . ' module ', $this->receiptOf($r));
        $this->assertSame(array('add'), $this->receiptWriteOps());
    }

    public function testAnExceptionFromTheOverrideLookupStopsTheRunLikeOneFromTheCreate()
    {
        $pipeline = $this->scripted(array('override' => new RuntimeException('override lookup failed')));

        $r = $pipeline->run($this->order());

        $this->assertSame('error', $r->outcome);
        $this->assertSame('create', $r->stoppedAt);
        $this->assertSame('override lookup failed', $r->message);
        $this->assertSame(array('hold:ok:', 'guard:ok:', 'create:stopped:override lookup failed'), $this->stepLines($r));
        $this->assertSame(array('requests.hasPending', 'guard.check', 'requests.getOverride'), $this->traced());
        $this->assertNull($r->receipt);
    }

    public function testTheResolutionOfABypassedGuardIsRecordedOnTheCreatedReceiptAfterTheCreateStep()
    {
        $created = $this->issuedReceipt(false);
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_BYPASSED), 'create' => $created));

        $r = $pipeline->run($this->order(), array('bypass_guard' => true, 'id_employee' => 3));

        $this->assertSame(array(array($created, 'issue_anyway', 3)), $this->callsOf('guard.recordResolution'));
        $this->assertSame(
            array('requests.hasPending', 'guard.check', 'requests.getOverride', 'service.createReceipt', 'guard.recordResolution', 'ksef.skipReason', 'ksef.submit', 'delivery.deliver'),
            $this->traced()
        );
        $this->assertSame(array('hold:ok:', 'guard:ok:bypassed', 'create:ok:', 'number:ok:', 'ksef:ok:', 'deliver:ok:sent'), $this->stepLines($r));
    }

    /** @return array label => array(options, employee as recorded) */
    public function employeeOptions()
    {
        return array(
            'absent' => array(array(), null),
            'null' => array(array('id_employee' => null), null),
            'an integer' => array(array('id_employee' => 3), 3),
            'a numeric string' => array(array('id_employee' => '3'), '3'),
            'zero' => array(array('id_employee' => 0), 0),
        );
    }

    /** @dataProvider employeeOptions */
    public function testTheEmployeeOfAnIssueAnywayRunIsRecordedAsGiven(array $options, $employee)
    {
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_BYPASSED)));

        $pipeline->run($this->order(), $options + array('bypass_guard' => true));

        $calls = $this->callsOf('guard.recordResolution');
        $this->assertCount(1, $calls);
        $this->assertSame($employee, $calls[0][2]);
        $this->assertSame('issue_anyway', $calls[0][1]);
    }

    /** @return array label => array(guard outcome) */
    public function outcomesWithoutAResolution()
    {
        return array(
            'proceed' => array(IfirmaGuardResult::OUTCOME_PROCEED),
            'disabled' => array(IfirmaGuardResult::OUTCOME_DISABLED),
            'not an outcome' => array('not_an_outcome'),
        );
    }

    /** @dataProvider outcomesWithoutAResolution */
    public function testNoResolutionIsRecordedUnlessTheGuardWasBypassed($outcome)
    {
        $this->scripted(array('guard' => $this->guardAnswer($outcome)))->run($this->order(), array('bypass_guard' => true, 'id_employee' => 3));

        $this->assertSame(array(), $this->callsOf('guard.recordResolution'));
    }

    public function testNoResolutionIsRecordedWhenTheCreateFailed()
    {
        $pipeline = $this->scripted(array(
            'guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_BYPASSED),
            'create' => new RuntimeException('bad structure'),
        ));

        $r = $pipeline->run($this->order(), array('bypass_guard' => true, 'id_employee' => 3));

        $this->assertSame('error', $r->outcome);
        $this->assertSame(array(), $this->callsOf('guard.recordResolution'));
        $this->assertSame(array('hold:ok:', 'guard:ok:bypassed', 'create:stopped:bad structure'), $this->stepLines($r));
    }

    public function testAnAdoptedRunRecordsNoResolutionEvenWhenTheGuardWasAskedToBypass()
    {
        $adopted = $this->issuedReceipt(false, array('source' => 'external'));
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_ADOPT, array('receipt' => $adopted))));

        $pipeline->run($this->order(), array('bypass_guard' => true, 'id_employee' => 3));

        $this->assertSame(array(), $this->callsOf('guard.recordResolution'));
        $this->assertSame(array(), $this->callsOf('service.createReceipt'));
    }

    // ---- the number step ------------------------------------------------------------------------------

    public function testTheNumberStepIsOkAndEmptyWhenTheCreatedReceiptHasANumber()
    {
        $r = $this->scripted()->run($this->order());

        $this->assertSame('number:ok:', $this->stepLines($r)[3]);
    }

    /** @return array label => array(ifirma_number) */
    public function missingNumbers()
    {
        return array('empty' => array(''), 'null' => array(null), 'zero' => array('0'));
    }

    /** @dataProvider missingNumbers */
    public function testTheNumberStepSaysSoWhenTheCreatedReceiptHasNoNumberYet($number)
    {
        $pipeline = $this->scripted(array('create' => $this->issuedReceipt(false, array('ifirma_number' => $number))));

        $r = $pipeline->run($this->order());

        $this->assertSame('number:ok:number not yet available', $this->stepLines($r)[3]);
        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('document issued', $r->message);
    }

    // ---- the KSeF step --------------------------------------------------------------------------------

    /** @return array label => array(whether the option is given, its value, whether it skips) */
    public function skipOptions()
    {
        return array(
            'absent' => array(false, null, false),
            'false' => array(true, false, false),
            'zero' => array(true, 0, false),
            'zero as a string' => array(true, '0', false),
            'empty string' => array(true, '', false),
            'null' => array(true, null, false),
            'true' => array(true, true, true),
            'one' => array(true, 1, true),
            'one as a string' => array(true, '1', true),
            'a word' => array(true, 'yes', true),
        );
    }

    /** @dataProvider skipOptions */
    public function testTheSkipKsefOptionSkipsTheStepWithoutAskingKsef($given, $value, $skips)
    {
        $r = $this->scripted()->run($this->order(), $given ? array('skip_ksef' => $value) : array());

        $this->assertSame($skips ? 'ksef:skipped:KSeF skipped by caller' : 'ksef:ok:', $this->stepLines($r)[4]);
        $asked = array_values(array_filter($this->traced(), function ($name) {
            return strpos($name, 'ksef.') === 0;
        }));
        $this->assertSame($skips ? array() : array('ksef.skipReason', 'ksef.submit'), $asked);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('document issued', $r->message);
    }

    public function testAnAdoptedRunWithoutAReceiptSkipsTheKsefStepAsHavingNoDocument()
    {
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_ADOPT)));

        $r = $pipeline->run($this->order(), array('skip_delivery' => true));

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'adopted',
            'stoppedAt' => null,
            'message' => 'existing document adopted',
            'trigger' => 'manual',
            'steps' => array(
                'hold:ok:',
                'guard:ok:existing document adopted',
                'create:skipped:adopted document',
                'number:skipped:adopted document',
                'ksef:skipped:no document',
                'deliver:skipped:delivery skipped by caller',
            ),
            'receipt' => null,
            'guard' => 'adopt',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array('requests.hasPending', 'guard.check'), $this->traced());
    }

    public function testASkipReasonSkipsTheKsefStepWithThatReasonAndSubmitsNothing()
    {
        $r = $this->scripted(array('skipReason' => 'type not supported'))->run($this->order());

        $this->assertSame('ksef:skipped:type not supported', $this->stepLines($r)[4]);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('document issued', $r->message);
        $this->assertSame(array(), $this->callsOf('ksef.submit'));
        $this->assertSame('deliver:ok:sent', $this->stepLines($r)[5]);
    }

    public function testAnAcceptedSubmissionLogsTheKsefStepAsOkWithTheAnswerOfIfirma()
    {
        $r = $this->scripted(array('submit' => array('ok' => true, 'message' => 'Faktura wyslana')))->run($this->order());

        $this->assertSame('ksef:ok:Faktura wyslana', $this->stepLines($r)[4]);
        $this->assertSame('document issued', $r->message);
    }

    public function testAnAnswerWithoutAMessageLogsTheKsefStepAsOkWithAnEmptyMessage()
    {
        $r = $this->scripted(array('submit' => array('ok' => 1)))->run($this->order());

        $this->assertSame('ksef:ok:', $this->stepLines($r)[4]);
        $this->assertSame('document issued', $r->message);
    }

    public function testARefusedSubmissionFailsTheKsefStepAndEndsTheMessageWithTheError()
    {
        $r = $this->scripted(array('submit' => array('ok' => false, 'message' => 'KSeF niedostepny')))->run($this->order());

        $this->assertSame('ksef:failed:KSeF niedostepny', $this->stepLines($r)[4]);
        $this->assertSame('document issued KSeF: KSeF niedostepny', $r->message);
        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertNull($r->stoppedAt);
        $this->assertSame('deliver:ok:sent', $this->stepLines($r)[5]);
    }

    /** @return array label => array(submit answer) */
    public function refusalsWithoutAMessage()
    {
        return array(
            'an empty message' => array(array('ok' => false, 'message' => '')),
            'no message' => array(array('ok' => false)),
            'no flag' => array(array()),
            'a false flag' => array(array('ok' => 0, 'message' => '')),
            'a message that is not text' => array(array('ok' => false, 'message' => false)),
        );
    }

    /** @dataProvider refusalsWithoutAMessage */
    public function testARefusalWithoutAMessageFailsTheStepEmptyAndEndsTheMessageWithTheDefaultError(array $answer)
    {
        $r = $this->scripted(array('submit' => $answer))->run($this->order());

        $this->assertSame('ksef:failed:', $this->stepLines($r)[4]);
        $this->assertSame('document issued KSeF: KSeF submission failed', $r->message);
    }

    public function testAnAdoptedRunKeepsItsMessageBeforeTheKsefError()
    {
        $adopted = $this->issuedReceipt(false, array('source' => 'external'));
        $pipeline = $this->scripted(array(
            'guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_ADOPT, array('receipt' => $adopted)),
            'submit' => array('ok' => false, 'message' => 'KSeF niedostepny'),
        ));

        $r = $pipeline->run($this->order());

        $this->assertSame('adopted', $r->outcome);
        $this->assertSame('existing document adopted KSeF: KSeF niedostepny', $r->message);
    }

    /** @return array label => array(script key of the KSeF call that throws) */
    public function ksefCalls()
    {
        return array('the skip reason' => array('skipReason'), 'the submission' => array('submit'));
    }

    /** @dataProvider ksefCalls */
    public function testAThrowableFromAKsefCallFailsTheKsefStepAndTheRunGoesOn($where)
    {
        $r = $this->scripted(array($where => new RuntimeException('ksef broke')))->run($this->order());

        $this->assertSame(array('hold:ok:', 'guard:ok:', 'create:ok:', 'number:ok:', 'ksef:failed:ksef broke', 'deliver:ok:sent'), $this->stepLines($r));
        $this->assertSame('document issued KSeF: ksef broke', $r->message);
        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertNull($r->stoppedAt);
    }

    /** @return array label => array(throwable) */
    public function throwablesWithoutAMessage()
    {
        return array(
            'Exception' => array(new RuntimeException('')),
            'Error' => array(new TypeError('')),
        );
    }

    /** @dataProvider throwablesWithoutAMessage */
    public function testAThrowableWithoutAMessageFailsTheKsefStepEmptyAndEndsTheMessageWithTheDefaultError(Throwable $failure)
    {
        $r = $this->scripted(array('submit' => $failure))->run($this->order());

        $this->assertSame('ksef:failed:', $this->stepLines($r)[4]);
        $this->assertSame('document issued KSeF: KSeF submission failed', $r->message);
    }

    public function testTheKsefErrorIsAppendedToTheMessageOnceWhateverTheDeliveryDoes()
    {
        $failed = new IfirmaDeliveryResult(false, 'failed', 'shop_mail', 'smtp down');
        $r = $this->scripted(array('submit' => array('ok' => false, 'message' => 'KSeF niedostepny'), 'delivery' => $failed))->run($this->order());

        $this->assertSame('document issued KSeF: KSeF niedostepny', $r->message);
        $this->assertSame('deliver', $r->stoppedAt);
        $this->assertSame('ksef:failed:KSeF niedostepny', $this->stepLines($r)[4]);
        $this->assertSame('deliver:stopped:smtp down', $this->stepLines($r)[5]);
    }

    public function testASuccessfulKsefStepLeavesTheMessageAsItWas()
    {
        $r = $this->scripted(array('submit' => array('ok' => true, 'message' => 'Faktura wyslana')))->run($this->order(), array('skip_delivery' => true));

        $this->assertSame('document issued', $r->message);
    }

    // ---- the deliver step -----------------------------------------------------------------------------

    /** @dataProvider skipOptions */
    public function testTheSkipDeliveryOptionSkipsTheStepWithoutDelivering($given, $value, $skips)
    {
        $r = $this->scripted()->run($this->order(), $given ? array('skip_delivery' => $value) : array());

        $this->assertSame($skips ? 'deliver:skipped:delivery skipped by caller' : 'deliver:ok:sent', $this->stepLines($r)[5]);
        $this->assertCount($skips ? 0 : 1, $this->callsOf('delivery.deliver'));
        $this->assertSame($skips ? null : 'ok|sent|shop_mail|', $this->deliveryOf($r));
        $this->assertNull($r->stoppedAt);
        $this->assertSame('issued', $r->outcome);
    }

    public function testASkippedDeliveryStillRunsTheKsefStep()
    {
        $r = $this->scripted(array('submit' => array('ok' => true, 'message' => 'Faktura wyslana')))->run($this->order(), array('skip_delivery' => true));

        $this->assertSame(array('hold:ok:', 'guard:ok:', 'create:ok:', 'number:ok:', 'ksef:ok:Faktura wyslana', 'deliver:skipped:delivery skipped by caller'), $this->stepLines($r));
        $this->assertSame(
            array('requests.hasPending', 'guard.check', 'requests.getOverride', 'service.createReceipt', 'ksef.skipReason', 'ksef.submit'),
            $this->traced()
        );
    }

    /** @return array label => array(delivery result fields, expected step line) */
    public function sentDeliveries()
    {
        return array(
            'sent' => array(array(true, 'sent', 'shop_mail', ''), 'deliver:ok:sent'),
            'skipped without a reason' => array(array(true, 'skipped', 'none', ''), 'deliver:ok:skipped'),
            'skipped with a reason' => array(array(true, 'skipped', 'shop_mail', 'external document - not auto-delivered'), 'deliver:ok:external document - not auto-delivered'),
            'sent with a message' => array(array(true, 'sent', 'ifirma_mail', 'sent twice'), 'deliver:ok:sent twice'),
        );
    }

    /** @dataProvider sentDeliveries */
    public function testADeliveryThatDidNotFailLogsTheDeliverStepAsOkWithItsMessageOrItsStatus(array $fields, $line)
    {
        $delivery = new IfirmaDeliveryResult($fields[0], $fields[1], $fields[2], $fields[3]);

        $r = $this->scripted(array('delivery' => $delivery, 'submit' => array('ok' => false, 'message' => 'x')))->run($this->order());

        $this->assertSame($line, $this->stepLines($r)[5]);
        $this->assertNull($r->stoppedAt);
        $this->assertSame($delivery, $r->delivery);
        $this->assertSame('document issued KSeF: x', $r->message);
    }

    /** @return array label => array(delivery result fields, expected step line) */
    public function failedDeliveries()
    {
        return array(
            'failed' => array(array(false, 'failed', 'shop_mail', 'smtp down'), 'deliver:stopped:smtp down'),
            'failed without a message' => array(array(false, 'failed', 'shop_mail', ''), 'deliver:stopped:'),
            'disabled' => array(array(false, 'disabled', 'none', 'Delivery is disabled (channel none)'), 'deliver:stopped:Delivery is disabled (channel none)'),
        );
    }

    /** @dataProvider failedDeliveries */
    public function testAFailedDeliveryStopsOnlyTheDeliverStepAndKeepsTheDocument(array $fields, $line)
    {
        $delivery = new IfirmaDeliveryResult($fields[0], $fields[1], $fields[2], $fields[3]);

        $r = $this->scripted(array('delivery' => $delivery))->run($this->order());

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => 'deliver',
            'message' => 'document issued',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:ok:', 'create:ok:', 'number:ok:', 'ksef:ok:', $line),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'proceed',
            'delivery' => ($fields[0] ? 'ok' : 'failed') . '|' . $fields[1] . '|' . $fields[2] . '|' . $fields[3],
        ), $this->snapshot($r));
        $this->assertSame($delivery, $r->delivery);
    }

    public function testAnExceptionFromTheDeliveryBecomesAFailedDeliveryResult()
    {
        $r = $this->scripted(array('delivery' => new RuntimeException('mail server down')))->run($this->order());

        $this->assertSame(array(
            'ok' => true,
            'outcome' => 'issued',
            'stoppedAt' => 'deliver',
            'message' => 'document issued',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:ok:', 'create:ok:', 'number:ok:', 'ksef:ok:', 'deliver:stopped:mail server down'),
            'receipt' => '#1 created module 7/2026',
            'guard' => 'proceed',
            'delivery' => 'failed|failed||mail server down',
        ), $this->snapshot($r));
        $this->assertFalse($r->delivery->isSent());
    }

    public function testAnExceptionFromTheDeliveryWithoutAMessageStopsTheDeliverStepEmpty()
    {
        $r = $this->scripted(array('delivery' => new RuntimeException('')))->run($this->order());

        $this->assertSame('deliver:stopped:', $this->stepLines($r)[5]);
        $this->assertSame('failed|failed||', $this->deliveryOf($r));
        $this->assertSame('deliver', $r->stoppedAt);
    }

    public function testADeliveryIsOnlyAskedForTheReceiptOfTheRunAndTheOrder()
    {
        $order = $this->order();

        $r = $this->scripted()->run($order);

        $calls = $this->callsOf('delivery.deliver');
        $this->assertCount(1, $calls);
        $this->assertSame($r->receipt, $calls[0][0]);
        $this->assertSame($order, $calls[0][1]);
        $this->assertSame(array(), $calls[0][2]);
    }

    // ---- a throwable that escapes the run names the step that was running -------------------------------

    /** @return array label => array(script key, expected stoppedAt, expected steps) */
    public function throwablesAtEachStep()
    {
        return array(
            'the hold check' => array('pending', 'hold', array()),
            'the guard' => array('guard', 'guard', array('hold:ok:')),
            'the override lookup' => array('override', 'create', array('hold:ok:', 'guard:ok:')),
            'the create' => array('create', 'create', array('hold:ok:', 'guard:ok:')),
        );
    }

    /**
     * An Error is no Exception: the create step does not catch it, so it reaches the run's own guard, which
     * names the step by the number of entries the step log holds by then.
     *
     * @dataProvider throwablesAtEachStep
     */
    public function testAnErrorFromAStepFailsTheRunAndNamesTheStepThatWasRunning($where, $step, array $steps)
    {
        $pipeline = $this->scripted(array($where => new TypeError('step broke')));

        $r = $pipeline->run($this->order());

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'error',
            'stoppedAt' => $step,
            'message' => 'step broke',
            'trigger' => 'manual',
            'steps' => $steps,
            'receipt' => '#1 error module ',
            // the guard result is kept once the guard answered, so not when the guard itself raised
            'guard' => in_array($where, array('pending', 'guard'), true) ? null : 'proceed',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array('add'), $this->receiptWriteOps());
        $this->assertSame('iFirma pipeline: step broke', PrestaShopLogger::$logs[0]['message']);
    }

    public function testAnErrorFromTheDeliveryFailsTheRunAtTheDeliverStepWithAllEarlierStepsLogged()
    {
        $r = $this->scripted(array('delivery' => new TypeError('mail transport crashed'), 'submit' => array('ok' => false, 'message' => 'KSeF niedostepny')))->run($this->order());

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'error',
            'stoppedAt' => 'deliver',
            'message' => 'mail transport crashed',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:ok:', 'create:ok:', 'number:ok:', 'ksef:failed:KSeF niedostepny'),
            'receipt' => '#2 error module ',
            'guard' => 'proceed',
            'delivery' => null,
        ), $this->snapshot($r));
    }

    public function testAnExceptionFromTheResolutionRecordingFailsTheRunAtTheNumberStep()
    {
        $pipeline = $this->scripted(array(
            'guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_BYPASSED),
            'resolution' => new RuntimeException('resolution not stored'),
        ));

        $r = $pipeline->run($this->order(), array('bypass_guard' => true, 'id_employee' => 3));

        $this->assertSame(array(
            'ok' => false,
            'outcome' => 'error',
            'stoppedAt' => 'number',
            'message' => 'resolution not stored',
            'trigger' => 'manual',
            'steps' => array('hold:ok:', 'guard:ok:bypassed', 'create:ok:'),
            'receipt' => '#2 error module ',
            'guard' => 'bypassed',
            'delivery' => null,
        ), $this->snapshot($r));
        $this->assertSame(array(), $this->callsOf('ksef.skipReason'));
        $this->assertSame(array(), $this->callsOf('delivery.deliver'));
    }

    public function testAnErrorFromTheDataRequestLookupOfTheHeldRunFailsItAtTheHoldStep()
    {
        $pipeline = $this->scripted(array('pending' => true, 'pendingRequest' => new TypeError('request lookup broke')));

        $r = $pipeline->run($this->order());

        $this->assertSame('error', $r->outcome);
        $this->assertSame('hold', $r->stoppedAt);
        $this->assertSame('request lookup broke', $r->message);
        $this->assertSame(array(), $r->steps);
        $this->assertSame(array('requests.hasPending', 'requests.getPending'), $this->traced());
    }

    public function testAFailureWhileHoldingNamesTheHoldStepBecauseItWasNotLoggedYet()
    {
        // the second receipt lookup is the hold step's own, for the row it holds
        $req = new IfirmaDataRequest();
        $req->id = 8;
        $this->lookupFailingOn(2, new RuntimeException('row lookup broke'));
        $pipeline = $this->scripted(array('pending' => true, 'pendingRequest' => $req));

        $r = $pipeline->run($this->order());

        $this->assertSame('error', $r->outcome);
        $this->assertSame('hold', $r->stoppedAt);
        $this->assertSame('row lookup broke', $r->message);
        $this->assertSame(array(), $r->steps);
        $this->assertSame('#1 error module ', $this->receiptOf($r));
    }

    public function testAFailureWhileRecordingAnInconclusiveGuardNamesTheGuardStepBecauseItWasNotLoggedYet()
    {
        // the second receipt lookup is the one of the row that records the guard's error
        $this->lookupFailingOn(2, new RuntimeException('row lookup broke'));
        $pipeline = $this->scripted(array('guard' => $this->guardAnswer(IfirmaGuardResult::OUTCOME_INCONCLUSIVE, array('error' => 'list request failed'))));

        $r = $pipeline->run($this->order());

        $this->assertSame('error', $r->outcome);
        $this->assertSame('guard', $r->stoppedAt);
        $this->assertSame('row lookup broke', $r->message);
        $this->assertSame(array('hold:ok:'), $this->stepLines($r));
        $this->assertSame('#1 error module ', $this->receiptOf($r));
        $this->assertSame(array('add'), $this->receiptWriteOps());
    }

    public function testAFailureOfTheReceiptLookupAfterAFailedCreateNamesTheCreateStepBecauseItWasNotLoggedYet()
    {
        // lookups: the hold step's (1st), then the one that reads the row the failed create left (2nd)
        $this->lookupFailingOn(2, new RuntimeException('row lookup broke'));
        $pipeline = $this->scripted(array('create' => new RuntimeException('bad structure')));

        $r = $pipeline->run($this->order());

        $this->assertSame('error', $r->outcome);
        $this->assertSame('create', $r->stoppedAt);
        $this->assertSame('row lookup broke', $r->message);
        $this->assertSame(array('hold:ok:', 'guard:ok:'), $this->stepLines($r));
    }

    // ---- the shape of the step log --------------------------------------------------------------------

    public function testEveryStepEntryHasExactlyTheThreeKeysAndAStringMessage()
    {
        $r = $this->scripted(array('submit' => array('ok' => false, 'message' => 'KSeF niedostepny'), 'delivery' => new IfirmaDeliveryResult(false, 'failed', 'shop_mail', 'smtp down')))->run($this->order());

        $this->assertCount(6, $r->steps);
        foreach ($r->steps as $entry) {
            $this->assertSame(array('step', 'status', 'message'), array_keys($entry));
            $this->assertIsString($entry['message']);
        }
    }
}
