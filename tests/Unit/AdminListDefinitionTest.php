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

    public function testTheBulkEntriesAreTheThreeBatchActions()
    {
        $c = $this->controller();

        $this->assertSame(array('retryIssuance', 'resend', 'refresh'), array_keys($c->bulk_actions));
        foreach ($c->bulk_actions as $entry) {
            $this->assertNotSame('', $entry['text']);
        }
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
        foreach (array('processBulkRetryIssuance', 'processBulkResend', 'processBulkRefresh') as $method) {
            $c = $this->controller();
            $c->$method();
            $this->assertSame(array('The bulk actions of this page need JavaScript.'), $c->errors, $method);
        }
    }
}
