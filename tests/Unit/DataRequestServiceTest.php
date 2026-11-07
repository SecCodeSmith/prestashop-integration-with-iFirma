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
    use IfirmaTestMailTrait;

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

    public function testAPostcodeWithForbiddenCharactersIsRejected()
    {
        $errors = $this->service->validate($this->privateInput(array('postcode' => '00_950', 'id_country' => '')));

        $this->assertArrayHasKey('postcode', $errors);
        $this->assertCount(1, $errors);
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

    /**
     * Fields set on top of the invoice address of setUp() => the VAT number that buildDiff() takes
     * as the current one: the digits of the address's vat_number, else those of its dni.
     *
     * @return array
     */
    public function invoiceVatNumbers()
    {
        return array(
            'the VAT number, as typed' => array(array('vat_number' => 'PL 525-000-12-34', 'dni' => '1112223344'), '5250001234'),
            'a VAT number of 0 is a number' => array(array('vat_number' => '0', 'dni' => '1112223344'), '0'),
            'the dni when the VAT number is empty' => array(array('vat_number' => '', 'dni' => '525-000-12-34'), '5250001234'),
            'the dni when the VAT number is blanks' => array(array('vat_number' => " \t ", 'dni' => 'PL5250001234'), '5250001234'),
            'nothing when the VAT number is blanks and there is no dni' => array(array('vat_number' => '   '), ''),
            'nothing when the VAT number and the dni are empty' => array(array('vat_number' => '', 'dni' => ''), ''),
            'nothing when the address has neither' => array(array(), ''),
        );
    }

    /**
     * @dataProvider invoiceVatNumbers
     */
    public function testBuildDiffComparesTheRequestWithTheVatNumberOfTheInvoiceAddress(array $fields, $current)
    {
        $this->changeInvoiceAddress($fields);

        $diff = $this->service->buildDiff(array('vat_number' => '9999999999'), new Address(3));

        $this->assertSame(
            array(array('field' => 'vat_number', 'current' => $current, 'requested' => '9999999999')),
            array_values(array_filter($diff, function ($change) {
                return $change['field'] === 'vat_number';
            }))
        );
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

    // ---- mails: the whole chain (service, default notifier, Mail stub) --------------------------
    // What reaches Mail::Send(), argument for argument. The service and the notifier have tests of
    // their own below and in IfirmaDataRequestNotifierTest; these show that together they send the
    // mails the module always sent.

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

    /**
     * @param string $postcode
     * @return string the data of requestedData() as the mails list it
     */
    private function dataText($postcode)
    {
        return "want_invoice_for_company: 1\ncompany: ACME Sp. z o.o.\nvat_number: 5250001234\n"
            . "address1: Nowa 5\npostcode: " . $postcode . "\ncity: Warszawa\nid_country: 14";
    }

    /** @return array the variables of the merchant mail for submittedRequest() and the fixture address */
    private function merchantVars()
    {
        return array(
            '{order_reference}' => 'ABCDEFGHI',
            '{order_id}' => 15,
            '{customer_name}' => 'Anna Nowak',
            '{customer_email}' => 'buyer@example.test',
            '{requested_data}' => $this->dataText('00-950'),
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
            '{approved_data}' => $this->dataText('00-951'),
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

    public function testBuildGivesTheServiceANotifierThatSendsTheMail()
    {
        $service = IfirmaDataRequestService::build(new Module());

        $this->assertTrue($service->notifyMerchant($this->submittedRequest(), $this->order));

        $this->assertCount(1, Mail::$sent);
        $this->assertSame('data_request_received', Mail::$sent[0]['template']);
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

    // ---- mails: what the service asks the notifier to send (no Mail stub) ------------------------

    /**
     * A notifier that records what it is asked to send instead of mailing it. Every entry also holds
     * the stored requests at that moment, which shows what had been saved before the mail.
     *
     * @param string $address answer of merchantAddress()
     * @param bool $result answer of the send methods
     * @return IfirmaDataRequestNotifier
     */
    private function recordingNotifier($address = 'merchant@fake.test', $result = true)
    {
        return new class($address, $result) extends IfirmaDataRequestNotifier {
            /** @var string */
            public $address;

            /** @var bool */
            public $result;

            /** @var array[] one entry per sendToMerchant() call */
            public $merchant = array();

            /** @var array[] one entry per sendToCustomer() call */
            public $customer = array();

            public function __construct($address, $result)
            {
                $this->address = $address;
                $this->result = $result;
            }

            public function merchantAddress()
            {
                return $this->address;
            }

            public function sendToMerchant($to, Order $order, $subject, array $vars)
            {
                $this->merchant[] = array(
                    'to' => $to,
                    'order' => $order,
                    'subject' => $subject,
                    'vars' => $vars,
                    'stored' => IfirmaTestState::objects('ifirma_data_request'),
                );

                return $this->result;
            }

            public function sendToCustomer($decision, Customer $customer, Order $order, $subject, array $vars)
            {
                $this->customer[] = array(
                    'decision' => $decision,
                    'customer' => $customer,
                    'order' => $order,
                    'subject' => $subject,
                    'vars' => $vars,
                    'stored' => IfirmaTestState::objects('ifirma_data_request'),
                );

                return $this->result;
            }
        };
    }

    /** @return IfirmaDataRequestService a service that hands its mails to $notifier */
    private function serviceWith(IfirmaDataRequestNotifier $notifier, Module $module = null)
    {
        return new IfirmaDataRequestService($module ?: new Module(), null, $notifier);
    }

    /** Asserts the variables of a mail, type for type, whatever the order of the keys. */
    private function assertVars(array $expected, array $actual)
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
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

    public function testSubmitTellsTheMerchantAboutTheStoredRequest()
    {
        $notifier = $this->recordingNotifier('ops@fake.test');

        $request = $this->serviceWith($notifier)->submit($this->order, $this->customer, $this->companyInput());

        $this->assertSame(array(), $notifier->customer);
        $this->assertCount(1, $notifier->merchant);
        $told = $notifier->merchant[0];
        $this->assertSame('ops@fake.test', $told['to']);
        $this->assertSame($this->order, $told['order']);
        $this->assertSame('Invoice data change request ABCDEFGHI', $told['subject']);
        $this->assertVars($this->merchantVars(), $told['vars']);
        // the request was stored, still pending, before the merchant was told
        $this->assertNotEmpty($request->id);
        $this->assertSame(array($request->id), array_keys($told['stored']));
        $this->assertSame('pending', $told['stored'][$request->id]['status']);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testApproveTellsTheCustomerOnceTheApprovalIsStored()
    {
        $notifier = $this->recordingNotifier();
        $request = $this->storedRequest();

        $this->serviceWith($notifier)->approve($request, 4, null, ' Checked with the NIP register ');

        $this->assertSame(array(), $notifier->merchant);
        $this->assertCount(1, $notifier->customer);
        $told = $notifier->customer[0];
        $this->assertSame('approved', $told['decision']);
        $this->assertSame(7, (int) $told['customer']->id);
        $this->assertSame('buyer@example.test', $told['customer']->email);
        $this->assertSame(15, (int) $told['order']->id);
        $this->assertSame('Your invoice data change was approved', $told['subject']);
        $this->assertVars(array(
            '{firstname}' => 'Anna',
            '{order_reference}' => 'ABCDEFGHI',
            '{reason}' => 'Checked with the NIP register',
            '{approved_data}' => $this->dataText('00-950'),
        ), $told['vars']);
        // saved once, and already approved when the customer was told
        $this->assertCount(1, $request->saves);
        $this->assertSame('approved', $told['stored'][$request->id]['status']);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testRejectTellsTheCustomerOnceTheRejectionIsStored()
    {
        $notifier = $this->recordingNotifier();
        $request = $this->storedRequest();

        $this->serviceWith($notifier)->reject($request, 4, '  NIP does not match  ');

        $this->assertSame(array(), $notifier->merchant);
        $this->assertCount(1, $notifier->customer);
        $told = $notifier->customer[0];
        $this->assertSame('rejected', $told['decision']);
        $this->assertSame(7, (int) $told['customer']->id);
        $this->assertSame(15, (int) $told['order']->id);
        $this->assertSame('Your invoice data change was rejected', $told['subject']);
        $this->assertVars(array(
            '{firstname}' => 'Anna',
            '{order_reference}' => 'ABCDEFGHI',
            '{reason}' => 'NIP does not match',
            '{approved_data}' => '',
        ), $told['vars']);
        // saved once, and already rejected when the customer was told
        $this->assertCount(1, $request->saves);
        $this->assertSame('rejected', $told['stored'][$request->id]['status']);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testTheApprovalMailCarriesTheDataThatWasApproved()
    {
        $notifier = $this->recordingNotifier();
        $request = $this->storedRequest();

        $this->serviceWith($notifier)->approve($request, 4, $this->companyInput(array('postcode' => '00-951')), 'fixed postcode');

        $vars = $notifier->customer[0]['vars'];
        $this->assertSame($this->dataText('00-951'), $vars['{approved_data}']);
        $this->assertSame('fixed postcode', $vars['{reason}']);
    }

    public function testCancelAndRefusedActionsTellNobody()
    {
        $notifier = $this->recordingNotifier();
        $service = $this->serviceWith($notifier);

        $service->cancel($this->storedRequest(), $this->customer);

        $resolved = $this->storedRequest(IfirmaDataRequest::STATUS_APPROVED);
        $this->assertRefused('not_pending', function () use ($service, $resolved) {
            $service->approve($resolved, 4);
        });
        $this->assertRefused('not_pending', function () use ($service, $resolved) {
            $service->reject($resolved, 4, 'late');
        });
        $pending = $this->storedRequest();
        $this->assertRefused('reason_required', function () use ($service, $pending) {
            $service->reject($pending, 4, ' ');
        });
        $this->assertRefused('invalid', function () use ($service, $pending) {
            $service->approve($pending, 4, array('postcode' => '', 'city' => ''));
        });
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 0);
        $this->assertRefused('disabled', function () use ($service) {
            $service->submit($this->order, $this->customer, $this->companyInput());
        });
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 1);
        $this->assertRefused('invalid', function () use ($service) {
            $service->submit($this->order, $this->customer, $this->companyInput(array('vat_number' => '')));
        });

        $this->assertSame(array(), $notifier->merchant);
        $this->assertSame(array(), $notifier->customer);
    }

    public function testTheNotifyMethodsAnswerWhatTheNotifierAnswers()
    {
        foreach (array(true, false) as $result) {
            $notifier = $this->recordingNotifier('merchant@fake.test', $result);
            $service = $this->serviceWith($notifier);

            $this->assertSame($result, $service->notifyMerchant($this->submittedRequest(), $this->order));
            $this->assertSame($result, $service->notifyCustomer($this->decidedRequest(), 'approved'));
            $this->assertSame($result, $service->notifyCustomer($this->decidedRequest(), 'rejected'));
            $this->assertCount(1, $notifier->merchant);
            $this->assertCount(2, $notifier->customer);
        }
    }

    public function testAFailedNotificationNeverUndoesTheOutcome()
    {
        $service = $this->serviceWith($this->recordingNotifier('merchant@fake.test', false));

        $submitted = $service->submit($this->order, $this->customer, $this->companyInput());
        $this->assertSame('pending', $this->storedRow($submitted)['status']);
        $this->assertSame($submitted, $service->approve($submitted, 4));
        $this->assertSame('approved', $this->storedRow($submitted)['status']);
        $other = $this->storedRequest();
        $this->assertSame($other, $service->reject($other, 4, 'No'));
        $this->assertSame('rejected', $this->storedRow($other)['status']);
    }

    public function testTheMerchantIsToldAtTheAddressTheNotifierGives()
    {
        // nothing is configured for the service itself to find: the address is the notifier's business
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', 'not-this@example.test');
        $notifier = $this->recordingNotifier('ops@fake.test');

        $this->serviceWith($notifier)->notifyMerchant($this->submittedRequest(), $this->order);

        $this->assertSame('ops@fake.test', $notifier->merchant[0]['to']);
    }

    public function testNobodyIsToldWhenThereIsNoMerchantAddress()
    {
        $notifier = $this->recordingNotifier('');

        $this->assertFalse($this->serviceWith($notifier)->notifyMerchant($this->submittedRequest(), $this->order));

        $this->assertSame(array(), $notifier->merchant);
    }

    public function testMerchantMailLinksToTheOrderInTheAdmin()
    {
        $link = $this->recordingLink();
        $notifier = $this->recordingNotifier();

        $this->serviceWith($notifier)->notifyMerchant($this->submittedRequest(), $this->order);

        $this->assertSame(array(array('AdminOrders', true, array(), array('id_order' => 15, 'vieworder' => 1))), $link->calls);
        // the link is handed over as it is: the template puts it in an href and in the text
        $this->assertSame('https://admin.test/order?a=1&b=2', $notifier->merchant[0]['vars']['{admin_link}']);
    }

    public function testMerchantMailIsSentWithoutALinkWhenTheLinkCannotBeBuilt()
    {
        Context::getContext()->link = new class extends Link {
            public function getAdminLink($controller, $withToken = true, $sfRouteParams = [], $params = [])
            {
                throw new RuntimeException('no admin url');
            }
        };
        $notifier = $this->recordingNotifier();

        $this->assertTrue($this->serviceWith($notifier)->notifyMerchant($this->submittedRequest(), $this->order));

        $this->assertSame('', $notifier->merchant[0]['vars']['{admin_link}']);
    }

    public function testMerchantMailIsSentWithoutALinkWhenThereIsNoLinkBuilder()
    {
        foreach (array(null, new stdClass()) as $link) {
            $notifier = $this->recordingNotifier();
            Context::getContext()->link = $link;

            $this->assertTrue($this->serviceWith($notifier)->notifyMerchant($this->submittedRequest(), $this->order));

            $this->assertSame('', $notifier->merchant[0]['vars']['{admin_link}']);
        }
    }

    public function testMerchantMailSaysSoWhenNothingDiffers()
    {
        $notifier = $this->recordingNotifier();
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

        $this->serviceWith($notifier)->notifyMerchant($request, $this->order);

        $vars = $notifier->merchant[0]['vars'];
        $this->assertSame('No differences.', $vars['{diff}']);
        $this->assertSame(
            "want_invoice_for_company: 0\nfirstname: Anna\nlastname: Nowak\naddress1: Stara 1\npostcode: 00-001\ncity: Warszawa\nid_country: 14",
            $vars['{requested_data}']
        );
    }

    public function testMerchantMailEscapesWhatTheCustomerControlsButNotTheSubject()
    {
        $notifier = $this->recordingNotifier();
        $this->order->reference = '<b>"O\'1"&';
        $this->customer->firstname = '<i>Ann</i>';
        $this->customer->lastname = "O'Neil";
        $this->customer->email = 'a&b@example.test';
        $request = $this->submittedRequest();
        $request->customer_note = '<script>alert("x")</script>';
        $request->setRequestedData(array_merge($this->requestedData(), array('company' => 'A & B <Sp.>')));

        $this->serviceWith($notifier)->notifyMerchant($request, $this->order);

        $told = $notifier->merchant[0];
        $this->assertSame('Invoice data change request <b>"O\'1"&', $told['subject']);
        $vars = $told['vars'];
        $this->assertSame('&lt;b&gt;&quot;O&#039;1&quot;&amp;', $vars['{order_reference}']);
        $this->assertSame('&lt;i&gt;Ann&lt;/i&gt; O&#039;Neil', $vars['{customer_name}']);
        $this->assertSame('a&amp;b@example.test', $vars['{customer_email}']);
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $vars['{note}']);
        $this->assertStringContainsString("company: A &amp; B &lt;Sp.&gt;\n", $vars['{requested_data}']);
        $this->assertStringContainsString("company: - -&gt; A &amp; B &lt;Sp.&gt;\n", $vars['{diff}']);
    }

    public function testCustomerMailEscapesWhatIsPutInTheBody()
    {
        $notifier = $this->recordingNotifier();
        $this->order->reference = '<b>"O\'1"&';
        $this->customer->firstname = '<i>Ann</i>';
        $request = $this->decidedRequest();
        $request->admin_note = '<script>alert("x")</script>';
        $request->setApprovedData(array_merge($this->requestedData(), array('company' => 'A & B <Sp.>')));

        $this->serviceWith($notifier)->notifyCustomer($request, 'approved');

        $vars = $notifier->customer[0]['vars'];
        $this->assertSame('&lt;i&gt;Ann&lt;/i&gt;', $vars['{firstname}']);
        $this->assertSame('&lt;b&gt;&quot;O&#039;1&quot;&amp;', $vars['{order_reference}']);
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $vars['{reason}']);
        $this->assertStringContainsString("company: A &amp; B &lt;Sp.&gt;\n", $vars['{approved_data}']);
    }

    public function testAnyDecisionButApprovedIsToldAsRejected()
    {
        foreach (array('cancelled', '', null, 'APPROVED', 1) as $decision) {
            $notifier = $this->recordingNotifier();

            $this->assertTrue($this->serviceWith($notifier)->notifyCustomer($this->decidedRequest(), $decision));

            $told = $notifier->customer[0];
            $this->assertSame('rejected', $told['decision']);
            $this->assertSame('Your invoice data change was rejected', $told['subject']);
            $this->assertSame('', $told['vars']['{approved_data}']);
        }
    }

    public function testCustomerWithoutAnAddressIsNotTold()
    {
        $notifier = $this->recordingNotifier();
        foreach (array('', null) as $email) {
            $this->customer->email = $email;

            $this->assertFalse($this->serviceWith($notifier)->notifyCustomer($this->decidedRequest(), 'approved'));
        }

        $this->assertSame(array(), $notifier->customer);
    }

    public function testTheMailTextsComeFromTheModuleTranslatorInTheSourceOfTheService()
    {
        $module = $this->recordingTranslator();
        $notifier = $this->recordingNotifier();
        $service = $this->serviceWith($notifier, $module);
        $nothingDiffers = $this->submittedRequest();
        $nothingDiffers->setRequestedData(array('firstname' => 'Anna', 'lastname' => 'Nowak', 'address1' => 'Stara 1',
            'postcode' => '00-001', 'city' => 'Warszawa', 'id_country' => 14));

        $service->notifyMerchant($nothingDiffers, $this->order);
        $service->notifyCustomer($this->decidedRequest(), 'approved');
        $service->notifyCustomer($this->decidedRequest(), 'rejected');

        $this->assertSame('[pl] Invoice data change request ABCDEFGHI', $notifier->merchant[0]['subject']);
        $this->assertSame('[pl] No differences.', $notifier->merchant[0]['vars']['{diff}']);
        $this->assertSame('[pl] Your invoice data change was approved', $notifier->customer[0]['subject']);
        $this->assertSame('[pl] Your invoice data change was rejected', $notifier->customer[1]['subject']);
        // the keys of translations/pl.php are <source>_<md5 of the text>: the source stays this file's
        $this->assertEqualsCanonicalizing(array(
            array('Invoice data change request', 'ifirmadatarequestservice'),
            array('No differences.', 'ifirmadatarequestservice'),
            array('Your invoice data change was approved', 'ifirmadatarequestservice'),
            array('Your invoice data change was rejected', 'ifirmadatarequestservice'),
        ), $module->asked);
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
        $builder = IfirmaPayloadBuilder::build(new Module());
        $contractor = $builder->buildContractor($this->order, $this->service->getOverrideForOrder(15));

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
        $builder = IfirmaPayloadBuilder::build(new Module());
        $contractor = $builder->buildContractor($this->order, $this->service->getOverrideForOrder(15));
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

    // ---- controller: reading the form, starting it from the address ----------

    /** Sets $fields on top of the invoice address of setUp() (order 15 -> address 3). */
    private function changeInvoiceAddress(array $fields)
    {
        foreach ($fields as $field => $value) {
            Address::$instances[3]->$field = $value;
        }
    }

    /** @return array the form values for the invoice address of setUp(), with $override on top */
    private function formValues(array $override = array())
    {
        return array_merge(array(
            'want_invoice_for_company' => false,
            'company' => null,
            'vat_number' => null,
            'firstname' => 'Anna',
            'lastname' => 'Nowak',
            'address1' => 'Stara 1',
            'address2' => null,
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'id_country' => 14,
            'customer_note' => '',
        ), $override);
    }

    /**
     * Fields set on top of the invoice address of setUp() => the values the form starts from.
     *
     * @return array
     */
    public function invoiceAddresses()
    {
        $others = array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'address1' => 'Nowa 5', 'address2' => 'lok. 2',
            'postcode' => '80-001', 'city' => 'Gdansk', 'id_country' => '14');

        return array(
            'a private person' => array(array(), $this->formValues()),
            'a company with a VAT number' => array(
                array('company' => 'ACME Sp. z o.o.', 'vat_number' => '525-000-12-34', 'dni' => 'not used'),
                $this->formValues(array('want_invoice_for_company' => true, 'company' => 'ACME Sp. z o.o.', 'vat_number' => '525-000-12-34')),
            ),
            'a company with its number in the dni field' => array(
                array('company' => 'ACME', 'vat_number' => '', 'dni' => '5250001234'),
                $this->formValues(array('want_invoice_for_company' => true, 'company' => 'ACME', 'vat_number' => '5250001234')),
            ),
            'a VAT number of blanks falls back to the dni' => array(
                array('company' => 'ACME', 'vat_number' => " \t ", 'dni' => '5250001234'),
                $this->formValues(array('want_invoice_for_company' => true, 'company' => 'ACME', 'vat_number' => '5250001234')),
            ),
            'blanks and no dni leave the VAT number null' => array(
                array('company' => 'ACME', 'vat_number' => '   '),
                $this->formValues(array('company' => 'ACME')),
            ),
            'blanks and a blank dni leave the VAT number empty' => array(
                array('company' => 'ACME', 'vat_number' => '', 'dni' => ''),
                $this->formValues(array('company' => 'ACME', 'vat_number' => '')),
            ),
            'the VAT number is shown as stored' => array(
                array('company' => 'ACME', 'vat_number' => ' PL 525-000-12-34 '),
                $this->formValues(array('want_invoice_for_company' => true, 'company' => 'ACME', 'vat_number' => ' PL 525-000-12-34 ')),
            ),
            'a VAT number of 0 is no number' => array(
                array('company' => 'ACME', 'vat_number' => '0'),
                $this->formValues(array('company' => 'ACME', 'vat_number' => '0')),
            ),
            'an empty company name is no company' => array(
                array('company' => '', 'vat_number' => '5250001234'),
                $this->formValues(array('company' => '', 'vat_number' => '5250001234')),
            ),
            'a company name of one space is a company' => array(
                array('company' => ' ', 'vat_number' => '5250001234'),
                $this->formValues(array('want_invoice_for_company' => true, 'company' => ' ', 'vat_number' => '5250001234')),
            ),
            'a company named 0 is a company' => array(
                array('company' => '0', 'vat_number' => '5250001234'),
                $this->formValues(array('want_invoice_for_company' => true, 'company' => '0', 'vat_number' => '5250001234')),
            ),
            'no company name is no company' => array(
                array('company' => null, 'vat_number' => '5250001234'),
                $this->formValues(array('vat_number' => '5250001234')),
            ),
            'the other fields are shown as stored' => array($others, $this->formValues($others)),
        );
    }

    public function testControllerDoesNothingWhenTheFormWasNotPosted()
    {
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15, 'token' => 'front-token') + $this->companyInput();

        $this->assertNull($controller->postProcess());
        $this->assertSame(array(), $controller->errors);
        $this->assertSame(array(), $controller->fieldErrors);
        $this->assertSame(array(), $controller->submitted);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testControllerCancelsThePendingRequestAndRedirects()
    {
        $request = $this->submitPending();
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', (int) $request->id);
        IfirmaTestState::clearWrites();
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15, 'cancelIfirmaDataRequest' => '1', 'token' => 'front-token') + $this->companyInput();
        try {
            $controller->postProcess();
            $this->fail('expected redirect');
        } catch (IfirmaTestRedirect $r) {
            $this->assertStringContainsString('order-detail', $r->getUrl());
        }

        $writes = IfirmaTestState::writes('ifirma_data_request');
        $this->assertCount(1, $writes);
        $this->assertSame('update', $writes[0]['op']);
        $this->assertSame('cancelled', $writes[0]['row']['status']);
        $this->assertSame(array(), $controller->errors);
        $this->assertSame(array(), $controller->submitted);
    }

    public function testControllerRefusesToCancelWhenNothingIsPending()
    {
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15, 'cancelIfirmaDataRequest' => '1', 'token' => 'front-token');
        $controller->postProcess();

        $this->assertSame(array('This request is no longer pending.'), $controller->errors);
        $this->assertSame(array(), $controller->fieldErrors);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testControllerRemembersEveryFormFieldInFormOrderAndNothingElse()
    {
        $controller = $this->controller();
        Tools::$values = array(
            'stray' => 'ignored', 'id_order' => 15, 'submitIfirmaDataRequest' => '1', 'token' => 'front-token',
            'customer_note' => 'Call me', 'city' => 'Gdansk', 'company' => array('Not', 'text'),
        );
        $controller->postProcess();

        $this->assertSame(array(
            'want_invoice_for_company' => false,
            'company' => array('Not', 'text'),
            'vat_number' => false,
            'firstname' => false,
            'lastname' => false,
            'address1' => false,
            'address2' => false,
            'postcode' => false,
            'city' => 'Gdansk',
            'id_country' => false,
            'customer_note' => 'Call me',
        ), $controller->submitted);
        // the service refused exactly this input: a field that was not posted arrives as false
        $this->assertSame(array('Please correct the highlighted fields.'), $controller->errors);
        $this->assertSame(array('postcode', 'firstname', 'lastname', 'id_country'), array_keys($controller->fieldErrors));
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testControllerStoresTheRequestFromTheFormFields()
    {
        $controller = $this->controller();
        Tools::$values = array('stray' => 'ignored', 'id_order' => 15, 'submitIfirmaDataRequest' => '1', 'token' => 'front-token')
            + $this->companyInput();
        try {
            $controller->postProcess();
            $this->fail('expected redirect');
        } catch (IfirmaTestRedirect $r) {
            $this->assertStringContainsString('order-detail', $r->getUrl());
        }

        $this->assertSame($this->companyInput(), $controller->submitted);
        $writes = IfirmaTestState::writes('ifirma_data_request');
        $this->assertCount(1, $writes);
        $this->assertSame('add', $writes[0]['op']);
        $row = $writes[0]['row'];
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
        ), json_decode($row['requested_data'], true));
        $this->assertSame('Please use company data', $row['customer_note']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(15, (int) $row['id_order']);
        $this->assertSame(7, (int) $row['id_customer']);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('data_request_received', Mail::$sent[0]['template']);
    }

    public function testControllerRefillsTheFormWithWhatWasSubmitted()
    {
        $controller = $this->controller();
        $posted = $this->companyInput(array('vat_number' => ''));
        Tools::$values = array('id_order' => 15, 'submitIfirmaDataRequest' => '1', 'token' => 'front-token') + $posted;
        $controller->postProcess();
        $controller->initContent();

        $vars = Context::getContext()->smarty->vars;
        $this->assertSame($posted, $vars['ifirma_values']);
        $this->assertSame(array('Please correct the highlighted fields.'), $vars['ifirma_errors']);
        $this->assertSame(array('vat_number'), array_keys($vars['ifirma_field_errors']));
    }

    /**
     * @dataProvider invoiceAddresses
     */
    public function testControllerStartsTheFormFromTheInvoiceAddress(array $address, array $expected)
    {
        $this->changeInvoiceAddress($address);
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15);
        $controller->initContent();

        $vars = Context::getContext()->smarty->vars;
        $this->assertSame($expected, $vars['ifirma_values']);
        $this->assertSame(3, $vars['ifirma_address']->id);
        $this->assertSame(array(), Address::$saved);
    }

    public function testControllerOffersTheActiveCountriesOnly()
    {
        Country::$instances[14]->name = 'Polska';
        $closed = new Country();
        $closed->id = 15;
        $closed->iso_code = 'XX';
        $closed->active = false;
        Country::register($closed);
        $controller = $this->controller();
        Tools::$values = array('id_order' => 15);
        $controller->initContent();

        $this->assertSame(
            array(14 => array('id_country' => 14, 'iso_code' => 'PL', 'name' => 'Polska', 'active' => 1)),
            Context::getContext()->smarty->vars['ifirma_countries']
        );
    }

    public function testControllerBuildsItsServiceOnce()
    {
        $controller = $this->controller();
        $service = new ReflectionMethod($controller, 'service');
        $service->setAccessible(true);

        $first = $service->invoke($controller);

        $this->assertInstanceOf('IfirmaDataRequestService', $first);
        $this->assertSame($first, $service->invoke($controller));
    }

    // ---- the form in the service: the request read, the address as defaults ----

    public function testCollectInputReadsEveryFieldInTheOrderOfFields()
    {
        Tools::$values = array(
            'stray' => 'ignored', 'token' => 'front-token', 'id_order' => 15,
            'customer_note' => 'Call me', 'city' => ' Gdansk ', 'company' => array('Not', 'text'),
            'want_invoice_for_company' => '1', 'id_country' => 14, 'address2' => '0', 'vat_number' => '',
        );

        $input = $this->service->collectInput();

        $this->assertSame(array(
            'want_invoice_for_company' => '1',
            'company' => array('Not', 'text'),
            'vat_number' => '',
            'firstname' => false,
            'lastname' => false,
            'address1' => false,
            'address2' => '0',
            'postcode' => false,
            'city' => ' Gdansk ',
            'id_country' => 14,
            'customer_note' => 'Call me',
        ), $input);
        $this->assertSame($this->service->fields(), array_keys($input));
    }

    public function testCollectInputReturnsAFullFormAsPosted()
    {
        Tools::$values = array('id_order' => 15, 'token' => 'front-token') + $this->companyInput();

        $this->assertSame($this->companyInput(), $this->service->collectInput());
    }

    public function testCollectInputOfAnEmptyRequestHasEveryFieldAsFalse()
    {
        Tools::$values = array();

        $this->assertSame(array_fill_keys($this->service->fields(), false), $this->service->collectInput());
    }

    /**
     * @dataProvider invoiceAddresses
     */
    public function testDefaultsFromAddressShowsTheInvoiceAddress(array $fields, array $expected)
    {
        $this->changeInvoiceAddress($fields);

        $this->assertSame($expected, $this->service->defaultsFromAddress(new Address(3)));
        $this->assertSame(array(), Address::$saved);
    }

    public function testDefaultsFromAddressHasTheKeysOfFieldsInOrder()
    {
        $this->assertSame($this->service->fields(), array_keys($this->service->defaultsFromAddress(new Address(3))));
    }

    // ---- field validation, cool-down and admin text limits (D14) ---------------

    private function refusedFields(array $input)
    {
        $e = $this->assertRefused('invalid', function () use ($input) {
            $this->service->submit($this->order, $this->customer, $input);
        });
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'), 'nothing stored');

        return $e->errors;
    }

    private function polandWithFormat()
    {
        $poland = new Country();
        $poland->id = 14;
        $poland->iso_code = 'PL';
        $poland->zip_code_format = 'NN-NNN';
        Country::register($poland);
    }

    public function testNameWithMarkupRefused()
    {
        Mail::$sent = array();
        $errors = $this->refusedFields($this->privateInput(array('firstname' => '<script>x</script>')));

        $this->assertArrayHasKey('firstname', $errors);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testAddressAndCityWithMarkupRefused()
    {
        $errors = $this->refusedFields($this->privateInput(array('address1' => 'Nowa <b>5', 'city' => 'War<i>szawa')));

        $this->assertArrayHasKey('address1', $errors);
        $this->assertArrayHasKey('city', $errors);
    }

    public function testPostcodeFollowsTheFormatOfTheCountry()
    {
        $this->polandWithFormat();

        $errors = $this->refusedFields($this->privateInput(array('postcode' => 'ABCDE')));
        $this->assertSame(array('postcode'), array_keys($errors));

        $this->assertInstanceOf('IfirmaDataRequest', $this->service->submit($this->order, $this->customer, $this->privateInput(array('postcode' => '00-950'))));
    }

    public function testPostcodeWithoutTheDashMatchesTheFormat()
    {
        $this->polandWithFormat();

        $this->assertSame(array(), $this->service->validate($this->privateInput(array('postcode' => '00950'))));
    }

    public function testInactiveCountryRefused()
    {
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'PL';
        $country->active = false;
        Country::register($country);

        $errors = $this->refusedFields($this->privateInput());

        $this->assertArrayHasKey('id_country', $errors);
    }

    public function testUnknownCountryRefused()
    {
        $errors = $this->refusedFields($this->privateInput(array('id_country' => '999')));

        $this->assertArrayHasKey('id_country', $errors);
    }

    public function testOverLongNoteRefused()
    {
        $errors = $this->refusedFields($this->companyInput(array('customer_note' => str_repeat('a', 1001))));

        $this->assertSame(array('customer_note'), array_keys($errors));
    }

    public function testNoteOfExactlyTheLimitAccepted()
    {
        $request = $this->submitPending($this->companyInput(array('customer_note' => str_repeat('a', 1000))));

        $this->assertSame(1000, strlen($request->customer_note));
    }

    public function testArrayValuesCountAsEmpty()
    {
        $errors = $this->refusedFields($this->privateInput(array('firstname' => array('Anna'), 'city' => array('x'))));

        $this->assertArrayHasKey('firstname', $errors);
        $this->assertArrayHasKey('city', $errors);
    }

    /**
     * Scripts the two cool-down queries from the stored requests, as the database would answer them.
     */
    private function scriptRequestHistory()
    {
        $rows = function () {
            return IfirmaTestState::objects('ifirma_data_request');
        };
        Db::getInstance()->whenGetValue('/SELECT GREATEST\(date_add, date_upd\)/', function ($sql) use ($rows) {
            $newest = '';
            foreach ($rows() as $row) {
                $stamp = max($row['date_add'], $row['date_upd']);
                if ($stamp > $newest) {
                    $newest = $stamp;
                }
            }

            return $newest;
        });
        Db::getInstance()->whenGetValue('/SELECT COUNT\(\*\) FROM `\w*ifirma_data_request`/', function ($sql) use ($rows) {
            preg_match('/date_add >= "([^"]+)"/', $sql, $since);
            $count = 0;
            foreach ($rows() as $row) {
                if ($row['date_add'] >= $since[1]) {
                    $count++;
                }
            }

            return $count;
        });
    }

    /** @return IfirmaDataRequestService a service whose clock runs $seconds ahead of real time */
    private function serviceAhead($seconds)
    {
        return new IfirmaDataRequestService(new Module(), function () use ($seconds) {
            return date('Y-m-d H:i:s', time() + $seconds);
        });
    }

    public function testResubmitAfterCancelWithinTenMinutesRefused()
    {
        $this->scriptRequestHistory();
        $request = $this->submitPending();
        $this->service->cancel($request, $this->customer);
        Mail::$sent = array();
        $later = $this->serviceAhead(120);

        $this->assertSame('cooldown', $later->canRequest($this->order, $this->customer)['reason']);
        $e = $this->assertRefused('cooldown', function () use ($later) {
            $later->submit($this->order, $this->customer, $this->companyInput());
        });
        $this->assertSame('Please wait 10 minutes before sending another request for this order.', $e->getMessage());
        $this->assertCount(1, IfirmaTestState::objects('ifirma_data_request'), 'nothing new stored');
        $this->assertSame(array(), Mail::$sent);
    }

    public function testCooldownOverAfterTenMinutes()
    {
        $this->scriptRequestHistory();
        $request = $this->submitPending();
        $this->service->cancel($request, $this->customer);
        $later = $this->serviceAhead(601);

        $this->assertSame('ok', $later->canRequest($this->order, $this->customer)['reason']);
        $this->assertInstanceOf('IfirmaDataRequest', $later->submit($this->order, $this->customer, $this->companyInput()));
    }

    private function storeCancelledRequests($count)
    {
        for ($i = 0; $i < $count; $i++) {
            $request = new IfirmaDataRequest();
            $request->id_order = 100 + $i;
            $request->id_customer = 7;
            $request->status = IfirmaDataRequest::STATUS_CANCELLED;
            $request->save();
        }
    }

    public function testDailyCapRefusesTheEleventhRequest()
    {
        $this->scriptRequestHistory();
        // the requests belong to other orders: the cool-down of this order is not what refuses
        Db::getInstance()->whenGetValue('/SELECT GREATEST\(date_add, date_upd\)/', '2000-01-01 00:00:00');
        $this->storeCancelledRequests(10);
        Mail::$sent = array();

        $this->assertSame('daily_cap', $this->service->canRequest($this->order, $this->customer)['reason']);
        $e = $this->assertRefused('daily_cap', function () {
            $this->submitPending();
        });
        $this->assertSame('You have reached the limit of requests for today.', $e->getMessage());
        $this->assertCount(10, IfirmaTestState::objects('ifirma_data_request'));
        $this->assertSame(array(), Mail::$sent);
    }

    public function testNineRequestsInADayStillAllowed()
    {
        $this->scriptRequestHistory();
        Db::getInstance()->whenGetValue('/SELECT GREATEST\(date_add, date_upd\)/', '2000-01-01 00:00:00');
        $this->storeCancelledRequests(9);

        $this->assertSame('ok', $this->service->canRequest($this->order, $this->customer)['reason']);
    }

    public function testOverLongRejectionReasonRefusedAndRequestUnchanged()
    {
        $request = $this->submitPending();
        $e = $this->assertRefused('invalid', function () use ($request) {
            $this->service->reject($request, 4, str_repeat('a', 1001));
        });

        $this->assertArrayHasKey('reason', $e->errors);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
        $rows = IfirmaTestState::objects('ifirma_data_request');
        $this->assertSame('pending', reset($rows)['status']);
    }

    public function testOverLongAdminNoteRefusedAndRequestUnchanged()
    {
        $request = $this->submitPending();
        $e = $this->assertRefused('invalid', function () use ($request) {
            $this->service->approve($request, 4, null, str_repeat('b', 1001));
        });

        $this->assertArrayHasKey('admin_note', $e->errors);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
    }

    public function testMarkupInTheAdminNoteIsStoredAsText()
    {
        $request = $this->submitPending();

        $this->service->approve($request, 4, null, '<b>ok</b> & fine');

        $rows = IfirmaTestState::objects('ifirma_data_request');
        $this->assertSame('<b>ok</b> & fine', reset($rows)['admin_note']);
    }
}
