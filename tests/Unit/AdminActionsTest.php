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
 * IfirmaAdminActions: the logic behind the AdminIfirma ajax endpoints.
 */
class AdminActionsTest extends TestCase
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
        // the edited data of the approve tests names country 14; the request validator needs it active
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'PL';
        $country->active = true;
        Country::register($country);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function suspectedReceipt()
    {
        return $this->makeReceipt(array(
            'status' => 'duplicate_suspected',
            'ifirma_id' => '',
            'ifirma_number' => '',
            'duplicate_candidates' => json_encode(array(
                'candidates' => array(IfirmaDuplicateMatcher::summarize($this->remoteRow())),
                'resolution' => null,
            )),
        ));
    }

    // ---- create / issue anyway -----------------------------------------------------------

    public function testCreateReceiptSuccess()
    {
        $this->queueFullSuccess();
        $r = $this->actions->createReceipt(15, 3);

        $this->assertTrue($r['success']);
        $this->assertStringContainsString('Document issued: 7/2026', $r['message']);
        $this->assertSame('issued', $r['data']['outcome']);
        $this->assertSame('77', $r['data']['receipt']['ifirma_id']);
        $this->assertCount(1, $this->posts());
    }

    public function testCreateReceiptHeldByPendingRequest()
    {
        $this->makeRequest('pending');
        $r = $this->actions->createReceipt(15, 3);

        $this->assertFalse($r['success']);
        $this->assertStringContainsString('hold', $r['message']);
        $this->assertSame('held', $r['data']['outcome']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testCreateReceiptSuspectedDuplicate()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array('Rodzaj' => 'prz_faktura_kraj')))));
        $r = $this->actions->createReceipt(15, 3);

        $this->assertFalse($r['success']);
        $this->assertSame('suspected', $r['data']['outcome']);
        $this->assertStringContainsString('guard', $r['message']);
        $this->assertSame(array(), $this->posts());
        $this->assertSame('duplicate_suspected', (new IfirmaReceipt(1))->status);
    }

    public function testCreateReceiptReportsOutcomeStopAndReceiptInThatOrder()
    {
        $this->queueFullSuccess();
        $r = $this->actions->createReceipt(15, 3);

        $this->assertSame(
            array(
                'outcome' => 'issued',
                'stopped_at' => null,
                'receipt' => $this->actions->receiptToArray(new IfirmaReceipt(1)),
            ),
            $r['data']
        );
    }

    public function testCreateReceiptReportsTheStepThatStoppedTheRun()
    {
        $this->makeRequest('pending');
        $held = $this->actions->createReceipt(15, 3);

        $this->assertFalse($held['success']);
        $this->assertSame(
            array(
                'outcome' => 'held',
                'stopped_at' => 'hold',
                'receipt' => $this->actions->receiptToArray(new IfirmaReceipt(1)),
            ),
            $held['data']
        );
    }

    public function testCreateReceiptSuspectedReportsTheGuardAsTheStoppingStep()
    {
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array('Rodzaj' => 'prz_faktura_kraj')))));
        $r = $this->actions->createReceipt(15, 3);

        $this->assertFalse($r['success']);
        $this->assertSame(
            array(
                'outcome' => 'suspected',
                'stopped_at' => 'guard',
                'receipt' => $this->actions->receiptToArray(new IfirmaReceipt(1)),
            ),
            $r['data']
        );
    }

    public function testCreateReceiptWithNoStoredReceiptReportsAnEmptyReceipt()
    {
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`\s+WHERE id_order = (\d+)/', function () {
            throw new RuntimeException('receipt table unavailable');
        });

        $r = $this->actions->createReceipt(15, 3);

        $this->assertSame(
            array(
                'success' => false,
                'message' => 'Stopped at the "hold" step (error): receipt table unavailable',
                'data' => array('outcome' => 'error', 'stopped_at' => 'hold', 'receipt' => array()),
            ),
            $r
        );
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testCreateReceiptInvalidOrderAndUnconfigured()
    {
        $this->assertFalse($this->actions->createReceipt(999, 3)['success']);
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', '');
        $r = $this->actions->createReceipt(15, 3);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('not configured', $r['message']);
    }

    public function testIssueAnywaySkipsTheListCallAndRecordsResolution()
    {
        $this->suspectedReceipt();
        $this->queueCreateOk();
        $this->queueNumber();
        $this->queuePdf();

        $r = $this->actions->issueAnyway(15, 3);

        $this->assertTrue($r['success']);
        $this->assertCount(1, $this->posts());
        // the only list call is the number lookup after creation, not a duplicate-guard query
        $this->assertSame(1, $this->listCalls());
        $stored = (new IfirmaReceipt(1))->getDuplicateCandidates();
        $this->assertSame('issue_anyway', $stored['resolution']['action']);
        $this->assertSame(3, $stored['resolution']['id_employee']);
    }

    // ---- link candidate --------------------------------------------------------------------

    public function testLinkCandidateAdoptsTheExternalDocument()
    {
        $this->suspectedReceipt();
        $r = $this->actions->linkCandidate(15, '77', 3);

        $this->assertTrue($r['success']);
        $this->assertStringContainsString('7/2026', $r['message']);
        $this->assertSame('external', $r['data']['receipt']['source']);
        $receipt = new IfirmaReceipt(1);
        $this->assertSame('77', (string) $receipt->ifirma_id);
        $this->assertSame('external', $receipt->source);
        $this->assertSame('skipped', $receipt->delivery_status);
        $this->assertSame(array(), $this->posts());
        $this->assertSame(array(), Mail::$sent);
        $this->assertSame('link', $receipt->getDuplicateCandidates()['resolution']['action']);
    }

    public function testLinkUnknownCandidateFails()
    {
        $this->suspectedReceipt();
        $r = $this->actions->linkCandidate(15, '12345', 3);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('not found', $r['message']);
    }

    public function testLinkWithoutReceiptFails()
    {
        $this->assertFalse($this->actions->linkCandidate(15, '77', 3)['success']);
        $this->suspectedReceipt();
        $this->assertFalse($this->actions->linkCandidate(15, '', 3)['success']);
    }

    // ---- update status -----------------------------------------------------------------------

    public function testUpdateStatusWorksForAnAdoptedDocument()
    {
        $this->suspectedReceipt();
        $this->actions->linkCandidate(15, '77', 3);
        $this->t->queue('POST', '#/prz_faktura_kraj/#', array('body' => '{"response":{"Kod":0,"Informacja":"ok"}}'));
        $this->t->queue('POST', '#\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok"}}'));

        $r = $this->actions->updateStatus(15, 123.0);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('paid', $r['data']['receipt']['status']);
        $this->assertEquals(123.0, $r['data']['receipt']['paid_amount']);
        $this->assertCount(1, $this->posts());
    }

    public function testUpdateStatusWithoutIssuedDocumentFails()
    {
        $r = $this->actions->updateStatus(15);
        $this->assertFalse($r['success']);
        $this->makeReceipt(array('status' => 'error', 'ifirma_id' => ''));
        $this->assertFalse($this->actions->updateStatus(15)['success']);
    }

    // ---- resend ------------------------------------------------------------------------------

    public function testResendUpdatesTheDeliveryState()
    {
        $this->makeReceipt();
        $this->queuePdf();

        $r = $this->actions->resend(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertCount(1, Mail::$sent);
        $receipt = new IfirmaReceipt(1);
        $this->assertSame('sent', $receipt->delivery_status);
        $this->assertSame('shop_mail', $receipt->delivery_channel);
        $this->assertSame('sent', $r['data']['receipt']['delivery_status']);
        $this->assertStringContainsString('shop e-mail', $r['data']['delivery']);
    }

    public function testResendAnswersWithTheDocumentSentEnvelope()
    {
        $this->makeReceipt();
        $this->queuePdf();

        $r = $this->actions->resend(15);

        $this->assertTrue($r['success']);
        $this->assertSame('Document sent.', $r['message']);
        $this->assertSame(array('receipt', 'delivery'), array_keys($r['data']));
        $this->assertMatchesRegularExpression(
            '/^Sent on \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} via shop e-mail$/',
            $r['data']['delivery']
        );
    }

    public function testResendOfAChannelNoneInstallAnswersWithTheServiceReason()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $this->makeReceipt();

        $r = $this->actions->resend(15);

        $this->assertFalse($r['success']);
        $this->assertSame('Delivery is disabled (channel none)', $r['message']);
        $this->assertSame(array('receipt', 'delivery'), array_keys($r['data']));
        $this->assertSame('none', $r['data']['receipt']['delivery_status']);
        $this->assertSame('Not sent', $r['data']['delivery']);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testResendFailureAnswersWithTheMailSystemReason()
    {
        Mail::$result = false;
        $this->makeReceipt();
        $this->queuePdf();

        $r = $this->actions->resend(15);

        $this->assertFalse($r['success']);
        $this->assertSame('The shop mail system refused to send the e-mail.', $r['message']);
        $this->assertSame('failed', $r['data']['receipt']['delivery_status']);
        $this->assertSame('Failed: The shop mail system refused to send the e-mail.', $r['data']['delivery']);
    }

    public function testResendWithChannelNoneReportsFailure()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $this->makeReceipt();

        $r = $this->actions->resend(15);
        $this->assertFalse($r['success']);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testResendWithoutDocumentFails()
    {
        $this->assertFalse($this->actions->resend(15)['success']);
    }

    // ---- KSeF --------------------------------------------------------------------------------

    public function testSendToKsefForcesSubmissionEvenWhenModeIsOff()
    {
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'off');
        $this->makeReceipt();
        $this->t->queue('POST', '#fakturakraj/ksef/send/77\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Wyslano"}}'));

        $r = $this->actions->sendToKsef(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertCount(1, $this->posts());
        $this->assertSame('{"DataWysylki":null}', $this->posts()[0]['body']);
        $this->assertSame('WYSLANA', $r['data']['receipt']['ksef_status']);
        $this->assertNotEmpty($r['data']['receipt']['ksef_sent_at']);
        $this->assertSame('', $r['data']['receipt']['ksef_error']);
        $this->assertSame('WYSLANA', (new IfirmaReceipt(1))->ksef_status);
    }

    public function testSendToKsefReportsFailure()
    {
        $this->makeReceipt();
        $this->t->queue('POST', '#ksef/send#', array('body' => '{"response":{"Kod":200,"Informacja":"Odmowa"}}'));

        $r = $this->actions->sendToKsef(15);

        $this->assertFalse($r['success']);
        $this->assertSame('BLAD', $r['data']['receipt']['ksef_status']);
        $this->assertNotSame('', $r['data']['receipt']['ksef_error']);
    }

    public function testSendToKsefWithoutDocumentFails()
    {
        $this->assertFalse($this->actions->sendToKsef(15)['success']);
        $this->assertCount(0, $this->posts());
    }

    // ---- refresh -----------------------------------------------------------------------------

    public function testRefreshStatusUpdatesRemoteFields()
    {
        $this->makeReceipt(array('last_sync_at' => null));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array(
            'Zaplacono' => 50.0, 'CzyWyslano' => true, 'KsefStatus' => 'ACCEPTED', 'KsefNumer' => 'KS-1',
        )))));

        $r = $this->actions->refreshStatus(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertEquals(50.0, $r['data']['receipt']['remote_paid_amount']);
        $this->assertTrue($r['data']['receipt']['remote_sent']);
        $this->assertSame('ACCEPTED', $r['data']['receipt']['ksef_status']);
        $this->assertNotEmpty($r['data']['receipt']['last_sync_at']);
        $this->assertSame(1, $this->listCalls());
    }

    public function testRefreshStatusWhenIFirmaIsUnreachable()
    {
        $this->makeReceipt(array('last_sync_at' => null));
        $r = $this->actions->refreshStatus(15);

        $this->assertFalse($r['success']);
        $this->assertNotSame('', $r['data']['receipt']['sync_error']);
    }

    public function testRefreshStatusWithoutDocumentFails()
    {
        $this->assertFalse($this->actions->refreshStatus(15)['success']);
        $this->assertSame(array(), $this->t->calls);
    }

    // ---- data requests -----------------------------------------------------------------------

    public function testApproveRunsTheHeldIssuanceWhenTheTriggerStateWasReached()
    {
        $this->makeReceipt(array('status' => 'held', 'ifirma_id' => '', 'ifirma_number' => ''));
        $req = $this->makeRequest('pending');
        $o = $this->wiringOrder();
        $o->current_state = 2;
        $o->history = array(array('id_order_state' => 1), array('id_order_state' => 5));
        Order::register($o);
        $this->queueFullSuccess();

        $r = $this->actions->approveRequest((int) $req->id, 3, null, 'ok');

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('approved', (new IfirmaDataRequest((int) $req->id))->status);
        $this->assertTrue($r['data']['issuance_ran']);
        $this->assertTrue($r['data']['issued']);
        $this->assertCount(1, $this->posts());
        $this->assertSame('created', (new IfirmaReceipt(1))->status);
    }

    public function testApproveWithoutTriggerInHistoryDoesNotIssue()
    {
        $req = $this->makeRequest('pending');
        $o = $this->wiringOrder();
        $o->current_state = 2;
        $o->history = array(array('id_order_state' => 1));
        Order::register($o);

        $r = $this->actions->approveRequest((int) $req->id, 3);

        $this->assertTrue($r['success']);
        $this->assertFalse($r['data']['issuance_ran']);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame('approved', (new IfirmaDataRequest((int) $req->id))->status);
    }

    public function testApproveWithEditedDataStoresTheEdits()
    {
        $req = $this->makeRequest('pending');
        $r = $this->actions->approveRequest((int) $req->id, 3, array(
            'company' => 'Edited Ltd', 'vat_number' => '526-025-02-74', 'firstname' => 'Jan', 'lastname' => 'Kowalski',
            'address1' => 'Edytowana 3', 'postcode' => '00-003', 'city' => 'Lodz', 'id_country' => 14,
        ), 'fixed');

        $this->assertTrue($r['success'], $r['message']);
        $stored = new IfirmaDataRequest((int) $req->id);
        $this->assertSame('Edytowana 3', $stored->getApprovedData()['address1']);
        $this->assertSame('fixed', $stored->admin_note);
        $this->assertSame(3, (int) $stored->resolved_by);
    }

    public function testApproveWithInvalidEditReturnsFieldErrors()
    {
        $req = $this->makeRequest('pending');
        $r = $this->actions->approveRequest((int) $req->id, 3, array('postcode' => '', 'city' => ''));

        $this->assertFalse($r['success']);
        $this->assertArrayHasKey('postcode', $r['data']['errors']);
        $this->assertArrayHasKey('city', $r['data']['errors']);
        $this->assertSame('invalid', $r['data']['code']);
        $this->assertSame('pending', (new IfirmaDataRequest((int) $req->id))->status);
    }

    public function testApproveUnknownOrResolvedRequestFails()
    {
        $this->assertFalse($this->actions->approveRequest(999, 3)['success']);
        $req = $this->makeRequest('rejected');
        $r = $this->actions->approveRequest((int) $req->id, 3);
        $this->assertFalse($r['success']);
        $this->assertSame('not_pending', $r['data']['code']);
    }

    public function testRejectStoresTheReasonAndResumes()
    {
        $req = $this->makeRequest('pending');
        $o = $this->wiringOrder();
        $o->current_state = 2;
        $o->history = array(array('id_order_state' => 5));
        Order::register($o);
        $this->queueFullSuccess();

        $r = $this->actions->rejectRequest((int) $req->id, 3, 'NIP does not match');

        $this->assertTrue($r['success'], $r['message']);
        $stored = new IfirmaDataRequest((int) $req->id);
        $this->assertSame('rejected', $stored->status);
        $this->assertSame('NIP does not match', $stored->admin_note);
        $this->assertTrue($r['data']['issuance_ran']);
        $this->assertCount(1, $this->posts());
    }

    public function testRejectWithoutReasonFails()
    {
        $req = $this->makeRequest('pending');
        $r = $this->actions->rejectRequest((int) $req->id, 3, '   ');

        // The action's own wording and only the code: not the guard's failure() envelope.
        $this->assertSame(
            array('success' => false, 'message' => 'A reason is required to reject the request.', 'data' => array('code' => 'reason_required')),
            $r
        );
        $this->assertSame('pending', (new IfirmaDataRequest((int) $req->id))->status);
    }

    // ---- serialisation -----------------------------------------------------------------------

    public function testReceiptToArrayExposesTheDocumentAndRemoteFields()
    {
        $receipt = $this->makeReceipt(array('remote_paid_amount' => 10.5, 'remote_sent' => 1, 'ksef_number' => 'KS-9', 'source' => 'external'));
        $a = $this->actions->receiptToArray($receipt);

        foreach (array('id', 'ifirma_id', 'ifirma_number', 'status', 'source', 'paid_amount', 'issue_date', 'remote_paid_amount',
            'remote_sent', 'ksef_status', 'ksef_number', 'linked_document', 'last_sync_at', 'sync_error',
            'delivery_status', 'delivery_channel', 'delivered_at', 'delivery_error') as $key) {
            $this->assertArrayHasKey($key, $a, $key);
        }
        $this->assertSame('external', $a['source']);
        $this->assertEquals(10.5, $a['remote_paid_amount']);
        $this->assertTrue($a['remote_sent']);
    }

    // ---- contractor registration -----------------------------------------------------------

    public function testRegisterContractorCreatesMissingRecord()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"124451232"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[{"Identyfikator":"PS-C5","Nazwa":"Jan K"}]}}'));

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('PS-C5', $r['data']['identifier']);
        $this->assertSame('created', $r['data']['action']);
        $this->assertSame('Contractor PS-C5 created in iFirma.', $r['message']);
        $this->assertSame(array('GET', 'GET', 'POST', 'GET'), array_column($this->t->calls, 'method'));
        $this->assertSame('/iapi/kontrahenci/id/PS-C5.json', parse_url($this->t->calls[0]['url'], PHP_URL_PATH));
        $this->assertSame('/iapi/kontrahenci/Jan%20K.json', parse_url($this->t->calls[1]['url'], PHP_URL_PATH));
        $this->assertSame('/iapi/kontrahenci.json', parse_url($this->t->calls[2]['url'], PHP_URL_PATH));
        $this->assertSame('/iapi/kontrahenci/id/PS-C5.json', parse_url($this->t->calls[3]['url'], PHP_URL_PATH));
    }

    public function testRegisterContractorUsesTheBuyerDataOfAnApprovedRequest()
    {
        $request = $this->makeRequest('approved', array(
            'want_invoice_for_company' => true,
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => '5260250274',
            'firstname' => 'Jan',
            'lastname' => 'Kowalski',
            'address1' => 'Nowa 2',
            'postcode' => '00-002',
            'city' => 'Warszawa',
            'id_country' => 14,
        ));
        $request->setApprovedData($request->getRequestedData());
        $request->save();
        $created = false;
        $this->t->queue('POST', '#kontrahenci\.json#', function () use (&$created) {
            $created = true;

            return array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"124451232"}}');
        });
        $this->t->queue('GET', '#kontrahenci/#', function () use (&$created) {
            return array('body' => $created
                ? '{"response":{"Kod":0,"Informacja":"","Wynik":[{"Identyfikator":"PS-C5","Nazwa":"ACME Sp. z o.o."}]}}'
                : '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
        });

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('created', $r['data']['action']);
        $posts = $this->posts();
        $this->assertCount(1, $posts);
        $sent = json_decode($posts[0]['body'], true);
        $this->assertSame('ACME Sp. z o.o.', $sent['Nazwa']);
        $this->assertSame('5260250274', $sent['NIP']);
        $this->assertSame('Nowa 2', $sent['Ulica']);
        $this->assertFalse($sent['OsobaFizyczna']);
    }

    public function testRegisterContractorKeepsAnUpToDateRecord()
    {
        $contractor = IfirmaPayloadBuilder::build($this->module)->buildContractor(Order::$instances[15]);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => json_encode(array(
            'response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array($contractor)),
        ))));

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('kept', $r['data']['action']);
        $this->assertSame('Contractor PS-C5 already exists in iFirma and is up to date.', $r['message']);
        $this->assertCount(1, $this->t->calls);
    }

    public function testRegisterContractorFallsBackWhenIFirmaIsUnreachable()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->actions->registerContractor(15);

        $this->assertFalse($r['success']);
        $this->assertSame('fallback', $r['data']['action']);
        $this->assertStringContainsString('could not be registered', $r['message']);
        $this->assertCount(1, $this->t->calls);
    }

    public function testRegisterContractorInvalidOrderAndDisabledSync()
    {
        $invalid = $this->actions->registerContractor(999);
        $this->assertFalse($invalid['success']);
        $this->assertSame('Invalid order.', $invalid['message']);

        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $r = $this->actions->registerContractor(15);
        $this->assertFalse($r['success']);
        $this->assertSame('Contractor registration is disabled in the module settings.', $r['message']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testRegisterContractorReportsTheLinkToAnExistingContractor()
    {
        Address::$instances[7]->company = 'ACME Sp. z o.o.';
        Address::$instances[7]->vat_number = '525-224-84-81';
        $row = array_merge(
            IfirmaPayloadBuilder::build($this->module)->buildContractor(Order::$instances[15]),
            array('Identyfikator' => 'ACME-02')
        );
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row))))));

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('ACME-02', $r['data']['identifier']);
        $this->assertSame('nip', $r['data']['matched_by']);
        $this->assertStringContainsString('Linked to existing contractor ACME-02 (matched by NIP)', $r['message']);
        $this->assertSame(array(), $this->posts());
        $rows = IfirmaTestState::objects('ifirma_contractor_map');
        $this->assertSame('ACME-02', reset($rows)['ifirma_identifier']);
    }

    // ---- contractor registration: the whole answer of every outcome (refactor-clean-code-tda 13.1 + 13.2) --

    public function testRegisterContractorAnswersTheWholeEnvelopeOfACreatedRecord()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"124451232"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[{"Identyfikator":"PS-C5","Nazwa":"Jan K"}]}}'));

        $r = $this->actions->registerContractor(15);

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

    public function testRegisterContractorAnswersTheWholeEnvelopeOfARecordCreatedUnderAnAssignedIdentifier()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"124451232"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/id/124451232\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[{"Identyfikator":"124451232","Nazwa":"Jan K"}]}}'));

        $r = $this->actions->registerContractor(15);

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Contractor 124451232 created in iFirma.',
                'data' => array(
                    'identifier' => '124451232',
                    'action' => 'created',
                    'message' => '',
                    'matched_by' => 'module',
                    'note' => array('code' => 'assigned', 'params' => array('identifier' => '124451232')),
                    'assigned_identifier' => '124451232',
                ),
            ),
            $r
        );
    }

    public function testRegisterContractorAnswersTheWholeEnvelopeOfAnUpdatedRecord()
    {
        $outdated = array_merge(
            IfirmaPayloadBuilder::build($this->module)->buildContractor(Order::$instances[15]),
            array('Miejscowosc' => 'Stare Miasto')
        );
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => json_encode(array(
            'response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array($outdated)),
        ))));
        $this->t->queue('PUT', '#kontrahenci/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Zaktualizowano kontrahenta"}}'));

        $r = $this->actions->registerContractor(15);

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Contractor PS-C5 updated in iFirma.',
                'data' => array(
                    'identifier' => 'PS-C5',
                    'action' => 'updated',
                    'message' => '',
                    'matched_by' => 'module',
                    'note' => null,
                    'assigned_identifier' => null,
                ),
            ),
            $r
        );
    }

    public function testRegisterContractorAnswersTheWholeEnvelopeOfAKeptRecord()
    {
        $contractor = IfirmaPayloadBuilder::build($this->module)->buildContractor(Order::$instances[15]);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => json_encode(array(
            'response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array($contractor)),
        ))));

        $r = $this->actions->registerContractor(15);

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Contractor PS-C5 already exists in iFirma and is up to date.',
                'data' => array(
                    'identifier' => 'PS-C5',
                    'action' => 'kept',
                    'message' => '',
                    'matched_by' => 'module',
                    'note' => null,
                    'assigned_identifier' => null,
                ),
            ),
            $r
        );
    }

    public function testRegisterContractorAnswersTheWholeEnvelopeWhenIFirmaIsUnreachable()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->actions->registerContractor(15);

        $this->assertSame(
            array(
                'success' => false,
                'message' => 'Contractor PS-C5 could not be registered: cURL error: timeout',
                'data' => array(
                    'identifier' => 'PS-C5',
                    'action' => 'fallback',
                    'message' => 'cURL error: timeout',
                    'matched_by' => '',
                    'note' => array('code' => 'lookup_failed', 'params' => array('identifier' => 'PS-C5', 'error' => 'cURL error: timeout')),
                    'assigned_identifier' => null,
                ),
            ),
            $r
        );
    }

    public function testRegisterContractorAnswersTheWholeEnvelopeOfALinkToAnExistingContractor()
    {
        Address::$instances[7]->company = 'ACME Sp. z o.o.';
        Address::$instances[7]->vat_number = '525-224-84-81';
        $row = array_merge(
            IfirmaPayloadBuilder::build($this->module)->buildContractor(Order::$instances[15]),
            array('Identyfikator' => 'ACME-02')
        );
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/5252248481\.json#', array('body' => json_encode(array('response' => array('Kod' => 0, 'Wynik' => array($row))))));

        $r = $this->actions->registerContractor(15);

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Linked to existing contractor ACME-02 (matched by NIP). Contractor ACME-02 already exists in iFirma and is up to date.',
                'data' => array(
                    'identifier' => 'ACME-02',
                    'action' => 'kept',
                    'message' => '',
                    'matched_by' => 'nip',
                    'note' => null,
                    'assigned_identifier' => null,
                ),
            ),
            $r
        );
    }

    /**
     * A stored mapping whose matched_by is empty (the schema default) still names a contractor, but
     * no match code says how it was found. The answer must carry the mapped contractor, not PS-C5.
     */
    public function testRegisterContractorAnswersTheWholeEnvelopeOfAStoredMappingWithoutAMatchCode()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', '', 'Jan K');
        $mapped = array_merge(
            IfirmaPayloadBuilder::build($this->module)->buildContractor(Order::$instances[15]),
            array('Identyfikator' => 'KOWALSKI-01')
        );
        $this->t->queue('GET', '#kontrahenci/id/KOWALSKI-01\.json#', array('body' => json_encode(array(
            'response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array($mapped)),
        ))));

        $r = $this->actions->registerContractor(15);

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Contractor KOWALSKI-01 already exists in iFirma and is up to date.',
                'data' => array(
                    'identifier' => 'KOWALSKI-01',
                    'action' => 'kept',
                    'message' => '',
                    'matched_by' => '',
                    'note' => null,
                    'assigned_identifier' => null,
                ),
            ),
            $r
        );
        $this->assertCount(1, $this->t->calls);
        $this->assertSame('/iapi/kontrahenci/id/KOWALSKI-01.json', parse_url($this->t->calls[0]['url'], PHP_URL_PATH));
    }

    public function testRegisterContractorExplainsARejectedCreate()
    {
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}'));
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":201,"Informacja":"Zly kod pocztowy"}}'));

        $r = $this->actions->registerContractor(15);

        $this->assertSame(
            array(
                'success' => false,
                'message' => 'Contractor PS-C5 could not be registered: Validation error in document data (Kod 201): Zly kod pocztowy',
                'data' => array(
                    'identifier' => 'PS-C5',
                    'action' => 'fallback',
                    'message' => 'Validation error in document data (Kod 201): Zly kod pocztowy',
                    'matched_by' => '',
                    'note' => array('code' => 'create_rejected', 'params' => array('error' => 'Validation error in document data (Kod 201): Zly kod pocztowy')),
                    'assigned_identifier' => null,
                ),
            ),
            $r
        );
    }
}
