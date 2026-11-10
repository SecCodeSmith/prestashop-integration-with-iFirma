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
 * What the admin order panel decides, case by case (refactor-srp-splits 7.3 and 7.4).
 *
 * AdminOrderPanelGoldenTest compares the whole assignment of each scenario. These tests pin the
 * decisions behind it one by one, from the hook: the state, its wording and the two buttons for every
 * status a record can have, what the panel digs out of the stored request payload, the request history
 * with its statuses and employees, the cases of an order that does not load or has no customer, the
 * reads the panel makes and the render guard. Every case goes through the real hook, so the tests keep
 * holding when the code behind it moves between classes.
 */
class AdminOrderPanelTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    const HELD = 'On hold (data change request pending)';
    const UNKNOWN = 'Outcome unknown - verify in iFirma';

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    /** Renders the panel of an order and returns what it assigned to Smarty. */
    private function panel($idOrder = 15)
    {
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => $idOrder));

        return Context::getContext()->smarty->vars;
    }

    private function employee($id, $firstname, $lastname)
    {
        $e = new Employee();
        $e->id = $id;
        $e->firstname = $firstname;
        $e->lastname = $lastname;

        return Employee::register($e);
    }

    /** A request of order 15 in $status; $props override its fields. */
    private function request($status, array $props = array())
    {
        $req = $this->makeRequest($status);
        foreach ($props as $field => $value) {
            $req->$field = $value;
        }
        $req->save();

        return $req;
    }

    /** The SELECT statements the panel sent, whitespace folded; the schema checks are left out. */
    private function dataReads()
    {
        $reads = array();
        foreach (Db::getInstance()->queries as $sql) {
            if (preg_match('/^\s*SELECT/i', $sql) && strpos($sql, 'information_schema') === false) {
                $reads[] = preg_replace('/\s+/', ' ', trim($sql));
            }
        }

        return $reads;
    }

    // ---- state, wording and buttons -----------------------------------------------------------

    /**
     * @return array[] case => array(status, holds a document, remote_missing, state, label, issued, can create, can issue anyway)
     */
    public function recordStates()
    {
        return array(
            'created, with a document' => array('created', true, 0, 'issued', 'Issued', true, false, false),
            'created, with a document iFirma lost' => array('created', true, 1, 'missing', 'Missing in iFirma', true, false, false),
            'created, without a document' => array('created', false, 0, 'not_issued', 'Not issued', false, true, false),
            'created, without a document, flagged missing' => array('created', false, 1, 'not_issued', 'Not issued', false, true, false),
            'paid, with a document' => array('paid', true, 0, 'paid', 'Paid', true, false, false),
            'paid, with a document iFirma lost' => array('paid', true, 1, 'missing', 'Missing in iFirma', true, false, false),
            'paid, without a document' => array('paid', false, 0, 'not_issued', 'Not issued', false, true, false),
            'error, without a document' => array('error', false, 0, 'error', 'Error', false, true, true),
            'error, with a document id' => array('error', true, 0, 'error', 'Error', false, true, true),
            'unknown, without a document' => array('unknown', false, 0, 'unknown', self::UNKNOWN, false, true, true),
            'unknown, with a document' => array('unknown', true, 0, 'unknown', self::UNKNOWN, true, false, false),
            'suspected, without a document' => array('duplicate_suspected', false, 0, 'suspected', 'Suspected duplicate', false, true, true),
            'suspected, with a document' => array('duplicate_suspected', true, 0, 'suspected', 'Suspected duplicate', true, false, false),
            'held, without a document' => array('held', false, 0, 'held', self::HELD, false, false, false),
            'held, with a document' => array('held', true, 0, 'held', self::HELD, true, false, false),
            'a status of another version, with a document' => array('archived', true, 0, 'issued', 'Issued', true, false, false),
            'a status of another version, without a document' => array('archived', false, 0, 'not_issued', 'Not issued', false, true, false),
        );
    }

    /**
     * @dataProvider recordStates
     */
    public function testTheStateItsWordsAndTheButtonsFollowTheStatusAndTheDocument($status, $hasDocument, $missing, $state, $label, $issued, $canCreate, $canIssueAnyway)
    {
        $this->makeReceipt(array('status' => $status, 'ifirma_id' => $hasDocument ? '77' : '', 'remote_missing' => $missing));

        $v = $this->panel();

        $this->assertSame($state, $v['ifirma_state']);
        $this->assertSame($label, $v['ifirma_state_label']);
        $this->assertSame($issued, $v['ifirma_is_issued']);
        $this->assertSame($canCreate, $v['ifirma_can_create']);
        $this->assertSame($canIssueAnyway, $v['ifirma_can_issue_anyway']);
    }

    public function testWithoutARecordThePanelOffersToIssueAndOnlyThat()
    {
        $v = $this->panel();

        $this->assertSame('not_issued', $v['ifirma_state']);
        $this->assertSame('Not issued', $v['ifirma_state_label']);
        $this->assertNull($v['ifirma_receipt']);
        $this->assertFalse($v['ifirma_has_receipt']);
        $this->assertFalse($v['ifirma_is_issued']);
        $this->assertFalse($v['ifirma_is_external']);
        $this->assertTrue($v['ifirma_can_create']);
        $this->assertFalse($v['ifirma_can_issue_anyway']);
        $this->assertSame('', $v['ifirma_delivery_text']);
        $this->assertSame('', $v['ifirma_ksef_text']);
        $this->assertSame(array(), $v['ifirma_candidates']);
        $this->assertNull($v['ifirma_resolution']);
    }

    public function testTheRecordsOwnSourceAndTheDeliveryAreTold()
    {
        $this->makeReceipt(array('source' => 'external', 'delivery_status' => 'failed', 'delivery_error' => 'no mailbox'));

        $v = $this->panel();

        $this->assertTrue($v['ifirma_is_external']);
        $this->assertSame('Failed: no mailbox – No automatic attempt is scheduled', $v['ifirma_delivery_text']);
    }

    public function testTheFailedDeliveryTellsTheAttemptUsedAndTheNextDueTime()
    {
        $this->makeReceipt(array('delivery_status' => 'failed', 'delivery_error' => 'no mailbox', 'delivery_attempts' => 1, 'delivery_retry_at' => '2026-11-10 10:15:00'));

        $v = $this->panel();

        $this->assertSame('Failed: no mailbox – Attempt 1 of 3, next attempt at 2026-11-10 10:15', $v['ifirma_delivery_text']);
    }

    public function testTheFailedDeliveryWithUsedUpAttemptsSaysNoneRemains()
    {
        $this->makeReceipt(array('delivery_status' => 'failed', 'delivery_error' => 'no mailbox', 'delivery_attempts' => 3));

        $v = $this->panel();

        $this->assertSame('Failed: no mailbox – No automatic attempt remains', $v['ifirma_delivery_text']);
    }

    public function testMarkupInTheDeliveryErrorReachesTheTemplateOnlyThroughItsEscapedLine()
    {
        $this->makeReceipt(array('delivery_status' => 'failed', 'delivery_error' => '<script>alert(1)</script>'));
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/views/templates/hook/admin_order_panel.tpl');

        $v = $this->panel();

        $this->assertStringContainsString('<script>alert(1)</script>', $v['ifirma_delivery_text']);
        $this->assertStringContainsString('{$ifirma_delivery_text|escape:\'html\':\'UTF-8\'}', $tpl);
        $this->assertSame(1, substr_count($tpl, 'ifirma_delivery_text'), 'the line is printed in one place, escaped');
    }

    public function testAnUnconfiguredModuleStillRendersAndSaysSo()
    {
        Configuration::updateValue('IFIRMA_USER', '');
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', '');

        $v = $this->panel();

        $this->assertFalse($v['ifirma_configured']);
        $this->assertSame('not_issued', $v['ifirma_state']);
    }

    public function testTheTemplateGetsTheVersionOfTheModuleAndTheStampOfItsFile()
    {
        $v = $this->panel();

        $this->assertSame($this->module->version, $v['ifirma_module_version']);
        $this->assertSame(date('Y-m-d H:i', (int) filemtime(dirname(__DIR__, 2) . '/ifirma.php')), $v['ifirma_build_stamp']);
    }

    public function testTheGrossIsTheTotalPaidOfTheOrderAsAFloat()
    {
        $order = $this->wiringOrder();
        $order->total_paid_tax_incl = '45.50';
        Order::register($order);

        $this->assertSame(45.5, $this->panel()['ifirma_gross']);
    }

    public function testTheOrderIdIsAnIntegerWhateverTheHookWasGiven()
    {
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => '15'));

        $this->assertSame(15, Context::getContext()->smarty->vars['ifirma_id_order']);
    }

    // ---- what is dug out of the stored request ------------------------------------------------

    /**
     * @return array[] case => array(stored request payload, payment method, contractor note)
     */
    public function storedPayloads()
    {
        return array(
            'none' => array(null, '', ''),
            'empty' => array('', '', ''),
            'not json' => array('{broken', '', ''),
            'a json string' => array('"text"', '', ''),
            'a json list' => array('[1,2,3]', '', ''),
            'a payment method' => array('{"SposobZaplaty":"PRZ"}', 'PRZ', ''),
            'a numeric payment method' => array('{"SposobZaplaty":5}', '5', ''),
            'a null payment method' => array('{"SposobZaplaty":null}', '', ''),
            'a contractor that is not an object' => array('{"SposobZaplaty":"PRZ","_contractor":"created"}', 'PRZ', ''),
            'a contractor without an action' => array('{"_contractor":{"message":"x"}}', '', ''),
            'a contractor with a null action' => array('{"_contractor":{"action":null}}', '', ''),
            'a numeric action' => array('{"_contractor":{"action":1}}', '', ''),
            'an action without a message' => array('{"_contractor":{"action":"created"}}', '', 'created'),
            'a note that is not an object' => array('{"_contractor":{"action":"created","note":"text"}}', '', 'created'),
            'a fallback whose note code is a boolean' => array('{"_contractor":{"action":"fallback","message":"raw","note":{"code":true,"params":{}}}}', '', 'fallback (raw)'),
            'the attached contractor is the referenced one' => array('{"_contractor":{"action":"updated","identifier":" A-1 ","document_identifier":"A-1 "}}', '', 'updated'),
            'the attached contractor is a number' => array('{"_contractor":{"action":"kept","document_identifier":5,"identifier":"5"}}', '', 'kept'),
            'a blank attached contractor' => array('{"_contractor":{"action":"kept","document_identifier":"   "}}', '', 'kept'),
            'no attached contractor' => array('{"_contractor":{"action":"kept","identifier":"A-1"}}', '', 'kept'),
            'an attached contractor and none referenced' => array('{"_contractor":{"action":"kept","document_identifier":"X-9"}}', '', 'kept iFirma attached contractor X-9'),
            'an attached contractor and another referenced' => array('{"_contractor":{"action":"kept","identifier":"A-1","document_identifier":"X-9"}}', '', 'kept iFirma attached contractor X-9'),
            'an attached contractor after an action without a note' => array('{"_contractor":{"action":"bogus","document_identifier":"X-9"}}', '', 'iFirma attached contractor X-9'),
        );
    }

    /**
     * @dataProvider storedPayloads
     */
    public function testThePaymentMethodAndTheContractorNoteAreDugOutOfTheStoredRequest($payload, $paymentMethod, $note)
    {
        $this->makeReceipt(array('request_payload' => $payload));

        $v = $this->panel();

        $this->assertSame($paymentMethod, $v['ifirma_payment_method']);
        $this->assertSame($note, $v['ifirma_contractor_note']);
    }

    public function testTheStoredPayloadsAreHandedOverAsTheyAre()
    {
        $this->makeReceipt(array('request_payload' => '{"SposobZaplaty":"PRZ"}', 'response_payload' => '{"response":{"Kod":0}}'));

        $v = $this->panel();

        $this->assertSame('{"SposobZaplaty":"PRZ"}', $v['ifirma_request_payload']);
        $this->assertSame('{"response":{"Kod":0}}', $v['ifirma_response_payload']);
    }

    public function testNoStoredPayloadsAreHandedOverAsEmptyStrings()
    {
        $this->makeReceipt(array('request_payload' => null, 'response_payload' => null));

        $v = $this->panel();

        $this->assertSame('', $v['ifirma_request_payload']);
        $this->assertSame('', $v['ifirma_response_payload']);
    }

    // ---- the request history ------------------------------------------------------------------

    public function testTheHistoryWordsEachStatusAndNamesTheEmployeeWhoResolved()
    {
        $this->employee(3, 'Anna', 'Nowak');
        $this->employee(4, '', '');
        $this->employee(6, 'Ewa', '');
        $this->request('approved', array('resolved_by' => 3));
        $this->request('cancelled');
        $this->request('rejected', array('resolved_by' => 4));
        $this->request('archived', array('resolved_by' => 5));
        $this->request('approved', array('resolved_by' => 6));
        // the id as the database hands it over: a string
        $this->request('approved', array('resolved_by' => '07'));
        $this->request('pending');
        Employee::$loads = array();

        $history = $this->panel()['ifirma_request_history'];

        // newest first
        $this->assertSame(array(7, 6, 5, 4, 3, 2, 1), array_column($history, 'id'));
        $this->assertSame(array('Pending', 'Approved', 'Approved', 'archived', 'Rejected', 'Cancelled', 'Approved'), array_column($history, 'status_label'));
        $this->assertSame(array('', '#7', 'Ewa', '#5', '#4', '', 'Anna Nowak'), array_column($history, 'employee'));
        $this->assertSame(array(7, 6, 5, 4, 3), Employee::$loads, 'only the requests an employee resolved load an employee');
    }

    public function testTheHistoryLeavesOutARowWhoseRequestDoesNotLoad()
    {
        $this->request('approved');
        $this->request('rejected');
        Db::getInstance()->whenExecuteS('/SELECT \* FROM `ps_ifirma_data_request`\s+WHERE id_order = (\d+)/', array(
            array('id_ifirma_data_request' => 2),
            array('id_ifirma_data_request' => 99),
            array('id_ifirma_data_request' => 0),
            array('id_ifirma_data_request' => 1),
        ));

        $history = $this->panel()['ifirma_request_history'];

        $this->assertSame(array(2, 1), array_column($history, 'id'));
        $this->assertSame(array('Rejected', 'Approved'), array_column($history, 'status_label'));
    }

    public function testTheHistoryOfAnOrderWithoutRequestsIsEmpty()
    {
        $v = $this->panel();

        $this->assertSame(array(), $v['ifirma_request_history']);
        $this->assertFalse($v['ifirma_has_pending_request']);
        $this->assertNull($v['ifirma_pending_request']);
        $this->assertSame(array(), $v['ifirma_request_diff']);
        $this->assertSame(array(), $v['ifirma_request_fields']);
    }

    public function testAPendingRequestIsSummarisedDiffedAndOfferedAsAForm()
    {
        $this->request('pending', array('customer_note' => 'Faktura na firme'));

        $v = $this->panel();

        $this->assertTrue($v['ifirma_has_pending_request']);
        $this->assertSame('Faktura na firme', $v['ifirma_pending_request']['customer_note']);
        $this->assertSame('pending', $v['ifirma_pending_request']['status']);
        $this->assertSame(array('company', 'vat_number', 'lastname', 'address1', 'postcode'), array_column($v['ifirma_request_diff'], 'field'));
        $this->assertSame(array('Invoice for a company', 'Company name', 'VAT number (NIP)', 'First name', 'Last name', 'Address', 'Address (line 2)', 'Postcode', 'City', 'Country', 'Note for the shop (optional)'), array_column($v['ifirma_request_fields'], 'label'));
        $this->assertSame(array('checkbox', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text', 'text'), array_column($v['ifirma_request_fields'], 'type'));
        $this->assertSame(array('', 'ACME Sp. z o.o.', '5260250274', 'Jan', 'Kowalski', 'Nowa 2', '', '00-002', 'Warszawa', '14', ''), array_column($v['ifirma_request_fields'], 'value'));
    }

    // ---- an order that does not load, or has no customer --------------------------------------

    public function testAnOrderThatDoesNotLoadHasNoDiffNoGrossAndNoLink()
    {
        $this->request('pending', array('id_order' => 16));
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'nip', 'Jan Kowalski');
        Address::$loads = array();

        $v = $this->panel(16);

        $this->assertTrue($v['ifirma_has_pending_request']);
        $this->assertSame(array(), $v['ifirma_request_diff']);
        $this->assertNotEmpty($v['ifirma_request_fields']);
        $this->assertSame(0.0, $v['ifirma_gross']);
        $this->assertNull($v['ifirma_contractor_map']);
        $this->assertSame(array(), Address::$loads, 'no address is read for a diff that is not built');
    }

    public function testTheLinkShownIsTheOneOfTheCustomerOfTheOrder()
    {
        $order = $this->wiringOrder();
        $order->id = 16;
        $order->id_customer = 6;
        Order::register($order);
        IfirmaContractorMap::link(5, 'FIVE-05', 'nip', 'Jan Kowalski');
        IfirmaContractorMap::link(6, 'SIX-06', 'name', 'Ewa Nowak');

        $this->assertSame(
            array('identifier' => 'SIX-06', 'matched_by' => 'name', 'matched_by_label' => 'name', 'name' => 'Ewa Nowak'),
            $this->panel(16)['ifirma_contractor_map']
        );
    }

    public function testAnOrderThatCarriesFiguresButDoesNotLoadIsTreatedAsMissing()
    {
        $order = $this->wiringOrder();
        $order->id = 16;
        $order->total_paid_tax_incl = 99.0;
        Order::register($order);
        // an order row that core could not load: the stub keeps the figures, but the object has no id
        Order::$instances[16]->id = 0;
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'nip', 'Jan Kowalski');

        $v = $this->panel(16);

        $this->assertSame(0.0, $v['ifirma_gross']);
        $this->assertNull($v['ifirma_contractor_map']);
    }

    public function testAnOrderWithoutACustomerShowsNoLinkEvenWhenTheMapHoldsOneForCustomerZero()
    {
        $order = $this->wiringOrder();
        $order->id = 16;
        $order->id_customer = 0;
        Order::register($order);
        IfirmaContractorMap::link(0, 'GHOST-1', 'nip', 'Nobody');
        Db::getInstance()->queries = array();

        $v = $this->panel(16);

        $this->assertNull($v['ifirma_contractor_map']);
        $this->assertSame(array(), array_filter($this->dataReads(), function ($sql) {
            return strpos($sql, 'ps_ifirma_contractor_map') !== false;
        }), 'the map is not even read');
    }

    public function testAContractorLinkThatCannotBeReadLeavesTheLinkOutAndTheRestInPlace()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'nip', 'Jan Kowalski');
        $this->makeReceipt();
        Db::getInstance()->whenGetValue('/SELECT id_ifirma_contractor_map FROM `ps_ifirma_contractor_map`\s+WHERE id_customer = (\d+)/', function () {
            throw new RuntimeException('the contractor map cannot be read');
        });

        $v = $this->panel();

        $this->assertNull($v['ifirma_contractor_map']);
        $this->assertSame('issued', $v['ifirma_state']);
        $this->assertSame(123.0, $v['ifirma_gross']);
    }

    // ---- the stored duplicate candidates ------------------------------------------------------

    /**
     * @return array[] case => array(stored duplicate_candidates, candidates shown, resolution shown)
     */
    public function storedCandidates()
    {
        $row = array('id' => '77');
        $resolution = array('action' => 'link', 'id_employee' => 3, 'at' => '2026-11-02 11:00:00');

        return array(
            'none' => array(null, array(), null),
            'not json' => array('{broken', array(), null),
            'a plain list' => array(json_encode(array($row)), array(), null),
            'candidates and a resolution' => array(json_encode(array('candidates' => array($row), 'resolution' => $resolution)), array($row), $resolution),
            'candidates without a resolution' => array(json_encode(array('candidates' => array($row))), array($row), null),
            'an empty list of candidates' => array(json_encode(array('candidates' => array(), 'resolution' => $resolution)), array(), $resolution),
            'candidates that are not a list' => array(json_encode(array('candidates' => 'none', 'resolution' => $resolution)), array(), null),
            'a resolution without candidates' => array(json_encode(array('resolution' => $resolution)), array(), null),
        );
    }

    /**
     * @dataProvider storedCandidates
     */
    public function testTheStoredCandidatesAndTheirResolutionAreShownOnlyAsAPair($stored, array $candidates, $resolution)
    {
        $this->makeReceipt(array('duplicate_candidates' => $stored));

        $v = $this->panel();

        $this->assertSame($candidates, $v['ifirma_candidates']);
        $this->assertSame($resolution, $v['ifirma_resolution']);
    }

    // ---- reads and the render guard -----------------------------------------------------------

    public function testThePanelReadsTheRecordTheRequestsAndTheLinkOnceEachAndStoresNothing()
    {
        $this->employee(3, 'Anna', 'Nowak');
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'nip', 'Jan Kowalski');
        $this->makeReceipt(array('status' => 'held', 'ifirma_id' => ''));
        $this->request('approved', array('resolved_by' => 3));
        $this->request('pending');
        Db::getInstance()->queries = array();
        IfirmaTestState::clearWrites();
        Order::$loads = array();
        Address::$loads = array();
        Employee::$loads = array();

        $this->panel();

        $this->assertSame(array(
            'SELECT id_ifirma_receipt FROM `ps_ifirma_receipt` WHERE id_order = 15 ORDER BY id_ifirma_receipt DESC',
            'SELECT id_ifirma_data_request FROM `ps_ifirma_data_request` WHERE id_order = 15 AND status = "pending" ORDER BY id_ifirma_data_request DESC',
            'SELECT * FROM `ps_ifirma_data_request` WHERE id_order = 15 ORDER BY id_ifirma_data_request DESC',
            'SELECT id_ifirma_contractor_map FROM `ps_ifirma_contractor_map` WHERE id_customer = 5',
        ), $this->dataReads());
        $this->assertSame(array(15), Order::$loads);
        $this->assertSame(array(7), Address::$loads);
        $this->assertSame(array(3), Employee::$loads);
        $this->assertSame(array(), ObjectModel::$writeLog);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheSecondRenderOfARequestAssignsAndDisplaysNothing()
    {
        $first = $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));
        $assigned = Context::getContext()->smarty->vars;
        // had the second call rendered, it would assign the record that exists by now
        $this->makeReceipt();

        $second = $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        $this->assertSame('<rendered:views/templates/hook/admin_order_panel.tpl>', $first);
        $this->assertSame('', $second);
        $this->assertSame($assigned, Context::getContext()->smarty->vars);
        $this->assertCount(1, Module::$displayed);
    }

    public function testAnOrderWithoutAnIdRendersNothingAndLeavesTheGuardAlone()
    {
        $this->assertSame('', $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 0)));
        $this->assertSame(array(), Context::getContext()->smarty->vars);
        $this->assertSame(array(), Module::$displayed);

        $this->assertSame('<rendered:views/templates/hook/admin_order_panel.tpl>', $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15)));
        $this->assertCount(1, Module::$displayed);
    }
}
