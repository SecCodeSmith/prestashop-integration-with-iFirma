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
 * IfirmaAdminOrderPanelView (refactor-srp-splits 7.4), built by hand around the real services: the
 * variables and their order, what it takes from the module, what it takes from the words it is given,
 * and that it only reads. The golden test and AdminOrderPanelTest drive the same code through the hook,
 * with the module's own words.
 */
class AdminOrderPanelViewTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    protected function setUp(): void
    {
        // loads the module, and with it the view and the presenter, as production does
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    /** Words of their own for every key the presenter reads: "L:" and the key. */
    private function labels()
    {
        $keys = array(
            'state' => array('held', 'suspected', 'unknown', 'error', 'missing', 'paid', 'issued', 'not_issued'),
            'request_status' => array('pending', 'approved', 'rejected', 'cancelled'),
            'request_fields' => $this->requestsService()->fields(),
            'contractor' => array(
                'created', 'updated', 'kept', 'fallback', 'assigned', 'lookup_failed', 'update_failed', 'create_rejected',
                'reference_rejected', 'readback_failed', 'ambiguous', 'search_failed', 'found_nothing', 'attached',
            ),
        );
        $labels = array();
        foreach ($keys as $group => $names) {
            foreach ($names as $name) {
                $labels[$group][$name] = 'L:' . $name;
            }
        }
        $labels['contractor']['attached'] = 'L:attached %s';

        return $labels;
    }

    private function requestsService()
    {
        return IfirmaDataRequestService::build($this->module);
    }

    private function view()
    {
        return new IfirmaAdminOrderPanelView(
            new IfirmaAdminPanelPresenter($this->labels()),
            new IfirmaMatchLabels(array('nip' => 'M:nip', 'import-nip' => 'M:import nip')),
            $this->requestsService(),
            IfirmaDeliveryService::build($this->module),
            IfirmaKsefService::build($this->module)
        );
    }

    private function facts(array $over = array())
    {
        return array_merge(array(
            'version' => '9.8.7',
            'build_stamp' => '2026-11-05 10:20',
            'configured' => true,
            'ajax_url' => 'https://shop.test/ajax?token=T9',
            'token' => 'T9',
        ), $over);
    }

    private function build($idOrder = 15, array $facts = null)
    {
        return $this->view()->build($idOrder, $facts !== null ? $facts : $this->facts());
    }

    // ---- the variables ------------------------------------------------------------------------

    public function testTheVariablesAreThoseOfTheTemplateInTheirOrder()
    {
        $this->assertSame(array(
            'ifirma_contractor_map',
            'ifirma_id_order',
            'ifirma_receipt',
            'ifirma_payment_method',
            'ifirma_contractor_note',
            'ifirma_request_payload',
            'ifirma_response_payload',
            'ifirma_module_version',
            'ifirma_build_stamp',
            'ifirma_has_receipt',
            'ifirma_is_issued',
            'ifirma_is_external',
            'ifirma_configured',
            'ifirma_state',
            'ifirma_state_label',
            'ifirma_can_create',
            'ifirma_can_issue_anyway',
            'ifirma_has_pending_request',
            'ifirma_candidates',
            'ifirma_resolution',
            'ifirma_delivery_text',
            'ifirma_ksef_text',
            'ifirma_gross',
            'ifirma_pending_request',
            'ifirma_request_diff',
            'ifirma_request_fields',
            'ifirma_request_history',
            'ifirma_ajax_url',
            'ifirma_token',
        ), array_keys($this->build()));
    }

    public function testEveryVariableTheTemplateUsesIsThere()
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/views/templates/hook/admin_order_panel.tpl');
        preg_match_all('/\$(ifirma_[a-z_]+)/', $template, $m);
        $built = array_keys($this->build());

        foreach (array_unique($m[1]) as $variable) {
            if ($variable === 'ifirma_badge') {
                continue; // assigned by the template itself
            }
            $this->assertContains($variable, $built, $variable . ' is used by the template');
        }
    }

    public function testTheViewOnlyReadsAndAssignsNothing()
    {
        $this->makeReceipt(array('status' => 'held', 'ifirma_id' => ''));
        $this->makeRequest('pending');
        IfirmaTestState::clearWrites();

        $this->build();

        $this->assertSame(array(), ObjectModel::$writeLog);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), Context::getContext()->smarty->vars);
        $this->assertSame(array(), Module::$displayed);
    }

    // ---- what comes from the module -----------------------------------------------------------

    public function testTheFactsOfTheModuleAreHandedOverAsTheyAre()
    {
        $v = $this->build(15, $this->facts());

        $this->assertSame('9.8.7', $v['ifirma_module_version']);
        $this->assertSame('2026-11-05 10:20', $v['ifirma_build_stamp']);
        $this->assertTrue($v['ifirma_configured']);
        $this->assertSame('https://shop.test/ajax?token=T9', $v['ifirma_ajax_url']);
        $this->assertSame('T9', $v['ifirma_token']);
    }

    public function testAnUnconfiguredModuleIsHandedOverAsFalse()
    {
        $v = $this->build(15, $this->facts(array('configured' => false)));

        $this->assertFalse($v['ifirma_configured']);
    }

    public function testTheOrderIsTheOneItIsGiven()
    {
        $other = $this->wiringOrder();
        $other->id = 16;
        $other->total_paid_tax_incl = 45.5;
        Order::register($other);
        $this->makeReceipt(array('id_order' => 16, 'ifirma_number' => '8/2026'));

        $v = $this->build(16);

        $this->assertSame(16, $v['ifirma_id_order']);
        $this->assertSame(45.5, $v['ifirma_gross']);
        $this->assertSame('8/2026', $v['ifirma_receipt']->ifirma_number);
    }

    // ---- what comes from the words ------------------------------------------------------------

    public function testTheWordsAreThoseOfTheLabelsItWasGiven()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'import-nip', 'Jan Kowalski');
        $this->makeReceipt(array(
            'status' => 'unknown',
            'ifirma_id' => '',
            'request_payload' => json_encode(array('_contractor' => array('action' => 'created', 'document_identifier' => 'AUTO-77'))),
        ));
        $this->makeRequest('pending');
        $this->makeRequest('rejected');

        $v = $this->build();

        $this->assertSame('L:unknown', $v['ifirma_state_label']);
        $this->assertSame('L:created L:attached AUTO-77', $v['ifirma_contractor_note']);
        $this->assertSame('L:company', $v['ifirma_request_fields'][1]['label']);
        $this->assertSame(array('L:pending', 'L:rejected'), array_reverse(array_column($v['ifirma_request_history'], 'status_label')));
        $this->assertSame('M:import nip', $v['ifirma_contractor_map']['matched_by_label']);
    }

    public function testALinkWithACodeTheMatchLabelsDoNotKnowIsShownAsStored()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'legacy-code', 'Jan Kowalski');

        $this->assertSame('legacy-code', $this->build()['ifirma_contractor_map']['matched_by_label']);
    }

    // ---- an order with nothing stored ---------------------------------------------------------

    public function testAnOrderWithNothingStoredOffersToIssue()
    {
        $v = $this->build();

        $this->assertNull($v['ifirma_contractor_map']);
        $this->assertSame(15, $v['ifirma_id_order']);
        $this->assertNull($v['ifirma_receipt']);
        $this->assertSame('', $v['ifirma_payment_method']);
        $this->assertSame('', $v['ifirma_contractor_note']);
        $this->assertSame('', $v['ifirma_request_payload']);
        $this->assertSame('', $v['ifirma_response_payload']);
        $this->assertFalse($v['ifirma_has_receipt']);
        $this->assertFalse($v['ifirma_is_issued']);
        $this->assertFalse($v['ifirma_is_external']);
        $this->assertSame('not_issued', $v['ifirma_state']);
        $this->assertSame('L:not_issued', $v['ifirma_state_label']);
        $this->assertTrue($v['ifirma_can_create']);
        $this->assertFalse($v['ifirma_can_issue_anyway']);
        $this->assertFalse($v['ifirma_has_pending_request']);
        $this->assertSame(array(), $v['ifirma_candidates']);
        $this->assertNull($v['ifirma_resolution']);
        $this->assertSame('', $v['ifirma_delivery_text']);
        $this->assertSame('', $v['ifirma_ksef_text']);
        $this->assertSame(123.0, $v['ifirma_gross']);
        $this->assertNull($v['ifirma_pending_request']);
        $this->assertSame(array(), $v['ifirma_request_diff']);
        $this->assertSame(array(), $v['ifirma_request_fields']);
        $this->assertSame(array(), $v['ifirma_request_history']);
    }
}
