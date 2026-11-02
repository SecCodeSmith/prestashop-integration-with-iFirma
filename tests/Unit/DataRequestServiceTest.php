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

class DataRequestServiceTest extends TestCase
{
    /** @var IfirmaDataRequestService */
    private $service;

    /** @var Order */
    private $order;

    /** @var Customer */
    private $customer;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 1);

        $this->customer = new Customer();
        $this->customer->id = 7;
        $this->customer->email = 'buyer@example.test';
        $this->customer->firstname = 'Anna';
        $this->customer->lastname = 'Nowak';
        $this->customer->logged = true;
        Customer::register($this->customer);

        $address = new Address();
        $address->id = 3;
        $address->id_customer = 7;
        $address->firstname = 'Anna';
        $address->lastname = 'Nowak';
        $address->address1 = 'Stara 1';
        $address->postcode = '00-001';
        $address->city = 'Warszawa';
        $address->id_country = 14;
        Address::register($address);

        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'PL';
        Country::register($country);

        $this->order = new Order();
        $this->order->id = 15;
        $this->order->id_customer = 7;
        $this->order->id_lang = 2;
        $this->order->reference = 'ABCDEFGHI';
        $this->order->id_address_invoice = 3;
        Order::register($this->order);

        $this->service = new IfirmaDataRequestService(new Module());
    }

    private function companyInput(array $override = array())
    {
        return array_merge(array(
            'want_invoice_for_company' => '1',
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => '525-000-12-34',
            'firstname' => '',
            'lastname' => '',
            'address1' => 'Nowa 5',
            'address2' => '',
            'postcode' => '00-950',
            'city' => 'Warszawa',
            'id_country' => '14',
            'customer_note' => 'Please use company data',
        ), $override);
    }

    private function privateInput(array $override = array())
    {
        return array_merge(array(
            'want_invoice_for_company' => '',
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Nowa 5',
            'postcode' => '00-950',
            'city' => 'Warszawa',
            'id_country' => '14',
        ), $override);
    }

    private function submitPending(array $input = null)
    {
        return $this->service->submit($this->order, $this->customer, $input === null ? $this->companyInput() : $input);
    }

    private function assertRefused($code, callable $fn)
    {
        try {
            $fn();
            $this->fail('expected IfirmaDataRequestException ' . $code);
        } catch (IfirmaDataRequestException $e) {
            $this->assertSame($code, $e->code_name);

            return $e;
        }
    }

    // ---- eligibility -------------------------------------------------

    public function testEligibleCustomer()
    {
        $this->assertSame(array('allowed' => true, 'reason' => 'ok'), $this->service->canRequest($this->order, $this->customer));
    }

    public function testFeatureDisabled()
    {
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 0);
        $this->assertSame('disabled', $this->service->canRequest($this->order, $this->customer)['reason']);
        $this->assertRefused('disabled', function () {
            $this->submitPending();
        });
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testOrderOfAnotherCustomer()
    {
        $other = new Customer();
        $other->id = 8;
        $other->logged = true;
        $this->assertSame('not_owner', $this->service->canRequest($this->order, $other)['reason']);
        $this->assertRefused('not_owner', function () use ($other) {
            $this->service->submit($this->order, $other, $this->companyInput());
        });
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testPendingRequestExists()
    {
        $pending = new IfirmaDataRequest();
        $pending->id_order = 15;
        $pending->id_customer = 7;
        $pending->save();
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', (int) $pending->id);

        $this->assertSame('pending_exists', $this->service->canRequest($this->order, $this->customer)['reason']);
        $this->assertTrue($this->service->hasPendingForOrder(15));
        $this->assertSame((int) $pending->id, (int) $this->service->getPendingForOrder(15)->id);
        $this->assertRefused('pending_exists', function () {
            $this->submitPending();
        });
        $this->assertCount(1, IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testDocumentAlreadyIssued()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->status = IfirmaReceipt::STATUS_CREATED;
        $r->ifirma_id = '99';
        $r->save();
        Db::getInstance()->whenGetValue('/FROM `ps_ifirma_receipt`/', (int) $r->id);

        $this->assertSame('document_exists', $this->service->canRequest($this->order, $this->customer)['reason']);
        $this->assertRefused('document_exists', function () {
            $this->submitPending();
        });
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    // ---- validation --------------------------------------------------

    public function testNormalizeNip()
    {
        $this->assertSame('5250001234', $this->service->normalizeNip('525-000-12-34'));
        $this->assertSame('5250001234', $this->service->normalizeNip(' PL 525 000 12 34'));
    }

    public function testFieldsList()
    {
        $this->assertContains('customer_note', $this->service->fields());
        $this->assertContains('want_invoice_for_company', $this->service->fields());
    }

    public function testValidCompanyStoresNormalizedNip()
    {
        $this->assertSame(array(), $this->service->validate($this->companyInput()));
        $request = $this->submitPending();
        $data = $request->getRequestedData();
        $this->assertSame('5250001234', $data['vat_number']);
        $this->assertTrue($data['want_invoice_for_company']);
        $this->assertSame(14, $data['id_country']);
    }

    public function testCompanyWithoutNip()
    {
        $errors = $this->service->validate($this->companyInput(array('vat_number' => '')));
        $this->assertArrayHasKey('vat_number', $errors);
        $e = $this->assertRefused('invalid', function () {
            $this->submitPending($this->companyInput(array('vat_number' => '')));
        });
        $this->assertArrayHasKey('vat_number', $e->errors);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testMalformedNip()
    {
        $errors = $this->service->validate($this->companyInput(array('vat_number' => '525-000-12-3')));
        $this->assertArrayHasKey('vat_number', $errors);
    }

    public function testCompanyNameRequired()
    {
        $this->assertArrayHasKey('company', $this->service->validate($this->companyInput(array('company' => ' '))));
    }

    public function testMissingPostcodeOrCity()
    {
        $errors = $this->service->validate($this->privateInput(array('postcode' => '', 'city' => '')));
        $this->assertArrayHasKey('postcode', $errors);
        $this->assertArrayHasKey('city', $errors);
        $this->assertArrayNotHasKey('firstname', $errors);
        $this->assertCount(1, $this->service->validate($this->privateInput(array('city' => ''))));
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testPrivatePersonAccepted()
    {
        $this->assertSame(array(), $this->service->validate($this->privateInput()));
        $errors = $this->service->validate($this->privateInput(array('firstname' => '', 'lastname' => '')));
        $this->assertArrayHasKey('firstname', $errors);
        $this->assertArrayHasKey('lastname', $errors);
    }

    public function testCountryAndLengthLimits()
    {
        $this->assertArrayHasKey('id_country', $this->service->validate($this->privateInput(array('id_country' => '-3'))));
        $this->assertArrayHasKey('id_country', $this->service->validate($this->privateInput(array('id_country' => 'abc'))));
        $this->assertArrayHasKey('city', $this->service->validate($this->privateInput(array('city' => str_repeat('x', 66)))));
        $this->assertArrayHasKey('customer_note', $this->service->validate($this->privateInput(array('customer_note' => str_repeat('n', 1001)))));
        $this->assertArrayHasKey('company', $this->service->validate($this->companyInput(array('company' => str_repeat('c', 151)))));
        $this->assertSame(array(), $this->service->validate($this->companyInput(array('company' => str_repeat('c', 150)))));
    }

    // ---- submission and mail -----------------------------------------

    public function testSubmitStoresPendingAndMailsConfiguredAddress()
    {
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', 'merchant@example.test');
        $request = $this->submitPending();

        $rows = IfirmaTestState::objects('ifirma_data_request');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(15, (int) $row['id_order']);
        $this->assertSame(7, (int) $row['id_customer']);
        $this->assertSame('Please use company data', $row['customer_note']);
        $this->assertNotEmpty($row['date_add']);
        $this->assertSame('ACME Sp. z o.o.', $request->getRequestedData()['company']);

        $this->assertCount(1, Mail::$sent);
        $mail = Mail::$sent[0];
        $this->assertSame('merchant@example.test', $mail['to']);
        $this->assertSame('data_request_received', $mail['template']);
        $this->assertStringContainsString('ABCDEFGHI', $mail['subject']);
        $this->assertSame('ABCDEFGHI', $mail['templateVars']['{order_reference}']);
        $this->assertStringContainsString('company: - -&gt; ACME Sp. z o.o.', $mail['templateVars']['{diff}']);
        $this->assertStringContainsString('postcode: 00-001 -&gt; 00-950', $mail['templateVars']['{diff}']);
        $this->assertStringContainsString('5250001234', $mail['templateVars']['{requested_data}']);
        $this->assertStringContainsString('Please use company data', $mail['templateVars']['{note}']);
        $this->assertStringEndsWith('ifirma/mails/', str_replace('\\', '/', $mail['templatePath']));
    }

    public function testMerchantMailDefaultsToShopEmail()
    {
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', '');
        $this->submitPending();
        $this->assertSame('shop@example.test', Mail::$sent[0]['to']);
    }

    public function testMerchantMailEscapesCustomerInput()
    {
        $this->submitPending($this->companyInput(array('customer_note' => '<script>x</script>')));
        $this->assertStringNotContainsString('<script>', Mail::$sent[0]['templateVars']['{note}']);
    }

    public function testBuildDiffOnlyChangedFields()
    {
        $diff = $this->service->buildDiff(array('firstname' => 'Anna', 'lastname' => 'Nowak', 'address1' => 'Stara 1',
            'address2' => '', 'postcode' => '00-950', 'city' => 'Warszawa', 'id_country' => 14, 'company' => '', 'vat_number' => ''), new Address(3));
        $this->assertSame(array(array('field' => 'postcode', 'current' => '00-001', 'requested' => '00-950')), $diff);
    }

    // ---- cancel --------------------------------------------------------

    public function testCancelOwnPending()
    {
        $request = $this->submitPending();
        $this->assertTrue($this->service->cancel($request, $this->customer));
        $this->assertSame(IfirmaDataRequest::STATUS_CANCELLED, $request->status);
        $this->assertNotEmpty($request->date_resolved);
        $rows = IfirmaTestState::objects('ifirma_data_request');
        $this->assertSame('cancelled', reset($rows)['status']);
    }

    public function testCancelResolvedRequestRefused()
    {
        $request = $this->submitPending();
        $this->service->reject($request, 1, 'No');
        $this->assertRefused('not_pending', function () use ($request) {
            $this->service->cancel($request, $this->customer);
        });
        $this->assertSame(IfirmaDataRequest::STATUS_REJECTED, $request->status);
    }

    public function testCancelOtherCustomersRequestRefused()
    {
        $request = $this->submitPending();
        $other = new Customer();
        $other->id = 8;
        $this->assertRefused('not_owner', function () use ($request, $other) {
            $this->service->cancel($request, $other);
        });
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
    }

    // ---- approve / reject ----------------------------------------------

    public function testApproveUnchanged()
    {
        $request = $this->submitPending();
        Mail::$sent = array();
        $fixed = '2026-11-01 10:00:00';
        $service = new IfirmaDataRequestService(new Module(), function () use ($fixed) {
            return $fixed;
        });
        $service->approve($request, 4, null, 'ok');

        $this->assertSame(IfirmaDataRequest::STATUS_APPROVED, $request->status);
        $this->assertSame(4, (int) $request->resolved_by);
        $this->assertSame($fixed, $request->date_resolved);
        $this->assertSame('ok', $request->admin_note);
        $this->assertSame($request->getRequestedData(), $request->getApprovedData());

        $this->assertCount(1, Mail::$sent);
        $this->assertSame('data_request_approved', Mail::$sent[0]['template']);
        $this->assertSame('buyer@example.test', Mail::$sent[0]['to']);
        $this->assertSame(2, Mail::$sent[0]['idLang']);
        $this->assertSame('Anna', Mail::$sent[0]['templateVars']['{firstname}']);
        $this->assertStringContainsString('5250001234', Mail::$sent[0]['templateVars']['{approved_data}']);
    }

    public function testApproveWithEditsKeepsOriginal()
    {
        $request = $this->submitPending();
        $this->service->approve($request, 4, $this->companyInput(array('postcode' => '00-951')));

        $this->assertSame('00-951', $request->getApprovedData()['postcode']);
        $this->assertSame('00-950', $request->getRequestedData()['postcode']);
        $this->assertSame(IfirmaDataRequest::STATUS_APPROVED, $request->status);
    }

    public function testInvalidEditRefusedAndStaysPending()
    {
        $request = $this->submitPending();
        Mail::$sent = array();
        $e = $this->assertRefused('invalid', function () use ($request) {
            $this->service->approve($request, 4, $this->companyInput(array('vat_number' => '12345')));
        });
        $this->assertArrayHasKey('vat_number', $e->errors);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->approved_data);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testApproveResolvedRefused()
    {
        $request = $this->submitPending();
        $this->service->approve($request, 4);
        $this->assertRefused('not_pending', function () use ($request) {
            $this->service->approve($request, 4);
        });
        $this->assertRefused('not_pending', function () use ($request) {
            $this->service->reject($request, 4, 'late');
        });
    }

    public function testRejectWithReason()
    {
        $request = $this->submitPending();
        Mail::$sent = array();
        $this->service->reject($request, 4, ' NIP does not match ');

        $this->assertSame(IfirmaDataRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('NIP does not match', $request->admin_note);
        $this->assertSame(4, (int) $request->resolved_by);
        $this->assertNotEmpty($request->date_resolved);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('data_request_rejected', Mail::$sent[0]['template']);
        $this->assertStringContainsString('NIP does not match', Mail::$sent[0]['templateVars']['{reason}']);
        $this->assertSame('buyer@example.test', Mail::$sent[0]['to']);
    }

    public function testRejectWithoutReason()
    {
        $request = $this->submitPending();
        $this->assertRefused('reason_required', function () use ($request) {
            $this->service->reject($request, 4, '   ');
        });
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
    }

    public function testCustomerMailFallsBackToEnglishWhenTemplateMissing()
    {
        $this->order->id_lang = 5;
        Order::register($this->order);
        $request = $this->submitPending();
        Mail::$sent = array();
        $this->service->reject($request, 4, 'No');
        $this->assertSame(1, Mail::$sent[0]['idLang']);
    }

    // ---- override --------------------------------------------------------

    private function approvedRequest(array $edited = null)
    {
        $request = $this->submitPending($this->companyInput());
        $this->service->approve($request, 4, $edited);
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "approved"/s', (int) $request->id);

        return $request;
    }

    public function testNoApprovedRequestMeansNoOverride()
    {
        $this->assertNull($this->service->getOverrideForOrder(15));
    }

    public function testOverrideMapping()
    {
        $this->approvedRequest();
        $this->assertSame(array(
            'want_invoice_for_company' => true,
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => '5250001234',
            'firstname' => '',
            'lastname' => '',
            'address1' => 'Nowa 5',
            'address2' => '',
            'postcode' => '00-950',
            'city' => 'Warszawa',
            'id_country' => 14,
        ), $this->service->getOverrideForOrder(15));
    }

    public function testOverrideDrivesContractorAndLeavesAddressUntouched()
    {
        $this->approvedRequest();
        $receiptService = new IfirmaReceiptService(
            new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, new ScriptedTransport()),
            new Module()
        );
        $contractor = $receiptService->buildContractor($this->order, $this->service->getOverrideForOrder(15));

        $this->assertSame('ACME Sp. z o.o.', $contractor['Nazwa']);
        $this->assertSame('5250001234', $contractor['NIP']);
        $this->assertFalse($contractor['OsobaFizyczna']);
        $this->assertSame('00-950', $contractor['KodPocztowy']);
        $this->assertSame(array(), Address::$saved);
    }

    public function testPrivateOverrideContractor()
    {
        $request = $this->submitPending($this->privateInput());
        $this->service->approve($request, 4);
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "approved"/s', (int) $request->id);
        $receiptService = new IfirmaReceiptService(
            new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, new ScriptedTransport()),
            new Module()
        );
        $contractor = $receiptService->buildContractor($this->order, $this->service->getOverrideForOrder(15));
        $this->assertSame('Anna Nowak', $contractor['Nazwa']);
        $this->assertSame('', $contractor['NIP']);
        $this->assertTrue($contractor['OsobaFizyczna']);
    }

    // ---- summary and token ------------------------------------------------

    public function testSummarize()
    {
        $request = $this->submitPending();
        $this->service->approve($request, 4, $this->companyInput(array('postcode' => '00-951')), 'fixed postcode');
        $s = $this->service->summarize($request);

        $this->assertSame((int) $request->id, $s['id']);
        $this->assertSame('approved', $s['status']);
        $this->assertSame(7, $s['requester']);
        $this->assertSame(4, $s['resolved_by']);
        $this->assertSame('00-950', $s['requested_data']['postcode']);
        $this->assertSame('00-951', $s['approved_data']['postcode']);
        $this->assertSame('fixed postcode', $s['admin_note']);
        $this->assertSame('Please use company data', $s['customer_note']);
        $this->assertNotEmpty($s['date_add']);
        $this->assertNotEmpty($s['date_resolved']);
    }

    public function testTokenHelper()
    {
        $this->assertTrue(IfirmaDataRequestService::isValidToken('front-token'));
        $this->assertFalse(IfirmaDataRequestService::isValidToken('wrong'));
        $this->assertFalse(IfirmaDataRequestService::isValidToken(''));
        $this->assertFalse(IfirmaDataRequestService::isValidToken(false));
        $this->assertFalse(IfirmaDataRequestService::isValidToken(null));
    }

    // ---- controller --------------------------------------------------------

    private function controller()
    {
        Module::$instances['ifirma'] = new Module();
        Context::getContext()->customer = $this->customer;

        return IfirmaTestModuleLoader::loadFrontController('datarequest');
    }

    public function testControllerRefusesMissingTokenOnSubmission()
    {
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15, 'submitIfirmaDataRequest' => '1') + $this->companyInput();
        $controller->postProcess();

        $this->assertNotEmpty($controller->errors);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
        $this->assertSame(array(), Mail::$sent);
    }

    public function testControllerRefusesWrongTokenOnCancel()
    {
        $request = $this->submitPending();
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', (int) $request->id);
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15, 'cancelIfirmaDataRequest' => '1', 'token' => 'bad');
        $controller->postProcess();

        $this->assertNotEmpty($controller->errors);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
        $rows = IfirmaTestState::objects('ifirma_data_request');
        $this->assertSame('pending', reset($rows)['status']);
    }

    public function testControllerSubmitsWithValidTokenAndRedirects()
    {
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15, 'submitIfirmaDataRequest' => '1', 'token' => 'front-token') + $this->companyInput();
        try {
            $controller->postProcess();
            $this->fail('expected redirect');
        } catch (IfirmaTestRedirect $r) {
            $this->assertStringContainsString('order-detail', $r->getUrl());
        }
        $this->assertCount(1, IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testControllerShowsFieldErrorsOnInvalidInput()
    {
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15, 'submitIfirmaDataRequest' => '1', 'token' => 'front-token') + $this->companyInput(array('vat_number' => ''));
        $controller->postProcess();
        $this->assertArrayHasKey('vat_number', $controller->fieldErrors);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testControllerRedirectsForeignOrderToHistory()
    {
        $controller = $this->controller();
        $foreign = new Order();
        $foreign->id = 16;
        $foreign->id_customer = 99;
        Order::register($foreign);
        Tools::$values = array('id_order' => 16);
        try {
            $controller->initContent();
            $this->fail('expected redirect');
        } catch (IfirmaTestRedirect $r) {
            $this->assertStringContainsString('history', $r->getUrl());
        }
    }

    public function testControllerInitContentAssignsTemplateVars()
    {
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15);
        $controller->initContent();
        $this->assertSame('module:ifirma/views/templates/front/datarequest.tpl', $controller->template);
        $vars = Context::getContext()->smarty->vars;
        $this->assertSame('front-token', $vars['ifirma_token']);
        $this->assertTrue($vars['ifirma_eligibility']['allowed']);
    }
}
