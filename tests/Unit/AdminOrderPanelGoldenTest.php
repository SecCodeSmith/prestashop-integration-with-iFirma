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
 * Golden comparison of the admin order panel (refactor-srp-splits 7.3).
 *
 * tests/fixtures/admin-order-panel/golden.json holds the full array that the order panel handed to
 * Smarty for each scenario below, as the module returned it before the panel was split into
 * IfirmaAdminOrderPanelView and IfirmaAdminPanelPresenter (7.4): every variable, in order, with its
 * type. The receipt object is stored as its class and its public fields.
 *
 * The scenarios are the admin-panel fixtures of ModuleHooksTest, copied with the values that follow
 * the clock pinned (issue date, last sync, creation dates), followed by the cases the panel's helpers
 * decide that no earlier test reached: a record without a document, the stored duplicate candidates
 * in each shape, the request history with its employees and statuses, an order that does not load
 * and a contractor link that cannot be read. Two values depend on the checkout, not on the scenario:
 * the module version and the modification time of ifirma.php. Each is compared with its source and
 * stored as a placeholder.
 *
 * The golden is the specification of the split. Regenerate it only for a change that is meant to
 * alter what the panel shows.
 */
class AdminOrderPanelGoldenTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    const DAY = '2026-11-03';
    const MOMENT = '2026-11-03 09:30:00';
    const PLACEHOLDER_BUILD_STAMP = '<modification time of ifirma.php>';
    const PLACEHOLDER_VERSION = '<version of the module>';

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    // ---- fixtures ---------------------------------------------------------------------------

    /** Puts an employee of a non-SuperAdmin profile with exactly these roles into the context. */
    public function employeeWith(array $roles)
    {
        Access::reset();
        Context::getContext()->employee->id_profile = 4;
        foreach ($roles as $role) {
            Access::grant($role, 4);
        }
    }

    /** A receipt of order 15 with the dates fixed; $props override anything. */
    protected function receipt(array $props = array())
    {
        return $this->makeReceipt(array_merge(array(
            'issue_date' => self::DAY,
            'last_sync_at' => self::MOMENT,
            'date_add' => self::MOMENT,
            'date_upd' => self::MOMENT,
        ), $props));
    }

    /** Like makeRequest(), with the creation dates fixed; $props override anything. */
    protected function request($status = 'pending', array $props = array(), array $data = null)
    {
        $req = new IfirmaDataRequest();
        $req->id_order = 15;
        $req->id_customer = 5;
        $req->status = $status;
        $req->date_add = self::MOMENT;
        $req->date_upd = self::MOMENT;
        foreach ($props as $field => $value) {
            $req->$field = $value;
        }
        $req->setRequestedData($data !== null ? $data : array(
            'company' => 'ACME Sp. z o.o.',
            'vat_number' => '5260250274',
            'firstname' => 'Jan',
            'lastname' => 'Kowalski',
            'address1' => 'Nowa 2',
            'postcode' => '00-002',
            'city' => 'Warszawa',
            'id_country' => 14,
        ));
        $req->save();

        return new IfirmaDataRequest((int) $req->id);
    }

    protected function employee($id, $firstname, $lastname)
    {
        $e = new Employee();
        $e->id = $id;
        $e->firstname = $firstname;
        $e->lastname = $lastname;

        return Employee::register($e);
    }

    /** The summary of the remote document that every duplicate scenario starts from. */
    protected function candidate()
    {
        return IfirmaDuplicateMatcher::summarize($this->remoteRow(array('DataWystawienia' => self::DAY)));
    }

    /** A receipt that carries a _contractor step in its request payload. */
    protected function receiptWithContractorStep(array $contractor)
    {
        return $this->receipt(array('request_payload' => json_encode(array('SposobZaplaty' => 'PRZ', '_contractor' => $contractor))));
    }

    /** An order of customer $idCustomer that the module can load. */
    protected function order($id, $idCustomer)
    {
        $o = $this->wiringOrder();
        $o->id = $id;
        $o->id_customer = $idCustomer;

        return Order::register($o);
    }

    // ---- scenarios --------------------------------------------------------------------------

    /**
     * @return array[] scenario => array(scenario, ModuleHooksTest tests it copies, seed, id of the order)
     */
    public function scenarios()
    {
        $cases = array();
        foreach (array_merge($this->moduleHooksScenarios(), $this->contractorNoteScenarios(), $this->panelScenarios()) as $name => $case) {
            $cases[$name] = array($name, $case[0], $case[1], isset($case[2]) ? $case[2] : 15);
        }

        return $cases;
    }

    private function moduleHooksScenarios()
    {
        return array(
            'suspected duplicate' => array(array('testAdminPanelForSuspectedReceipt'), static function (self $t) {
                $t->receipt(array(
                    'status' => 'duplicate_suspected',
                    'ifirma_id' => '',
                    'last_error' => 'Suspected duplicate: 1 matching document(s) found in iFirma',
                    'duplicate_candidates' => json_encode(array('candidates' => array($t->candidate()), 'resolution' => null)),
                ));
            }),
            'paid external document, delivered' => array(array('testAdminPanelForIssuedReceipt'), static function (self $t) {
                $t->receipt(array('status' => 'paid', 'source' => 'external', 'delivery_status' => 'sent', 'delivery_channel' => 'shop_mail', 'delivered_at' => '2026-11-01 10:00:00'));
            }),
            'sent to KSeF' => array(array('testAdminPanelAssignsTheKsefText'), static function (self $t) {
                $t->receipt(array('ksef_status' => 'WYSLANA', 'ksef_sent_at' => '2026-11-02 20:30:00'));
            }),
            'issued document of a private buyer' => array(array('testAdminPanelKsefTextForAPrivateBuyer'), static function (self $t) {
                $t->receipt();
            }),
            'document missing in iFirma' => array(array('testAdminPanelStateDerivation'), static function (self $t) {
                $t->receipt(array('status' => 'created', 'remote_missing' => 1));
            }),
            'outcome unknown' => array(array('testAdminPanelStateDerivation'), static function (self $t) {
                $t->receipt(array('status' => 'unknown', 'ifirma_id' => ''));
            }),
            'error' => array(array('testAdminPanelStateDerivation'), static function (self $t) {
                $t->receipt(array('status' => 'error', 'ifirma_id' => ''));
            }),
            'issued document' => array(array('testAdminPanelStateDerivation'), static function (self $t) {
                $t->receipt(array('status' => 'created'));
            }),
            'no receipt' => array(array('testAdminPanelWithoutReceiptOffersCreate', 'testAdminPanelHasNoLinkedContractorForAnUnmappedCustomer'), static function (self $t) {
            }),
            'held by a pending request' => array(array('testAdminPanelHeldWithPendingRequest'), static function (self $t) {
                $t->receipt(array('status' => 'held', 'ifirma_id' => ''));
                $t->request('pending');
                $t->request('rejected');
            }),
            'linked contractor' => array(array('testAdminPanelAssignsTheLinkedContractor'), static function (self $t) {
                IfirmaContractorMap::link(5, 'KOWALSKI-01', 'import-nip', 'Jan Kowalski');
            }),
        );
    }

    private function contractorNoteScenarios()
    {
        $miss = array('identifier' => 'PS-C1909', 'answer' => 'Kod 208: Nie znaleziono kontrahenta');
        $tried = array(array('identifier' => 'PS-C1', 'answer' => 'empty result'), array('identifier' => '99', 'answer' => 'boom'));
        $fallback = static function ($code, array $params) {
            return array('action' => 'fallback', 'message' => 'raw', 'note' => array('code' => $code, 'params' => $params));
        };

        return array(
            'contractor note: read-back failure' => array(array('testAdminPanelShowsTheReadbackFailureNoteOnceAndTheEmptySearch'), static function (self $t) use ($miss) {
                $t->receiptWithContractorStep(array(
                    'action' => 'fallback',
                    'message' => 'iFirma created the contractor but it cannot be read back (PS-C1909: Kod 208: Nie znaleziono kontrahenta); search: none.',
                    'note' => array('code' => 'readback_failed', 'params' => array('tried' => array($miss, $miss), 'search' => 'none', 'search_error' => '')),
                ));
            }),
            'contractor note: ambiguous search' => array(array('testAdminPanelShowsTheAmbiguousAndFailedSearchWords'), static function (self $t) use ($tried) {
                $t->receiptWithContractorStep(array(
                    'action' => 'fallback', 'message' => 'x',
                    'note' => array('code' => 'readback_failed', 'params' => array('tried' => $tried, 'search' => 'ambiguous', 'search_error' => '')),
                ));
            }),
            'contractor note: failed search' => array(array('testAdminPanelShowsTheAmbiguousAndFailedSearchWords'), static function (self $t) use ($tried) {
                $t->receiptWithContractorStep(array(
                    'action' => 'fallback', 'message' => 'x',
                    'note' => array('code' => 'readback_failed', 'params' => array('tried' => $tried, 'search' => 'error', 'search_error' => 'search timeout')),
                ));
            }),
            'contractor note: assigned identifier' => array(array('testAdminPanelShowsTheAssignedIdentifierOfACreatedContractor'), static function (self $t) {
                $t->receiptWithContractorStep(array(
                    'action' => 'created', 'message' => '', 'identifier' => 'IFI1909556979', 'matched_by' => 'module', 'assigned_identifier' => 'IFI1909556979',
                    'note' => array('code' => 'assigned', 'params' => array('identifier' => 'IFI1909556979')),
                ));
            }),
            'contractor note: failed lookup' => array(array('testAdminPanelShowsTheOtherFallbackNotes'), static function (self $t) use ($fallback) {
                $t->receiptWithContractorStep($fallback('lookup_failed', array('identifier' => 'PS-C5', 'error' => 'timeout')));
            }),
            'contractor note: failed update' => array(array('testAdminPanelShowsTheOtherFallbackNotes'), static function (self $t) use ($fallback) {
                $t->receiptWithContractorStep($fallback('update_failed', array('identifier' => 'PS-C5', 'error' => 'bad')));
            }),
            'contractor note: rejected create' => array(array('testAdminPanelShowsTheOtherFallbackNotes'), static function (self $t) use ($fallback) {
                $t->receiptWithContractorStep($fallback('create_rejected', array('error' => 'Zly kod')));
            }),
            'contractor note: rejected reference' => array(array('testAdminPanelShowsTheOtherFallbackNotes'), static function (self $t) use ($fallback) {
                $t->receiptWithContractorStep($fallback('reference_rejected', array('identifier' => 'PS-C5', 'error' => 'nieprawidlowa')));
            }),
            'contractor note: record of an older version' => array(array('testAdminPanelKeepsTheRawMessageForALegacyFallbackRecord'), static function (self $t) {
                $t->receiptWithContractorStep(array('action' => 'fallback', 'message' => 'old sentence'));
            }),
            'contractor note: attached contractor' => array(array('testAdminPanelAppendsTheAttachedContractorAfterTheFallbackNote'), static function (self $t) {
                $t->receiptWithContractorStep(array(
                    'action' => 'fallback', 'message' => 'raw', 'identifier' => '', 'document_identifier' => 'AUTO-77',
                    'note' => array('code' => 'create_rejected', 'params' => array('error' => 'Zly kod')),
                ));
            }),
        );
    }

    private function panelScenarios()
    {
        return array(
            'record without a document' => array(array(), static function (self $t) {
                $t->receipt(array('status' => 'created', 'ifirma_id' => '', 'ifirma_number' => ''));
            }),
            'issued document of a business buyer' => array(array(), static function (self $t) {
                $t->receipt();
                // the buyer is a business when the invoice address of the order carries a VAT number
                Address::$instances[7]->vat_number = '526-025-02-74';
            }),
            'payloads stored with the record' => array(array(), static function (self $t) {
                $t->receipt(array('request_payload' => '{"SposobZaplaty":"PRZ"}', 'response_payload' => '{"response":{"Kod":0,"Identyfikator":"77"}}'));
            }),
            'duplicate resolved by an employee' => array(array(), static function (self $t) {
                $t->receipt(array('status' => 'created', 'source' => 'external', 'duplicate_candidates' => json_encode(array(
                    'candidates' => array($t->candidate()),
                    'resolution' => array('action' => 'link', 'id_employee' => 3, 'at' => '2026-11-02 11:00:00'),
                ))));
            }),
            'stored candidates as a plain list' => array(array(), static function (self $t) {
                $t->receipt(array('status' => 'duplicate_suspected', 'ifirma_id' => '', 'duplicate_candidates' => json_encode(array($t->candidate()))));
            }),
            'resolution stored without a candidate list' => array(array(), static function (self $t) {
                $t->receipt(array('duplicate_candidates' => json_encode(array(
                    'candidates' => 'none',
                    'resolution' => array('action' => 'link', 'id_employee' => null, 'at' => '2026-11-02 11:00:00'),
                ))));
            }),
            'candidates stored without a resolution' => array(array(), static function (self $t) {
                $t->receipt(array('status' => 'duplicate_suspected', 'ifirma_id' => '', 'duplicate_candidates' => json_encode(array('candidates' => array($t->candidate())))));
            }),
            'request history' => array(array(), static function (self $t) {
                $t->employee(3, 'Anna', 'Nowak');
                $t->employee(4, '', '');
                $t->receipt(array('status' => 'held', 'ifirma_id' => ''));
                $t->request('approved', array('resolved_by' => 3, 'date_resolved' => '2026-11-02 12:00:00', 'admin_note' => 'ok', 'approved_data' => json_encode(array('company' => 'ACME Sp. z o.o.'))));
                $t->request('cancelled', array('date_resolved' => '2026-11-02 13:00:00'));
                $t->request('rejected', array('resolved_by' => 4, 'date_resolved' => '2026-11-02 14:00:00', 'admin_note' => 'wrong NIP'));
                $t->request('archived', array('resolved_by' => 5, 'date_resolved' => '2026-11-02 15:00:00'));
                $t->request('pending', array('customer_note' => 'Faktura na firme'));
                // a row of the list whose request does not load any more
                Db::getInstance()->whenExecuteS('/SELECT \* FROM `ps_ifirma_data_request`\s+WHERE id_order = (\d+)/', function () {
                    $rows = array(array('id_ifirma_data_request' => 99));
                    foreach (array_reverse(IfirmaTestState::objects('ifirma_data_request'), true) as $id => $row) {
                        $rows[] = array('id_ifirma_data_request' => $id) + $row;
                    }

                    return $rows;
                });
            }),
            'request with values of every kind' => array(array(), static function (self $t) {
                $t->receipt(array('status' => 'held', 'ifirma_id' => ''));
                $t->request('pending', array('customer_note' => 'Prosze o fakture'), array(
                    'want_invoice_for_company' => true,
                    'company' => 'ACME Sp. z o.o.',
                    'vat_number' => '526-025-02-74',
                    'firstname' => 'Jan',
                    'lastname' => null,
                    'address1' => 'Prosta 1',
                    'id_country' => 14,
                    'unknown_field' => 'ignored',
                ));
            }),
            'order that does not load' => array(array(), static function (self $t) {
                $t->receipt(array('id_order' => 16));
                $t->request('pending', array('id_order' => 16), array('company' => array('not', 'a', 'string'), 'vat_number' => '5260250274', 'id_country' => 14));
            }, 16),
            'order without a customer' => array(array(), static function (self $t) {
                $t->order(16, 0);
                IfirmaContractorMap::link(0, 'GHOST-1', 'nip', 'Nobody');
            }, 16),
            'contractor link that cannot be read' => array(array(), static function (self $t) {
                IfirmaContractorMap::link(5, 'KOWALSKI-01', 'nip', 'Jan Kowalski');
                Db::getInstance()->whenGetValue('/SELECT id_ifirma_contractor_map FROM `ps_ifirma_contractor_map`\s+WHERE id_customer = (\d+)/', function () {
                    throw new RuntimeException('the contractor map cannot be read');
                });
            }),
            'link with a code of an older version' => array(array(), static function (self $t) {
                IfirmaContractorMap::link(5, 'KOWALSKI-01', 'legacy-code', 'Jan Kowalski');
            }),
            'view-only employee' => array(array(), static function (self $t) {
                $t->receipt(array('status' => 'created'));
                $t->employeeWith(array(IfirmaPermission::ROLE_READ));
            }),
            'issue without manage' => array(array(), static function (self $t) {
                $t->receipt(array('status' => 'created'));
                $t->employeeWith(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
            }),
        );
    }

    // ---- the comparison ---------------------------------------------------------------------

    /**
     * @param mixed $value
     * @return mixed arrays kept, objects turned into array('__class' => the class) + their public fields
     */
    private function plain($value)
    {
        if (is_object($value)) {
            return array('__class' => get_class($value)) + $this->plain(get_object_vars($value));
        }

        return is_array($value) ? array_map(array($this, 'plain'), $value) : $value;
    }

    /**
     * Renders the panel of an order and returns what it assigned to Smarty, comparable and without the
     * two values that depend on the checkout.
     *
     * @param int $idOrder
     * @return array
     */
    protected function renderedAssignment($idOrder)
    {
        IfirmaTestState::clearWrites();
        $out = $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => $idOrder));

        $this->assertSame('<rendered:views/templates/hook/admin_order_panel.tpl>', $out);
        $this->assertSame(array(), ObjectModel::$writeLog, 'the panel stores nothing');
        $this->assertSame(array(), $this->t->calls, 'the panel calls no iFirma API');
        $displayed = Module::$displayed;
        $this->assertCount(1, $displayed);
        $this->assertSame('views/templates/hook/admin_order_panel.tpl', $displayed[0][0]);
        $vars = Context::getContext()->smarty->vars;
        $this->assertSame($vars, $displayed[0][1], 'the template gets what the module assigned');

        $this->assertSame(date('Y-m-d H:i', (int) filemtime(dirname(__DIR__, 2) . '/ifirma.php')), $vars['ifirma_build_stamp']);
        $vars['ifirma_build_stamp'] = self::PLACEHOLDER_BUILD_STAMP;
        $this->assertSame((string) $this->module->version, $vars['ifirma_module_version']);
        $vars['ifirma_module_version'] = self::PLACEHOLDER_VERSION;

        return $this->plain($vars);
    }

    protected static function golden()
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/admin-order-panel/golden.json'), true);
    }

    /**
     * @param string $name
     * @param array  $from
     * @param array  $assignment what the panel assigned
     */
    protected function assertAssignmentIsTheCapturedOne($name, array $from, array $assignment)
    {
        $golden = self::golden();
        $this->assertArrayHasKey($name, $golden['scenarios']);
        $expected = $golden['scenarios'][$name]['assignment'];

        $this->assertSame(array_keys($expected), array_keys($assignment), $name . ': the variables and their order');
        foreach ($expected as $variable => $value) {
            $this->assertSame($value, $assignment[$variable], $name . ': ' . $variable);
        }
    }

    /**
     * @dataProvider scenarios
     */
    public function testTheAssignmentEqualsTheCapturedOne($name, array $from, Closure $seed, $idOrder)
    {
        $seed($this);

        $this->assertAssignmentIsTheCapturedOne($name, $from, $this->renderedAssignment($idOrder));
    }

    public function testTheGoldenHoldsExactlyTheScenarios()
    {
        $golden = self::golden();

        $this->assertSame(array_keys($this->scenarios()), array_keys($golden['scenarios']));
        foreach ($this->scenarios() as $name => $case) {
            $this->assertSame($case[1], $golden['scenarios'][$name]['from'], $name);
        }
    }

    /**
     * The panel tests are read from the source of ModuleHooksTest, minus the exempt ones, which are
     * listed with the reason each has no golden scenario.
     */
    public function testTheGoldenCoversEveryAdminPanelFixtureOfModuleHooksTest()
    {
        $exempt = array(
            'testAdminPanelRendersOncePerRequest' => 'renders twice to check the render guard, assigns no fixture',
            'testAdminPanelTemplateShowsTheLinkedContractorRow' => 'reads the template file, renders nothing',
        );
        $covered = array();
        foreach ($this->scenarios() as $case) {
            $covered = array_merge($covered, $case[1]);
        }
        $source = file_get_contents(__DIR__ . '/ModuleHooksTest.php');
        preg_match_all('/public function (testAdminPanel\w*)\(/', $source, $matches);
        $tests = $matches[1];

        $this->assertNotEmpty($tests);
        foreach ($tests as $test) {
            if (!isset($exempt[$test])) {
                $this->assertContains($test, $covered, $test . ' has no scenario: add one, or list it as exempt with its reason');
            }
        }
        foreach (array_keys($exempt) as $test) {
            $this->assertContains($test, $tests, $test . ' is exempt but is not a testAdminPanel test of ModuleHooksTest any more');
            $this->assertNotContains($test, $covered, $test . ' is exempt and also has a scenario: drop one of the two');
        }
        foreach ($covered as $test) {
            $this->assertContains($test, $tests, $test . ' is covered but is not a testAdminPanel test of ModuleHooksTest any more');
        }
    }

    public function testTheAssignmentOfEveryScenarioHoldsTheSameVariablesInTheSameOrder()
    {
        $golden = self::golden();
        $keys = null;
        foreach ($golden['scenarios'] as $name => $scenario) {
            $keys = $keys === null ? array_keys($scenario['assignment']) : $keys;
            $this->assertSame($keys, array_keys($scenario['assignment']), $name);
        }

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
            'ifirma_can_issue',
            'ifirma_can_manage',
        ), $keys);
    }
}
