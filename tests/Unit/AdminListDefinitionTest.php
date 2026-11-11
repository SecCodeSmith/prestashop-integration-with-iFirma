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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/admin/AdminIfirmaController.php';

/** Link that remembers the controller and the query parameters of every admin link it builds. */
class IfirmaTestParamRecordingLink extends Link
{
    public static $calls = array();

    public function getAdminLink($controller, $withToken = true, $sfRouteParams = [], $params = [])
    {
        self::$calls[] = array($controller, $params);

        return parent::getAdminLink($controller, $withToken, $sfRouteParams, $params);
    }
}

/**
 * Spec: receipt-documents-list (design D3): the legacy list definition of AdminIfirma.
 */
class AdminListDefinitionTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id_profile = 4;
        Access::reset();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function controller(array $request = array())
    {
        Tools::$values = $request;

        return new AdminIfirmaController();
    }

    private function holding(array $roles)
    {
        Access::reset();
        foreach ($roles as $role) {
            Access::grant($role, 4);
        }
    }

    private function row(array $props = array())
    {
        return array_merge(array(
            'id_ifirma_receipt' => 12,
            'id_order' => 42,
            'ifirma_id' => '77',
            'ifirma_number' => 'FP 7/2026',
            'status' => 'created',
        ), $props);
    }

    // ---- the definition ---------------------------------------------------------------------

    public function testTheListReadsTheReceiptTable()
    {
        $c = $this->controller();

        $this->assertSame('ifirma_receipt', $c->table);
        $this->assertSame('IfirmaReceipt', $c->className);
        $this->assertSame('id_ifirma_receipt', $c->identifier);
        $this->assertSame('ifirma_receipt', $c->list_id);
        $this->assertFalse($c->lang);
        $this->assertFalse($c->allow_export);
        $this->assertTrue($c->list_no_link);
        $this->assertSame('DESC', $c->_defaultOrderWay);
        $this->assertStringContainsString('ps_orders', $c->_join);
        $this->assertStringContainsString('ps_customer', $c->_join);
    }

    public function testTheColumnsAndTheirFilterKeys()
    {
        $c = $this->controller();

        $this->assertSame(array(
            'id_ifirma_receipt', 'order_reference', 'customer_name', 'ifirma_number', 'status', 'source',
            'delivery_status', 'ksef_status', 'issue_date', 'last_error', 'date_upd', 'actions',
        ), array_keys($c->fields_list));
        $keys = array(
            'id_ifirma_receipt' => 'a!id_ifirma_receipt',
            'order_reference' => 'o!reference',
            'status' => 'a!status',
            'source' => 'a!source',
            'delivery_status' => 'a!delivery_status',
            'ksef_status' => 'a!ksef_status',
        );
        foreach ($keys as $column => $filter) {
            $this->assertSame($filter, $c->fields_list[$column]['filter_key'], $column);
        }
        $this->assertTrue($c->fields_list['customer_name']['havingFilter']);
        $this->assertSame('select', $c->fields_list['status']['type']);
        $this->assertArrayHasKey('duplicate_suspected', $c->fields_list['status']['list']);
        $this->assertSame(array('WYSLANA', 'PRZYJETA', 'BLAD'), array_keys($c->fields_list['ksef_status']['list']));
        $this->assertSame('date', $c->fields_list['issue_date']['type']);
        $this->assertSame('datetime', $c->fields_list['date_upd']['type']);
        $this->assertFalse($c->fields_list['last_error']['orderby']);
        $this->assertFalse($c->fields_list['last_error']['search']);
        $this->assertFalse($c->fields_list['actions']['search']);
    }

    public function testTheStateFilterOffersQueuedAndAFilterOnErrorMatchesOnlyTheErrorState()
    {
        $column = $this->controller()->fields_list['status'];

        $this->assertArrayHasKey('queued', $column['list']);
        $this->assertSame('a!status', $column['filter_key']);
        $this->assertSame('string', $column['filter_type']);
        // the core compares the chosen word with the column, exactly or as a substring: either way only `error` matches
        $matching = array_filter(array_keys($column['list']), function ($state) {
            return strpos($state, 'error') !== false;
        });
        $this->assertSame(array('error'), array_values($matching));
    }

    public function testTheDeliveryFilterOffersAwaitingKsefAndAFilterOnItMatchesOnlyThatState()
    {
        $column = $this->controller()->fields_list['delivery_status'];

        $this->assertArrayHasKey('awaiting_ksef', $column['list']);
        $this->assertSame('a!delivery_status', $column['filter_key']);
        $this->assertSame('string', $column['filter_type']);
        $matching = array_filter(array_keys($column['list']), function ($state) {
            return strpos($state, 'awaiting_ksef') !== false;
        });
        $this->assertSame(array('awaiting_ksef'), array_values($matching));
    }

    public function testTheBulkEntriesAreTheThreeBatchActions()
    {
        $c = $this->controller();

        $this->assertSame(array('retryIssuance', 'resend', 'refresh'), array_keys($c->bulk_actions));
        foreach ($c->bulk_actions as $entry) {
            $this->assertNotSame('', $entry['text']);
        }
    }

    public function testTheMarkAsSentBulkEntryIsOfferedToTheManageLevelOnly()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_UPDATE));
        $manager = $this->controller();
        $manager->init();

        $this->holding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
        $issuer = $this->controller();
        $issuer->init();

        $this->assertArrayHasKey('markSent', $manager->bulk_actions);
        $this->assertSame('Mark as sent', $manager->bulk_actions['markSent']['text']);
        $this->assertArrayNotHasKey('markSent', $issuer->bulk_actions);
        $this->assertSame(array('retryIssuance', 'resend', 'refresh'), array_keys($issuer->bulk_actions));
    }

    public function testTheNumberFallsBackToTheIdentifierAndTheErrorIsShortened()
    {
        $c = $this->controller();

        $this->assertSame('FP 7/2026', $c->renderNumber('FP 7/2026', array('ifirma_id' => '77')));
        $this->assertSame('77', $c->renderNumber('', array('ifirma_id' => '77')));
        $this->assertSame('', $c->renderNumber(array('x'), array()));

        $long = str_repeat('a', 200);
        $short = $c->renderLastError($long);
        $this->assertSame(80, strlen($short));
        $this->assertSame('...', substr($short, -3));
        $this->assertSame('short &amp; sweet', $c->renderLastError('short & sweet'));
    }

    // ---- the preset -------------------------------------------------------------------------

    public function testThePresetAddsThePredicateToTheWhere()
    {
        $c = $this->controller(array('ifirma_preset' => 'attention'));

        $this->assertStringContainsString(IfirmaIssueFeed::issuePredicate('a'), $c->_where);
        $this->assertStringStartsWith(' AND (', $c->_where);
    }

    public function testWithoutThePresetTheWhereStaysEmpty()
    {
        $this->assertSame('', $this->controller()->_where);
        $this->assertSame('', $this->controller(array('ifirma_preset' => 'other'))->_where);
        $this->assertSame('', $this->controller(array('ifirma_preset' => array('attention')))->_where);
    }

    public function testTheToolbarOffersThePresetOrTheWayBack()
    {
        $c = $this->controller();
        $c->initPageHeaderToolbar();
        $this->assertArrayHasKey('ifirma_attention', $c->page_header_toolbar_btn);
        $this->assertStringContainsString('controller=AdminIfirma', $c->page_header_toolbar_btn['ifirma_attention']['href']);
        $this->assertArrayNotHasKey('ifirma_all', $c->page_header_toolbar_btn);

        $c = $this->controller(array('ifirma_preset' => 'attention'));
        $c->initPageHeaderToolbar();
        $this->assertArrayHasKey('ifirma_all', $c->page_header_toolbar_btn);
        $this->assertSame('All documents', $c->page_header_toolbar_btn['ifirma_all']['desc']);
        $this->assertArrayNotHasKey('ifirma_attention', $c->page_header_toolbar_btn);
    }

    public function testTheAddNewButtonIsRemoved()
    {
        $c = $this->controller();
        $c->initToolbar();

        $this->assertArrayNotHasKey('new', $c->toolbar_btn);
    }

    public function testTheActivePresetIsCarriedByTheListBaseUrl()
    {
        AdminController::$currentIndex = 'index.php?controller=AdminIfirma';
        $c = $this->controller();
        $c->init();
        $this->assertSame('index.php?controller=AdminIfirma', AdminController::$currentIndex);

        $c = $this->controller(array('ifirma_preset' => 'attention'));
        $c->init();
        $this->assertSame('index.php?controller=AdminIfirma&ifirma_preset=attention', AdminController::$currentIndex);

        // the way back to all documents does not carry it
        $c->initPageHeaderToolbar();
        $this->assertStringNotContainsString('ifirma_preset', $c->page_header_toolbar_btn['ifirma_all']['href']);
        AdminController::$currentIndex = 'index.php?controller=AdminModules';
    }

    // ---- the generic list actions -----------------------------------------------------------------

    public static function legacyActions()
    {
        return array(
            'none' => array('', null, true),
            'list' => array('list', null, true),
            'filter' => array('filter', null, true),
            'reset filters' => array('reset_filters', null, true),
            'bulk retry' => array('bulkretryIssuance', null, true),
            'bulk resend' => array('bulkresend', 'list', true),
            'bulk refresh' => array('bulkrefresh', null, true),
            'bulk mark sent' => array('bulkmarkSent', null, true),
            'save' => array('save', 'list', false),
            'new' => array('new', 'add', false),
            'delete' => array('delete', null, false),
            'status' => array('status', null, false),
            'position' => array('position', null, false),
            'export' => array('export', null, false),
            'bulk delete' => array('bulkdelete', null, false),
            'bulk enable' => array('bulkenableSelection', null, false),
            'update options' => array('update_options', 'options', false),
            'edit form without action' => array('', 'edit', false),
            'view' => array('view', 'view', false),
        );
    }

    /** @dataProvider legacyActions */
    public function testOnlyTheListFilterAndBulkFallbackActionsSurviveTheCoreAction($action, $display, $kept)
    {
        $c = $this->controller();
        $c->action = $action;
        $c->display = $display;

        $c->initProcess();

        if ($kept) {
            $this->assertSame($action, $c->action);
            $this->assertSame($display, $c->display);
            $this->assertSame(array(), $c->errors);

            return;
        }
        $this->assertSame('', $c->action);
        $this->assertNull($c->display);
        $this->assertSame(array('This action is not available on this page.'), $c->errors);
    }

    public function testAnAjaxRequestKeepsItsAction()
    {
        $c = $this->controller();
        $c->ajax = true;
        $c->action = 'BulkRun';

        $c->initProcess();

        $this->assertSame('BulkRun', $c->action);
        $this->assertSame(array(), $c->errors);
    }

    public function testTheGenericProcessMethodsRefuseAndAlterNothing()
    {
        $methods = array(
            'processSave', 'processAdd', 'processUpdate', 'processDelete', 'processStatus', 'processPosition',
            'processExport', 'processBulkDelete', 'processBulkEnableSelection', 'processBulkDisableSelection',
        );
        foreach ($methods as $method) {
            $c = $this->controller();
            $this->assertFalse($c->$method(), $method);
            $this->assertSame(array('This action is not available on this page.'), $c->errors, $method);
        }
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    // ---- the row buttons ----------------------------------------------------------------------

    public function testANotIssuedRowWithTheIssueLevelShowsRetryOnly()
    {
        $this->holding(array(IfirmaPermission::ROLE_CREATE));
        $html = $this->controller()->renderRowActions('', $this->row(array('ifirma_id' => '', 'status' => 'error')));

        $this->assertStringContainsString('data-kind="retry"', $html);
        $this->assertStringContainsString('data-id="12"', $html);
        $this->assertStringContainsString('js-ifirma-row', $html);
        $this->assertStringContainsString('Open order', $html);
        $this->assertStringNotContainsString('data-kind="resend"', $html);
        $this->assertStringNotContainsString('data-kind="refresh"', $html);
        $this->assertStringNotContainsString('Download PDF', $html);
    }

    public function testAnIssuedRowWithTheManageLevelShowsResendRefreshAndThePdf()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_UPDATE));
        $html = $this->controller()->renderRowActions('', $this->row());

        $this->assertStringContainsString('data-kind="resend" data-id="12"', $html);
        $this->assertStringContainsString('data-kind="refresh" data-id="12"', $html);
        $this->assertStringContainsString('Download PDF', $html);
        $this->assertStringNotContainsString('data-kind="retry"', $html);
    }

    public function testThePdfLinkOfARowNamesTheRecordAndTheOrder()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ));
        $context = Context::getContext();
        $saved = $context->link;
        $context->link = new IfirmaTestParamRecordingLink();
        try {
            $this->controller()->renderRowActions('', $this->row());
            $calls = IfirmaTestParamRecordingLink::$calls;
        } finally {
            $context->link = $saved;
        }

        $pdf = array_filter($calls, function ($call) {
            return $call[0] === 'AdminIfirma';
        });
        $params = reset($pdf);
        $this->assertSame(array('ajax' => 1, 'action' => 'DownloadPdf', 'id_order' => 42, 'id_ifirma_receipt' => 12), $params[1]);
    }

    /** Scenario: Actions hidden without the level */
    public function testAViewOnlyEmployeeSeesTheOrderLinkAndThePdfLinkOnly()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ));
        $html = $this->controller()->renderRowActions('', $this->row());

        $this->assertStringContainsString('Open order', $html);
        $this->assertStringContainsString('Download PDF', $html);
        $this->assertStringNotContainsString('<button', $html);

        $html = $this->controller()->renderRowActions('', $this->row(array('ifirma_id' => '', 'status' => 'error')));
        $this->assertStringContainsString('Open order', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('Download PDF', $html);
    }

    public function testAnEmployeeWithNothingSeesNoPdfLinkAndNoButtons()
    {
        $this->holding(array());
        $html = $this->controller()->renderRowActions('', $this->row());

        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('Download PDF', $html);
    }

    // ---- the bulk fallback ----------------------------------------------------------------------

    public function testTheNativeBulkSubmitsOnlyExplainThatJavascriptIsNeeded()
    {
        foreach (array('processBulkRetryIssuance', 'processBulkResend', 'processBulkRefresh', 'processBulkMarkSent') as $method) {
            $c = $this->controller();
            $c->$method();
            $this->assertSame(array('The bulk actions of this page need JavaScript.'), $c->errors, $method);
        }
    }
}
