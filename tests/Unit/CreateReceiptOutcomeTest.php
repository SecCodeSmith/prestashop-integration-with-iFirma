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
 * What IfirmaReceiptService::createReceipt() leaves on the receipt for each answer of iFirma, and what
 * its two retries leave behind (refactor-srp-splits 5.2).
 *
 * The scenarios pin every field the create step sets, the fields it must leave alone, the number of
 * writes, the messages, the order of the requests and the contractor it learns afterwards, so that
 * splitting the method into posting with retries, parsing the answer and applying it cannot change
 * them. CreateReceiptTest and ContractorReferenceTest keep their own scenarios and
 * CreateReceiptGoldenTest compares the payloads; the cases here are the ones they leave open.
 */
class CreateReceiptOutcomeTest extends TestCase
{
    use IfirmaContractorMapDb;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaReceiptService */
    private $service;

    /** @var IfirmaPayloadBuilder */
    private $builder;

    /** @var string[] the dates today may have been while createReceipt() ran */
    private $days = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->scriptContractorMapDb();
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->service = new IfirmaReceiptService($client, new Module());
        $this->builder = IfirmaPayloadBuilder::build(new Module());

        $c = new Customer();
        $c->id = 5;
        $c->email = 'a@example.test';
        Customer::register($c);
        $a = new Address();
        $a->id = 7;
        $a->firstname = 'Jan';
        $a->lastname = 'K';
        $a->id_country = 14;
        Address::register($a);
    }

    private function order()
    {
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_address_invoice = 7;
        $o->reference = 'ABCDEFGHI';
        $o->date_add = '2026-03-04 10:00:00';
        $o->total_paid_tax_incl = 10;
        $o->details = array(array(
            'product_name' => 'W',
            'product_quantity' => 1,
            'unit_price_tax_incl' => 10,
            'unit_price_tax_excl' => 10,
            'tax_rate' => 0,
        ));

        return $o;
    }

    private function enableSync()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 1);
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'pl';
        $country->name = 'Polska';
        Country::register($country);
    }

    /** The contractor record iFirma holds for the buyer, stored under $identifier. */
    private function foundContractor($identifier)
    {
        $row = array_merge($this->builder->buildContractor($this->order()), array('Identyfikator' => $identifier));

        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row)))));
    }

    /** @param string $body the answer to the next request that creates the document */
    private function answerDocument($body)
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => $body));
    }

    private function docOk()
    {
        return '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77","PelnyNumer":"7/2026"}}';
    }

    /**
     * createReceipt() of the order, noting the dates today may have been meanwhile.
     *
     * @return IfirmaReceipt
     */
    private function create(array $options = array(), Order $order = null)
    {
        $this->days = array(date('Y-m-d'));
        try {
            return $this->service->createReceipt($order !== null ? $order : $this->order(), $options);
        } finally {
            $this->days[] = date('Y-m-d');
        }
    }

    /** @return string the message createReceipt() ended in */
    private function createFailing(array $options = array())
    {
        $message = null;
        try {
            $this->create($options);
        } catch (Exception $e) {
            $message = $e->getMessage();
        }
        $this->assertNotNull($message, 'createReceipt() was expected to throw');

        return $message;
    }

    /**
     * @param string $op the one write the path must make: add or update
     * @return array that write: op, table, id, row
     */
    private function onlyWrite($op)
    {
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array($op), array_column($writes, 'op'));

        return $writes[0];
    }

    /** @return array[] the POSTs that create the document */
    private function docCalls()
    {
        $out = array();
        foreach ($this->t->calls as $c) {
            if ($c['method'] === 'POST' && substr(parse_url($c['url'], PHP_URL_PATH), -16) === 'fakturakraj.json') {
                $out[] = $c;
            }
        }

        return $out;
    }

    /** @return string[] METHOD and endpoint of every request, oldest first */
    private function requests()
    {
        $out = array();
        foreach ($this->t->calls as $c) {
            $out[] = $c['method'] . ' ' . substr($c['url'], strlen('http://ifirma-mock:8001/iapi/'));
        }

        return $out;
    }

    private function pretty($decoded)
    {
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function seedHeldRecord()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_HELD;
        $old->last_error = 'held: data request #4 pending';
        $old->security_token = 'keep-me';
        $old->delivery_status = IfirmaReceipt::DELIVERY_FAILED;
        $old->delivery_channel = 'shop_mail';
        $old->delivery_error = 'smtp down';
        $old->id_data_request = 4;
        $old->duplicate_candidates = '[{"id":"9"}]';
        $old->linked_document = 'LD-1';
        $old->ksef_status = 'error';
        $old->ksef_error = 'boom';
        $old->request_payload = 'old request';
        $old->response_payload = 'old response';
        $old->id_customer = 99;
        $old->id_shop = 9;
        $old->document_type = 'fakturaparagon';
        $old->source = IfirmaReceipt::SOURCE_EXTERNAL;
        $old->order_reference = 'OLD-REF';
        $old->issue_date = '2020-01-01';
        $old->paid_amount = 99.0;
        $old->save();
        Db::getInstance()->whenGetValue('/ifirma_receipt/', (int) $old->id);
        IfirmaTestState::clearWrites();

        return $old;
    }

    // ---- the receipt of an issued document -------------------------------------------------

    public function testAnIssuedDocumentIsRecordedInOneWrite()
    {
        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', 1);
        $this->answerDocument('{"response":{"Kod":0,"Informacja":"ok żółć","Identyfikator":"77","PelnyNumer":"7/2026"}}');

        $receipt = $this->create();

        $write = $this->onlyWrite('add');
        $row = $write['row'];
        $this->assertSame(15, $row['id_order']);
        $this->assertSame(5, $row['id_customer']);
        $this->assertSame(1, $row['id_shop']);
        $this->assertSame('fakturakraj', $row['document_type']);
        $this->assertSame('77', $row['ifirma_id']);
        $this->assertSame('7/2026', $row['ifirma_number']);
        $this->assertSame('created', $row['status']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $row['order_reference']);
        $this->assertContains($row['issue_date'], $this->days);
        $this->assertSame('module', $row['source']);
        $this->assertSame(10.0, $row['paid_amount']);
        $this->assertSame('', $row['last_error']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $row['security_token']);
        $this->assertSame(
            implode("\n", array(
                '{',
                '    "response": {',
                '        "Kod": 0,',
                '        "Informacja": "ok żółć",',
                '        "Identyfikator": "77",',
                '        "PelnyNumer": "7\/2026"',
                '    }',
                '}',
            )),
            $row['response_payload']
        );
        $this->assertSame($write['id'], $receipt->id);
        $this->assertSame('created', $receipt->status);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testAnExistingRecordKeepsTheFieldsTheCreateStepDoesNotSet()
    {
        $old = $this->seedHeldRecord();
        $this->answerDocument($this->docOk());

        $this->create();

        $write = $this->onlyWrite('update');
        $this->assertSame((int) $old->id, $write['id']);
        $row = $write['row'];
        $this->assertSame('created', $row['status']);
        $this->assertSame('', $row['last_error']);
        $this->assertSame('77', $row['ifirma_id']);
        $this->assertSame('7/2026', $row['ifirma_number']);
        $this->assertSame(5, $row['id_customer']);
        $this->assertSame(1, $row['id_shop']);
        $this->assertSame('fakturakraj', $row['document_type']);
        $this->assertSame(IfirmaReceipt::SOURCE_MODULE, $row['source']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', $row['order_reference']);
        $this->assertContains($row['issue_date'], $this->days);
        $this->assertSame(0.0, $row['paid_amount']);
        $this->assertSame('keep-me', $row['security_token']);
        $this->assertSame(IfirmaReceipt::DELIVERY_FAILED, $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame('smtp down', $row['delivery_error']);
        $this->assertSame(4, $row['id_data_request']);
        $this->assertSame('[{"id":"9"}]', $row['duplicate_candidates']);
        $this->assertSame('LD-1', $row['linked_document']);
        $this->assertSame('error', $row['ksef_status']);
        $this->assertSame('boom', $row['ksef_error']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', json_decode($row['request_payload'], true)['Uwagi']);
        $this->assertSame($this->pretty(json_decode($this->docOk(), true)), $row['response_payload']);
    }

    public function testARecordWithoutATokenGetsOne()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_ERROR;
        $old->last_error = 'earlier failure';
        $old->save();
        Db::getInstance()->whenGetValue('/ifirma_receipt/', (int) $old->id);
        IfirmaTestState::clearWrites();
        $this->answerDocument($this->docOk());

        $this->create();

        $row = $this->onlyWrite('update')['row'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $row['security_token']);
        $this->assertSame('created', $row['status']);
        $this->assertSame('', $row['last_error']);
    }

    public function testThePaidAmountIsTheOrderTotalWhenMarkedPaidOnCreate()
    {
        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', 1);
        $order = $this->order();
        $order->total_paid_tax_incl = 123.456;
        $order->totalPaidReal = 50.5;
        $this->answerDocument($this->docOk());

        $this->create(array(), $order);

        $this->assertSame(123.46, $this->onlyWrite('add')['row']['paid_amount']);
    }

    public function testThePaidAmountIsWhatWasPaidOtherwise()
    {
        $order = $this->order();
        $order->total_paid_tax_incl = 123.456;
        $order->totalPaidReal = 50.5;
        $this->answerDocument($this->docOk());

        $this->create(array(), $order);

        $this->assertSame(50.5, $this->onlyWrite('add')['row']['paid_amount']);
    }

    // ---- where the number of the document comes from ---------------------------------------

    /**
     * @return array answer node => the number the receipt gets
     */
    public function numbersTheAnswerCarries()
    {
        return array(
            'the full number wins over the short one' => array('{"Kod":0,"Identyfikator":"77","PelnyNumer":"7/2026","Numer":"7"}', '7/2026'),
            'the short number when the full one is empty' => array('{"Kod":0,"Identyfikator":"77","PelnyNumer":"","Numer":"12"}', '12'),
            'the short number when the full one is 0' => array('{"Kod":0,"Identyfikator":"77","PelnyNumer":"0","Numer":"12"}', '12'),
            'Kod given as text' => array('{"Kod":"0","Identyfikator":"77","PelnyNumer":"7/2026"}', '7/2026'),
            'an identifier given as a number' => array('{"Kod":0,"Identyfikator":77,"PelnyNumer":"7/2026"}', '7/2026'),
            'a short number given as a number' => array('{"Kod":0,"Identyfikator":"77","Numer":12}', '12'),
            'a full number given as a number' => array('{"Kod":0,"Identyfikator":"77","PelnyNumer":2026}', '2026'),
        );
    }

    /**
     * @dataProvider numbersTheAnswerCarries
     */
    public function testTheNumberTheAnswerCarriesNeedsNoSecondRequest($node, $number)
    {
        $this->answerDocument('{"response":' . $node . '}');

        $this->create();

        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('created', $row['status']);
        $this->assertSame('77', $row['ifirma_id']);
        $this->assertSame($number, $row['ifirma_number']);
        $this->assertSame(array('POST fakturakraj.json'), $this->requests());
    }

    public function testAnAnswerWithoutAnEnvelopeIsReadToo()
    {
        $this->answerDocument('{"Kod":0,"Identyfikator":"9","PelnyNumer":"9/2026"}');

        $this->create();

        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('created', $row['status']);
        $this->assertSame('9', $row['ifirma_id']);
        $this->assertSame('9/2026', $row['ifirma_number']);
        $this->assertSame($this->pretty(array('Kod' => 0, 'Identyfikator' => '9', 'PelnyNumer' => '9/2026')), $row['response_payload']);
    }

    public function testAnAnswerWithoutANumberIsLookedUpInTheListOfThatDay()
    {
        $this->answerDocument('{"response":{"Kod":0,"Identyfikator":"77"}}');
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array(
            array('FakturaId' => 76, 'PelnyNumer' => '6/2026'),
            array('FakturaId' => 77, 'PelnyNumer' => '9/2026'),
        ))))));

        $this->create();

        $this->assertSame('9/2026', $this->onlyWrite('add')['row']['ifirma_number']);
        $requests = $this->requests();
        $this->assertCount(2, $requests);
        $this->assertSame('POST fakturakraj.json', $requests[0]);
        $matches = array();
        $this->assertSame(1, preg_match('/^GET faktury\.json\?dataOd=([0-9-]+)&dataDo=([0-9-]+)&iloscNaStronie=100$/', $requests[1], $matches));
        $this->assertSame($matches[1], $matches[2]);
        $this->assertContains($matches[1], $this->days);
    }

    public function testAnAnswerWithoutANumberLeavesItUnsetWhenTheListLacksTheDocument()
    {
        $this->answerDocument('{"response":{"Kod":0,"Identyfikator":"77"}}');
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array(
            array('FakturaId' => 76, 'PelnyNumer' => '6/2026'),
        ))))));

        $this->create();

        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('created', $row['status']);
        $this->assertSame('77', $row['ifirma_id']);
        $this->assertNull($row['ifirma_number']);
    }

    public function testAnAnswerWithoutANumberLeavesItUnsetWhenTheListCannotBeRead()
    {
        $this->answerDocument('{"response":{"Kod":0,"Identyfikator":"77"}}');
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'timeout'));

        $this->create();

        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('created', $row['status']);
        $this->assertNull($row['ifirma_number']);
    }

    public function testAnAnswerWithoutAnIdentifierIsRecordedWithoutLookingAnythingUp()
    {
        $this->answerDocument('{"response":{"Kod":0}}');

        $this->create();

        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('created', $row['status']);
        $this->assertSame('', $row['ifirma_id']);
        $this->assertNull($row['ifirma_number']);
        $this->assertSame(array('POST fakturakraj.json'), $this->requests());
    }

    public function testAnAnswerWithoutANumberKeepsTheNumberTheRecordAlreadyHad()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_ERROR;
        $old->ifirma_id = '5';
        $old->ifirma_number = 'OLD/1';
        $old->last_error = 'earlier failure';
        $old->save();
        Db::getInstance()->whenGetValue('/ifirma_receipt/', (int) $old->id);
        IfirmaTestState::clearWrites();
        $this->answerDocument('{"response":{"Kod":0,"Identyfikator":"77"}}');

        $this->create();

        $row = $this->onlyWrite('update')['row'];
        $this->assertSame('created', $row['status']);
        $this->assertSame('77', $row['ifirma_id']);
        $this->assertSame('OLD/1', $row['ifirma_number']);
        $this->assertSame(array('POST fakturakraj.json'), $this->requests());
    }

    // ---- an answer that is not Kod 0 -------------------------------------------------------

    /**
     * @return array body of the answer => the reason the receipt records
     */
    public function answersThatAreNotAcceptances()
    {
        return array(
            'no envelope' => array('{"Kod":201,"Informacja":"flat"}', 'flat'),
            'no Kod' => array('{"response":{"Informacja":"no code"}}', 'no code'),
            'Kod as text' => array('{"response":{"Kod":"201","Informacja":"as text"}}', 'as text'),
            'an empty reason' => array('{"response":{"Kod":201,"Informacja":""}}', ''),
            'a reason of 0' => array('{"response":{"Kod":201,"Informacja":"0"}}', '0'),
            'a reason given as a number' => array('{"response":{"Kod":201,"Informacja":12}}', 12),
            'nothing in the envelope' => array('{"response":{}}', 'Unknown iFirma error.'),
            'an empty object' => array('{}', 'Unknown iFirma error.'),
            'an empty list' => array('[]', 'Unknown iFirma error.'),
            'an envelope that holds a string' => array('{"response":"oops"}', 'Unknown iFirma error.'),
        );
    }

    /**
     * @dataProvider answersThatAreNotAcceptances
     */
    public function testAnAnswerThatIsNotKodZeroIsARejection($body, $reason)
    {
        $this->answerDocument($body);

        $message = $this->createFailing();

        $this->assertSame('iFirma rejected the document: ' . $reason, $message);
        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('error', $row['status']);
        $this->assertSame($reason, $row['last_error']);
        $this->assertNull($row['ifirma_id']);
        $this->assertNull($row['ifirma_number']);
        $this->assertSame($this->pretty(json_decode($body, true)), $row['response_payload']);
        $this->assertCount(1, $this->docCalls());
        $this->assertSame(1, count($this->t->calls));
    }

    public function testARejectionThatCameWithAnErrorStatusIsARejection()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 400, 'body' => '{"response":{"Kod":201,"Informacja":"bad request"}}'));

        $message = $this->createFailing();

        $this->assertSame('iFirma rejected the document: bad request', $message);
        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('error', $row['status']);
        $this->assertSame('bad request', $row['last_error']);
    }

    // ---- no answer -------------------------------------------------------------------------

    public function testAnErrorStatusWithoutAnAnswerIsUnknownAndKeepsTheEarlierResponse()
    {
        $old = new IfirmaReceipt();
        $old->id_order = 15;
        $old->status = IfirmaReceipt::STATUS_ERROR;
        $old->last_error = 'earlier failure';
        $old->request_payload = 'old request';
        $old->response_payload = 'previous answer';
        $old->security_token = 'keep-me';
        $old->save();
        Db::getInstance()->whenGetValue('/ifirma_receipt/', (int) $old->id);
        IfirmaTestState::clearWrites();
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 502, 'body' => '<html>bad gateway</html>'));

        $message = $this->createFailing();

        $this->assertSame('Could not reach iFirma: HTTP 502 returned by iFirma.', $message);
        $write = $this->onlyWrite('update');
        $this->assertSame((int) $old->id, $write['id']);
        $row = $write['row'];
        $this->assertSame('unknown', $row['status']);
        $this->assertSame('HTTP 502 returned by iFirma.', $row['last_error']);
        $this->assertSame('previous answer', $row['response_payload']);
        $this->assertSame('PS-ORDER-15-ABCDEFGHI', json_decode($row['request_payload'], true)['Uwagi']);
        $this->assertSame('keep-me', $row['security_token']);
        $this->assertNull($row['ifirma_id']);
    }

    public function testNoAnswerLeavesANewRecordWithoutAResponse()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));

        $this->assertSame('Could not reach iFirma: cURL error: timeout', $this->createFailing());

        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('unknown', $row['status']);
        $this->assertNull($row['response_payload']);
        $this->assertNull($row['ifirma_id']);
        $this->assertNull($row['ifirma_number']);
        $this->assertNotSame('', (string) $row['request_payload']);
    }

    // ---- the DodatkoweUwagi retry ----------------------------------------------------------

    public function testKod201NamingDodatkoweUwagiRetriesOnceWithoutIt()
    {
        $this->answerDocument('{"response":{"Kod":201,"Informacja":"Pole DodatkoweUwagi jest nieprawidłowe"}}');
        $this->answerDocument($this->docOk());

        $this->create();

        $docs = $this->docCalls();
        $this->assertCount(2, $docs);
        $this->assertArrayHasKey('DodatkoweUwagi', json_decode($docs[0]['body'], true));
        $this->assertArrayNotHasKey('DodatkoweUwagi', json_decode($docs[1]['body'], true));
        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('created', $row['status']);
        $this->assertArrayNotHasKey('DodatkoweUwagi', json_decode($row['request_payload'], true));
        $this->assertSame($this->pretty(json_decode($this->docOk(), true)), $row['response_payload']);
    }

    public function testTheRetryWithoutDodatkoweUwagiIsRejectedToo()
    {
        $this->answerDocument('{"response":{"Kod":201,"Informacja":"DodatkoweUwagi invalid"}}');
        $this->answerDocument('{"response":{"Kod":201,"Informacja":"DodatkoweUwagi still invalid"}}');

        $message = $this->createFailing();

        $this->assertSame('iFirma rejected the document: DodatkoweUwagi still invalid', $message);
        $this->assertCount(2, $this->docCalls());
        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('error', $row['status']);
        $this->assertSame('DodatkoweUwagi still invalid', $row['last_error']);
        $this->assertArrayNotHasKey('DodatkoweUwagi', json_decode($row['request_payload'], true));
        $this->assertSame(
            $this->pretty(array('response' => array('Kod' => 201, 'Informacja' => 'DodatkoweUwagi still invalid'))),
            $row['response_payload']
        );
    }

    public function testTheRetryRebuildsTheDocumentFromTheApprovedBuyerData()
    {
        $override = array(
            'want_invoice_for_company' => true,
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => '525-224-84-81',
            'firstname' => 'X',
            'lastname' => 'Y',
            'address1' => 'Polna 3',
            'address2' => '',
            'postcode' => '00-950',
            'city' => 'Warszawa',
            'id_country' => 14,
        );
        $this->answerDocument('{"response":{"Kod":200,"Informacja":"Niepoprawna zawartość żądania"}}');
        $this->answerDocument($this->docOk());

        $this->create(array('override' => $override));

        $docs = $this->docCalls();
        $this->assertCount(2, $docs);
        foreach ($docs as $doc) {
            $sent = json_decode($doc['body'], true);
            $this->assertSame('ACME Sp. z o.o.', $sent['Kontrahent']['Nazwa']);
            $this->assertSame('Polna 3', $sent['Kontrahent']['Ulica']);
            $this->assertSame('5252248481', $sent['NIPKontrahenta']);
        }
        $this->assertArrayHasKey('DodatkoweUwagi', json_decode($docs[0]['body'], true));
        $this->assertArrayNotHasKey('DodatkoweUwagi', json_decode($docs[1]['body'], true));
    }

    public function testNoAnswerToTheRetryIsUnknownAndStoresTheRetriedPayload()
    {
        $this->answerDocument('{"response":{"Kod":200,"Informacja":"Niepoprawna zawartość żądania"}}');
        $this->t->queue('POST', '#fakturakraj\.json#', array('status' => 0, 'error' => 'timeout'));

        $message = $this->createFailing();

        $this->assertSame('Could not reach iFirma: cURL error: timeout', $message);
        $this->assertCount(2, $this->docCalls());
        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('unknown', $row['status']);
        $this->assertSame('cURL error: timeout', $row['last_error']);
        $this->assertArrayNotHasKey('DodatkoweUwagi', json_decode($row['request_payload'], true));
        $this->assertNull($row['response_payload']);
    }

    // ---- the contractor retry, alone and after the DodatkoweUwagi retry --------------------

    public function testTheContractorTheDocumentCarriesIsLearnedAfterTheFallbackRetry()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->answerDocument('{"response":{"Kod":201,"Informacja":"IdentyfikatorKontrahenta: wartość nieprawidłowa"}}');
        $this->answerDocument('{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}');
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 555,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-77'),
        )))));

        $this->create();

        $this->assertSame(
            array('GET kontrahenci/id/PS-C5.json', 'POST fakturakraj.json', 'POST fakturakraj.json', 'GET fakturakraj/555.json'),
            $this->requests()
        );
        $map = IfirmaContractorMap::getByCustomer(5);
        $this->assertSame('AUTO-77', $map->ifirma_identifier);
        $this->assertSame('document', $map->matched_by);
        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('created', $row['status']);
        $stored = json_decode($row['request_payload'], true);
        $this->assertSame('fallback', $stored['_contractor']['action']);
        $this->assertSame('reference_rejected', $stored['_contractor']['note']['code']);
        $this->assertSame('PS-C5', $stored['_contractor']['note']['params']['identifier']);
        $this->assertSame('IdentyfikatorKontrahenta: wartość nieprawidłowa', $stored['_contractor']['note']['params']['error']);
        $this->assertSame('AUTO-77', $stored['_contractor']['document_identifier']);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', $stored);
    }

    public function testBothRetriesRunInOrderAndTheSecondOneBuildsOnTheFirst()
    {
        $this->enableSync();
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->foundContractor('PS-C5'));
        $this->answerDocument('{"response":{"Kod":200,"Informacja":"Niepoprawna zawartość żądania"}}');
        $this->answerDocument('{"response":{"Kod":201,"Informacja":"Nie znaleziono kontrahenta o zadanym identyfikatorze"}}');
        $this->answerDocument('{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"555","PelnyNumer":"555/2026"}}');
        $this->t->queue('GET', '#fakturakraj/555\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Identyfikator' => 555,
            'Kontrahent' => array('Nazwa' => 'Jan K', 'Identyfikator' => 'AUTO-77'),
        )))));

        $receipt = $this->create();

        $this->assertSame(
            array('GET kontrahenci/id/PS-C5.json', 'POST fakturakraj.json', 'POST fakturakraj.json', 'POST fakturakraj.json', 'GET fakturakraj/555.json'),
            $this->requests()
        );
        $docs = $this->docCalls();
        $first = json_decode($docs[0]['body'], true);
        $second = json_decode($docs[1]['body'], true);
        $third = json_decode($docs[2]['body'], true);
        $this->assertArrayHasKey('DodatkoweUwagi', $first);
        $this->assertSame('PS-C5', $first['IdentyfikatorKontrahenta']);
        $this->assertArrayNotHasKey('DodatkoweUwagi', $second);
        $this->assertSame('PS-C5', $second['IdentyfikatorKontrahenta']);
        $this->assertArrayNotHasKey('DodatkoweUwagi', $third);
        $this->assertArrayNotHasKey('IdentyfikatorKontrahenta', $third);
        $this->assertSame('created', $receipt->status);
        $row = $this->onlyWrite('add')['row'];
        $this->assertSame('555', $row['ifirma_id']);
        $this->assertSame('555/2026', $row['ifirma_number']);
        $stored = json_decode($row['request_payload'], true);
        $this->assertArrayNotHasKey('DodatkoweUwagi', $stored);
        $this->assertSame('fallback', $stored['_contractor']['action']);
        $this->assertSame('Nie znaleziono kontrahenta o zadanym identyfikatorze', $stored['_contractor']['message']);
        $this->assertSame('AUTO-77', $stored['_contractor']['document_identifier']);
        $this->assertSame(
            $this->pretty(array('response' => array('Kod' => 0, 'Informacja' => 'ok', 'Identyfikator' => '555', 'PelnyNumer' => '555/2026'))),
            $row['response_payload']
        );
        $this->assertSame('AUTO-77', IfirmaContractorMap::getByCustomer(5)->ifirma_identifier);
    }
}
