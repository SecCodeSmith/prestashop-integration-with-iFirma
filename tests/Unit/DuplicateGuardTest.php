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

class DuplicateGuardTest extends TestCase
{
    use IfirmaContractorMapDb;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaDuplicateGuard */
    private $guard;

    /** @var IfirmaApiClient */
    private $client;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->client = $client;
        $this->guard = new IfirmaDuplicateGuard($client);
        $this->scriptContractorMapDb();

        $cur = new Currency();
        $cur->id = 1;
        $cur->iso_code = 'PLN';
        Currency::register($cur);
        $a = new Address();
        $a->id = 7;
        $a->id_country = 14;
        Address::register($a);

        // IfirmaReceipt::getByOrder() runs raw SQL; answer it from the in-memory registry.
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

    private function order($daysAgo = 1)
    {
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_shop = 1;
        $o->id_currency = 1;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = date('Y-m-d H:i:s', time() - $daysAgo * 86400);
        $o->total_paid_tax_incl = 123.00;
        $o->details = array(array(
            'product_name' => 'Produkt A',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 123.00,
            'unit_price_tax_excl' => 100.00,
            'tax_rate' => 23,
        ));

        return $o;
    }

    /** Sale date of order(): the order date. */
    private function saleDate()
    {
        return date('Y-m-d', time() - 86400);
    }

    /** Detail of a document of the buyer without the order reference, matching sale date and lines. */
    private function detailNoRef(array $over = array())
    {
        $node = array_merge(array(
            'Uwagi' => 'other',
            'DataSprzedazy' => $this->saleDate(),
            'FormatDatySprzedazy' => 'DZN',
            'Pozycje' => array(array('NazwaPelna' => 'Produkt A', 'Ilosc' => 1, 'CenaJednostkowa' => 123.0)),
        ), $over);

        return json_encode(array('response' => $node));
    }

