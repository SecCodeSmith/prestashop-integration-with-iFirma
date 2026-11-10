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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaIssueQueueJob.php';

/**
 * The cron job that issues queued orders (spec receipt-issuance-queue "Cron issues queued orders").
 */
class IssueQueueJobTest extends TestCase
{
    const PDF = "%PDF-1.4\nfake body\n%%EOF";

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaIssuancePipeline */
    private $pipeline;

    /** @var bool whether the permanent empty answer of the guard list is in place */
    private $guardListScripted = false;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 5);
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        Configuration::updateValue('IFIRMA_USER', 'u@example.com');
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', str_repeat('1', 64));
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', 1);

        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $module = new Module();
        $service = new IfirmaReceiptService($this->client, $module);
        $this->pipeline = new IfirmaIssuancePipeline(
            $service,
            new IfirmaDuplicateGuard($this->client),
            new IfirmaDeliveryService($this->client, $service, $module, null, function () {
            }),
            new IfirmaDataRequestService($module),
            $module,
            null,
            new IfirmaKsefService($this->client, $module)
        );

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

        // the queue as the database would list it: queued rows, oldest first (date_upd, then id)
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`\s+WHERE status = \'queued\'/', function ($sql) {
            $limit = preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : 500;
            $rows = array();
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ($row['status'] === 'queued') {
                    $rows[] = array('id_ifirma_receipt' => (string) $id, 'date_upd' => $row['date_upd'], 'id' => $id);
                }
            }
            usort($rows, function ($a, $b) {
                return strcmp($a['date_upd'], $b['date_upd']) ?: $a['id'] - $b['id'];
            });

            return array_slice($rows, 0, $limit);
        });
        Db::getInstance()->whenGetValue('/SELECT COUNT\(\*\) FROM `ps_ifirma_receipt` WHERE status = \'queued\'/', function () {
            $n = 0;
            foreach (IfirmaTestState::objects('ifirma_receipt') as $row) {
                if ($row['status'] === 'queued') {
                    $n++;
                }
            }

            return $n;
        });
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

    private function order($id)
    {
        $o = new Order();
        $o->id = $id;
        $o->id_customer = 5;
        $o->id_shop = 1;
        $o->id_lang = 1;
        $o->id_currency = 1;
        $o->id_address_invoice = 7;
        $o->reference = 'REF' . $id;
        $o->date_add = date('Y-m-d H:i:s', time() - 86400);
        $o->total_paid_tax_incl = 100.00 + $id;
        $o->details = array(array(
            'product_name' => 'W',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 100 + $id,
            'unit_price_tax_excl' => 100 + $id,
            'tax_rate' => 0,
        ));
        Order::register($o);

        return $o;
    }

    /** A queued record for the order; $age orders the queue (smaller = older). */
    private function queued($idOrder, $age)
    {
        $this->order($idOrder);
        $row = new IfirmaReceipt();
        $row->id_order = $idOrder;
        $row->id_customer = 5;
        $row->id_shop = 1;
        $row->markQueued('refused earlier');
        $row->date_upd = date('Y-m-d H:i:s', mktime(9, 0, 0, 11, 1, 2026) + $age);
        $row->save(false, false);
        IfirmaTestState::clearWrites();

        return $row;
    }

    private function job($maxItems = 10)
    {
        return new IfirmaIssueQueueJob($this->pipeline, function () {
            return false;
        });
    }

    private function context($maxItems = 10)
    {
        return new IfirmaCronContext($maxItems, function () {
            return mktime(10, 0, 0, 11, 1, 2026);
        });
    }

    /** Scripts a whole successful issuance of the order with the given iFirma id. */
    private function scriptIssuance($idOrder, $ifirmaId)
    {
        // the guard lists with the contractor filter and then without it: neither finds anything of these orders
        if (!$this->guardListScripted) {
            $this->guardListScripted = true;
            $this->t->queue('GET', '#faktury\.json#', function () {
                return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array()))));
            });
        }
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'Informacja' => 'ok', 'Identyfikator' => (string) $ifirmaId, 'PelnyNumer' => $ifirmaId . '/2026',
        )))));
    }

    private function statuses()
    {
        $out = array();
        foreach (IfirmaTestState::objects('ifirma_receipt') as $row) {
            $out[(int) $row['id_order']] = $row['status'];
        }
        ksort($out);

        return $out;
    }

    private function postedOrderStamps()
    {
        $stamps = array();
        foreach ($this->t->calls as $call) {
            if ($call['method'] === 'POST') {
                preg_match('/REF(\d+)/', $call['body'], $m);
                $stamps[] = (int) $m[1];
            }
        }

        return $stamps;
    }

    public function testTheQueueIsDrainedOldestFirst()
    {
        $this->queued(41, 20);
        $this->queued(40, 10);
        $this->queued(42, 30);
        foreach (array(40 => 140, 41 => 141, 42 => 142) as $order => $id) {
            $this->scriptIssuance($order, $id);
        }

        $report = $this->job()->run($this->context());

        $this->assertSame(array(40, 41, 42), $this->postedOrderStamps());
        $this->assertSame(array(40 => 'created', 41 => 'created', 42 => 'created'), $this->statuses());
        $this->assertSame(array('handled' => 3, 'succeeded' => 3, 'failed' => 0, 'left' => 0), $report->block());
        $this->assertFalse($report->isRateLimited());
    }

    public function testOneRunHandlesAtMostTheItemCap()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->queued(50 + $i, $i);
            $this->scriptIssuance(50 + $i, 150 + $i);
        }

        $report = $this->job()->run($this->context(2));

        $this->assertSame(array(51, 52), $this->postedOrderStamps());
        $this->assertSame(array('handled' => 2, 'succeeded' => 2, 'failed' => 0, 'left' => 3), $report->block());
    }

    public function testARefusalEndsTheDrainAndLeavesTheRestQueuedInTheirPlace()
    {
        $fixed = mktime(10, 15, 20, 11, 1, 2026);
        $this->client->setClock(function () use ($fixed) {
            return $fixed;
        });
        $this->queued(60, 1);
        $this->queued(61, 2);
        $this->queued(62, 3);
        $this->scriptIssuance(60, 160);
        // the day cap is reached after the first order: the guard check of the second is refused
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_DAY', 7);
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd', $fixed) . ':3');

        $report = $this->job()->run($this->context());

        $this->assertSame(array(60 => 'created', 61 => 'queued', 62 => 'queued'), $this->statuses());
        $this->assertTrue($report->isRateLimited());
        $this->assertSame(IfirmaRateDecision::WINDOW_DAY, $report->getRateWindow());
        $this->assertSame(array('handled' => 2, 'succeeded' => 1, 'failed' => 0, 'left' => 2), $report->block());
        $this->assertSame(array(60), $this->postedOrderStamps(), 'no request after the refusal');
    }

    public function testADocumentCreatedByAnEarlierRefusedRequestIsAdopted()
    {
        $this->queued(70, 1);
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array(array(
            'FakturaId' => 77, 'IdentyfikatorKontrahenta' => 'PS-C5', 'PelnyNumer' => '7/2026',
            'DataWystawienia' => date('Y-m-d'), 'Brutto' => 170.00, 'Zaplacono' => 0, 'KontrahentNazwa' => 'Jan K',
            'Rodzaj' => 'prz_faktura_kraj', 'Waluta' => 'PLN', 'CzySzkic' => false, 'CzyWyslano' => false,
        )))))));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Uwagi":"PS-ORDER-70-REF70"}}'));

        $report = $this->job()->run($this->context());

        $this->assertSame(array(), $this->postedOrderStamps(), 'no second document is created');
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 0), $report->block());
    }

    public function testAnOrderIfirmaRejectsLeavesTheQueueAsAnError()
    {
        $this->queued(80, 1);
        $this->t->queue('GET', '#faktury\.json#', function () {
            return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array()))));
        });
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"bad data"}}'));

        $report = $this->job()->run($this->context());

        $this->assertSame(array(80 => 'error'), $this->statuses());
        $this->assertSame(array('handled' => 1, 'succeeded' => 0, 'failed' => 1, 'left' => 0), $report->block());
    }

    public function testAnOrderWhoseLockIsBusyIsLeftQueuedAndNotCounted()
    {
        $this->queued(90, 1);
        $this->queued(91, 2);
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\(\'ifirma_order_90\'/', 0);
        $this->scriptIssuance(91, 191);

        $report = $this->job()->run($this->context());

        $this->assertSame(array(90 => 'queued', 91 => 'created'), $this->statuses());
        $this->assertSame(array(91), $this->postedOrderStamps());
        $this->assertSame(array('handled' => 1, 'succeeded' => 1, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testAnOrderWhoseFirstRequestEndedBeforeTheCreateIsIssuedByTheNextCronRunExactlyOnce()
    {
        $this->queued(92, 1);
        $this->scriptIssuance(92, 192);

        $firstRun = $this->job()->run($this->context());
        $secondRun = $this->job()->run($this->context());

        $this->assertSame(array(92), $this->postedOrderStamps(), 'one create request in all');
        $this->assertSame(array(92 => 'created'), $this->statuses());
        $this->assertSame(1, $firstRun->block()['succeeded']);
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 0), $secondRun->block());
    }

    public function testAQueuedOrderWhoseDocumentAlreadyExistsInIfirmaIsAdoptedAndNotCreatedAgain()
    {
        $this->queued(70, 1);
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array(array(
            'FakturaId' => 77, 'IdentyfikatorKontrahenta' => 'PS-C5', 'PelnyNumer' => '7/2026',
            'DataWystawienia' => date('Y-m-d'), 'Brutto' => 170.00, 'Zaplacono' => 0, 'KontrahentNazwa' => 'Jan K',
            'Rodzaj' => 'prz_faktura_kraj', 'Waluta' => 'PLN', 'CzySzkic' => false, 'CzyWyslano' => false,
        )))))));
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Uwagi":"PS-ORDER-70-REF70"}}'));

        $this->job()->run($this->context());

        $creates = array_filter($this->t->calls, function ($call) {
            return $call['method'] === 'POST';
        });
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertCount(0, $creates, 'no create request is sent');
        $this->assertSame('77', (string) $row['ifirma_id']);
        $this->assertNotSame('queued', $row['status']);
    }

    public function testAStatusChangeWhileTheDrainHoldsTheOrderLockIssuesNothingAndTheOrderGetsOneDocument()
    {
        $this->emulateOrderLocks();
        $this->queued(93, 1);
        $hook = null;
        $self = $this;
        $this->t->queue('GET', '#faktury\.json#', function () {
            return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array()))));
        });
        $this->t->queue('POST', '#fakturakraj\.json#', function () use (&$hook, $self) {
            $hook = $self->pipeline->onOrderStatusChange(93, 5);

            return array('body' => json_encode(array('response' => array(
                'Kod' => 0, 'Informacja' => 'ok', 'Identyfikator' => '193', 'PelnyNumer' => '193/2026',
            ))));
        });

        $this->job()->run($this->context());

        $this->assertNull($hook, 'the status change finds the order lock busy and does nothing');
        $this->assertSame(array(93), $this->postedOrderStamps(), 'exactly one document is created');
        $this->assertSame(array(93 => 'created'), $this->statuses());
    }

    public function testTheDrainWhileAStatusChangeHoldsTheOrderLockLeavesTheOrderAndTheOrderGetsOneDocument()
    {
        $this->emulateOrderLocks();
        $this->order(94);
        $drain = null;
        $self = $this;
        $this->t->queue('GET', '#faktury\.json#', function () use (&$drain, $self) {
            $drain = $self->job()->run($self->context());

            return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array()))));
        });
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'Informacja' => 'ok', 'Identyfikator' => '194', 'PelnyNumer' => '194/2026',
        )))));

        $this->pipeline->onOrderStatusChange(94, 5);

        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $drain->block());
        $this->assertSame(array(94), $this->postedOrderStamps(), 'exactly one document is created');
        $this->assertSame(array(94 => 'created'), $this->statuses());
    }

    /** Order locks as MySQL grants them: a lock that is held is refused to the next taker until it is released. */
    private function emulateOrderLocks()
    {
        $held = array();
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\(\'(\w+)\'/', function ($sql, $m) use (&$held) {
            if (isset($held[$m[1]])) {
                return 0;
            }
            $held[$m[1]] = true;

            return 1;
        });
        Db::getInstance()->whenGetValue('/^SELECT RELEASE_LOCK\(\'(\w+)\'/', function ($sql, $m) use (&$held) {
            unset($held[$m[1]]);

            return 1;
        });
    }

    public function testTheOrderLockIsTakenWithoutWaitingAndReleased()
    {
        $this->queued(95, 1);
        $this->scriptIssuance(95, 195);
        $asked = array();
        Db::getInstance()->whenGetValue('/^SELECT GET_LOCK\(\'ifirma_order_95\'/', function ($sql) use (&$asked) {
            $asked[] = $sql;

            return 1;
        });

        $this->job()->run($this->context());

        $this->assertSame(array("SELECT GET_LOCK('ifirma_order_95', 0)"), $asked);
        $released = array_filter(Db::getInstance()->queries, function ($sql) {
            return strpos($sql, "RELEASE_LOCK('ifirma_order_95')") !== false;
        });
        $this->assertCount(1, $released);
    }

    public function testAQueuedRecordOfAnOrderThatNoLongerExistsLeavesTheQueue()
    {
        $row = new IfirmaReceipt();
        $row->id_order = 999;
        $row->id_customer = 5;
        $row->id_shop = 1;
        $row->markQueued('');
        $row->save();

        $report = $this->job()->run($this->context());

        $this->assertSame(array(999 => 'error'), $this->statuses());
        $this->assertSame(array('handled' => 1, 'succeeded' => 0, 'failed' => 1, 'left' => 0), $report->block());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testNothingIsDoneWhileTheCredentialsArePaused()
    {
        $this->queued(96, 1);
        $job = new IfirmaIssueQueueJob($this->pipeline, function () {
            return true;
        });

        $report = $job->run($this->context());

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(96 => 'queued'), $this->statuses());
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $report->block());
    }

    public function testAnEmptyQueueIsAnEmptyReport()
    {
        $report = $this->job()->run($this->context());

        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 0), $report->block());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testASkippedJobReportsZerosButStillCountsTheQueue()
    {
        $this->queued(97, 1);

        $report = $this->job()->skip($this->context());

        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 1), $report->block());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheQueueJobStartsNoOrderOnceTheTimeBudgetIsUsedUp()
    {
        $this->queued(90, 1);
        $this->queued(91, 2);
        $this->scriptIssuance(90, 910);
        $this->scriptIssuance(91, 911);
        $now = mktime(10, 0, 0, 11, 1, 2026);
        $context = new IfirmaCronContext(10, function () use (&$now) {
            return $now;
        }, 45);
        $stepping = new IfirmaIssueQueueJob($this->pipeline, function () use (&$now) {
            $now += 50;

            return false;
        });

        $report = $stepping->run($context);

        $this->assertSame(1, $report->block()['handled'], 'the second order did not start');
        $this->assertSame(1, $report->block()['left']);
    }
}
