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
    use IfirmaContractorMapDb;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        unset($_FILES['IFIRMA_CONTRACTOR_CSV']);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        Ifirma::$uploadValidator = null;
        unset($_FILES['IFIRMA_CONTRACTOR_CSV']);
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

    public function testAdminPanelAssignsTheKsefText()
    {
        $this->makeReceipt(array('ksef_status' => 'WYSLANA', 'ksef_sent_at' => '2026-11-02 20:30:00'));

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        $v = $this->vars();

        $this->assertSame('sent 2026-11-02 20:30, awaiting KSeF', $v['ifirma_ksef_text']);
    }

    public function testAdminPanelKsefTextForAPrivateBuyer()
    {
        $this->makeReceipt();

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        $this->assertSame('not sent (private buyer (B2B mode))', $this->vars()['ifirma_ksef_text']);
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
        $this->assertTrue($v['ifirma_configured']);
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v['ifirma_build_stamp']));
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

    public function testAdminPanelRendersOncePerRequest()
    {
        $this->assertFalse(method_exists($this->module, 'hookDisplayAdminOrder'));
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        // render guard: the second call on the same request renders nothing
        $this->assertSame('', $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15)));
    }

    // ---- admin customer page panel ---------------------------------------------------------

    private function receiptCountsByCustomer(array $rows)
    {
        // same pattern as IfirmaWiringFixture::wiringScriptDb(), which would otherwise answer first
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`\s+WHERE id_customer = (\d+)/', function ($sql, $m) use ($rows) {
            return strpos($sql, 'GROUP BY document_type, status') !== false && (int) $m[1] === 5 ? $rows : array();
        });
    }

    public function testPanelShownOnAFreshInstall()
    {
        Module::reset();
        $this->assertTrue($this->module->install());
        $this->assertContains('displayAdminCustomers', Module::$hooks);

        $out = $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertSame('<rendered:views/templates/hook/admin_customer_panel.tpl>', $out);
        $this->assertSame('views/templates/hook/admin_customer_panel.tpl', $this->lastDisplayed()[0]);
        $this->assertSame(5, $v['ifirma_customer_id']);
        $this->assertSame('PS-C5', $v['ifirma_customer_link']['identifier']);
        $this->assertFalse($v['ifirma_customer_link']['linked']);
        $this->assertStringContainsString('token=admin-token-AdminIfirma', $v['ifirma_customer_ajax_url']);
        $this->assertSame('Jan', $v['ifirma_customer_criteria']['firstname']);
        $this->assertSame(0, $v['ifirma_customer_counts']['issued']);
        $this->assertTrue($v['ifirma_customer_can_register']);
    }

    public function testPanelAcceptsACustomerObjectAndRefusesUnknownCustomers()
    {
        $out = $this->module->hookDisplayAdminCustomers(array('customer' => Customer::$instances[5]));
        $this->assertSame('<rendered:views/templates/hook/admin_customer_panel.tpl>', $out);
        $this->assertSame(5, $this->vars()['ifirma_customer_id']);

        $this->assertSame('', $this->module->hookDisplayAdminCustomers(array()));
        $this->assertSame('', $this->module->hookDisplayAdminCustomers(array('id_customer' => 0)));
        $this->assertSame('', $this->module->hookDisplayAdminCustomers(array('id_customer' => 999)));
    }

    public function testPanelPageDoesNotWaitForIfirma()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'manual', 'Jan Kowalski');
        $this->receiptCountsByCustomer(array(array('document_type' => 'fakturakraj', 'status' => 'paid', 'total' => 2)));

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));

        $this->assertSame(array(), $this->t->calls);
        $v = $this->vars();
        $this->assertSame(2, $v['ifirma_customer_counts']['issued']);
        $this->assertSame(2, $v['ifirma_customer_counts']['by_type']['invoice']);
    }

    public function testPanelShowsTheLinkWithATranslatedMatchLabel()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'import-email', 'Jan Kowalski');

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertSame('KOWALSKI-01', $v['ifirma_customer_link']['identifier']);
        $this->assertTrue($v['ifirma_customer_link']['linked']);
        $this->assertSame('import, e-mail', $v['ifirma_customer_match_label']);
        $labels = json_decode($v['ifirma_customer_match_labels_json'], true);
        $this->assertSame('manual link', $labels['manual']);
        $this->assertCount(9, $labels);
    }

    public function testMarkupInAContractorNameNeverReachesThePageUnescaped()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'manual', '<script>alert(1)</script>');

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $this->assertSame('<script>alert(1)</script>', $this->vars()['ifirma_customer_link']['name']);

        // the stubbed Smarty does not render, so pin the template: every output is escaped or cast
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/views/templates/hook/admin_customer_panel.tpl');
        preg_match_all('/\{\$[^}]*\}/', $tpl, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $output) {
            $this->assertSame(
                1,
                preg_match("/\|(escape:'html':'UTF-8'|escape:'javascript':'UTF-8'|intval)\}$/", $output),
                'unescaped output ' . $output
            );
        }
        $this->assertStringNotContainsString('nofilter', $tpl);
        $this->assertStringNotContainsString('.html(', $tpl);
    }

    public function testRegisterIsUnavailableWithoutAnOrder()
    {
        $c = new Customer();
        $c->id = 8;
        $c->firstname = 'Anna';
        $c->lastname = 'Nowak';
        Customer::register($c);

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 8));
        $v = $this->vars();

        $this->assertFalse($v['ifirma_customer_can_register']);
        $this->assertSame('Registration needs at least one order of this customer.', $v['ifirma_customer_register_reason']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testRegisterIsUnavailableWhenContractorSyncIsDisabled()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertFalse($v['ifirma_customer_can_register']);
        $this->assertSame('Contractor registration is disabled in the module settings.', $v['ifirma_customer_register_reason']);
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
            'IFIRMA_SEND_COPY_MODE' => 'shop',
            'IFIRMA_SEND_COPY_EMPLOYEE' => '0',
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
            'IFIRMA_SEND_CHANNEL', 'IFIRMA_SEND_TEXT', 'IFIRMA_SEND_COPY_MODE', 'IFIRMA_SEND_COPY_EMPLOYEE',
            'IFIRMA_DUPLICATE_CHECK', 'IFIRMA_DUPLICATE_WINDOW_DAYS',
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

    public function testConfigFormHasTheKsefModeSelect()
    {
        $this->module->getContent();
        $inputs = $this->formInputNames();

        $this->assertArrayHasKey('IFIRMA_KSEF_MODE', $inputs);
        $this->assertSame('select', $inputs['IFIRMA_KSEF_MODE']['type']);
        $this->assertStringContainsString('cannot be withdrawn', $inputs['IFIRMA_KSEF_MODE']['desc']);
        $modes = array();
        foreach ($inputs['IFIRMA_KSEF_MODE']['options']['query'] as $o) {
            $modes[] = $o['id'];
        }
        $this->assertSame(array('b2b', 'all', 'off'), $modes);
        $this->assertSame('b2b', HelperForm::$lastHelper->fields_value['IFIRMA_KSEF_MODE']);
    }

    public function testKsefModeIsSavedNormalised()
    {
        Tools::$values = $this->validPost(array('IFIRMA_KSEF_MODE' => 'off'));
        $this->module->getContent();
        $this->assertSame('off', Configuration::get('IFIRMA_KSEF_MODE'));

        Tools::$values = $this->validPost(array('IFIRMA_KSEF_MODE' => 'ALL'));
        $this->module->getContent();
        $this->assertSame('all', Configuration::get('IFIRMA_KSEF_MODE'));

        Tools::$values = $this->validPost(array('IFIRMA_KSEF_MODE' => 'whenever'));
        $this->module->getContent();
        $this->assertSame('b2b', Configuration::get('IFIRMA_KSEF_MODE'));
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
        $this->assertSame('shop', Configuration::get('IFIRMA_SEND_COPY_MODE'));
        $this->assertSame(0, Configuration::get('IFIRMA_SEND_COPY_EMPLOYEE'));
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

    public function testSignatureSelectOffersTheFourCodes()
    {
        $this->module->getContent();
        $inputs = $this->formInputNames();

        $this->assertArrayHasKey('IFIRMA_SIGNATURE_TYPE', $inputs);
        $ids = array();
        foreach ($inputs['IFIRMA_SIGNATURE_TYPE']['options']['query'] as $o) {
            $ids[] = $o['id'];
        }
        $this->assertSame(array('BPO', 'BWO', 'OUP', 'UPO'), $ids);
        $this->assertSame('BPO', HelperForm::$lastHelper->fields_value['IFIRMA_SIGNATURE_TYPE']);
    }

    public function testPostedSignatureTypeIsNormalisedOnSave()
    {
        Tools::$values = $this->validPost(array('IFIRMA_SIGNATURE_TYPE' => 'brk'));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<confirmation>', $out);
        $this->assertSame('BPO', Configuration::get('IFIRMA_SIGNATURE_TYPE'));
    }

    public function testDocumentTypeSelectOffersOnlyFakturakraj()
    {
        $this->module->getContent();
        $inputs = $this->formInputNames();

        $this->assertArrayHasKey('IFIRMA_DOC_TYPE', $inputs);
        $ids = array();
        foreach ($inputs['IFIRMA_DOC_TYPE']['options']['query'] as $o) {
            $ids[] = $o['id'];
        }
        $this->assertSame(array('fakturakraj'), $ids);
    }

    public function testPostedParagonDocumentTypeIsStoredAsFakturakraj()
    {
        Tools::$values = $this->validPost(array('IFIRMA_DOC_TYPE' => 'fakturaparagon'));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<confirmation>', $out);
        $this->assertSame('fakturakraj', Configuration::get('IFIRMA_DOC_TYPE'));
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

    private function registerCopyEmployee($id, $first, $last, $active = 1)
    {
        $e = new Employee();
        $e->id = $id;
        $e->firstname = $first;
        $e->lastname = $last;
        $e->email = strtolower($first) . '@example.test';
        $e->active = $active;
        Employee::register($e);
    }

    public function testCopyEmployeeSelectListsActiveEmployees()
    {
        $this->registerCopyEmployee(3, 'Anna', 'Nowak');
        $this->registerCopyEmployee(4, 'Ewa', 'Adamska');
        $this->registerCopyEmployee(5, 'Olek', 'Zielinski', 0);
        $this->module->getContent();
        $inputs = $this->formInputNames();

        $modes = array();
        foreach ($inputs['IFIRMA_SEND_COPY_MODE']['options']['query'] as $o) {
            $modes[] = $o['id'];
        }
        $this->assertSame(array('none', 'shop', 'employee'), $modes);

        $this->assertSame('id_employee', $inputs['IFIRMA_SEND_COPY_EMPLOYEE']['options']['id']);
        $this->assertSame(
            array(
                array('id_employee' => 0, 'name' => 'choose an employee'),
                array('id_employee' => 4, 'name' => 'Ewa Adamska'),
                array('id_employee' => 3, 'name' => 'Anna Nowak'),
            ),
            $inputs['IFIRMA_SEND_COPY_EMPLOYEE']['options']['query']
        );
        $values = HelperForm::$lastHelper->fields_value;
        $this->assertSame('none', $values['IFIRMA_SEND_COPY_MODE']);
        $this->assertSame(0, $values['IFIRMA_SEND_COPY_EMPLOYEE']);
    }

    public function testEmployeeCopyModeWithoutActiveEmployeeIsRefusedAndNothingSaved()
    {
        $this->registerCopyEmployee(5, 'Olek', 'Zielinski', 0);
        foreach (array('0', '99', '5', '') as $bad) {
            Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'none');
            Tools::$values = $this->validPost(array(
                'IFIRMA_SEND_COPY_MODE' => 'employee',
                'IFIRMA_SEND_COPY_EMPLOYEE' => $bad,
            ));
            $out = $this->module->getContent();

            $this->assertStringContainsString('<error>', $out, 'employee ' . $bad);
            $this->assertStringContainsString('Choose an active employee for the delivery copy.', $out);
            $this->assertSame('none', Configuration::get('IFIRMA_SEND_COPY_MODE'));
            $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
        }
    }

    public function testUnknownCopyModeIsStoredAsNone()
    {
        Tools::$values = $this->validPost(array('IFIRMA_SEND_COPY_MODE' => 'everyone'));
        $this->assertStringContainsString('<confirmation>', $this->module->getContent());
        $this->assertSame('none', Configuration::get('IFIRMA_SEND_COPY_MODE'));
    }

    public function testValidEmployeeCopySavePersistsBothKeys()
    {
        $this->registerCopyEmployee(3, 'Anna', 'Nowak');
        Tools::$values = $this->validPost(array(
            'IFIRMA_SEND_COPY_MODE' => 'employee',
            'IFIRMA_SEND_COPY_EMPLOYEE' => '3',
        ));
        $this->assertStringContainsString('<confirmation>', $this->module->getContent());
        $this->assertSame('employee', Configuration::get('IFIRMA_SEND_COPY_MODE'));
        $this->assertSame(3, Configuration::get('IFIRMA_SEND_COPY_EMPLOYEE'));
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

    // ---- linking existing contractors ----------------------------------------------------------

    private function uploadFixture($content)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ifirma-csv');
        file_put_contents($tmp, $content);
        $_FILES['IFIRMA_CONTRACTOR_CSV'] = array(
            'name' => 'kontrahenci.csv',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => strlen($content),
        );
        Ifirma::$uploadValidator = function () {
            return true;
        };

        return $tmp;
    }

    public function testConfigPageShowsTheContractorsFieldsetWithTheCountTable()
    {
        IfirmaContractorMap::link(1, 'A-1', 'nip');
        IfirmaContractorMap::link(2, 'B-1', 'nip');
        IfirmaContractorMap::link(3, 'C-1', 'import-email');

        $out = $this->module->getContent();

        $this->assertStringContainsString('<form/>', $out);
        $this->assertStringContainsString('<rendered:views/templates/admin/contractors.tpl>', $out);
        $this->assertSame('views/templates/admin/contractors.tpl', $this->lastDisplayed()[0]);
        $v = $this->vars();
        $this->assertSame(array('import-email' => 1, 'nip' => 2), $v['ifirma_cm_counts']);
        $this->assertNull($v['ifirma_cm_report']);
        $this->assertSame(array(), $v['ifirma_cm_errors']);
        $this->assertStringContainsString('configure=ifirma', $v['ifirma_cm_action']);
        $this->assertStringContainsString('token=admin-token-AdminModules', $v['ifirma_cm_action']);
    }

    public function testContractorsTemplatePostsTheDocumentedFields()
    {
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/views/templates/admin/contractors.tpl');

        $this->assertStringContainsString('enctype="multipart/form-data"', $tpl);
        $this->assertStringContainsString('name="IFIRMA_CONTRACTOR_CSV"', $tpl);
        $this->assertStringContainsString('name="submitIfirmaImportContractors"', $tpl);
        $this->assertStringContainsString('name="submitIfirmaResetContractorMap"', $tpl);
        $this->assertStringContainsString('name="confirm" value="1"', $tpl);
        $this->assertStringContainsString('name="token"', $tpl);
    }

    public function testPostedUploadLinksCustomersAndShowsTheReport()
    {
        $db = Db::getInstance();
        $db->whenExecuteS('/FROM `ps_customer`/', array(
            array('id_customer' => 5, 'email' => 'jan@example.test', 'firstname' => 'Jan', 'lastname' => 'Kowalski'),
        ));
        $db->whenExecuteS('/FROM `ps_address`/', array(
            array('id_customer' => 9, 'vat_number' => 'PL5252248481', 'dni' => '', 'postcode' => '00-950', 'company' => 'ACME', 'firstname' => 'A', 'lastname' => 'B'),
        ));
        $tmp = $this->uploadFixture(file_get_contents(dirname(__DIR__) . '/fixtures/contractors-export.csv'));
        Tools::$values = array('submitIfirmaImportContractors' => '1');

        $this->module->getContent();
        @unlink($tmp);

        $v = $this->vars();
        $report = $v['ifirma_cm_report'];
        $this->assertSame(array(), $v['ifirma_cm_errors']);
        $this->assertSame(4, $report['rows']);
        $this->assertSame(2, $report['linked']);
        $this->assertSame(array('import-email' => 1, 'import-nip' => 1), $report['by']);
        $this->assertSame(2, $report['unmatched']);
        $this->assertSame('ZOLC-03, NIEZNANY-04', $report['unmatched_text']);
        $rows = IfirmaTestState::objects('ifirma_contractor_map');
        $this->assertCount(2, $rows);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testUploadWithoutIdentifierColumnShowsTheMissingColumn()
    {
        $tmp = $this->uploadFixture("Nazwa;NIP\nJan;5252248481\n");
        Tools::$values = array('submitIfirmaImportContractors' => '1');

        $this->module->getContent();
        @unlink($tmp);

        $v = $this->vars();
        $this->assertNull($v['ifirma_cm_report']);
        $this->assertCount(1, $v['ifirma_cm_errors']);
        $this->assertStringContainsString('Identyfikator', $v['ifirma_cm_errors'][0]);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_contractor_map'));
    }

    public function testUploadRejectsMissingOversizedAndForeignFiles()
    {
        Tools::$values = array('submitIfirmaImportContractors' => '1');

        unset($_FILES['IFIRMA_CONTRACTOR_CSV']);
        $this->module->getContent();
        $this->assertCount(1, $this->vars()['ifirma_cm_errors']);

        $tmp = $this->uploadFixture('Identyfikator;Nazwa');
        $_FILES['IFIRMA_CONTRACTOR_CSV']['size'] = 5242881;
        $this->module->getContent();
        $this->assertStringContainsString('5 MB', $this->vars()['ifirma_cm_errors'][0]);

        $_FILES['IFIRMA_CONTRACTOR_CSV']['size'] = 10;
        Ifirma::$uploadValidator = function () {
            return false;
        };
        $this->module->getContent();
        $this->assertCount(1, $this->vars()['ifirma_cm_errors']);
        $this->assertNull($this->vars()['ifirma_cm_report']);
        @unlink($tmp);
    }

    public function testForgetAllLinksNeedsTheConfirmationField()
    {
        IfirmaContractorMap::link(1, 'A-1', 'nip');
        Tools::$values = array('submitIfirmaResetContractorMap' => '1');
        $out = $this->module->getContent();
        $this->assertStringContainsString('<error>', $out);
        $this->assertNotContains('TRUNCATE TABLE `ps_ifirma_contractor_map`', Db::getInstance()->executed);

        Tools::$values = array('submitIfirmaResetContractorMap' => '1', 'confirm' => '1');
        $out = $this->module->getContent();
        $this->assertStringContainsString('<confirmation>', $out);
        $this->assertContains('TRUNCATE TABLE `ps_ifirma_contractor_map`', Db::getInstance()->executed);
    }

    public function testAdminPanelAssignsTheLinkedContractor()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'import-nip', 'Jan Kowalski');

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        $v = $this->vars();

        $this->assertSame(array(
            'identifier' => 'KOWALSKI-01',
            'matched_by' => 'import-nip',
            'matched_by_label' => 'import, NIP',
            'name' => 'Jan Kowalski',
        ), $v['ifirma_contractor_map']);
    }

    public function testAdminPanelHasNoLinkedContractorForAnUnmappedCustomer()
    {
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        $this->assertNull($this->vars()['ifirma_contractor_map']);
    }

    public function testAdminPanelTemplateShowsTheLinkedContractorRow()
    {
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/views/templates/hook/admin_order_panel.tpl');

        $this->assertSame(3, substr_count($tpl, "{l s='Linked contractor' mod='ifirma'}"));
        $this->assertStringContainsString('{if $ifirma_contractor_map}', $tpl);
    }

    private function contractorNoteFor(array $contractor)
    {
        $this->makeReceipt(array('request_payload' => json_encode(array('SposobZaplaty' => 'PRZ', '_contractor' => $contractor))));
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        return $this->vars()['ifirma_contractor_note'];
    }

    public function testAdminPanelShowsTheReadbackFailureNoteOnceAndTheEmptySearch()
    {
        $miss = array('identifier' => 'PS-C1909', 'answer' => 'Kod 208: Nie znaleziono kontrahenta');
        $note = $this->contractorNoteFor(array(
            'action' => 'fallback',
            'message' => 'iFirma created the contractor but it cannot be read back (PS-C1909: Kod 208: Nie znaleziono kontrahenta); search: none.',
            'note' => array('code' => 'readback_failed', 'params' => array('tried' => array($miss, $miss), 'search' => 'none', 'search_error' => '')),
        ));

        $this->assertStringContainsString('fallback (contractor created but not found: PS-C1909: Kod 208: Nie znaleziono kontrahenta; search: found nothing)', $note);
        $this->assertSame(1, substr_count($note, 'PS-C1909: Kod 208: Nie znaleziono kontrahenta'));
        $this->assertStringNotContainsString('iFirma created the contractor', $note);
    }

    public function testAdminPanelShowsTheAmbiguousAndFailedSearchWords()
    {
        $tried = array(array('identifier' => 'PS-C1', 'answer' => 'empty result'), array('identifier' => '99', 'answer' => 'boom'));
        $ambiguous = $this->contractorNoteFor(array(
            'action' => 'fallback', 'message' => 'x',
            'note' => array('code' => 'readback_failed', 'params' => array('tried' => $tried, 'search' => 'ambiguous', 'search_error' => '')),
        ));
        $this->assertSame('fallback (contractor created but not found: PS-C1: empty result; 99: boom; search: ambiguous)', $ambiguous);

        $this->setUp();
        $failed = $this->contractorNoteFor(array(
            'action' => 'fallback', 'message' => 'x',
            'note' => array('code' => 'readback_failed', 'params' => array('tried' => $tried, 'search' => 'error', 'search_error' => 'search timeout')),
        ));
        $this->assertStringEndsWith('search: failed: search timeout)', $failed);
    }

    public function testAdminPanelShowsTheAssignedIdentifierOfACreatedContractor()
    {
        $note = $this->contractorNoteFor(array(
            'action' => 'created', 'message' => '', 'identifier' => 'IFI1909556979', 'matched_by' => 'module', 'assigned_identifier' => 'IFI1909556979',
            'note' => array('code' => 'assigned', 'params' => array('identifier' => 'IFI1909556979')),
        ));

        $this->assertSame('created (iFirma assigned identifier IFI1909556979)', $note);
    }

    public function testAdminPanelShowsTheOtherFallbackNotes()
    {
        $cases = array(
            array('lookup_failed', array('identifier' => 'PS-C5', 'error' => 'timeout'), 'fallback (lookup of PS-C5 failed: timeout)'),
            array('update_failed', array('identifier' => 'PS-C5', 'error' => 'bad'), 'fallback (update of PS-C5 failed: bad)'),
            array('create_rejected', array('error' => 'Zly kod'), 'fallback (contractor create rejected: Zly kod)'),
            array('reference_rejected', array('identifier' => 'PS-C5', 'error' => 'nieprawidlowa'), 'fallback (reference PS-C5 rejected: nieprawidlowa)'),
        );
        foreach ($cases as $case) {
            IfirmaTestState::resetAll();
            $this->setUp();
            $this->assertSame($case[2], $this->contractorNoteFor(array(
                'action' => 'fallback', 'message' => 'raw', 'note' => array('code' => $case[0], 'params' => $case[1]),
            )));
        }
    }

    public function testAdminPanelKeepsTheRawMessageForALegacyFallbackRecord()
    {
        $this->assertSame('fallback (old sentence)', $this->contractorNoteFor(array('action' => 'fallback', 'message' => 'old sentence')));
    }

    public function testAdminPanelAppendsTheAttachedContractorAfterTheFallbackNote()
    {
        $note = $this->contractorNoteFor(array(
            'action' => 'fallback', 'message' => 'raw', 'identifier' => '', 'document_identifier' => 'AUTO-77',
            'note' => array('code' => 'create_rejected', 'params' => array('error' => 'Zly kod')),
        ));

        $this->assertSame('fallback (contractor create rejected: Zly kod) iFirma attached contractor AUTO-77', $note);
    }
}