    private function receiptRow()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return reset($rows);
    }

    private function recorded()
    {
        return json_decode($this->receiptRow()['duplicate_candidates'], true);
    }

    private function nip()
    {
        $a = new Address();
        $a->id = 7;
        $a->vat_number = 'PL 123-456-78-90';
        Address::register($a);
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
            'Rodzaj' => 'prz_faktura_paragon',
            'Waluta' => 'PLN',
            'CzySzkic' => false,
            'CzyWyslano' => false,
        ), $over);
    }

    private function queueList(array $rows)
    {
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => $rows)))));
    }

    private function queueDetail($type, $id, $body)
    {
        $this->t->queue('GET', '#' . $type . '/' . $id . '\.json#', array('body' => $body));
    }

    private function urls()
    {
        $u = array();
        foreach ($this->t->calls as $c) {
            $u[] = urldecode($c['url']);
        }

        return $u;
    }

    private function detailWithRef()
    {
        return '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI"}}';
    }

    public function testLocalIssuedNoRequest()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->ifirma_id = '99';
        $r->status = IfirmaReceipt::STATUS_CREATED;
        $r->save();

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_LOCAL_ISSUED, $res->outcome);
        $this->assertCount(0, $this->t->calls);
        $this->assertFalse($res->allowsCreation());
    }

    public function testErroredLocalRecordRunsLookup()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->ifirma_id = '';
        $r->status = IfirmaReceipt::STATUS_ERROR;
        $r->save();
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertNotEmpty($this->t->calls);
        $this->assertTrue($res->allowsCreation());
    }

    public function testDisabledNoRequest()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_CHECK', '0');
        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_DISABLED, $res->outcome);
        $this->assertCount(0, $this->t->calls);
        $this->assertTrue($res->allowsCreation());
    }

    public function testBypassNoRequest()
    {
        $res = $this->guard->check($this->order(), array('bypass' => true));
        $this->assertSame(IfirmaGuardResult::OUTCOME_BYPASSED, $res->outcome);
        $this->assertCount(0, $this->t->calls);
        $this->assertTrue($res->allowsCreation());
    }

    public function testContractorQueryWithRowsStopsThere()
    {
        $this->nip();
        $this->queueList(array($this->row(array('Brutto' => 5))));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount(1, $this->t->calls);
        $u = $this->urls();
        $this->assertStringContainsString('kontrahent=PS-C5', $u[0]);
        $this->assertStringContainsString('dataOd=' . date('Y-m-d', time() - 2 * 86400), $u[0]);
        $this->assertStringContainsString('dataDo=' . date('Y-m-d'), $u[0]);
        $this->assertStringContainsString('iloscNaStronie=100', $u[0]);
        $this->assertStringNotContainsString('nipKontrahenta', $u[0]);
    }

    public function testFallbackToNipThenUnfiltered()
    {
        $this->nip();
        $this->queueList(array());
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame(3, $res->queries);
        $u = $this->urls();
        $this->assertStringContainsString('kontrahent=PS-C5', $u[0]);
        $this->assertStringContainsString('nipKontrahenta=1234567890', $u[1]);
        $this->assertStringNotContainsString('kontrahent=', $u[2]);
        $this->assertStringNotContainsString('nipKontrahenta', $u[2]);
        $this->assertStringContainsString('dataOd=', $u[2]);
    }

    public function testNoNipSkipsNipQuery()
    {
        $this->queueList(array());
        $this->queueList(array());

        $this->guard->check($this->order());
        $this->assertCount(2, $this->t->calls);
        foreach ($this->urls() as $u) {
            $this->assertStringNotContainsString('nipKontrahenta', $u);
        }
    }

    public function testWindowTooLargeSkipsUnfiltered()
    {
        $this->queueList(array());

        $res = $this->guard->check($this->order(90));
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount(1, $this->t->calls);
        $this->assertStringContainsString('kontrahent=', $this->urls()[0]);
    }

    public function testWindowDaysOptionOverride()
    {
        $this->queueList(array());
        $this->queueList(array());
        $this->guard->check($this->order(90), array('window_days' => 120));
        $this->assertCount(2, $this->t->calls);
    }

    public function testPaging()
    {
        $page1 = array();
        for ($i = 1; $i <= 100; ++$i) {
            $page1[] = $this->row(array('FakturaId' => $i, 'Brutto' => 1));
        }
        $this->queueList($page1);
        $this->queueList(array($this->row(array('FakturaId' => 200, 'Brutto' => 1))));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertCount(2, $this->t->calls);
        $u = $this->urls();
        $this->assertStringNotContainsString('strona=', $u[0]);
        $this->assertStringContainsString('strona=2', $u[1]);
        $this->assertStringContainsString('kontrahent=PS-C5', $u[1]);
    }

    public function testAdoptPaid()
    {
        $this->queueList(array($this->row(array('Zaplacono' => 123.00, 'CzyWyslano' => true))));
        $this->queueDetail('fakturaparagon', 77, $this->detailWithRef());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame(77, $res->candidate['FakturaId']);
        $this->assertNotNull($res->receipt);
        $this->assertCount(2, $this->t->calls);
        foreach ($this->t->calls as $c) {
            $this->assertSame('GET', $c['method']);
        }

        $rows = IfirmaTestState::objects('ifirma_receipt');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('77', (string) $row['ifirma_id']);
        $this->assertSame('7/2026', $row['ifirma_number']);
        $this->assertSame('fakturaparagon', $row['document_type']);
        $this->assertSame('external', $row['source']);
        $this->assertSame('paid', $row['status']);
        $this->assertSame('skipped', $row['delivery_status']);
        $this->assertSame('', $row['delivery_channel']);
        $this->assertSame('external document - not auto-delivered', $row['delivery_error']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $row['order_reference']);
        $this->assertEquals(123.0, $row['remote_paid_amount']);
        $this->assertNotEmpty($row['security_token']);
        $this->assertSame(15, (int) $row['id_order']);
        $data = json_decode($row['duplicate_candidates'], true);
        $this->assertSame('adopt', $data['resolution']['action']);
        $this->assertSame('77', $data['candidates'][0]['id']);
    }

    public function testAdoptUnpaidIsCreatedAndReusesExistingRecord()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->ifirma_id = '';
        $old->status = IfirmaReceipt::STATUS_ERROR;
        $old->security_token = 'keep-me';
        $old->save();

        $this->queueList(array($this->row(array('Rodzaj' => 'prz_faktura_kraj', 'Zaplacono' => 10))));
        $this->queueDetail('fakturakraj', 77, $this->detailWithRef());

        $this->guard->check($this->order());
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('created', $row['status']);
        $this->assertSame('fakturakraj', $row['document_type']);
        $this->assertSame('keep-me', $row['security_token']);
    }

    public function testSuspectedWhenReferenceMissing()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertTrue($res->isBlocking());
        $this->assertFalse($res->allowsCreation());
        $this->assertCount(1, $res->candidates);
        foreach ($this->t->calls as $c) {
            $this->assertNotSame('POST', $c['method']);
        }

        $rows = IfirmaTestState::objects('ifirma_receipt');
        $row = reset($rows);
        $this->assertSame('duplicate_suspected', $row['status']);
        $this->assertSame('', (string) $row['ifirma_id']);
        $this->assertSame('module', $row['source']);
        $this->assertStringContainsString('1 matching document', $row['last_error']);
        $data = json_decode($row['duplicate_candidates'], true);
        $this->assertSame('77', $data['candidates'][0]['id']);
        $this->assertSame('7/2026', $data['candidates'][0]['number']);
        $this->assertSame('Jan K', $data['candidates'][0]['contractor']);
        $this->assertSame($this->saleDate(), $data['candidates'][0]['sale_date']);
        $this->assertSame('identifier', $data['candidates'][0]['matched_by']);
        $this->assertNull($data['resolution']);
    }

    public function testDetailFailureStillSuspected()
    {
        $this->queueList(array($this->row()));
        $this->t->queue('GET', '#fakturaparagon/77\.json#', array('status' => 500, 'body' => ''));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertNotSame('', $res->error);
        $data = $this->recorded();
        $this->assertCount(1, $data['candidates']);
        $this->assertSame('', $data['candidates'][0]['sale_date']);
    }

    public function testNewestCandidateConfirmedFirst()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 70, 'DataWystawienia' => '2026-01-01')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-03')),
        ));
        $this->queueDetail('fakturaparagon', 80, $this->detailWithRef());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $this->assertSame(80, $res->candidate['FakturaId']);
        $this->assertCount(2, $this->t->calls);
    }

    public function testInconclusiveOnListFailure()
    {
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'timeout'));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_INCONCLUSIVE, $res->outcome);
        $this->assertNotSame('', $res->error);
        $this->assertFalse($res->rateLimited);
        $this->assertTrue($res->isBlocking());
        $this->assertFalse($res->allowsCreation());
        $this->assertCount(1, $this->t->calls);
    }

    public function testInconclusiveRateLimited()
    {
        // Local rate guard: the cap for the current minute is already used up.
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 1);
        $fixed = 1700000000;
        $this->client->setClock(function () use ($fixed) {
            return $fixed;
        });
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $fixed) . ':1');
        $this->queueList(array());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_INCONCLUSIVE, $res->outcome);
        $this->assertTrue($res->rateLimited);
    }

    public function testLinkCandidateAndResolution()
    {
        $this->queueList(array($this->row(array('Zaplacono' => 123))));
        $this->queueDetail('fakturaparagon', 77, '{"x":1}');
        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);

        $r = $this->guard->recordResolution($res->receipt, 'issue_anyway', 3);
        $data = json_decode($r->duplicate_candidates, true);
        $this->assertSame('issue_anyway', $data['resolution']['action']);
        $this->assertSame(3, $data['resolution']['id_employee']);
        $this->assertCount(1, $data['candidates']);

        $linked = $this->guard->linkCandidate($this->order(), $res->receipt, '77', 4);
        $this->assertSame('external', $linked->source);
        $this->assertSame('paid', $linked->status);
        $this->assertSame('77', (string) $linked->ifirma_id);
        $data = json_decode($linked->duplicate_candidates, true);
        $this->assertSame('link', $data['resolution']['action']);
        $this->assertSame(4, $data['resolution']['id_employee']);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testLinkUnknownCandidateThrows()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, '{"x":1}');
        $res = $this->guard->check($this->order());

        $this->expectException(Exception::class);
        $this->guard->linkCandidate($this->order(), $res->receipt, '999', 4);
    }

    private function unknownContractorResponse()
    {
        return array('body' => '{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta o zadanym identyfikatorze"}}');
    }

    public function testUnknownContractorFilterIsSkippedAndNipAndUnfilteredStillRun()
    {
        $this->nip();
        $this->t->queue('GET', '#faktury\.json#', $this->unknownContractorResponse());
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame('', $res->error);
        $this->assertCount(3, $this->t->calls);
        $u = $this->urls();
        $this->assertStringContainsString('kontrahent=PS-C5', $u[0]);
        $this->assertStringContainsString('nipKontrahenta=1234567890', $u[1]);
        $this->assertStringNotContainsString('kontrahent=', $u[2]);
        $this->assertStringNotContainsString('nipKontrahenta', $u[2]);
    }

    public function testUnknownContractorFilterWithoutNipUsesUnfilteredWindow()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->unknownContractorResponse());
        $this->queueList(array());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertSame('', $res->error);
        $this->assertCount(2, $this->t->calls);
        $u = $this->urls();
        $this->assertStringContainsString('kontrahent=PS-C5', $u[0]);
        $this->assertStringNotContainsString('kontrahent=', $u[1]);
    }

    public function testStoredMappingIsUsedAsContractorFilter()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', IfirmaContractorMap::BY_NIP);
        $this->queueList(array());
        $this->queueList(array());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $u = $this->urls();
        $this->assertStringContainsString('kontrahent=KOWALSKI-01', $u[0]);
        $this->assertStringNotContainsString('PS-C', $u[0]);
    }

    public function testStoredMappingWithSpaceIsUrlEncoded()
    {
        IfirmaContractorMap::link(5, 'EC_JACEK ORZECHO', IfirmaContractorMap::BY_NAME);
        $this->queueList(array());
        $this->queueList(array());

        $this->guard->check($this->order());
        $this->assertStringNotContainsString(' ', $this->t->calls[0]['url']);
        $u = $this->urls();
        $this->assertStringContainsString('kontrahent=EC_JACEK ORZECHO', $u[0]);
    }

    public function testOtherKod201OnFilteredQueryIsInconclusive()
    {
        $this->t->queue('GET', '#faktury\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Nieprawidłowy format daty"}}'));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_INCONCLUSIVE, $res->outcome);
        $this->assertStringContainsString('Nieprawidłowy format daty', $res->error);
        $this->assertCount(1, $this->t->calls);
        $this->assertTrue($res->isBlocking());
    }

    public function testUnknownContractorOnUnfilteredQueryIsNotExcused()
    {
        $this->queueList(array());
        $this->t->queue('GET', '#faktury\.json#', $this->unknownContractorResponse());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_INCONCLUSIVE, $res->outcome);
        $this->assertStringContainsString('Nie znaleziono kontrahenta', $res->error);
        $this->assertCount(2, $this->t->calls);
        $this->assertFalse($res->allowsCreation());
    }

    // ---- buyer filter and detail verification ------------------------------------------

    public function testOtherBuyerRowIsNotACandidateAndNeedsNoDetail()
    {
        $this->queueList(array($this->row(array('IdentyfikatorKontrahenta' => 'PS-C9', 'NIPKontrahenta' => '5555555555', 'KontrahentNazwa' => 'Anna Nowak'))));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertTrue($res->allowsCreation());
        $this->assertCount(1, $this->t->calls);
        $this->assertCount(0, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testSameProductsOnAnotherSaleDateProceeds()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef(array('DataSprzedazy' => date('Y-m-d', time() - 2 * 86400))));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $res->outcome);
        $this->assertTrue($res->allowsCreation());
        $this->assertCount(2, $this->t->calls);
        $this->assertCount(0, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testDifferentProductProceeds()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef(array('Pozycje' => array(array('NazwaPelna' => 'Produkt B', 'Ilosc' => 1)))));

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $this->guard->check($this->order())->outcome);
        $this->assertCount(0, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testDifferentQuantityProceeds()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef(array('Pozycje' => array(array('NazwaPelna' => 'Produkt A', 'Ilosc' => 2)))));

        $this->assertSame(IfirmaGuardResult::OUTCOME_PROCEED, $this->guard->check($this->order())->outcome);
    }

    public function testExtraPositionStaysSuspected()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef(array('Pozycje' => array(
            array('NazwaPelna' => 'Produkt A', 'Ilosc' => 1),
            array('NazwaPelna' => 'Koszt dostawy', 'Ilosc' => 1),
        ))));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $data = $this->recorded();
        $this->assertSame($this->saleDate(), $data['candidates'][0]['sale_date']);
        $this->assertSame('identifier', $data['candidates'][0]['matched_by']);
    }

    public function testMonthlySaleDateFormatStaysSuspected()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef(array(
            'FormatDatySprzedazy' => 'MSC',
            'DataSprzedazy' => substr($this->saleDate(), 0, 7) . '-15',
        )));

        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $this->guard->check($this->order())->outcome);
    }

    public function testDetailWithoutPositionsStaysSuspected()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, '{"response":{"Uwagi":"other","DataSprzedazy":"' . $this->saleDate() . '"}}');

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame($this->saleDate(), $this->recorded()['candidates'][0]['sale_date']);
    }

    public function testReferenceWinsOverSaleDateAndPositions()
    {
        $this->queueList(array($this->row()));
        $this->queueDetail('fakturaparagon', 77, '{"response":{"Uwagi":"PS-ORDER-15-ABCDEFGHI","DataSprzedazy":"2020-01-01","Pozycje":[{"NazwaPelna":"Inny","Ilosc":3}]}}');

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_ADOPT, $res->outcome);
        $row = $this->receiptRow();
        $this->assertSame('77', (string) $row['ifirma_id']);
        $this->assertStringNotContainsString('_matched_by', $row['response_payload']);
        $this->assertSame('identifier', json_decode($row['duplicate_candidates'], true)['candidates'][0]['matched_by']);
    }

    public function testNipMatchedRowIsCandidate()
    {
        $this->nip();
        $this->queueList(array($this->row(array('IdentyfikatorKontrahenta' => 'EC_KOWALSKI', 'NIPKontrahenta' => '123-456-78-90', 'KontrahentNazwa' => 'Firma'))));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame('nip', $this->recorded()['candidates'][0]['matched_by']);
    }

    public function testNameMatchedRowIsCandidate()
    {
        $a = new Address();
        $a->id = 7;
        $a->id_country = 14;
        $a->firstname = 'Jan';
        $a->lastname = 'Kowalski';
        Address::register($a);
        $this->queueList(array($this->row(array('IdentyfikatorKontrahenta' => 'X-1', 'KontrahentNazwa' => 'jan   KOWALSKI'))));
        $this->queueDetail('fakturaparagon', 77, $this->detailNoRef());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertSame('name', $this->recorded()['candidates'][0]['matched_by']);
    }

    public function testStoredMappingIdentifierSelectsCandidates()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', IfirmaContractorMap::BY_NIP);
        $this->queueList(array(
            $this->row(array('FakturaId' => 70, 'IdentyfikatorKontrahenta' => 'PS-C5')),
            $this->row(array('FakturaId' => 80, 'IdentyfikatorKontrahenta' => 'KOWALSKI-01')),
        ));
        $this->queueDetail('fakturaparagon', 80, $this->detailNoRef());

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertCount(2, $this->t->calls);
        $this->assertCount(1, $res->candidates);
        $this->assertSame('80', $this->recorded()['candidates'][0]['id']);
    }

    public function testCandidatesBeyondDetailCapStayUnverified()
    {
        $rows = array();
        for ($i = 1; $i <= 12; ++$i) {
            $rows[] = $this->row(array('FakturaId' => $i, 'DataWystawienia' => date('Y-m-d', time() - $i * 86400)));
        }
        $this->queueList($rows);
        for ($i = 1; $i <= 10; ++$i) {
            $this->queueDetail('fakturaparagon', $i, $this->detailNoRef());
        }

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertCount(11, $this->t->calls);
        $data = $this->recorded();
        $this->assertCount(12, $data['candidates']);
        $unverified = array();
        foreach ($data['candidates'] as $c) {
            if ($c['sale_date'] === '') {
                $unverified[] = $c['id'];
            }
        }
        $this->assertSame(array('11', '12'), $unverified);
    }

    public function testOnlySurvivingCandidateIsRecorded()
    {
        $this->queueList(array(
            $this->row(array('FakturaId' => 70, 'DataWystawienia' => '2026-01-01')),
            $this->row(array('FakturaId' => 80, 'DataWystawienia' => '2026-01-03')),
        ));
        $this->queueDetail('fakturaparagon', 80, $this->detailNoRef());
        $this->queueDetail('fakturaparagon', 70, $this->detailNoRef(array('DataSprzedazy' => '2020-01-01')));

        $res = $this->guard->check($this->order());
        $this->assertSame(IfirmaGuardResult::OUTCOME_SUSPECTED, $res->outcome);
        $this->assertCount(3, $this->t->calls);
        $this->assertCount(1, $res->candidates);
        $data = $this->recorded();
        $this->assertCount(1, $data['candidates']);
        $this->assertSame('80', $data['candidates'][0]['id']);
        $this->assertStringContainsString('1 matching document', $this->receiptRow()['last_error']);
    }
}
