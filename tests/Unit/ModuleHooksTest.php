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

/** A value whose first string conversion throws and whose later ones give a user name. */
class ModuleHooksThrowsOnce
{
    /** @var bool */
    private $thrown = false;

    public function __toString()
    {
        if (!$this->thrown) {
            $this->thrown = true;

            throw new RuntimeException('configuration unreadable');
        }

        return 'u@example.test';
    }
}

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

    public function testGridHooksIgnoreACallWithoutTheirParameter()
    {
        $this->assertNull($this->module->hookActionOrderGridDefinitionModifier(array()));
        $this->assertNull($this->module->hookActionOrderGridDefinitionModifier(array('definition' => 'x')));
        $this->assertNull($this->module->hookActionOrderGridQueryBuilderModifier(array()));
        $params = array('presented_grid' => array());
        $this->module->hookActionOrderGridPresenterModifier($params);
        $this->assertSame(array('presented_grid' => array()), $params);
    }

    public function testDefinitionHookAddsTheIfirmaColumnBeforeTheActions()
    {
        $columns = new class {
            public $calls = array();

            public function addBefore($id, $column)
            {
                $this->calls[] = array($id, $column);

                return $this;
            }
        };
        $definition = new class($columns) {
            private $columns;

            public function __construct($columns)
            {
                $this->columns = $columns;
            }

            public function getColumns()
            {
                return $this->columns;
            }
        };

        $this->module->hookActionOrderGridDefinitionModifier(array('definition' => $definition));

        $this->assertCount(1, $columns->calls);
        $this->assertSame('actions', $columns->calls[0][0]);
        $column = $columns->calls[0][1];
        $this->assertInstanceOf('PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn', $column);
        $this->assertSame('ifirma_document', $column->id);
        $this->assertSame('iFirma', $column->name);
        $this->assertSame(array('field' => 'ifirma_document', 'sortable' => false, 'clickable' => true), $column->options);
    }

    public function testQueryBuilderHookAddsTheTwoSelectsToTheSearchQueryOnly()
    {
        $search = new class {
            public $selects = array();
            public $joins = array();

            public function addSelect($s)
            {
                $this->selects[] = $s;

                return $this;
            }

            public function leftJoin($fromAlias, $join, $alias, $condition = null)
            {
                $this->joins[] = array($fromAlias, $join, $alias, $condition);

                return $this;
            }
        };
        $count = clone $search;

        $this->module->hookActionOrderGridQueryBuilderModifier(array(
            'search_query_builder' => $search,
            'count_query_builder' => $count,
            'search_criteria' => null,
        ));

        $join = IfirmaOrderGridIndicator::joinSql('o');
        $this->assertSame(array(array('o', $join['table'], $join['alias'], $join['condition'])), $search->joins);
        $this->assertSame(IfirmaOrderGridIndicator::selectSql(), $search->selects);
        $this->assertSame(array(), $count->selects);
        $this->assertSame(array(), $count->joins, 'the count query keeps its shape and its total');
    }

    public function testPresenterHookFillsTheIconCellOfEveryRecord()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ));
        $grid = array('data' => array('records' => array(
            array('id_order' => 42, 'ifirma_document_number' => 'FP 7/2026', 'ifirma_document_status' => 'created'),
            array('id_order' => 43),
            array('id_order' => 44, 'ifirma_document_number' => 'FP 8/2026', 'ifirma_document_status' => 'paid'),
        )));
        $params = array('presented_grid' => &$grid);

        $this->module->hookActionOrderGridPresenterModifier($params);

        $records = $grid['data']['records'];
        $this->assertStringContainsString('receipt_long', $records[0]['ifirma_document']);
        $this->assertStringContainsString('title="iFirma: FP 7/2026 (Issued)"', $records[0]['ifirma_document']);
        $this->assertStringContainsString('text-info', $records[0]['ifirma_document']);
        $this->assertSame('', $records[1]['ifirma_document']);
        $this->assertStringContainsString('(Paid)', $records[2]['ifirma_document']);
        $this->assertStringContainsString('text-success', $records[2]['ifirma_document']);
    }

    public function testPresenterHookLeavesAGridWithoutRecordsAlone()
    {
        $grid = array('data' => array());
        $params = array('presented_grid' => &$grid);

        $this->module->hookActionOrderGridPresenterModifier($params);

        $this->assertSame(array('data' => array()), $grid);

        $grid = array('data' => array('records' => 'not rows'));
        $params = array('presented_grid' => &$grid);
        $this->module->hookActionOrderGridPresenterModifier($params);
        $this->assertSame(array('data' => array('records' => 'not rows')), $grid);
    }

    /**
     * PrestaShop 8 and 9 present the records as a RecordCollection object (all() gives the rows, the constructor
     * takes them back), not as an array; the Orders list returned a 500 ("Key ifirma_document does not exist")
     * until the hook took the rows out of the collection and handed a filled collection back.
     */
    public function testPresenterHookFillsARecordCollectionAndHandsOneBack()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ));
        $collection = new class(array(
            array('id_order' => 42, 'ifirma_document_number' => 'FP 7/2026', 'ifirma_document_status' => 'created'),
            array('id_order' => 43),
        )) {
            private $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function all()
            {
                return $this->rows;
            }
        };
        $grid = array('data' => array('records' => $collection));
        $params = array('presented_grid' => &$grid);

        $this->module->hookActionOrderGridPresenterModifier($params);

        $this->assertInstanceOf(get_class($collection), $grid['data']['records']);
        $this->assertNotSame($collection, $grid['data']['records'], 'a new collection carries the filled rows');
        $rows = $grid['data']['records']->all();
        $this->assertStringContainsString('title="iFirma: FP 7/2026 (Issued)"', $rows[0]['ifirma_document']);
        $this->assertSame('', $rows[1]['ifirma_document']);
        $this->assertSame(42, $rows[0]['id_order'], 'the other keys of a row are untouched');
    }

    public function testInstallRegistersTheGridHooks()
    {
        Module::reset();
        $this->assertTrue($this->module->install());

        $this->assertContains('actionOrderGridDefinitionModifier', Module::$hooks);
        $this->assertContains('actionOrderGridQueryBuilderModifier', Module::$hooks);
        $this->assertContains('actionOrderGridPresenterModifier', Module::$hooks);
    }

    public function testInstallAddsTheTabUnderTheOrdersParentWithTheName()
    {
        Module::reset();
        Tab::$rows[4] = array(
            'class_name' => 'AdminParentOrders', 'module' => '', 'id_parent' => 0,
            'name' => array(1 => 'Orders', 2 => 'Zamówienia'), 'position' => 0, 'active' => 1,
        );

        $this->assertTrue($this->module->install());

        $this->assertCount(1, Tab::$added);
        $tab = Tab::$added[0];
        $this->assertSame('AdminIfirma', $tab->class_name);
        $this->assertSame(4, $tab->id_parent);
        $this->assertSame(array(1 => 'iFirma documents', 2 => 'Dokumenty iFirma'), $tab->name);
    }

    public function testTheTabNameFollowsTheIsoCodeOfEachLanguage()
    {
        Module::reset();
        Language::$languages = array(
            array('id_lang' => 1, 'iso_code' => 'en', 'name' => 'English'),
            array('id_lang' => 2, 'iso_code' => 'pl', 'name' => 'Polski'),
            array('id_lang' => 3, 'iso_code' => 'de', 'name' => 'Deutsch'),
            array('id_lang' => 4, 'name' => 'a row without an iso code'),
        );

        $this->assertTrue($this->module->install());

        $this->assertSame(
            array(1 => 'iFirma documents', 2 => 'Dokumenty iFirma', 3 => 'iFirma documents', 4 => 'iFirma documents'),
            Tab::$added[0]->name
        );
    }

    public function testInstallFallsBackToTheParentOfTheOrdersTab()
    {
        Module::reset();
        Tab::$rows[3] = array(
            'class_name' => 'AdminOrders', 'module' => '', 'id_parent' => 4,
            'name' => array(1 => 'Orders', 2 => 'Zamówienia'), 'position' => 0, 'active' => 1,
        );

        $this->assertTrue($this->module->install());

        $this->assertSame(4, Tab::$added[0]->id_parent);
        $this->assertSame(array(1 => 'iFirma documents', 2 => 'Dokumenty iFirma'), Tab::$added[0]->name);
    }

    public function testInstallKeepsTheTabHiddenAndLogsWithoutAnOrdersMenu()
    {
        Module::reset();

        $this->assertTrue($this->module->install());

        $this->assertSame(-1, Tab::$added[0]->id_parent);
        $this->assertContains(
            'iFirma: Orders menu not found, tab installed hidden',
            array_column(PrestaShopLogger::$logs, 'message')
        );
    }

    public function testHookHeaderAddsTheStylesheetThroughAControllerWithoutRegisterStylesheet()
    {
        $controller = new class {
            /** @var array[] */
            public $css = array();

            public function addCSS($path, $media)
            {
                $this->css[] = array($path, $media);
            }
        };
        $previous = Context::getContext()->controller;
        Context::getContext()->controller = $controller;

        $this->module->hookHeader(array());

        Context::getContext()->controller = $previous;
        $this->assertSame(array(array('/modules/ifirma/views/css/ifirma.css', 'all')), $controller->css);
    }

    public function testHookHeaderRegistersTheStylesheetOnAControllerWithRegisterStylesheet()
    {
        $controller = new IfirmaTestController();
        $previous = Context::getContext()->controller;
        Context::getContext()->controller = $controller;

        $this->module->hookHeader(array());

        Context::getContext()->controller = $previous;
        $this->assertSame(array(array(
            'id' => 'module-ifirma',
            'path' => 'modules/ifirma/views/css/ifirma.css',
            'params' => array('media' => 'all', 'priority' => 150),
        )), $controller->stylesheets);
    }

    public function testInstallRegistersTheMediaHookOnce()
    {
        Module::reset();

        $this->assertTrue($this->module->install());

        $this->assertTrue($this->module->isRegisteredInHook('actionAdminControllerSetMedia'));
        $this->assertSame(1, count(array_keys(Module::$hooks, 'actionAdminControllerSetMedia', true)));
    }

    public function testUninstallDeletesTheSeenMarks()
    {
        $this->assertTrue($this->module->uninstall());

        $this->assertSame(array(
            array('table' => 'configuration', 'where' => "name LIKE 'IFIRMA\\_SEEN\\_%'"),
        ), Db::getInstance()->deletes);
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

    public function testTheSettingsPageWarnsAboutAStoredBaseUrlThatIsRefusedAtRunTime()
    {
        IfirmaSettings::$devMode = false;
        try {
            Configuration::updateValue('IFIRMA_BASE_URL', 'http://sandbox.example/iapi/?x=<1>');
            Tools::$values = array();
            $out = $this->module->getContent();

            $this->assertStringContainsString(
                '<warning>The stored API base URL "http://sandbox.example/iapi/?x=&lt;1&gt;" is not accepted, so the module talks to the iFirma production address. Save a valid URL below.</warning>',
                $out
            );

            foreach (array('http://ifirma-mock:8001/iapi/', 'https://www.ifirma.pl/iapi/', '') as $fine) {
                Configuration::updateValue('IFIRMA_BASE_URL', $fine);
                $this->assertStringNotContainsString('is not accepted', $this->module->getContent(), 'stored: ' . $fine);
            }
            Configuration::deleteByName('IFIRMA_BASE_URL');
            $this->assertStringNotContainsString('is not accepted', $this->module->getContent());
        } finally {
            IfirmaSettings::$devMode = null;
        }
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

    public function testTheFormShowsTheStoredDocumentAndSignatureTypeNormalised()
    {
        Configuration::updateValue('IFIRMA_DOC_TYPE', 'fakturaparagon');
        Configuration::updateValue('IFIRMA_SIGNATURE_TYPE', ' oup ');

        $this->module->getContent();

        $this->assertSame('fakturakraj', HelperForm::$lastHelper->fields_value['IFIRMA_DOC_TYPE']);
        $this->assertSame('OUP', HelperForm::$lastHelper->fields_value['IFIRMA_SIGNATURE_TYPE']);
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

    public function testEmptyPaymentMethodMapClearsTheStoredMap()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MAP', 'ps_wirepayment=PRZ');
        Tools::$values = $this->validPost(array('IFIRMA_PAYMENT_METHOD_MAP' => ''));

        $this->assertStringContainsString('<confirmation>', $this->module->getContent());
        $this->assertSame('', Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'));
    }

    public function testBlankAndCommentOnlyPaymentMethodMapIsStoredEmpty()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MAP', 'ps_wirepayment=PRZ');
        Tools::$values = $this->validPost(array('IFIRMA_PAYMENT_METHOD_MAP' => "  \r\n# only a comment\r\n\t\n"));

        $this->assertStringContainsString('<confirmation>', $this->module->getContent());
        $this->assertSame('', Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'));
    }

    public function testPaymentMethodMapIsStoredOneEntryPerLineWhateverTheInputSeparators()
    {
        Tools::$values = $this->validPost(array(
            'IFIRMA_PAYMENT_METHOD_MAP' => "ps_wirepayment=PRZ\rps_checkpayment=czk\r\n\nmygateway*=ELE\n",
        ));

        $this->assertStringContainsString('<confirmation>', $this->module->getContent());
        $this->assertSame(
            "ps_wirepayment=PRZ\nps_checkpayment=CZK\nmygateway*=ELE",
            Configuration::get('IFIRMA_PAYMENT_METHOD_MAP')
        );
    }

    public function testDuplicatePaymentMethodMapEntryKeepsItsFirstPositionAndLastCode()
    {
        Tools::$values = $this->validPost(array(
            'IFIRMA_PAYMENT_METHOD_MAP' => "ps_checkpayment=PRZ\nmygateway*=ELE\nps_checkpayment=GTK",
        ));

        $this->assertStringContainsString('<confirmation>', $this->module->getContent());
        $this->assertSame("ps_checkpayment=GTK\nmygateway*=ELE", Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'));
    }

    public function testMissingLoginIsRejectedAndNothingSaved()
    {
        Tools::$values = $this->validPost(array('IFIRMA_USER' => '   '));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<error>', $out);
        $this->assertStringContainsString('The iFirma login is required.', $out);
        $this->assertSame('u@example.com', Configuration::get('IFIRMA_USER'));
        $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
    }

    public function testNonHexadecimalInvoiceKeyIsRejectedAndNothingSaved()
    {
        Tools::$values = $this->validPost(array('IFIRMA_KEY_FAKTURA' => 'not-a-hex-key'));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<error>', $out);
        $this->assertStringContainsString('The invoice API key must be a hexadecimal string.', $out);
        $this->assertSame(str_repeat('1', 64), Configuration::get('IFIRMA_KEY_FAKTURA'));
        $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
    }

    public function testUnknownPaymentMethodModeIsRejectedAndNothingSaved()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD_MODE', 'fixed');
        foreach (array('sometimes', '') as $bad) {
            Tools::$values = $this->validPost(array('IFIRMA_PAYMENT_METHOD_MODE' => $bad));
            $out = $this->module->getContent();

            $this->assertStringContainsString('<error>', $out, 'mode ' . $bad);
            $this->assertStringContainsString('The payment method mode must be "order" or "fixed".', $out);
            $this->assertSame('fixed', Configuration::get('IFIRMA_PAYMENT_METHOD_MODE'));
            $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
        }
    }

    public function testUnknownFallbackPaymentMethodIsRejectedAndNothingSaved()
    {
        Configuration::updateValue('IFIRMA_PAYMENT_METHOD', 'GTK');
        Tools::$values = $this->validPost(array('IFIRMA_PAYMENT_METHOD' => 'XYZ'));
        $out = $this->module->getContent();

        $this->assertStringContainsString('<error>', $out);
        $this->assertStringContainsString('The fallback payment method must be one of the listed codes.', $out);
        $this->assertSame('GTK', Configuration::get('IFIRMA_PAYMENT_METHOD'));
        $this->assertSame('shop_mail', Configuration::get('IFIRMA_SEND_CHANNEL'));
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

    // ---- permission levels (spec: receipt-access-control) ----------------------------------------

    /** Puts an employee of profile 4 holding exactly these roles into the context. */
    private function employeeHolding(array $roles)
    {
        Access::reset();
        Context::getContext()->employee->id_profile = 4;
        foreach ($roles as $role) {
            Access::grant($role, 4);
        }
    }

    private function resetOrderPanelGuard()
    {
        $guard = new ReflectionProperty('Ifirma', 'adminOrderRendered');
        $guard->setAccessible(true);
        $guard->setValue(null, false);
    }

    private function columnsSpy()
    {
        return new class {
            public $calls = array();

            public function addBefore($id, $column)
            {
                $this->calls[] = array($id, $column);

                return $this;
            }
        };
    }

    private function definitionOf($columns)
    {
        return new class($columns) {
            private $columns;

            public function __construct($columns)
            {
                $this->columns = $columns;
            }

            public function getColumns()
            {
                return $this->columns;
            }
        };
    }

    public function testNoOrderPanelWithoutTheViewLevel()
    {
        $this->makeReceipt(array('status' => 'paid'));
        $this->employeeHolding(array(IfirmaPermission::ROLE_CREATE, IfirmaPermission::ROLE_UPDATE));

        $out = $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        $this->assertSame('', $out);
        $this->assertSame(array(), $this->vars());
        $this->assertSame(array(), Module::$displayed);
    }

    public function testNoOrderPanelWithoutAnEmployee()
    {
        Context::getContext()->employee = null;

        $this->assertSame('', $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15)));
    }

    public function testOrderPanelFlagsFollowTheLevelsOfTheEmployee()
    {
        $this->makeReceipt(array('status' => 'paid'));
        $cases = array(
            'view' => array(array(IfirmaPermission::ROLE_READ), false, false),
            'view and issue' => array(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE), true, false),
            'view and manage' => array(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_UPDATE), false, true),
            'all three' => array(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE, IfirmaPermission::ROLE_UPDATE), true, true),
        );
        foreach ($cases as $name => $case) {
            Module::$displayed = array();
            $this->resetOrderPanelGuard();
            $this->employeeHolding($case[0]);

            $out = $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

            $this->assertNotSame('', $out, $name);
            $v = $this->vars();
            $this->assertSame($case[1], $v['ifirma_can_issue'], $name . ': issue');
            $this->assertSame($case[2], $v['ifirma_can_manage'], $name . ': manage');
        }
    }

    public function testOrderPanelOfASuperAdminHasBothFlags()
    {
        $this->makeReceipt(array('status' => 'paid'));
        Access::reset();

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        $this->assertTrue($this->vars()['ifirma_can_issue']);
        $this->assertTrue($this->vars()['ifirma_can_manage']);
    }

    public function testGridHooksDoNothingWithoutTheViewLevel()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_CREATE, IfirmaPermission::ROLE_UPDATE));

        $columns = $this->columnsSpy();
        $this->module->hookActionOrderGridDefinitionModifier(array('definition' => $this->definitionOf($columns)));
        $this->assertSame(array(), $columns->calls, 'no column without the view level');

        $search = new class {
            public $selects = array();
            public $joins = array();

            public function addSelect($s)
            {
                $this->selects[] = $s;

                return $this;
            }

            public function leftJoin($fromAlias, $join, $alias, $condition = null)
            {
                $this->joins[] = array($fromAlias, $join, $alias, $condition);

                return $this;
            }
        };
        $this->module->hookActionOrderGridQueryBuilderModifier(array('search_query_builder' => $search));
        $this->assertSame(array(), $search->joins);
        $this->assertSame(array(), $search->selects);

        $records = array(
            array('id_order' => 42, 'ifirma_document_number' => 'FP 7/2026', 'ifirma_document_status' => 'created'),
        );
        $grid = array('data' => array('records' => $records));
        $params = array('presented_grid' => &$grid);
        $this->module->hookActionOrderGridPresenterModifier($params);
        $this->assertSame($records, $grid['data']['records'], 'the records stay untouched');
    }

    public function testGridHooksWorkWithTheViewLevelAlone()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ));

        $columns = $this->columnsSpy();
        $this->module->hookActionOrderGridDefinitionModifier(array('definition' => $this->definitionOf($columns)));
        $this->assertCount(1, $columns->calls);

        $grid = array('data' => array('records' => array(
            array('id_order' => 42, 'ifirma_document_number' => 'FP 7/2026', 'ifirma_document_status' => 'created'),
        )));
        $params = array('presented_grid' => &$grid);
        $this->module->hookActionOrderGridPresenterModifier($params);
        $this->assertStringContainsString('FP 7/2026', $grid['data']['records'][0]['ifirma_document']);
    }

    // ---- the bulk action and the issue anchor (admin-receipt-ops-and-hardening 5.2) --------------

    /**
     * @return object a grid definition with a bulk action collection that records what is added
     */
    private function definitionWithBulk($columns, &$bulk)
    {
        $bulk = new class {
            public $added = array();

            public function add($action)
            {
                $this->added[] = $action;

                return $this;
            }
        };

        return new class($columns, $bulk) {
            private $columns;
            private $bulk;

            public function __construct($columns, $bulk)
            {
                $this->columns = $columns;
                $this->bulk = $bulk;
            }

            public function getColumns()
            {
                return $this->columns;
            }

            public function getBulkActions()
            {
                return $this->bulk;
            }
        };
    }

    public function testBulkActionIsAddedWithTheIssueLevel()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));

        $this->module->hookActionOrderGridDefinitionModifier(array('definition' => $this->definitionWithBulk($this->columnsSpy(), $bulk)));

        $this->assertCount(1, $bulk->added);
        $action = $bulk->added[0];
        $this->assertInstanceOf('PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\ButtonBulkAction', $action);
        $this->assertSame('ifirma_issue', $action->id);
        $this->assertSame('Issue iFirma documents', $action->name);
        $this->assertSame(
            array('class' => 'js-ifirma-bulk-issue', 'attributes' => array('data-kind' => 'issue')),
            $action->options
        );
    }

    public function testBulkActionHiddenWithoutTheIssueLevel()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_UPDATE));
        $columns = $this->columnsSpy();

        $this->module->hookActionOrderGridDefinitionModifier(array('definition' => $this->definitionWithBulk($columns, $bulk)));

        $this->assertCount(1, $columns->calls, 'the column is still there for the view level');
        $this->assertSame(array(), $bulk->added);
    }

    public function testADefinitionWithoutBulkActionsStillGetsItsColumn()
    {
        $columns = $this->columnsSpy();

        $this->module->hookActionOrderGridDefinitionModifier(array('definition' => $this->definitionOf($columns)));

        $this->assertCount(1, $columns->calls);
    }

    public function testPresenterPassesTheIssueLevelToTheCell()
    {
        $records = array(
            array('id_order' => 42, 'ifirma_document_number' => 'FP 7/2026', 'ifirma_document_status' => 'created', 'ifirma_document_state' => 'created'),
            array('id_order' => 43, 'ifirma_document_number' => '', 'ifirma_document_status' => '', 'ifirma_document_state' => ''),
            array('id_order' => 44, 'ifirma_document_number' => '', 'ifirma_document_status' => '', 'ifirma_document_state' => 'unknown'),
        );

        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
        $grid = array('data' => array('records' => $records));
        $params = array('presented_grid' => &$grid);
        $this->module->hookActionOrderGridPresenterModifier($params);
        $rows = $grid['data']['records'];
        $this->assertStringContainsString('receipt_long', $rows[0]['ifirma_document']);
        $this->assertStringContainsString('class="js-ifirma-issue" data-id-order="43" title="Issue iFirma document"', $rows[1]['ifirma_document']);
        $this->assertStringContainsString('title="Issue iFirma document (unknown outcome)"', $rows[2]['ifirma_document']);

        $this->employeeHolding(array(IfirmaPermission::ROLE_READ));
        $grid = array('data' => array('records' => $records));
        $params = array('presented_grid' => &$grid);
        $this->module->hookActionOrderGridPresenterModifier($params);
        $rows = $grid['data']['records'];
        $this->assertStringContainsString('receipt_long', $rows[0]['ifirma_document']);
        $this->assertSame('', $rows[1]['ifirma_document']);
        $this->assertSame('', $rows[2]['ifirma_document']);
    }

    public function testGridCellLabelsCarryEveryWordOfTheCell()
    {
        $labels = $this->module->gridCellLabels();

        $this->assertSame(
            array('paid', 'issued', 'issue', 'error', 'unknown', 'duplicate_suspected', 'held'),
            array_keys($labels)
        );
        $this->assertSame('Issue iFirma document', $labels['issue']);
        $this->assertSame('suspected duplicate', $labels['duplicate_suspected']);
    }

    public function testTheScriptWordsAndTheKindLabelsAreDecodedBecauseTheScriptsRenderThemAsText()
    {
        $module = new class extends Ifirma {
            public function l($string, $specific = null, $locale = null)
            {
                return $string . ' &quot;q&quot;';
            }
        };
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
        $this->adminControllerNamed('AdminOrders');

        $module->hookActionAdminControllerSetMedia(array());

        $words = Media::$jsDefs['ifirmaAdmin']['words'];
        $this->assertSame('Running… %1$d of %2$d "q"', $words['running']);
        $this->assertSame('Held by a data request "q"', $words['kinds']['held']);
        $this->assertSame($module->issueKindLabels(), $words['kinds']);

        $decode = new ReflectionMethod('Ifirma', 'decodeWords');
        $decode->setAccessible(true);
        $this->assertSame(
            array('a' => 'say "hi"', 'b' => array('c' => "it's"), 'd' => 5),
            $decode->invoke(null, array('a' => 'say &quot;hi&quot;', 'b' => array('c' => 'it&#039;s'), 'd' => 5))
        );
    }

    public function testIssueKindLabelsCoverEveryKindOfTheFeed()
    {
        $labels = $this->module->issueKindLabels();

        $this->assertSame(IfirmaIssueFeed::kinds(), array_keys($labels));
        $this->assertSame('Delivery failed', $labels['delivery_failed']);
        $this->assertSame('Held by a data request', $labels['held']);
    }

    // ---- scripts and variables of the back office (6.3) ----------------------------------------

    /**
     * @param string|null $name controller_name of the current controller, null for none
     * @return object a back-office controller that records its addJS()/addCSS() calls
     */
    private function adminControllerNamed($name)
    {
        $controller = new class {
            public $controller_name;
            public $js = array();
            public $css = array();

            public function addJS($path)
            {
                $this->js[] = $path;
            }

            public function addCSS($path, $media = 'all')
            {
                $this->css[] = array($path, $media);
            }
        };
        if ($name !== null) {
            $controller->controller_name = $name;
        }
        Context::getContext()->controller = $controller;

        return $controller;
    }

    public function testEmployeeWithoutViewGetsNoFileAndNoVariables()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_CREATE, IfirmaPermission::ROLE_UPDATE));
        $controller = $this->adminControllerNamed('AdminOrders');

        $this->module->hookActionAdminControllerSetMedia(array());

        $this->assertSame(array(), $controller->js);
        $this->assertSame(array(), $controller->css);
        $this->assertSame(array(), Media::$jsDefs);
    }

    public function testViewEmployeeOnAnotherControllerGetsTheNotificationScriptAndTheStylesheetOnly()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE, IfirmaPermission::ROLE_UPDATE));
        $controller = $this->adminControllerNamed('AdminCustomers');

        $this->module->hookActionAdminControllerSetMedia(array());

        $this->assertSame(array('views/js/admin/ifirma-notifications.js'), $this->relative($controller->js));
        $this->assertSame(array('views/css/ifirma-admin.css'), $this->relative(array_column($controller->css, 0)));
        $this->assertSame(array('all'), array_column($controller->css, 1));
        $this->assertSame('AdminCustomers', Media::$jsDefs['ifirmaAdmin']['controller']);
    }

    public function testTheBulkScriptComesOnTheOrdersAndTheDocumentsListsForTheIssueOrManageLevel()
    {
        $cases = array(
            'orders, issue' => array('AdminOrders', array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE), true),
            'orders, manage' => array('AdminOrders', array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_UPDATE), true),
            'documents, issue' => array('AdminIfirma', array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE), true),
            'orders, view only' => array('AdminOrders', array(IfirmaPermission::ROLE_READ), false),
            'products, issue' => array('AdminProducts', array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE), false),
        );
        foreach ($cases as $name => $case) {
            Media::reset();
            $this->employeeHolding($case[1]);
            $controller = $this->adminControllerNamed($case[0]);

            $this->module->hookActionAdminControllerSetMedia(array());

            $js = $this->relative($controller->js);
            $this->assertContains('views/js/admin/ifirma-notifications.js', $js, $name);
            $this->assertSame($case[2], in_array('views/js/admin/ifirma-bulk.js', $js, true), $name);
        }
    }

    public function testTheControllerNameFallsBackToTheRequest()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
        $controller = $this->adminControllerNamed(null);
        Tools::$values = array('controller' => 'AdminOrders');

        $this->module->hookActionAdminControllerSetMedia(array());

        $this->assertContains('views/js/admin/ifirma-bulk.js', $this->relative($controller->js));
        $this->assertSame('AdminOrders', Media::$jsDefs['ifirmaAdmin']['controller']);
    }

    public function testAControllerThatCannotAddScriptsStillGetsTheVariables()
    {
        Context::getContext()->controller = new stdClass();

        $this->module->hookActionAdminControllerSetMedia(array());

        $this->assertArrayHasKey('ifirmaAdmin', Media::$jsDefs);
    }

    public function testTheVariablesCarryEveryKeyOfTheContract()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
        $this->adminControllerNamed('AdminOrders');

        $this->module->hookActionAdminControllerSetMedia(array());

        $this->assertSame(array('ifirmaAdmin'), array_keys(Media::$jsDefs));
        $admin = Media::$jsDefs['ifirmaAdmin'];
        $this->assertSame(
            array('ajaxUrl', 'controller', 'canView', 'canIssue', 'canManage', 'batchSizes', 'pollInterval', 'listUrl', 'words'),
            array_keys($admin)
        );
        $this->assertSame('https://shop.test/admin/index.php?controller=AdminIfirma&token=admin-token-AdminIfirma', $admin['ajaxUrl']);
        $this->assertSame('AdminOrders', $admin['controller']);
        $this->assertSame(array(true, true, false), array($admin['canView'], $admin['canIssue'], $admin['canManage']));
        $this->assertSame(array('issue' => 3, 'retry' => 3, 'resend' => 5, 'refresh' => 25), $admin['batchSizes']);
        $this->assertSame(120000, $admin['pollInterval']);
        $this->assertStringContainsString('controller=AdminIfirma', $admin['listUrl']);

        $words = $admin['words'];
        $this->assertSame(
            array(
                'tab_title', 'no_issues', 'see_all', 'issue_action', 'issuing', 'confirm_issue', 'confirm_bulk_issue',
                'confirm_bulk_retry', 'confirm_bulk_resend', 'confirm_bulk_refresh', 'nothing_selected', 'running',
                'stopped_rate_limited', 'stopped_auth_blocked', 'request_failed', 'summary_title', 'col_order',
                'col_outcome', 'col_message', 'close', 'outcomes', 'kinds',
            ),
            array_keys($words)
        );
        $this->assertSame('iFirma', $words['tab_title']);
        $this->assertSame('Running… %1$d of %2$d', $words['running']);
        $this->assertSame('Issue iFirma documents for the %d selected orders?', $words['confirm_bulk_issue']);
        $this->assertSame(
            array('issued', 'adopted', 'suspected', 'held', 'failed', 'sent', 'refreshed', 'skipped', 'not_attempted', 'unknown'),
            array_keys($words['outcomes'])
        );
        $this->assertSame('Unknown, reload the list', $words['outcomes']['unknown']
        );
        $this->assertSame('Suspected duplicate', $words['outcomes']['suspected']);
        $this->assertSame(
            array('held', 'error', 'unknown', 'suspected', 'missing', 'ksef_failed', 'delivery_failed'),
            array_keys($words['kinds'])
        );
        $this->assertSame('KSeF failed', $words['kinds']['ksef_failed']);
        foreach ($words as $key => $word) {
            $this->assertNotSame('', $word, $key);
        }
    }

    /**
     * @param string[] $paths module-relative or absolute paths the hook enqueued
     * @return string[] the same paths without the module URL path in front
     */
    private function relative(array $paths)
    {
        return array_map(function ($path) {
            $at = strpos($path, 'views/');

            return $at === false ? $path : substr($path, $at);
        }, $paths);
    }

    public function testInstallVisibleTabAddsTheTabForTheUpgrade()
    {
        Module::reset();
        Tab::$rows[4] = array(
            'class_name' => 'AdminParentOrders', 'module' => '', 'id_parent' => 0,
            'name' => array(1 => 'Orders', 2 => 'Zamówienia'), 'position' => 0, 'active' => 1,
        );

        $this->assertTrue($this->module->installVisibleTab());

        $this->assertCount(1, Tab::$added);
        $this->assertSame('AdminIfirma', Tab::$added[0]->class_name);
        $this->assertSame(4, Tab::$added[0]->id_parent);
    }

    public function testTheConfigurationPageStillRendersWhenTheClientCannotBeBuilt()
    {
        Configuration::$values['IFIRMA_USER'] = new ModuleHooksThrowsOnce();

        $html = $this->module->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('calls are paused', $html);
    }

    public function testTheConfigurationPageStillRendersWhenNoCronTokenCanBeGenerated()
    {
        Configuration::deleteByName('IFIRMA_CRON_TOKEN');
        IfirmaSchema::$randomBytes = function () {
            return false;
        };

        try {
            $html = $this->module->getContent();
        } finally {
            IfirmaSchema::$randomBytes = null;
        }

        $this->assertIsString($html);
        $this->assertArrayNotHasKey('IFIRMA_CRON_TOKEN', Configuration::$values, 'nothing is stored when the generator fails');
    }
}
