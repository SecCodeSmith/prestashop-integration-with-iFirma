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
 * The exact answers of the admin actions where AdminActionsTest, AdminControllerCustomerTest and
 * ErrorGuardTest check only part of the envelope (a word of the message, the success flag): every
 * refusal, the wording and the data keys of the outcomes.
 *
 * The customer page actions run against doubles of the customer service, so that each answer is
 * fixed by the double's return value and not by the service's own rules.
 */
class AdminActionsAnswersTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    /** @var IfirmaAdminActions */
    private $actions;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        $this->actions = new IfirmaAdminActions($this->module);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function refusal($message, array $data = array())
    {
        return array('success' => false, 'message' => $message, 'data' => $data);
    }

    /**
     * A customer service double; $returns is method => return value. Every method that takes the customer
     * is held to customer 5, the one these tests act on.
     */
    private function serviceReturning(array $returns)
    {
        $service = $this->getMockBuilder('IfirmaCustomerContractorService')->disableOriginalConstructor()->getMock();
        foreach ($returns as $method => $value) {
            $call = $service->method($method);
            if (in_array($method, array('check', 'linkState', 'unlink', 'remoteCount'), true)) {
                $call->with(5);
            }
            $call->willReturn($value);
        }
        $this->actions->setCustomerService($service);

        return $service;
    }

    private function linkState($identifier = 'KOW-01')
    {
        return array('identifier' => $identifier, 'linked' => true, 'name' => 'Jan Kowalski', 'matched_by' => 'manual');
    }

    // ---- an order that cannot be acted on ------------------------------------------------------

    public function testIssuingRefusesAnUnknownOrderWhateverTheModuleState()
    {
        $this->assertSame($this->refusal('Invalid order.'), $this->actions->createReceipt(999, 3));
        $this->assertSame($this->refusal('Invalid order.'), $this->actions->issueAnyway(999, 3));

        Configuration::updateValue('IFIRMA_KEY_FAKTURA', '');
        $this->assertSame($this->refusal('Invalid order.'), $this->actions->createReceipt(999, 3));
        $this->assertSame($this->refusal('Invalid order.'), $this->actions->issueAnyway(999, 3));
        $this->assertSame(array(), $this->t->calls);
    }

    public function testIssuingRefusesAModuleThatIsNotConfigured()
    {
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', '');

        $this->assertSame($this->refusal('The iFirma module is not configured yet.'), $this->actions->createReceipt(15, 3));
        $this->assertSame($this->refusal('The iFirma module is not configured yet.'), $this->actions->issueAnyway(15, 3));
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testRegisterContractorRefusesAnUnknownOrder()
    {
        $this->assertSame($this->refusal('Invalid order.'), $this->actions->registerContractor(999));
    }

    public function testRegisterContractorRefusesAnUnknownOrderWhetherOrNotRegistrationIsSwitchedOff()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);

        $this->assertSame($this->refusal('Invalid order.'), $this->actions->registerContractor(999));
        $this->assertSame(array(), $this->t->calls);
    }

    public function testRegisterContractorRegistersTheDataOfTheApprovedRequestOfTheOrder()
    {
        $request = $this->makeRequest('pending', array(
            'want_invoice_for_company' => 1,
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => '5260250274',
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Nowa 2',
            'postcode' => '00-002',
            'city' => 'Warszawa',
            'id_country' => 14,
        ));
        $this->assertTrue($this->actions->approveRequest((int) $request->id, 3)['success']);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/5260250274\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/ACME#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"124451232"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[{"Identyfikator":"PS-C5","Nazwa":"ACME"}]}}'));

        $r = $this->actions->registerContractor(15);

        $paths = array();
        foreach ($this->t->calls as $c) {
            $paths[] = $c['method'] . ' ' . parse_url($c['url'], PHP_URL_PATH);
        }
        // the approved data names the contractor and drives both look-ups: by its NIP, then by its name
        $this->assertSame(
            array(
                'GET /iapi/kontrahenci/id/PS-C5.json',
                'GET /iapi/kontrahenci/5260250274.json',
                'GET /iapi/kontrahenci/ACME%20Sp.%20z%20o.o..json',
                'POST /iapi/kontrahenci.json',
                'GET /iapi/kontrahenci/id/PS-C5.json',
            ),
            $paths
        );
        $this->assertSame(
            array(
                'Nazwa' => 'ACME Sp. z o.o.',
                'Identyfikator' => 'PS-C5',
                'NIP' => '5260250274',
                'Ulica' => 'Nowa 2',
                'KodPocztowy' => '00-002',
                'Kraj' => 'PL',
                'KodKraju' => 'PL',
                'Miejscowosc' => 'Warszawa',
                'Email' => 'jan@example.test',
                'OsobaFizyczna' => false,
            ),
            json_decode($this->posts()[0]['body'], true)
        );
        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Contractor PS-C5 created in iFirma.',
                'data' => array(
                    'identifier' => 'PS-C5',
                    'action' => 'created',
                    'message' => '',
                    'matched_by' => 'module',
                    'note' => null,
                    'assigned_identifier' => null,
                ),
            ),
            $r
        );
    }

    public function testRegisterContractorSearchesTheNipOfTheInvoiceAddressOfAnIndividualToo()
    {
        Address::$instances[7]->vat_number = '525-224-84-81';
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"124451232"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[{"Identyfikator":"PS-C5","Nazwa":"Jan K"}]}}'));

        $r = $this->actions->registerContractor(15);

        $paths = array();
        foreach ($this->t->calls as $c) {
            $paths[] = $c['method'] . ' ' . parse_url($c['url'], PHP_URL_PATH);
        }
        // the contractor of an individual carries no NIP, the address of the order does: the look-up uses the address
        $this->assertSame(
            array(
                'GET /iapi/kontrahenci/id/PS-C5.json',
                'GET /iapi/kontrahenci/5252248481.json',
                'GET /iapi/kontrahenci/Jan%20K.json',
                'POST /iapi/kontrahenci.json',
                'GET /iapi/kontrahenci/id/PS-C5.json',
            ),
            $paths
        );
        $this->assertSame('', json_decode($this->posts()[0]['body'], true)['NIP']);
        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('created', $r['data']['action']);
    }

    public function testRegisterCustomerRegistersTheNewestOrderOfTheCustomer()
    {
        $other = new Address();
        $other->id = 8;
        $other->id_customer = 5;
        $other->firstname = 'Jan';
        $other->lastname = 'Old';
        $other->address1 = 'Stara 9';
        $other->postcode = '00-009';
        $other->city = 'Lodz';
        $other->id_country = 14;
        Address::register($other);
        $older = $this->wiringOrder();
        $older->id = 14;
        $older->id_address_invoice = 8;
        $older->date_add = date('Y-m-d H:i:s', time() - 5 * 86400);
        Order::register($older);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[{"Identyfikator":"PS-C5","Nazwa":"Jan K"}]}}'));

        $r = $this->actions->registerCustomer(5);

        // order 15 is the newest: its invoice address (Jan K) names the contractor, not the one of order 14 (Jan Old)
        $this->assertTrue($r['success'], $r['message']);
        $this->assertCount(1, $this->posts());
        $this->assertSame('Jan K', json_decode($this->posts()[0]['body'], true)['Nazwa']);
        $this->assertSame('Prosta 1', json_decode($this->posts()[0]['body'], true)['Ulica']);
    }

    // ---- linking a candidate -------------------------------------------------------------------

    public function testLinkCandidateRefusesWhenTheOrderHasNoReceipt()
    {
        $this->assertSame(
            $this->refusal('There is no candidate to link for this order.'),
            $this->actions->linkCandidate(15, '77', 3)
        );
    }

    /**
     * @return array[]
     */
    public function blankCandidateIds()
    {
        return array('empty' => array(''), 'spaces' => array('   '), 'tab' => array("\t"));
    }

    /**
     * @dataProvider blankCandidateIds
     */
    public function testLinkCandidateRefusesABlankCandidateId($id)
    {
        $this->makeReceipt(array('status' => 'duplicate_suspected', 'ifirma_id' => '', 'ifirma_number' => ''));

        $this->assertSame(
            $this->refusal('There is no candidate to link for this order.'),
            $this->actions->linkCandidate(15, $id, 3)
        );
    }

    // ---- the actions that need an issued document -------------------------------------------------

    /**
     * @return array[] action => array(callable taking the actions and the order id)
     */
    public function documentActions()
    {
        return array(
            'updateStatus' => array(function (IfirmaAdminActions $a, $idOrder) {
                return $a->updateStatus($idOrder, 10.0);
            }),
            'resend' => array(function (IfirmaAdminActions $a, $idOrder) {
                return $a->resend($idOrder);
            }),
            'sendToKsef' => array(function (IfirmaAdminActions $a, $idOrder) {
                return $a->sendToKsef($idOrder);
            }),
            'refreshStatus' => array(function (IfirmaAdminActions $a, $idOrder) {
                return $a->refreshStatus($idOrder);
            }),
        );
    }

    /**
     * @dataProvider documentActions
     */
    public function testAnOrderWithoutAReceiptHasNoIssuedDocument($call)
    {
        $this->assertSame($this->refusal('No issued iFirma document for this order.'), $call($this->actions, 15));
        $this->assertSame(array(), $this->t->calls);
    }

    /**
     * @dataProvider documentActions
     */
    public function testAReceiptThatWasNotIssuedIsNoIssuedDocument($call)
    {
        $this->makeReceipt(array('status' => 'error', 'ifirma_id' => ''));

        $this->assertSame($this->refusal('No issued iFirma document for this order.'), $call($this->actions, 15));
        $this->assertSame(array(), $this->t->calls);
    }

    public function testResendAndSendToKsefNeedTheOrderTooAnIssuedDocumentOfAnUnknownOrderIsRefused()
    {
        $this->makeReceipt(array('id_order' => 999));

        $this->assertSame($this->refusal('No issued iFirma document for this order.'), $this->actions->resend(999));
        $this->assertSame($this->refusal('No issued iFirma document for this order.'), $this->actions->sendToKsef(999));
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), Mail::$sent);
    }

    // ---- KSeF ----------------------------------------------------------------------------------

    public function testSendToKsefAddsTheMessageOfIFirmaToTheSuccessAndDescribesTheDocument()
    {
        $this->makeReceipt();
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Kod":0,"KsefStatus":"","KsefNumer":""}}'));
        $this->t->queue('POST', '#fakturakraj/ksef/send/77\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Wyslano"}}'));

        $r = $this->actions->sendToKsef(15);

        $receipt = new IfirmaReceipt(1);
        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Document sent to KSeF. Wyslano',
                'data' => array(
                    'receipt' => $this->actions->receiptToArray($receipt),
                    'ksef' => IfirmaKsefService::build($this->module)->describe($receipt, Order::$instances[15]),
                ),
            ),
            $r
        );
        $this->assertNotSame('', $r['data']['ksef']);
    }

    public function testSendToKsefAnswersAPlainSuccessWhenIFirmaSaysNothing()
    {
        $this->makeReceipt();
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Kod":0,"KsefStatus":"","KsefNumer":""}}'));
        $this->t->queue('POST', '#fakturakraj/ksef/send/77\.json#', array('body' => '{"response":{"Kod":0}}'));

        $r = $this->actions->sendToKsef(15);

        $this->assertTrue($r['success']);
        $this->assertSame('Document sent to KSeF.', $r['message']);
    }

    public function testSendToKsefAnswersTheReasonOfAFailureAndDescribesTheDocument()
    {
        $this->makeReceipt();
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Kod":0,"KsefStatus":"","KsefNumer":""}}'));
        $this->t->queue('POST', '#ksef/send#', array('body' => '{"response":{"Kod":200,"Informacja":"Odmowa"}}'));

        $r = $this->actions->sendToKsef(15);

        $receipt = new IfirmaReceipt(1);
        $this->assertFalse($r['success']);
        $this->assertSame(
            array(
                'receipt' => $this->actions->receiptToArray($receipt),
                'ksef' => IfirmaKsefService::build($this->module)->describe($receipt, Order::$instances[15]),
            ),
            $r['data']
        );
        $this->assertSame($receipt->ksef_error, $r['message']);
        $this->assertNotSame('', $r['message']);
    }

    // ---- refresh ---------------------------------------------------------------------------------

    public function testRefreshStatusAnswersTheWholeEnvelopeOfASuccess()
    {
        $this->makeReceipt(array('last_sync_at' => null));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow())));

        $r = $this->actions->refreshStatus(15);

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Status refreshed from iFirma.',
                'data' => array('receipt' => $this->actions->receiptToArray(new IfirmaReceipt(1))),
            ),
            $r
        );
        $this->assertSame('', $r['data']['receipt']['sync_error']);
    }

    public function testRefreshStatusAnswersTheSyncErrorOfAFailureWithTheDocument()
    {
        $this->makeReceipt(array('last_sync_at' => null));
        $this->t->queue('GET', '#faktury\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->actions->refreshStatus(15);

        $receipt = new IfirmaReceipt(1);
        $this->assertNotSame('', (string) $receipt->sync_error);
        $this->assertSame(
            array(
                'success' => false,
                'message' => 'Refresh from iFirma failed: ' . $receipt->sync_error,
                'data' => array('receipt' => $this->actions->receiptToArray($receipt)),
            ),
            $r
        );
    }

    // ---- data requests -------------------------------------------------------------------------

    public function testAnUnknownRequestIsRefusedByBothDecisions()
    {
        $this->assertSame($this->refusal('The request was not found.'), $this->actions->approveRequest(999, 3));
        $this->assertSame($this->refusal('The request was not found.'), $this->actions->rejectRequest(999, 3, 'No reason'));
    }

    public function testApprovingAnswersWithTheIssuanceItResumed()
    {
        $this->makeReceipt(array('status' => 'held', 'ifirma_id' => '', 'ifirma_number' => ''));
        $request = $this->makeRequest('pending');
        $order = $this->wiringOrder();
        $order->current_state = 2;
        $order->history = array(array('id_order_state' => 1), array('id_order_state' => 5));
        Order::register($order);
        $this->queueFullSuccess();

        $r = $this->actions->approveRequest((int) $request->id, 3, null, 'ok');

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Request approved. Document issued: 7/2026',
                'data' => array('issuance_ran' => true, 'status' => 'approved', 'issued' => true, 'outcome' => 'issued'),
            ),
            $r
        );
    }

    public function testRejectingAnswersWithTheIssuanceItResumed()
    {
        $request = $this->makeRequest('pending');
        $order = $this->wiringOrder();
        $order->current_state = 2;
        $order->history = array(array('id_order_state' => 5));
        Order::register($order);
        $this->queueFullSuccess();

        $r = $this->actions->rejectRequest((int) $request->id, 3, 'NIP does not match');

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Request rejected. Document issued: 7/2026',
                'data' => array('issuance_ran' => true, 'status' => 'rejected', 'issued' => true, 'outcome' => 'issued'),
            ),
            $r
        );
    }

    public function testAResumedIssuanceThatStopsIsReportedAsNotIssued()
    {
        $request = $this->makeRequest('pending');
        $order = $this->wiringOrder();
        $order->current_state = 2;
        $order->history = array(array('id_order_state' => 5));
        Order::register($order);
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array('Rodzaj' => 'prz_faktura_kraj')))));

        $r = $this->actions->approveRequest((int) $request->id, 3);

        $this->assertTrue($r['success']);
        $this->assertSame('approved', $r['data']['status']);
        $this->assertTrue($r['data']['issuance_ran']);
        $this->assertFalse($r['data']['issued']);
        $this->assertSame('suspected', $r['data']['outcome']);
        $this->assertStringStartsWith('Request approved. Stopped at the "guard" step (suspected): ', $r['message']);
        $this->assertSame(array('issuance_ran', 'status', 'issued', 'outcome'), array_keys($r['data']));
    }

    // ---- the customer page: wording of every outcome ------------------------------------------------

    public function testCheckWordsEachStateAndKeepsTheServiceAnswerWithTheLabel()
    {
        $registered = array('state' => 'registered', 'identifier' => 'KOW-01', 'name' => 'Jan Kowalski', 'message' => '');
        $missing = array('state' => 'missing', 'identifier' => 'KOW-01', 'name' => '', 'message' => '');
        $failed = array('state' => 'error', 'identifier' => 'KOW-01', 'name' => '', 'message' => 'it broke');
        $service = $this->serviceReturning(array());
        $service->method('check')->with(5)->willReturnOnConsecutiveCalls($registered, $missing, $failed);

        $this->assertSame(
            array('success' => true, 'message' => 'Registered in iFirma as KOW-01', 'data' => array('check' => $registered + array('label' => 'Registered in iFirma as KOW-01'))),
            $this->actions->customerCheck(5)
        );
        $this->assertSame(
            array('success' => true, 'message' => 'Not found in iFirma', 'data' => array('check' => $missing + array('label' => 'Not found in iFirma'))),
            $this->actions->customerCheck(5)
        );
        $this->assertSame(
            array('success' => false, 'message' => 'Could not check: it broke', 'data' => array('check' => $failed + array('label' => 'Could not check: it broke'))),
            $this->actions->customerCheck(5)
        );
    }

    public function testSearchCountsTheRowsAndAddsWhatFailed()
    {
        $rows = array(array('identifier' => 'KOW-01'), array('identifier' => 'KOW-02'));
        $clean = array('refused' => false, 'rows' => $rows, 'errors' => array(), 'phrases' => array('Nowak'));
        $partial = array('refused' => false, 'rows' => array(), 'errors' => array('Nowak' => 'timeout', 'Jan' => 'oops'), 'phrases' => array('Nowak', 'Jan'));
        $service = $this->serviceReturning(array());
        $service->method('searchCandidates')->willReturnOnConsecutiveCalls($clean, $partial);

        $this->assertSame(
            array('success' => true, 'message' => 'Found 2 contractor(s).', 'data' => array('search' => $clean)),
            $this->actions->customerSearch(array('lastname' => 'Nowak'))
        );
        $this->assertSame(
            array(
                'success' => true,
                'message' => 'No contractor was found in iFirma. Search failed for: Nowak: timeout; Jan: oops',
                'data' => array('search' => $partial),
            ),
            $this->actions->customerSearch(array('lastname' => 'Nowak'))
        );
    }

    public function testSearchPassesTheCriteriaOnAndKeepsTheRefusal()
    {
        $criteria = array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => '', 'nip' => '', 'email' => '', 'phone' => '');
        $refused = array('refused' => true, 'rows' => array(), 'errors' => array(), 'phrases' => array());
        $service = $this->serviceReturning(array());
        $service->expects($this->once())->method('searchCandidates')->with($criteria)->willReturn($refused);

        $this->assertSame(
            $this->refusal(
                'iFirma searches contractors by name, company or NIP. E-mail and phone are only used to confirm the results; fill in a name, company or NIP.',
                array('search' => $refused)
            ),
            $this->actions->customerSearch($criteria)
        );
    }

    /** The answer names the contractor the service reports, not the identifier that was typed. */
    public function testLinkWordsEachOutcomeAndAnswersTheLinkState()
    {
        $state = $this->linkState();
        $service = $this->serviceReturning(array('linkState' => $state));
        $service->expects($this->exactly(4))->method('link')->withConsecutive(
            array(5, 'kow-01'),
            array(5, 'gone-01'),
            array(5, 'err-01'),
            array(5, '  ')
        )->willReturnOnConsecutiveCalls(
            array('success' => true, 'code' => 'linked', 'identifier' => 'KOW-01', 'name' => 'Jan', 'message' => ''),
            array('success' => false, 'code' => 'not_found', 'identifier' => 'GONE-01', 'name' => '', 'message' => ''),
            array('success' => false, 'code' => 'error', 'identifier' => 'ERR-01', 'name' => '', 'message' => 'it broke'),
            array('success' => false, 'code' => 'invalid', 'identifier' => '', 'name' => '', 'message' => '')
        );
        $data = array('link_state' => $state);

        $this->assertSame(array('success' => true, 'message' => 'Customer linked to contractor KOW-01.', 'data' => $data), $this->actions->customerLink(5, 'kow-01'));
        $this->assertSame(
            $this->refusal('Contractor GONE-01 was not found in iFirma; nothing was linked.', $data),
            $this->actions->customerLink(5, 'gone-01')
        );
        $this->assertSame(
            $this->refusal('Contractor ERR-01 could not be checked: it broke', $data),
            $this->actions->customerLink(5, 'err-01')
        );
        $this->assertSame($this->refusal('Invalid contractor identifier.', $data), $this->actions->customerLink(5, '  '));
    }

    public function testUnlinkAnswersTheLinkStateAfterwards()
    {
        $unlinked = array('identifier' => '', 'linked' => false, 'name' => '', 'matched_by' => '');
        $service = $this->serviceReturning(array('linkState' => $unlinked));
        $service->expects($this->exactly(2))->method('unlink')->with(5)->willReturnOnConsecutiveCalls(true, false);

        $this->assertSame(
            array('success' => true, 'message' => 'The link was removed. Nothing was changed in iFirma.', 'data' => array('link_state' => $unlinked)),
            $this->actions->customerUnlink(5)
        );
        $this->assertSame(
            $this->refusal('The customer was not linked.', array('link_state' => $unlinked)),
            $this->actions->customerUnlink(5)
        );
    }

    public function testCountRemoteWordsEachOutcome()
    {
        $base = array('ok' => true, 'error' => '', 'total' => 12, 'by_type' => array(), 'paid' => 5, 'period_from' => '2025-01-02',
            'period_to' => '2026-03-04', 'calls' => 3, 'complete' => true, 'identifier' => 'KOW-01', 'nip' => '');
        $cut = array('complete' => false) + $base;
        $short = array('complete' => false, 'period_from' => '') + $base;
        $unknownStart = array('complete' => false, 'period_from' => null) + $base;
        $emptyAndComplete = array('total' => 0, 'paid' => 0, 'period_from' => '', 'period_to' => '') + $base;
        $failed = array('ok' => false, 'error' => 'it broke') + $base;
        $service = $this->serviceReturning(array());
        $service->expects($this->exactly(6))->method('remoteCount')->with(5)
            ->willReturnOnConsecutiveCalls($base, $cut, $short, $unknownStart, $emptyAndComplete, $failed);

        $this->assertSame(
            array('success' => true, 'message' => '12 document(s) in iFirma (5 paid), 2025-01-02 – 2026-03-04.', 'data' => array('count' => $base)),
            $this->actions->customerCountRemote(5)
        );
        $this->assertSame(
            array('success' => true, 'message' => 'At least 12 document(s) in iFirma (5 paid), 2025-01-02 – 2026-03-04; the call limit was reached.', 'data' => array('count' => $cut)),
            $this->actions->customerCountRemote(5)
        );
        $this->assertSame(
            array('success' => true, 'message' => 'At least 12 document(s) in iFirma (5 paid); the call limit was reached before a full year was listed.', 'data' => array('count' => $short)),
            $this->actions->customerCountRemote(5)
        );
        // no start of the period (null as well as '') means the call limit stopped the count inside the first window
        $this->assertSame(
            array('success' => true, 'message' => 'At least 12 document(s) in iFirma (5 paid); the call limit was reached before a full year was listed.', 'data' => array('count' => $unknownStart)),
            $this->actions->customerCountRemote(5)
        );
        // a complete count keeps the normal wording even when it names no period
        $this->assertSame(
            array('success' => true, 'message' => '0 document(s) in iFirma (0 paid),  – .', 'data' => array('count' => $emptyAndComplete)),
            $this->actions->customerCountRemote(5)
        );
        $this->assertSame(
            $this->refusal('The documents could not be counted: it broke', array('count' => $failed)),
            $this->actions->customerCountRemote(5)
        );
    }
}
