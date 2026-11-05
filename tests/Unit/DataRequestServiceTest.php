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

    // ---- approve / reject: what they set, in which order, and how they refuse -------------

    /**
     * A stored request in the given status. It records every save() with the status being written
     * and the number of mails sent so far, which pins the order: fields set, save, mail.
     *
     * @param string $status
     * @return IfirmaDataRequest
     */
    private function storedRequest($status = IfirmaDataRequest::STATUS_PENDING)
    {
        $request = new class extends IfirmaDataRequest {
            /** @var array[] one entry per save() */
            public $saves = array();

            public function save($nullValues = false, $autoDate = true)
            {
                $this->saves[] = array('status' => $this->status, 'mails' => count(Mail::$sent));

                return parent::save($nullValues, $autoDate);
            }
        };
        $request->id_order = 15;
        $request->id_customer = 7;
        $request->status = $status;
        $request->setRequestedData($this->requestedData());
        $request->save();
        $request->saves = array();

        return $request;
    }

    /** @return array the data of companyInput(), as the service stores it */
    private function requestedData()
    {
        return array(
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
        );
    }

    /** @return array the saved row of the request */
    private function storedRow(IfirmaDataRequest $request)
    {
        $rows = IfirmaTestState::objects('ifirma_data_request');

        return $rows[$request->id];
    }

    /** @return IfirmaDataRequestService a service whose clock stands still */
    private function serviceAt($now)
    {
        return new IfirmaDataRequestService(new Module(), function () use ($now) {
            return $now;
        });
    }

    public function testApproveSavesOnceWithTheResolutionSetAndMailsAfterwards()
    {
        $request = $this->storedRequest();

        $returned = $this->serviceAt('2026-11-01 10:00:00')->approve($request, '4', null, '  fixed postcode  ');

        $this->assertSame($request, $returned);
        $this->assertSame(array(array('status' => 'approved', 'mails' => 0)), $request->saves);
        $this->assertCount(1, Mail::$sent);
        $row = $this->storedRow($request);
        $this->assertSame('approved', $row['status']);
        $this->assertSame(4, $row['resolved_by']);
        $this->assertSame('fixed postcode', $row['admin_note']);
        $this->assertSame('2026-11-01 10:00:00', $row['date_resolved']);
        $this->assertSame(json_encode($this->requestedData()), $row['approved_data']);
        $this->assertSame($row['requested_data'], $row['approved_data']);
    }

    public function testApproveWithoutANoteStoresAnEmptyNote()
    {
        $request = $this->storedRequest();

        $this->service->approve($request, 4, null, null);

        $this->assertSame('', $request->admin_note);
        $this->assertSame('', $this->storedRow($request)['admin_note']);
    }

    public function testRejectSavesOnceWithTheResolutionSetAndMailsAfterwards()
    {
        $request = $this->storedRequest();

        $returned = $this->serviceAt('2026-11-01 11:30:00')->reject($request, '4', '  NIP does not match  ');

        $this->assertSame($request, $returned);
        $this->assertSame(array(array('status' => 'rejected', 'mails' => 0)), $request->saves);
        $this->assertCount(1, Mail::$sent);
        $row = $this->storedRow($request);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame(4, $row['resolved_by']);
        $this->assertSame('NIP does not match', $row['admin_note']);
        $this->assertSame('2026-11-01 11:30:00', $row['date_resolved']);
        $this->assertNull($row['approved_data']);
    }

    public function testResolvedRequestsAreRefusedWithoutAnyChange()
    {
        $calls = array(
            'approve' => function ($request) {
                $this->service->approve($request, 4, null, 'late');
            },
            'reject' => function ($request) {
                $this->service->reject($request, 4, 'late');
            },
        );
        $statuses = array(
            IfirmaDataRequest::STATUS_APPROVED,
            IfirmaDataRequest::STATUS_REJECTED,
            IfirmaDataRequest::STATUS_CANCELLED,
        );
        foreach ($statuses as $status) {
            foreach ($calls as $name => $call) {
                $request = $this->storedRequest($status);
                $stored = $this->storedRow($request);
                $label = $name . ' on a ' . $status . ' request';

                $e = $this->assertRefused('not_pending', function () use ($call, $request) {
                    $call($request);
                });

                $this->assertSame('This request is no longer pending.', $e->getMessage(), $label);
                $this->assertSame(array(), $e->errors, $label);
                $this->assertSame($status, $request->status, $label);
                $this->assertNull($request->approved_data, $label);
                $this->assertNull($request->admin_note, $label);
                $this->assertNull($request->resolved_by, $label);
                $this->assertNull($request->date_resolved, $label);
                $this->assertSame(array(), $request->saves, $label);
                $this->assertSame($stored, $this->storedRow($request), $label);
                $this->assertSame(array(), Mail::$sent, $label);
            }
        }
    }

    public function testRejectWithoutReasonChangesNothing()
    {
        $request = $this->storedRequest();
        $stored = $this->storedRow($request);

        $e = $this->assertRefused('reason_required', function () use ($request) {
            $this->service->reject($request, 4, " \t ");
        });

        $this->assertSame('A reason is required.', $e->getMessage());
        $this->assertSame(array(), $e->errors);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->admin_note);
        $this->assertNull($request->resolved_by);
        $this->assertNull($request->date_resolved);
        $this->assertSame(array(), $request->saves);
        $this->assertSame($stored, $this->storedRow($request));
        $this->assertSame(array(), Mail::$sent);
    }

    public function testResolvedRequestIsRefusedBeforeItsEditsAreValidated()
    {
        $request = $this->storedRequest(IfirmaDataRequest::STATUS_REJECTED);

        $this->assertRefused('not_pending', function () use ($request) {
            $this->service->approve($request, 4, array('postcode' => '', 'city' => ''));
        });
    }

    public function testResolvedRequestIsRefusedBeforeTheReasonIsChecked()
    {
        $request = $this->storedRequest(IfirmaDataRequest::STATUS_CANCELLED);

        $this->assertRefused('not_pending', function () use ($request) {
            $this->service->reject($request, 4, '   ');
        });
    }

    public function testRefusalsAreWordedByTheModuleTranslator()
    {
        $module = new class extends Module {
            public function l($string, $specific = null, $locale = null)
            {
                return '[pl] ' . $string;
            }
        };
        $service = new IfirmaDataRequestService($module);
        $resolved = $this->storedRequest(IfirmaDataRequest::STATUS_APPROVED);
        $pending = $this->storedRequest();

        $e = $this->assertRefused('not_pending', function () use ($service, $resolved) {
            $service->approve($resolved, 4);
        });
        $this->assertSame('[pl] This request is no longer pending.', $e->getMessage());

        $e = $this->assertRefused('not_pending', function () use ($service, $resolved) {
            $service->reject($resolved, 4, 'late');
        });
        $this->assertSame('[pl] This request is no longer pending.', $e->getMessage());

        $e = $this->assertRefused('reason_required', function () use ($service, $pending) {
            $service->reject($pending, 4, ' ');
        });
        $this->assertSame('[pl] A reason is required.', $e->getMessage());

        $e = $this->assertRefused('invalid', function () use ($service, $pending) {
            $service->approve($pending, 4, array('postcode' => '', 'city' => ''));
        });
        $this->assertSame('[pl] Please correct the highlighted fields.', $e->getMessage());
        $this->assertSame('[pl] This field is required.', $e->errors['postcode']);
    }

    // ---- mails: what goes out (notifyMerchant, notifyCustomer) ---------------------------------
    // Pinned through the Mail stub: template, recipient, language, subject, variables, and the value
    // returned when the mail fails.

    /** @return string the folder the mail templates are read from */
    private function mailDir()
    {
        return _PS_MODULE_DIR_ . 'ifirma/mails/';
    }

    /** @return IfirmaDataRequest an unsaved request of customer 7 for order 15, as submitted */
    private function submittedRequest()
    {
        $request = new IfirmaDataRequest();
        $request->id_order = 15;
        $request->id_customer = 7;
        $request->setRequestedData($this->requestedData());
        $request->customer_note = 'Please use company data';

        return $request;
    }

    /** @return IfirmaDataRequest the same request once decided; the approved data differ in the postcode */
    private function decidedRequest()
    {
        $request = $this->submittedRequest();
        $request->setApprovedData(array_merge($this->requestedData(), array('postcode' => '00-951')));
        $request->admin_note = 'Checked with the NIP register';

        return $request;
    }

    /** @return array the variables of the merchant mail for submittedRequest() and the fixture address */
    private function merchantVars()
    {
        return array(
            '{order_reference}' => 'ABCDEFGHI',
            '{order_id}' => 15,
            '{customer_name}' => 'Anna Nowak',
            '{customer_email}' => 'buyer@example.test',
            '{requested_data}' => "want_invoice_for_company: 1\ncompany: ACME Sp. z o.o.\nvat_number: 5250001234\n"
                . "address1: Nowa 5\npostcode: 00-950\ncity: Warszawa\nid_country: 14",
            '{diff}' => "company: - -&gt; ACME Sp. z o.o.\nvat_number: - -&gt; 5250001234\nfirstname: Anna -&gt; -\n"
                . "lastname: Nowak -&gt; -\naddress1: Stara 1 -&gt; Nowa 5\npostcode: 00-001 -&gt; 00-950",
            '{note}' => 'Please use company data',
            '{admin_link}' => 'https://shop.test/admin/index.php?controller=AdminOrders&token=admin-token-AdminOrders',
        );
    }

    /** @return array the variables of the approval mail for decidedRequest() */
    private function approvalVars()
    {
        return array(
            '{firstname}' => 'Anna',
            '{order_reference}' => 'ABCDEFGHI',
            '{reason}' => 'Checked with the NIP register',
            '{approved_data}' => "want_invoice_for_company: 1\ncompany: ACME Sp. z o.o.\nvat_number: 5250001234\n"
                . "address1: Nowa 5\npostcode: 00-951\ncity: Warszawa\nid_country: 14",
        );
    }

    /** @return array a recorded Mail::Send() call with its keys in a fixed order */
    private static function sortedMail(array $record)
    {
        ksort($record);
        ksort($record['templateVars']);

        return $record;
    }

    /**
     * Asserts a recorded Mail::Send() call, argument for argument and type for type. The arguments
     * that never change (sender, attachment, SMTP mode, template folder, die flag, bcc, reply-to)
     * are filled in here.
     *
     * @param array $expected
     * @param int $index
     */
    private function assertMailSent(array $expected, $index = 0)
    {
        $this->assertArrayHasKey($index, Mail::$sent, 'the mail was not sent');
        $expected += array(
            'from' => null,
            'fromName' => null,
            'fileAttachment' => null,
            'modeSmtp' => null,
            'templatePath' => $this->mailDir(),
            'die' => false,
            'bcc' => null,
            'replyTo' => null,
            'replyToName' => null,
        );
        $this->assertSame(self::sortedMail($expected), self::sortedMail(Mail::$sent[$index]));
    }

    /** @return Module a translator that tags every text and remembers which texts it was asked for */
    private function recordingTranslator()
    {
        return new class extends Module {
            /** @var array[] one [text, source] per l() call */
            public $asked = array();

            public function l($string, $specific = null, $locale = null)
            {
                $this->asked[] = array($string, $specific);

                return '[pl] ' . $string;
            }
        };
    }

    /** @return Link a link builder that remembers its calls and answers with a fixed URL */
    private function recordingLink()
    {
        $link = new class extends Link {
            /** @var array[] the arguments of every getAdminLink() call */
            public $calls = array();

            public function getAdminLink($controller, $withToken = true, $sfRouteParams = [], $params = [])
            {
                $this->calls[] = func_get_args();

                return 'https://admin.test/order?a=1&b=2';
            }
        };
        Context::getContext()->link = $link;

        return $link;
    }

    /** @return array[] id_shop of the order => shop id handed to the mailer */
    public function shopIds()
    {
        return array(
            'shop 3' => array(3, 3),
            'shop 1' => array(1, 1),
            'no shop' => array(0, null),
            'shop not set' => array(null, null),
        );
    }

    public function testMerchantMailIsSentWithEverythingTheTemplateNeeds()
    {
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', 'merchant@example.test');
        // the invoice address is the one compared, never the delivery address
        $delivery = new Address();
        $delivery->id = 4;
        $delivery->id_customer = 7;
        $delivery->postcode = '99-999';
        $delivery->city = 'Gdansk';
        Address::register($delivery);
        $this->order->id_address_delivery = 4;

        $this->assertTrue($this->service->notifyMerchant($this->submittedRequest(), $this->order));

        $this->assertCount(1, Mail::$sent);
        $this->assertMailSent(array(
            'idLang' => 1,
            'template' => 'data_request_received',
            'subject' => 'Invoice data change request ABCDEFGHI',
            'templateVars' => $this->merchantVars(),
            'to' => 'merchant@example.test',
            'toName' => null,
            'idShop' => 1,
        ));
    }

    public function testMerchantMailGoesOutInTheLanguageOfTheContext()
    {
        Context::getContext()->language->id = 9;

        $this->service->notifyMerchant($this->submittedRequest(), $this->order);

        // not the language of the order, which is 2
        $this->assertSame(9, Mail::$sent[0]['idLang']);
    }

    /** @return array[] configured address, shop address => address the mail goes to */
    public function merchantAddresses()
    {
        return array(
            'configured address' => array('merchant@example.test', 'shop@example.test', 'merchant@example.test'),
            'configured address is trimmed' => array("  merchant@example.test\t", 'shop@example.test', 'merchant@example.test'),
            'blank configured address falls back to the shop address' => array('   ', 'shop@example.test', 'shop@example.test'),
            'no configured address' => array(null, 'shop@example.test', 'shop@example.test'),
            'shop address is trimmed' => array('', ' shop@example.test ', 'shop@example.test'),
        );
    }

    /**
     * @dataProvider merchantAddresses
     */
    public function testMerchantMailGoesToTheNotifyAddressOrElseTheShopAddress($configured, $shop, $expected)
    {
        if ($configured === null) {
            Configuration::deleteByName('IFIRMA_NOTIFY_EMAIL');
        } else {
            Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', $configured);
        }
        Configuration::updateValue('PS_SHOP_EMAIL', $shop);

        $this->assertTrue($this->service->notifyMerchant($this->submittedRequest(), $this->order));

        $this->assertCount(1, Mail::$sent);
        $this->assertSame($expected, Mail::$sent[0]['to']);
    }

    public function testNoMerchantAddressMeansNoMail()
    {
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', '  ');
        Configuration::updateValue('PS_SHOP_EMAIL', ' ');

        $this->assertFalse($this->service->notifyMerchant($this->submittedRequest(), $this->order));

        $this->assertSame(array(), Mail::$sent);
    }

    public function testSubmitStillStoresTheRequestWhenThereIsNoMerchantAddress()
    {
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', '');
        Configuration::updateValue('PS_SHOP_EMAIL', '');

        $request = $this->submitPending();

        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
        $this->assertCount(1, IfirmaTestState::objects('ifirma_data_request'));
        $this->assertSame(array(), Mail::$sent);
    }

    public function testMerchantMailReportsWhetherTheMailWentOut()
    {
        Mail::$result = false;
        $this->assertFalse($this->service->notifyMerchant($this->submittedRequest(), $this->order));
        $this->assertCount(1, Mail::$sent);

        // core may answer with something that is not a boolean
        Mail::$result = 0;
        $this->assertFalse($this->service->notifyMerchant($this->submittedRequest(), $this->order));
        Mail::$result = 1;
        $this->assertTrue($this->service->notifyMerchant($this->submittedRequest(), $this->order));
        $this->assertCount(3, Mail::$sent);
    }

    /**
     * @dataProvider shopIds
     */
    public function testMerchantMailIsSentForTheShopOfTheOrder($idShop, $expected)
    {
        $this->order->id_shop = $idShop;

        $this->service->notifyMerchant($this->submittedRequest(), $this->order);

        $this->assertSame($expected, Mail::$sent[0]['idShop']);
    }

    public function testMerchantMailLinksToTheOrderInTheAdmin()
    {
        $link = $this->recordingLink();

        $this->service->notifyMerchant($this->submittedRequest(), $this->order);

        $this->assertSame(array(array('AdminOrders', true, array(), array('id_order' => 15, 'vieworder' => 1))), $link->calls);
        // the link is handed over as it is: the template puts it in an href and in the text
        $this->assertSame('https://admin.test/order?a=1&b=2', Mail::$sent[0]['templateVars']['{admin_link}']);
    }

    public function testMerchantMailIsSentWithoutALinkWhenTheLinkCannotBeBuilt()
    {
        Context::getContext()->link = new class extends Link {
            public function getAdminLink($controller, $withToken = true, $sfRouteParams = [], $params = [])
            {
                throw new RuntimeException('no admin url');
            }
        };

        $this->assertTrue($this->service->notifyMerchant($this->submittedRequest(), $this->order));

        $this->assertSame('', Mail::$sent[0]['templateVars']['{admin_link}']);
    }

    public function testMerchantMailIsSentWithoutALinkWhenThereIsNoLinkBuilder()
    {
        foreach (array(null, new stdClass()) as $link) {
            Mail::$sent = array();
            Context::getContext()->link = $link;

            $this->assertTrue($this->service->notifyMerchant($this->submittedRequest(), $this->order));

            $this->assertSame('', Mail::$sent[0]['templateVars']['{admin_link}']);
        }
    }

    public function testMerchantMailSaysSoWhenNothingDiffers()
    {
        $request = $this->submittedRequest();
        $request->setRequestedData(array(
            'want_invoice_for_company' => false,
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Stara 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'id_country' => 14,
        ));

        $this->service->notifyMerchant($request, $this->order);

        $vars = Mail::$sent[0]['templateVars'];
        $this->assertSame('No differences.', $vars['{diff}']);
        $this->assertSame(
            "want_invoice_for_company: 0\nfirstname: Anna\nlastname: Nowak\naddress1: Stara 1\npostcode: 00-001\ncity: Warszawa\nid_country: 14",
            $vars['{requested_data}']
        );
    }

    public function testMerchantMailEscapesWhatTheCustomerControlsButNotTheSubject()
    {
        $this->order->reference = '<b>"O\'1"&';
        $this->customer->firstname = '<i>Ann</i>';
        $this->customer->lastname = "O'Neil";
        $this->customer->email = 'a&b@example.test';
        $request = $this->submittedRequest();
        $request->customer_note = '<script>alert("x")</script>';
        $request->setRequestedData(array_merge($this->requestedData(), array('company' => 'A & B <Sp.>')));

        $this->service->notifyMerchant($request, $this->order);

        $mail = Mail::$sent[0];
        $this->assertSame('Invoice data change request <b>"O\'1"&', $mail['subject']);
        $vars = $mail['templateVars'];
        $this->assertSame('&lt;b&gt;&quot;O&#039;1&quot;&amp;', $vars['{order_reference}']);
        $this->assertSame('&lt;i&gt;Ann&lt;/i&gt; O&#039;Neil', $vars['{customer_name}']);
        $this->assertSame('a&amp;b@example.test', $vars['{customer_email}']);
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $vars['{note}']);
        $this->assertStringContainsString("company: A &amp; B &lt;Sp.&gt;\n", $vars['{requested_data}']);
        $this->assertStringContainsString("company: - -&gt; A &amp; B &lt;Sp.&gt;\n", $vars['{diff}']);
    }

    public function testApprovalMailIsSentWithEverythingTheTemplateNeeds()
    {
        $this->assertTrue($this->service->notifyCustomer($this->decidedRequest(), 'approved'));

        $this->assertCount(1, Mail::$sent);
        $this->assertMailSent(array(
            'idLang' => 2,
            'template' => 'data_request_approved',
            'subject' => 'Your invoice data change was approved',
            'templateVars' => $this->approvalVars(),
            'to' => 'buyer@example.test',
            'toName' => 'Anna Nowak',
            'idShop' => 1,
        ));
    }

    public function testRejectionMailIsSentWithEverythingTheTemplateNeeds()
    {
        $this->assertTrue($this->service->notifyCustomer($this->decidedRequest(), 'rejected'));

        $this->assertCount(1, Mail::$sent);
        // a rejection quotes the reason and no data, even when the request carries approved data
        $this->assertMailSent(array(
            'idLang' => 2,
            'template' => 'data_request_rejected',
            'subject' => 'Your invoice data change was rejected',
            'templateVars' => array('{approved_data}' => '') + $this->approvalVars(),
            'to' => 'buyer@example.test',
            'toName' => 'Anna Nowak',
            'idShop' => 1,
        ));
    }

    public function testAnyDecisionButApprovedIsMailedAsRejected()
    {
        foreach (array('cancelled', '', null, 'APPROVED', 1) as $decision) {
            Mail::$sent = array();

            $this->assertTrue($this->service->notifyCustomer($this->decidedRequest(), $decision));

            $this->assertSame('data_request_rejected', Mail::$sent[0]['template']);
            $this->assertSame('Your invoice data change was rejected', Mail::$sent[0]['subject']);
            $this->assertSame('', Mail::$sent[0]['templateVars']['{approved_data}']);
        }
    }

    public function testCustomerWithoutAnAddressIsNotMailed()
    {
        foreach (array('', null) as $email) {
            $this->customer->email = $email;

            $this->assertFalse($this->service->notifyCustomer($this->decidedRequest(), 'approved'));
        }

        $this->assertSame(array(), Mail::$sent);
    }

    public function testCustomerMailIsAddressedToTheTrimmedFullName()
    {
        $this->customer->lastname = '';
        $this->service->notifyCustomer($this->decidedRequest(), 'approved');
        $this->customer->firstname = '';
        $this->service->notifyCustomer($this->decidedRequest(), 'approved');

        $this->assertSame('Anna', Mail::$sent[0]['toName']);
        $this->assertSame('', Mail::$sent[1]['toName']);
        $this->assertSame('buyer@example.test', Mail::$sent[1]['to']);
    }

    /**
     * @return array[] label => [languages installed, id_lang of the order, language the mail goes out in]
     */
    public function customerMailLanguages()
    {
        $en = array('id_lang' => 1, 'iso_code' => 'en', 'name' => 'English');
        $pl = array('id_lang' => 2, 'iso_code' => 'pl', 'name' => 'Polski');
        $de = array('id_lang' => 3, 'iso_code' => 'de', 'name' => 'Deutsch');

        return array(
            'language of the order, which has templates' => array(array($en, $pl), 2, 2),
            'english order' => array(array($en, $pl), 1, 1),
            'language of the order has no template folder' => array(array($en, $pl, $de), 3, 1),
            'language of the order is not installed' => array(array($en, $pl), 5, 1),
            'order without a language' => array(array($en, $pl), 0, 1),
            'english is installed under another id' => array(array($pl, array('id_lang' => 7, 'iso_code' => 'en', 'name' => 'English')), 5, 7),
            // every installed language is searched, not just the active ones
            'english is installed but switched off' => array(array($pl, array('id_lang' => 8, 'iso_code' => 'en', 'name' => 'English', 'active' => 0)), 5, 8),
            'the first english wins' => array(array($pl, array('id_lang' => 4, 'iso_code' => 'en', 'name' => 'English'), array('id_lang' => 5, 'iso_code' => 'en', 'name' => 'English (UK)')), 6, 4),
            'no english: the language of the context' => array(array($pl, $de), 5, 9),
        );
    }

    /**
     * @dataProvider customerMailLanguages
     */
    public function testCustomerMailFallsBackToEnglishThenToTheContextLanguage(array $languages, $orderLang, $expected)
    {
        Language::$languages = $languages;
        Context::getContext()->language->id = 9;
        $this->order->id_lang = $orderLang;

        foreach (array('approved', 'rejected') as $n => $decision) {
            $this->assertTrue($this->service->notifyCustomer($this->decidedRequest(), $decision));

            $this->assertSame($expected, Mail::$sent[$n]['idLang'], $decision);
        }
    }

    public function testCustomerMailReportsWhetherTheMailWentOut()
    {
        Mail::$result = false;
        $this->assertFalse($this->service->notifyCustomer($this->decidedRequest(), 'approved'));
        $this->assertCount(1, Mail::$sent);

        // core may answer with something that is not a boolean
        Mail::$result = 0;
        $this->assertFalse($this->service->notifyCustomer($this->decidedRequest(), 'rejected'));
        Mail::$result = 1;
        $this->assertTrue($this->service->notifyCustomer($this->decidedRequest(), 'approved'));
        $this->assertCount(3, Mail::$sent);
    }

    /**
     * @dataProvider shopIds
     */
    public function testCustomerMailIsSentForTheShopOfTheOrder($idShop, $expected)
    {
        $this->order->id_shop = $idShop;

        $this->service->notifyCustomer($this->decidedRequest(), 'approved');

        $this->assertSame($expected, Mail::$sent[0]['idShop']);
    }

    public function testCustomerMailEscapesWhatIsPutInTheBody()
    {
        $this->order->reference = '<b>"O\'1"&';
        $this->customer->firstname = '<i>Ann</i>';
        $this->customer->lastname = "O'Neil";
        $request = $this->decidedRequest();
        $request->admin_note = '<script>alert("x")</script>';
        $request->setApprovedData(array_merge($this->requestedData(), array('company' => 'A & B <Sp.>')));

        $this->service->notifyCustomer($request, 'approved');

        $mail = Mail::$sent[0];
        $vars = $mail['templateVars'];
        $this->assertSame('&lt;i&gt;Ann&lt;/i&gt;', $vars['{firstname}']);
        $this->assertSame('&lt;b&gt;&quot;O&#039;1&quot;&amp;', $vars['{order_reference}']);
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $vars['{reason}']);
        $this->assertStringContainsString("company: A &amp; B &lt;Sp.&gt;\n", $vars['{approved_data}']);
        // the name of the recipient is not part of the body
        $this->assertSame("<i>Ann</i> O'Neil", $mail['toName']);
    }

    public function testTheMailTextsComeFromTheModuleTranslatorInTheSourceOfTheService()
    {
        $module = $this->recordingTranslator();
        $service = new IfirmaDataRequestService($module);
        $nothingDiffers = $this->submittedRequest();
        $nothingDiffers->setRequestedData(array('firstname' => 'Anna', 'lastname' => 'Nowak', 'address1' => 'Stara 1',
            'postcode' => '00-001', 'city' => 'Warszawa', 'id_country' => 14));

        $service->notifyMerchant($nothingDiffers, $this->order);
        $service->notifyCustomer($this->decidedRequest(), 'approved');
        $service->notifyCustomer($this->decidedRequest(), 'rejected');

        $this->assertSame('[pl] Invoice data change request ABCDEFGHI', Mail::$sent[0]['subject']);
        $this->assertSame('[pl] No differences.', Mail::$sent[0]['templateVars']['{diff}']);
        $this->assertSame('[pl] Your invoice data change was approved', Mail::$sent[1]['subject']);
        $this->assertSame('[pl] Your invoice data change was rejected', Mail::$sent[2]['subject']);
        // the keys of translations/pl.php are <source>_<md5 of the text>: the source stays this file's
        $this->assertEqualsCanonicalizing(array(
            array('Invoice data change request', 'ifirmadatarequestservice'),
            array('No differences.', 'ifirmadatarequestservice'),
            array('Your invoice data change was approved', 'ifirmadatarequestservice'),
            array('Your invoice data change was rejected', 'ifirmadatarequestservice'),
        ), $module->asked);
    }

    public function testEveryMailTemplateIsShippedInEnglishAndPolish()
    {
        // English is the fallback of the customer mails, so it has to exist for every template
        foreach (array('en', 'pl') as $iso) {
            foreach (array('data_request_received', 'data_request_approved', 'data_request_rejected') as $template) {
                $this->assertFileExists($this->mailDir() . $iso . '/' . $template . '.html');
                $this->assertFileExists($this->mailDir() . $iso . '/' . $template . '.txt');
            }
        }
    }

    // ---- mails: which actions send them --------------------------------------------------------

    public function testSubmitTellsTheMerchantAboutTheStoredRequest()
    {
        $service = new class(new Module()) extends IfirmaDataRequestService {
            /** @var array[] one entry per notifyMerchant() call */
            public $told = array();

            public function notifyMerchant(IfirmaDataRequest $request, Order $order)
            {
                $this->told[] = array(
                    'request' => $request,
                    'order' => $order,
                    'rows' => IfirmaTestState::objects('ifirma_data_request'),
                );

                return true;
            }
        };

        $request = $service->submit($this->order, $this->customer, $this->companyInput());

        $this->assertCount(1, $service->told);
        $this->assertSame($request, $service->told[0]['request']);
        $this->assertSame($this->order, $service->told[0]['order']);
        // the request was stored, still pending, before the merchant was told
        $this->assertNotEmpty($request->id);
        $this->assertSame(array($request->id), array_keys($service->told[0]['rows']));
        $this->assertSame('pending', $service->told[0]['rows'][$request->id]['status']);
    }

    public function testAFailedMailNeverUndoesTheOutcome()
    {
        Mail::$result = false;

        $submitted = $this->submitPending();
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $submitted->status);
        $this->assertSame('pending', $this->storedRow($submitted)['status']);

        $approved = $this->service->approve($submitted, 4);
        $this->assertSame($submitted, $approved);
        $this->assertSame(IfirmaDataRequest::STATUS_APPROVED, $approved->status);
        $this->assertSame('approved', $this->storedRow($approved)['status']);

        $other = $this->storedRequest();
        $rejected = $this->service->reject($other, 4, 'No');
        $this->assertSame($other, $rejected);
        $this->assertSame(IfirmaDataRequest::STATUS_REJECTED, $rejected->status);
        $this->assertSame('rejected', $this->storedRow($rejected)['status']);

        // all three mails were tried
        $this->assertCount(3, Mail::$sent);
    }

    public function testCancelAndRefusedSubmissionsSendNothing()
    {
        $request = $this->submitPending();
        $this->assertCount(1, Mail::$sent);

        $this->service->cancel($request, $this->customer);

        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 0);
        $this->assertRefused('disabled', function () {
            $this->submitPending();
        });
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 1);
        $this->assertRefused('invalid', function () {
            $this->submitPending($this->companyInput(array('vat_number' => '')));
        });

        $this->assertCount(1, Mail::$sent);
    }

    public function testTheApprovalMailCarriesTheDataThatWasApproved()
    {
        $request = $this->submitPending();
        Mail::$sent = array();

        $this->service->approve($request, 4, $this->companyInput(array('postcode' => '00-951')), 'fixed postcode');

        $this->assertCount(1, Mail::$sent);
        $vars = Mail::$sent[0]['templateVars'];
        $this->assertStringContainsString('postcode: 00-951', $vars['{approved_data}']);
        $this->assertStringNotContainsString('00-950', $vars['{approved_data}']);
        $this->assertSame('fixed postcode', $vars['{reason}']);
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
