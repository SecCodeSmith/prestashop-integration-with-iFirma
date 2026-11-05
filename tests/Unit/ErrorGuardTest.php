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
 * The error guard of IfirmaAdminActions and of the order status hook.
 *
 * Whatever a collaborator throws, an Exception or an Error, the admin actions answer with the
 * failure envelope array('success' => false, 'message' => <the message>, 'data' => array()) and the
 * status hook writes a log entry; neither lets anything escape. Every guarded action has its own
 * test for both kinds of throwable, so an action that is left outside the guard, or a guard that
 * only catches Exception, fails here.
 *
 * Collaborators are made to throw at the first point where each action reaches them:
 * - a database that is down (every query throws), for the actions that start with a receipt or
 *   data-request lookup or that build the issuance pipeline;
 * - a customer service double, for the customer page actions.
 */
class ErrorGuardTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaThrowableProviders;

    /** @var IfirmaAdminActions */
    private $actions;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->actions = new IfirmaAdminActions($this->module);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    /**
     * The two data sets of throwables(), with the words of a database that went away.
     *
     * @return array name => array(Throwable)
     */
    public function failures()
    {
        return $this->throwablesRaising('database exploded', 'typed database exploded');
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** Every query from now on raises $failure, like a database that went away. */
    private function databaseIsDown(Throwable $failure)
    {
        Db::reset();
        Db::getInstance()->when('/./s', function () use ($failure) {
            throw $failure;
        });
    }

    /** The envelope an action returns for a throwable. */
    private function envelope(Throwable $failure)
    {
        return array('success' => false, 'message' => $failure->getMessage(), 'data' => array());
    }

    /** Replaces the customer service of the actions by a double whose $method raises $failure. */
    private function customerServiceRaises($method, Throwable $failure)
    {
        $service = $this->getMockBuilder('IfirmaCustomerContractorService')->disableOriginalConstructor()->getMock();
        $service->method($method)->willThrowException($failure);
        $this->actions->setCustomerService($service);
    }

    /** Actions on a module whose translator returns '' for the given source strings. */
    private function actionsWithBlankTranslation(array $blank)
    {
        $module = $this->getMockBuilder(get_class($this->module))->onlyMethods(array('l'))->getMock();
        $module->method('l')->willReturnCallback(function ($string) use ($blank) {
            return in_array($string, $blank, true) ? '' : $string;
        });

        return new IfirmaAdminActions($module);
    }

    private function stateObject($id)
    {
        $state = new OrderState();
        $state->id = $id;

        return $state;
    }

    // ---- order and document actions -----------------------------------------------------------

    /** @dataProvider failures */
    public function testCreateReceiptAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->createReceipt(15, 3));
    }

    /** @dataProvider failures */
    public function testIssueAnywayAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->issueAnyway(15, 3));
    }

    /** @dataProvider failures */
    public function testLinkCandidateAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->linkCandidate(15, '77', 3));
    }

    /** @dataProvider failures */
    public function testUpdateStatusAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->updateStatus(15, 123.0));
    }

    /** @dataProvider failures */
    public function testResendAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->resend(15));
    }

    /** @dataProvider failures */
    public function testSendToKsefAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->sendToKsef(15));
    }

    /** @dataProvider failures */
    public function testRefreshStatusAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->refreshStatus(15));
    }

    /** @dataProvider failures */
    public function testRegisterContractorAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->registerContractor(15));
    }

    // ---- services that refuse on their own ----------------------------------------------------

    public function testLinkCandidateWithAnUnknownOrderIsRefusedBeforeAnyLookup()
    {
        $r = $this->actions->linkCandidate(999, '77', 3);

        $this->assertSame(array('success' => false, 'message' => 'Invalid order.', 'data' => array()), $r);
    }

    public function testLinkCandidateReportsTheCandidateTheGuardDoesNotKnow()
    {
        $this->makeReceipt(array(
            'status' => 'duplicate_suspected',
            'ifirma_id' => '',
            'ifirma_number' => '',
            'duplicate_candidates' => json_encode(array(
                'candidates' => array(IfirmaDuplicateMatcher::summarize($this->remoteRow())),
                'resolution' => null,
            )),
        ));

        $r = $this->actions->linkCandidate(15, '12345', 3);

        $this->assertSame(
            array('success' => false, 'message' => 'Candidate 12345 not found for this order.', 'data' => array()),
            $r
        );
        $this->assertSame('', (string) (new IfirmaReceipt(1))->ifirma_id);
    }

    public function testUpdateStatusReportsAnUnreachableIFirmaWithTheTransportError()
    {
        $this->makeReceipt();
        $this->t->queue('POST', '#.#', array('status' => 0, 'error' => 'timeout'));

        $r = $this->actions->updateStatus(15, 123.0);

        $this->assertSame(array('success' => false, 'message' => 'Could not reach iFirma: cURL error: timeout', 'data' => array()), $r);
        $this->assertSame('created', (new IfirmaReceipt(1))->status);
    }

    public function testUpdateStatusAnswersWithTheFailureEnvelopeWhenTheHttpLayerThrows()
    {
        $this->makeReceipt();
        $failure = new TypeError('typed transport failure');
        $this->t->queue('POST', '#.#', function () use ($failure) {
            throw $failure;
        });

        $this->assertSame($this->envelope($failure), $this->actions->updateStatus(15, 123.0));
    }

    public function testResendUsesItsOwnWordingWhenTheServiceGivesNoReason()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $this->makeReceipt();
        $actions = $this->actionsWithBlankTranslation(array('Delivery is disabled (channel none)'));

        $r = $actions->resend(15);

        $this->assertFalse($r['success']);
        $this->assertSame('The document could not be sent.', $r['message']);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testSendToKsefUsesItsOwnWordingWhenTheServiceGivesNoReason()
    {
        $this->makeReceipt(array('ksef_number' => 'KS-1'));
        $actions = $this->actionsWithBlankTranslation(array('already in KSeF'));

        $r = $actions->sendToKsef(15);

        $this->assertFalse($r['success']);
        $this->assertSame('The document could not be sent to KSeF.', $r['message']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testRegisterContractorReportsAnUpdatedRecord()
    {
        $contractor = IfirmaReceiptService::build($this->module)->buildContractor(Order::$instances[15]);
        $stale = array_merge($contractor, array('Ulica' => 'Stara 2'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', array('body' => json_encode(array(
            'response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array($stale)),
        ))));
        $this->t->queue('PUT', '#kontrahenci/PS-C5\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Zaktualizowano kontrahenta"}}'));

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('Contractor PS-C5 updated in iFirma.', $r['message']);
        $this->assertSame('updated', $r['data']['action']);
        $this->assertSame('PS-C5', $r['data']['identifier']);
        $this->assertSame(array('GET', 'PUT'), array_column($this->t->calls, 'method'));
    }

    // ---- customer page actions ----------------------------------------------------------------

    /** @dataProvider failures */
    public function testCustomerCheckAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->customerServiceRaises('check', $failure);

        $this->assertSame($this->envelope($failure), $this->actions->customerCheck(5));
    }

    /** @dataProvider failures */
    public function testCustomerSearchAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->customerServiceRaises('searchCandidates', $failure);

        $this->assertSame($this->envelope($failure), $this->actions->customerSearch(array('lastname' => 'Nowak')));
    }

    /** @dataProvider failures */
    public function testCustomerLinkAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->customerServiceRaises('link', $failure);

        $this->assertSame($this->envelope($failure), $this->actions->customerLink(5, 'KOW-01'));
    }

    /** @dataProvider failures */
    public function testCustomerUnlinkAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->customerServiceRaises('unlink', $failure);

        $this->assertSame($this->envelope($failure), $this->actions->customerUnlink(5));
    }

    /**
     * With contractor registration switched off the registration itself answers without any
     * remote call; the link state read that follows is what raises.
     *
     * @dataProvider failures
     */
    public function testRegisterCustomerAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->customerServiceRaises('linkState', $failure);

        $this->assertSame($this->envelope($failure), $this->actions->registerCustomer(5));
        $this->assertSame(array(), $this->t->calls);
    }

    /** @dataProvider failures */
    public function testCustomerCountRemoteAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $this->customerServiceRaises('remoteCount', $failure);

        $this->assertSame($this->envelope($failure), $this->actions->customerCountRemote(5));
    }

    public function testARefusalFromAnyActionKeepsItsFieldErrorsAndCode()
    {
        $refusal = new IfirmaDataRequestException('Please correct the highlighted fields.');
        $refusal->code_name = 'invalid';
        $refusal->errors = array('postcode' => 'The postcode is required.');
        $this->customerServiceRaises('check', $refusal);

        $r = $this->actions->customerCheck(5);

        $this->assertSame(
            array(
                'success' => false,
                'message' => 'Please correct the highlighted fields.',
                'data' => array('errors' => array('postcode' => 'The postcode is required.'), 'code' => 'invalid'),
            ),
            $r
        );
    }

    // ---- data request actions -----------------------------------------------------------------

    /**
     * Approving stores the decision first; the failure comes from resuming the held issuance
     * afterwards, which builds the pipeline and so reads the database schema.
     *
     * @dataProvider failures
     */
    public function testApproveRequestAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $request = $this->makeRequest('pending');
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->approveRequest((int) $request->id, 3));
    }

    /**
     * @see testApproveRequestAnswersWithTheFailureEnvelope
     * @dataProvider failures
     */
    public function testRejectRequestAnswersWithTheFailureEnvelope(Throwable $failure)
    {
        $request = $this->makeRequest('pending');
        $this->databaseIsDown($failure);

        $this->assertSame($this->envelope($failure), $this->actions->rejectRequest((int) $request->id, 3, 'NIP does not match'));
    }

    public function testRejectUnknownRequestFails()
    {
        $r = $this->actions->rejectRequest(999, 3, 'NIP does not match');

        $this->assertSame(array('success' => false, 'message' => 'The request was not found.', 'data' => array()), $r);
    }

    public function testRejectResolvedRequestKeepsTheRefusalCode()
    {
        $request = $this->makeRequest('approved');

        $r = $this->actions->rejectRequest((int) $request->id, 3, 'Too late');

        $this->assertSame(
            array(
                'success' => false,
                'message' => 'This request is no longer pending.',
                'data' => array('errors' => array(), 'code' => 'not_pending'),
            ),
            $r
        );
        $this->assertSame('approved', (new IfirmaDataRequest((int) $request->id))->status);
    }

    // ---- order status hook (ifirma.php) -------------------------------------------------------

    /** @dataProvider failures */
    public function testStatusHookLogsAFailureInsteadOfThrowing(Throwable $failure)
    {
        $this->databaseIsDown($failure);

        $this->module->hookActionOrderStatusPostUpdate(array('id_order' => 15, 'newOrderStatus' => $this->stateObject(5)));

        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertSame(
            'iFirma status hook failed for order 15: ' . $failure->getMessage(),
            PrestaShopLogger::$logs[0]['message']
        );
        $this->assertSame(2, PrestaShopLogger::$logs[0]['severity']);
    }
}
