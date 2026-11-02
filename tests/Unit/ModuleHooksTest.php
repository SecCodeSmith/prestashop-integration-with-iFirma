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
 * Hooks, admin panel assignment and configuration form of the main module class,
 * exercised through the real classes on one ScriptedTransport.
 */
class ModuleHooksTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function vars()
    {
        return Context::getContext()->smarty->vars;
    }

    private function lastDisplayed()
    {
        $d = Module::$displayed;

        return end($d);
    }

    private function stateObject($id)
    {
        $s = new OrderState();
        $s->id = $id;

        return $s;
    }

    private function formInputNames()
    {
        $names = array();
        foreach (HelperForm::$lastForm[0]['form']['input'] as $input) {
            $names[$input['name']] = $input;
        }

        return $names;
    }

    // ---- status hook -------------------------------------------------------------------

    public function testTriggerStateIssuesOnceAndMailsTheCustomer()
    {
        $this->queueFullSuccess();
        $this->module->hookActionOrderStatusPostUpdate(array('id_order' => 15, 'newOrderStatus' => $this->stateObject(5)));

        $this->assertCount(1, $this->posts());
        $this->assertCount(1, Mail::$sent);
        $rows = IfirmaTestState::objects('ifirma_receipt');
        $this->assertCount(1, $rows);
        $this->assertSame('created', reset($rows)['status']);
    }

    public function testOtherStateDoesNothing()
    {
        $this->module->hookActionOrderStatusPostUpdate(array('id_order' => 15, 'newOrderStatus' => $this->stateObject(3)));

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), Mail::$sent);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_receipt'));
    }

    public function testStatusHookIgnoresIncompleteParams()
    {
        $this->module->hookActionOrderStatusPostUpdate(array('id_order' => 15));
        $this->module->hookActionOrderStatusPostUpdate(array('newOrderStatus' => $this->stateObject(5)));
        $this->assertSame(array(), $this->t->calls);
    }

    // ---- customer order page -------------------------------------------------------------

    public function testOrderDetailIssued()
    {
        $this->makeReceipt();
        $out = $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));

        $this->assertSame('<rendered:views/templates/hook/order_detail.tpl>', $out);
        $this->assertSame('issued', $this->vars()['ifirma_view']['state']);
        $this->assertSame(0, $this->listCalls());
    }

    public function testOrderDetailNotIssuedShowsTriggerStateName()
    {
        $s = $this->stateObject(5);
        $s->name = 'Payment accepted';
        OrderState::register($s);

        $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));
        $view = $this->vars()['ifirma_view'];
        $this->assertSame('not_issued', $view['state']);
        $this->assertTrue($view['auto_issue_enabled']);
        $this->assertSame('Payment accepted', $view['trigger_state_name']);
        $this->assertTrue($view['can_request_change']);
    }

    public function testOrderDetailLocalizedStateNameArray()
    {
        $s = $this->stateObject(5);
        $s->name = array(1 => 'Paid', 2 => 'Zaplacono');
        OrderState::register($s);

        $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));
        $this->assertSame('Paid', $this->vars()['ifirma_view']['trigger_state_name']);
    }

    public function testOrderDetailHeldByPendingRequest()
    {
        $this->makeReceipt(array('status' => 'held', 'ifirma_id' => ''));
        $this->makeRequest('pending');

        $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));
        $this->assertSame('held', $this->vars()['ifirma_view']['state']);
    }

    public function testOrderDetailHeldReceiptWithoutRequestIsNotIssued()
    {
        $this->makeReceipt(array('status' => 'held', 'ifirma_id' => ''));

        $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));
        $this->assertSame('not_issued', $this->vars()['ifirma_view']['state']);
    }

    public function testStaleReceiptRunsOneOpportunisticSync()
    {
        $this->makeReceipt(array('last_sync_at' => date('Y-m-d H:i:s', time() - 7200)));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array($this->remoteRow(array('Zaplacono' => 123.00)))));

        $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));

        $this->assertSame(1, $this->listCalls());
        $this->assertSame('issued', $this->vars()['ifirma_view']['state']);
        $this->assertSame('paid', (new IfirmaReceipt(1))->status);
    }

    public function testRecentlySyncedReceiptMakesNoCall()
    {
        $this->makeReceipt(array('last_sync_at' => date('Y-m-d H:i:s', time() - 60)));
        $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));
        $this->assertSame(array(), $this->t->calls);
    }

    public function testOrderDetailEmptyWhenNotConfigured()
    {
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', '');
        $this->assertSame('', $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15])));
        $this->assertSame(array(), Module::$displayed);
    }

    public function testOrderDetailEmptyForAnotherCustomersOrder()
    {
        $this->makeReceipt();
        Context::getContext()->customer->id = 99;

        $this->assertSame('', $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15])));
        $this->assertSame(array(), $this->t->calls);
    }

    public function testOrderDetailEmptyWhenReceiptBelongsToAnotherCustomer()
    {
        $this->makeReceipt(array('id_customer' => 99));
        $this->assertSame('', $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15])));
    }

    // ---- customer account ----------------------------------------------------------------

    public function testCustomerAccountVisibleWithADocument()
    {
        $this->makeReceipt();
        $out = $this->module->hookDisplayCustomerAccount(array());

        $this->assertSame('<rendered:views/templates/hook/customer_account.tpl>', $out);
        $account = $this->vars()['ifirma_account'];
        $this->assertTrue($account['visible']);
        $this->assertCount(1, $account['receipts']);
    }

    public function testCustomerAccountHiddenWithoutDocuments()
    {
        $this->assertSame('', $this->module->hookDisplayCustomerAccount(array()));
        $this->assertSame(array(), Module::$displayed);
    }

    public function testCustomerAccountVisibleForPendingRequestOnly()
    {
        $this->makeRequest('pending');
        $this->module->hookDisplayCustomerAccount(array());
        $account = $this->vars()['ifirma_account'];
        $this->assertTrue($account['visible']);
        $this->assertSame(1, $account['pending_count']);
    }

    public function testCustomerAccountHiddenForGuests()
    {
        Context::getContext()->customer->logged = false;
        $this->makeReceipt();
        $this->assertSame('', $this->module->hookDisplayCustomerAccount(array()));
    }

    // ---- admin order panel ---------------------------------------------------------------

    public function testAdminPanelForSuspectedReceipt()
    {
        $this->makeReceipt(array(
            'status' => 'duplicate_suspected',
            'ifirma_id' => '',
            'last_error' => 'Suspected duplicate: 1 matching document(s) found in iFirma',
            'duplicate_candidates' => json_encode(array(
                'candidates' => array(IfirmaDuplicateMatcher::summarize($this->remoteRow())),
                'resolution' => null,
            )),
        ));

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        $v = $this->vars();

        $this->assertSame('views/templates/hook/admin_order_panel.tpl', $this->lastDisplayed()[0]);
        $this->assertSame('suspected', $v['ifirma_state']);
        $this->assertTrue($v['ifirma_can_issue_anyway']);
        $this->assertTrue($v['ifirma_can_create']);
        $this->assertFalse($v['ifirma_is_issued']);
        $this->assertCount(1, $v['ifirma_candidates']);
        $this->assertSame('77', $v['ifirma_candidates'][0]['id']);
        $this->assertFalse($v['ifirma_has_pending_request']);
    }

    public function testAdminPanelForIssuedReceipt()
    {
        $this->makeReceipt(array('status' => 'paid', 'source' => 'external', 'delivery_status' => 'sent', 'delivery_channel' => 'shop_mail', 'delivered_at' => '2026-11-01 10:00:00'));

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        $v = $this->vars();

        $this->assertSame('paid', $v['ifirma_state']);
        $this->assertTrue($v['ifirma_is_issued']);
        $this->assertTrue($v['ifirma_is_external']);
        $this->assertFalse($v['ifirma_can_create']);
        $this->assertFalse($v['ifirma_can_issue_anyway']);
        $this->assertStringContainsString('2026-11-01 10:00:00', $v['ifirma_delivery_text']);
        $this->assertEquals(123.0, $v['ifirma_gross']);
        $this->assertStringContainsString('token=admin-token-AdminIfirma', $v['ifirma_ajax_url']);
    }

    public function testAdminPanelStateDerivation()
    {
        $cases = array(
            array(array('status' => 'created', 'remote_missing' => 1), 'missing'),
            array(array('status' => 'unknown', 'ifirma_id' => ''), 'unknown'),
            array(array('status' => 'error', 'ifirma_id' => ''), 'error'),
            array(array('status' => 'created'), 'issued'),
        );
        foreach ($cases as $case) {
            IfirmaTestState::resetAll();
            $this->wiringSetUp();
            $this->makeReceipt($case[0]);
            $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
            $this->assertSame($case[1], $this->vars()['ifirma_state']);
        }
    }

    public function testAdminPanelWithoutReceiptOffersCreate()
    {
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        $v = $this->vars();
        $this->assertSame('not_issued', $v['ifirma_state']);
        $this->assertFalse($v['ifirma_has_receipt']);
        $this->assertTrue($v['ifirma_can_create']);
        $this->assertFalse($v['ifirma_can_issue_anyway']);
    }

    public function testAdminPanelHeldWithPendingRequest()
    {
        $this->makeReceipt(array('status' => 'held', 'ifirma_id' => ''));
        $req = $this->makeRequest('pending');
        $this->makeRequest('rejected');

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        $v = $this->vars();

        $this->assertSame('held', $v['ifirma_state']);
        $this->assertFalse($v['ifirma_can_create']);
        $this->assertTrue($v['ifirma_has_pending_request']);
        $this->assertSame((int) $req->id, $v['ifirma_pending_request']['id']);
        $this->assertNotEmpty($v['ifirma_request_diff']);
        $names = array();
        foreach ($v['ifirma_request_fields'] as $f) {
            $names[] = $f['name'];
        }
        $this->assertSame(IfirmaDataRequestService::build($this->module)->fields(), $names);
        $this->assertCount(2, $v['ifirma_request_history']);
    }

    public function testAdminPanelLegacyHookIsSilentOnModernPages()
    {
        $this->assertSame('', $this->module->hookDisplayAdminOrder(array('id_order' => 15)));
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        // render guard: the second call on the same request renders nothing
        $this->assertSame('', $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15)));
    }

    // ---- configuration form --------------------------------------------------------------

    private function validPost(array $over = array())
    {
        return array_merge(array(
            'submitIfirma' => '1',
            'IFIRMA_BASE_URL' => 'http://ifirma-mock:8001/iapi/',
            'IFIRMA_USER' => 'u@example.com',
            'IFIRMA_KEY_FAKTURA' => str_repeat('1', 64),
            'IFIRMA_KEY_ABONENT' => '',
            'IFIRMA_KEY_RACHUNEK' => '',
            'IFIRMA_DOC_TYPE' => 'fakturakraj',
            'IFIRMA_PAYMENT_METHOD' => 'PRZ',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'order',
            'IFIRMA_PAYMENT_METHOD_MAP' => '',
            'IFIRMA_PAYMENT_TYPE_TOKEN' => 'prz_faktura_kraj',
            'IFIRMA_DEFAULT_UNIT' => 'szt.',
            'IFIRMA_AUTO_CREATE_STATE' => '5',
            'IFIRMA_MARK_PAID_ON_CREATE' => '1',
            'IFIRMA_SEND_CHANNEL' => 'none',
            'IFIRMA_SEND_TEXT' => 'Thank you',
            'IFIRMA_DUPLICATE_CHECK' => '0',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '30',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0,05',
            'IFIRMA_ALLOW_DATA_REQUEST' => '0',
            'IFIRMA_NOTIFY_EMAIL' => 'office@example.test',
            'IFIRMA_SYNC_INTERVAL_MIN' => '30',
            'IFIRMA_CRON_MAX_CALLS' => '20',
            'IFIRMA_RATE_MAX_PER_MIN' => '60',
        ), $over);
    }

    public function testConfigFormHasEveryNewField()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_WINDOW_DAYS', 60);
        $this->module->getContent();
        $inputs = $this->formInputNames();

        foreach (array(
            'IFIRMA_SEND_CHANNEL', 'IFIRMA_SEND_TEXT', 'IFIRMA_DUPLICATE_CHECK', 'IFIRMA_DUPLICATE_WINDOW_DAYS',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', 'IFIRMA_ALLOW_DATA_REQUEST', 'IFIRMA_NOTIFY_EMAIL',
            'IFIRMA_SYNC_INTERVAL_MIN', 'IFIRMA_CRON_MAX_CALLS', 'IFIRMA_RATE_MAX_PER_MIN', 'IFIRMA_CRON_INFO',
        ) as $name) {
            $this->assertArrayHasKey($name, $inputs, $name);
        }

        $this->assertStringContainsString(
            "With the default channel, documents are e-mailed to customers automatically on the next trigger. Choose 'Do not send' to disable.",
            $inputs['IFIRMA_SEND_CHANNEL']['desc']
        );
        $channels = array();
        foreach ($inputs['IFIRMA_SEND_CHANNEL']['options']['query'] as $o) {
            $channels[] = $o['id'];
        }
        $this->assertSame(array('shop_mail', 'ifirma_mail', 'none'), $channels);

        $values = HelperForm::$lastHelper->fields_value;
        $this->assertSame('shop_mail', $values['IFIRMA_SEND_CHANNEL']);
        $this->assertSame(60, $values['IFIRMA_DUPLICATE_WINDOW_DAYS']);
    }

    public function testCronInfoShowsTokenAndUrl()
    {
        $this->module->getContent();
        $html = $this->formInputNames()['IFIRMA_CRON_INFO']['html_content'];

        $this->assertStringContainsString('cron-token-1', $html);
        $this->assertStringContainsString('https://shop.test/module/ifirma/cron?token=cron-token-1', $html);
        $this->assertStringContainsString('curl', $html);
        $buttons = HelperForm::$lastForm[0]['form']['buttons'];
        $this->assertSame('submitIfirmaRegenerateCron', $buttons[0]['name']);
    }

    public function testRegenerateCronTokenReplacesTheToken()
    {
        Tools::$values = array('submitIfirmaRegenerateCron' => '1');
        $out = $this->module->getContent();

        $new = Configuration::get('IFIRMA_CRON_TOKEN');
        $this->assertNotSame('cron-token-1', $new);
        $this->assertSame(32, strlen($new));
        $this->assertStringContainsString('<confirmation>', $out);
        $this->assertStringContainsString($new, $this->formInputNames()['IFIRMA_CRON_INFO']['html_content']);
    }

    public function testValidSavePersistsEveryKey()
    {
        Tools::$values = $this->validPost();
        $out = $this->module->getContent();

        $this->assertStringContainsString('<confirmation>', $out);
        $this->assertSame('none', Configuration::get('IFIRMA_SEND_CHANNEL'));
        $this->assertSame('Thank you', Configuration::get('IFIRMA_SEND_TEXT'));
        $this->assertSame(0, Configuration::get('IFIRMA_DUPLICATE_CHECK'));
        $this->assertSame(30, Configuration::get('IFIRMA_DUPLICATE_WINDOW_DAYS'));
        $this->assertSame('0.05', Configuration::get('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE'));
        $this->assertSame(0, Configuration::get('IFIRMA_ALLOW_DATA_REQUEST'));
        $this->assertSame('office@example.test', Configuration::get('IFIRMA_NOTIFY_EMAIL'));
        $this->assertSame(30, Configuration::get('IFIRMA_SYNC_INTERVAL_MIN'));
        $this->assertSame(20, Configuration::get('IFIRMA_CRON_MAX_CALLS'));
        $this->assertSame(60, Configuration::get('IFIRMA_RATE_MAX_PER_MIN'));
        $this->assertSame('fakturakraj', Configuration::get('IFIRMA_DOC_TYPE'));
        $this->assertSame(5, Configuration::get('IFIRMA_AUTO_CREATE_STATE'));
    }

    public function testInvalidNotifyEmailIsRejectedAndNothingSaved()
    {
        Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', 'old@example.test');
        Tools::$values = $this->validPost(array('IFIRMA_NOTIFY_EMAIL' => 'not-an-email'));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<error>', $out);
        $this->assertStringContainsString('The notification e-mail address is not valid.', $out);
        $this->assertSame('old@example.test', Configuration::get('IFIRMA_NOTIFY_EMAIL'));
        $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
    }

    public function testEmptyNotifyEmailIsAccepted()
    {
        Tools::$values = $this->validPost(array('IFIRMA_NOTIFY_EMAIL' => ''));
        $this->assertStringContainsString('<confirmation>', $this->module->getContent());
        $this->assertSame('', Configuration::get('IFIRMA_NOTIFY_EMAIL'));
    }

    public function testOutOfRangeNumbersAreRejected()
    {
        foreach (array(
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '0',
            'IFIRMA_SYNC_INTERVAL_MIN' => '1441',
            'IFIRMA_CRON_MAX_CALLS' => '91',
            'IFIRMA_RATE_MAX_PER_MIN' => '101',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '-1',
        ) as $key => $bad) {
            Tools::$values = $this->validPost(array($key => $bad));
            $this->assertStringContainsString('<error>', $this->module->getContent(), $key);
        }
        $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
    }

    public function testUnknownChannelFallsBackToDefault()
    {
        Tools::$values = $this->validPost(array('IFIRMA_SEND_CHANNEL' => 'carrier_pigeon'));
        $this->module->getContent();
        $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
    }

    public function testConfigFormHasPaymentMethodFields()
    {
        $this->module->getContent();
        $inputs = $this->formInputNames();

        $this->assertArrayHasKey('IFIRMA_PAYMENT_METHOD_MODE', $inputs);
        $this->assertArrayHasKey('IFIRMA_PAYMENT_METHOD_MAP', $inputs);
        $this->assertCount(14, $inputs['IFIRMA_PAYMENT_METHOD']['options']['query']);
    }

    public function testInvalidPaymentMethodMapIsRejectedAndNothingSaved()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MAP', 'ps_wirepayment=PRZ');
        Tools::$values = $this->validPost(array('IFIRMA_PAYMENT_METHOD_MAP' => 'ps_checkpayment=XYZ'));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<error>', $out);
        $this->assertStringContainsString('line 1', $out);
        $this->assertSame('ps_wirepayment=PRZ', Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'));
    }

    public function testValidPaymentMethodMapIsStoredNormalised()
    {
        Tools::$values = $this->validPost(array(
            'IFIRMA_PAYMENT_METHOD_MAP' => "  PS_CheckPayment = prz \r\n\r\n# comment\r\nMyGateway* = ele",
        ));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<confirmation>', $out);
        $this->assertSame("ps_checkpayment=PRZ\nmygateway*=ELE", Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'));
    }

    // ---- API limit box -------------------------------------------------------------------

    public function testLimitBoxShownWhenAbonentKeyIsSet()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', str_repeat('2', 64));
        $this->t->queue('GET', '#abonent/limit\.json#', array('body' => '{"response":{"Kod":0,"LimitWykorzystany":12,"LimitPrzyznany":15000}}'));

        $this->module->getContent();
        $html = $this->formInputNames()['IFIRMA_API_LIMIT']['html_content'];

        $this->assertStringContainsString('iFirma API limit today: 12/15000', $html);
        $this->assertCount(1, $this->t->calls);
    }

    public function testLimitUnavailableNotice()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', str_repeat('2', 64));

        $this->module->getContent();
        $html = $this->formInputNames()['IFIRMA_API_LIMIT']['html_content'];

        $this->assertStringContainsString('currently unavailable', $html);
        $this->assertStringNotContainsString('15000', $html);
    }

    public function testNoLimitBoxAndNoRequestWithoutTheAbonentKey()
    {
        $this->module->getContent();

        $this->assertArrayNotHasKey('IFIRMA_API_LIMIT', $this->formInputNames());
        $this->assertSame(array(), $this->t->calls);
    }
}
