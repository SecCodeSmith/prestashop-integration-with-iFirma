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

class IfirmaDataRequestTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaDataRequest.php';
    }

    public function testSaveAndLoadRoundTrip()
    {
        $request = new IfirmaDataRequest();
        $request->id_order = 7;
        $request->id_customer = 3;
        $request->setRequestedData(array('company' => 'ACME', 'nip' => '1234567890'));
        $this->assertTrue($request->save());
        $this->assertSame(1, $request->id);

        $loaded = IfirmaDataRequest::getById(1);
        $this->assertInstanceOf(IfirmaDataRequest::class, $loaded);
        $this->assertSame(7, $loaded->id_order);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $loaded->status);
        $data = $loaded->getRequestedData();
        $this->assertSame('ACME', $data['company']);
        $this->assertNull(IfirmaDataRequest::getById(99));
        $this->assertNull(IfirmaDataRequest::getById(0));
    }

    public function testJsonAccessors()
    {
        $request = new IfirmaDataRequest();
        $this->assertSame(array(), $request->getRequestedData());
        $this->assertSame(array(), $request->getApprovedData());

        $request->setApprovedData(array('city' => "\u{141}\u{F3}d\u{17A}"));
        $this->assertSame(array('city' => "\u{141}\u{F3}d\u{17A}"), $request->getApprovedData());

        $request->requested_data = 'not json';
        $this->assertSame(array(), $request->getRequestedData());
    }

    public function testFindersUseScriptedDb()
    {
        $approved = new IfirmaDataRequest();
        $approved->id_order = 7;
        $approved->status = IfirmaDataRequest::STATUS_APPROVED;
        $approved->save();

        $db = Db::getInstance();
        $db->whenGetValue('/COUNT\(\*\)/', '2');
        $db->whenGetValue('/SELECT id_ifirma_data_request.*status = "pending"/s', 0);
        $db->whenGetValue('/SELECT id_ifirma_data_request.*status = "approved"/s', 1);
        $this->assertNull(IfirmaDataRequest::getPendingByOrder(7));
        $this->assertSame(1, IfirmaDataRequest::getApprovedByOrder(7)->id);

        $this->assertSame(2, IfirmaDataRequest::countPendingByCustomer(3));

        $rows = array(array('id_ifirma_data_request' => 2), array('id_ifirma_data_request' => 1));
        $db->whenExecuteS('/FROM `ps_ifirma_data_request`/', $rows);
        $this->assertSame($rows, IfirmaDataRequest::getByOrder(7));
        $queries = $db->queries;
        $this->assertStringContainsString('ORDER BY id_ifirma_data_request DESC', end($queries));
    }

    // ---- approve / reject / assertPending ------------------------------------------------

    /** @return IfirmaDataRequest a saved request waiting for a decision */
    private function pendingRequest()
    {
        $request = new IfirmaDataRequest();
        $request->id_order = 7;
        $request->id_customer = 3;
        $request->setRequestedData(array('company' => 'ACME', 'vat_number' => '525-000-12-34'));
        $request->save();

        return $request;
    }

    /** @return array the saved row of the request */
    private function savedRow(IfirmaDataRequest $request)
    {
        $rows = IfirmaTestState::objects('ifirma_data_request');

        return $rows[$request->id];
    }

    /** The refusal the service throws today: same class, code, message and (empty) field errors. */
    private function assertRefusal($code, $message, callable $call)
    {
        try {
            $call();
        } catch (IfirmaDataRequestException $e) {
            $this->assertSame($code, $e->code_name);
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(array(), $e->errors);

            return;
        }
        $this->fail('expected IfirmaDataRequestException ' . $code);
    }

    /** @return array[] one case per status a request can be resolved into */
    public function resolvedStatuses()
    {
        return array(
            'approved' => array(IfirmaDataRequest::STATUS_APPROVED),
            'rejected' => array(IfirmaDataRequest::STATUS_REJECTED),
            'cancelled' => array(IfirmaDataRequest::STATUS_CANCELLED),
        );
    }

    /** @return array[] notes that do not count as a reason */
    public function blankReasons()
    {
        return array(
            'empty' => array(''),
            'spaces' => array('   '),
            'whitespace' => array("\t\r\n "),
            'null' => array(null),
        );
    }

    public function testApproveSetsTheResolutionFieldsAndSavesNothing()
    {
        $request = $this->pendingRequest();
        $final = array('company' => 'ACME Sp. z o.o.', 'vat_number' => '5250001234');

        $request->approve('4', $final, '  looks fine ', '2026-11-01 10:00:00');

        $this->assertSame(IfirmaDataRequest::STATUS_APPROVED, $request->status);
        $this->assertSame($final, $request->getApprovedData());
        $this->assertSame(4, $request->resolved_by);
        $this->assertSame('looks fine', $request->admin_note);
        $this->assertSame('2026-11-01 10:00:00', $request->date_resolved);
        $this->assertSame(array('company' => 'ACME', 'vat_number' => '525-000-12-34'), $request->getRequestedData());

        // persisting stays with the caller
        $row = $this->savedRow($request);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $row['status']);
        $this->assertNull($row['approved_data']);
        $this->assertNull($row['resolved_by']);
        $this->assertNull($row['date_resolved']);
        $request->save();
        $row = $this->savedRow($request);
        $this->assertSame(IfirmaDataRequest::STATUS_APPROVED, $row['status']);
        $this->assertSame(json_encode($final), $row['approved_data']);
    }

    public function testApproveStoresAnEmptyNoteForNull()
    {
        $request = $this->pendingRequest();

        $request->approve(4, array('company' => 'ACME'), null, '2026-11-01 10:00:00');

        $this->assertSame('', $request->admin_note);
    }

    /** @dataProvider resolvedStatuses */
    public function testApproveRefusesAResolvedRequestAndChangesNothing($status)
    {
        $request = $this->pendingRequest();
        $request->status = $status;

        $this->assertRefusal('not_pending', 'This request is no longer pending.', function () use ($request) {
            $request->approve(4, array('company' => 'ACME'), 'late', '2026-11-01 10:00:00');
        });

        $this->assertSame($status, $request->status);
        $this->assertNull($request->approved_data);
        $this->assertNull($request->admin_note);
        $this->assertNull($request->resolved_by);
        $this->assertNull($request->date_resolved);
    }

    public function testRejectSetsTheResolutionFieldsAndSavesNothing()
    {
        $request = $this->pendingRequest();

        $request->reject('4', '  NIP does not match ', '2026-11-01 11:30:00');

        $this->assertSame(IfirmaDataRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('NIP does not match', $request->admin_note);
        $this->assertSame(4, $request->resolved_by);
        $this->assertSame('2026-11-01 11:30:00', $request->date_resolved);
        $this->assertNull($request->approved_data);

        // persisting stays with the caller
        $row = $this->savedRow($request);
        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $row['status']);
        $this->assertNull($row['admin_note']);
        $request->save();
        $row = $this->savedRow($request);
        $this->assertSame(IfirmaDataRequest::STATUS_REJECTED, $row['status']);
        $this->assertSame('NIP does not match', $row['admin_note']);
    }

    /** @dataProvider resolvedStatuses */
    public function testRejectRefusesAResolvedRequestAndChangesNothing($status)
    {
        $request = $this->pendingRequest();
        $request->status = $status;

        $this->assertRefusal('not_pending', 'This request is no longer pending.', function () use ($request) {
            $request->reject(4, 'late', '2026-11-01 11:30:00');
        });

        $this->assertSame($status, $request->status);
        $this->assertNull($request->admin_note);
        $this->assertNull($request->resolved_by);
        $this->assertNull($request->date_resolved);
    }

    /** @dataProvider blankReasons */
    public function testRejectRequiresAReasonAndChangesNothing($note)
    {
        $request = $this->pendingRequest();

        $this->assertRefusal('reason_required', 'A reason is required.', function () use ($request, $note) {
            $request->reject(4, $note, '2026-11-01 11:30:00');
        });

        $this->assertSame(IfirmaDataRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->admin_note);
        $this->assertNull($request->resolved_by);
        $this->assertNull($request->date_resolved);
    }

    public function testRejectAcceptsTheReasonZero()
    {
        // '0' is a real reason (trim('0') !== ''); an empty() check would wrongly refuse it.
        $request = $this->pendingRequest();

        $request->reject(4, '0', '2026-11-01 11:30:00');

        $this->assertSame(IfirmaDataRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('0', $request->admin_note);
    }

    public function testRejectChecksTheStatusBeforeTheReason()
    {
        $request = $this->pendingRequest();
        $request->status = IfirmaDataRequest::STATUS_CANCELLED;

        $this->assertRefusal('not_pending', 'This request is no longer pending.', function () use ($request) {
            $request->reject(4, '   ', '2026-11-01 11:30:00');
        });
    }

    public function testAssertPendingPassesForAPendingRequest()
    {
        $this->assertNull($this->pendingRequest()->assertPending());
    }

    /** @dataProvider resolvedStatuses */
    public function testAssertPendingRefusesAResolvedRequest($status)
    {
        $request = $this->pendingRequest();
        $request->status = $status;

        $this->assertRefusal('not_pending', 'This request is no longer pending.', function () use ($request) {
            $request->assertPending();
        });
    }

    // ---- countOrdersOnHold() -------------------------------------------------------------

    /**
     * @return string[] every SQL string sent so far, in order, with the CRLF line endings of a Windows
     *                  checkout turned into "\n", so that the text is the same on every checkout
     */
    private function sentQueries()
    {
        return array_map(function ($sql) {
            return str_replace("\r\n", "\n", (string) $sql);
        }, Db::getInstance()->queries);
    }

    public function testCountOrdersOnHoldCountsTheOrdersOfThePendingRequestsInOneQuery()
    {
        Db::getInstance()->whenGetValue('/COUNT\(DISTINCT id_order\)/', '4');

        $this->assertSame(4, IfirmaDataRequest::countOrdersOnHold());

        $this->assertSame(array(
            "SELECT COUNT(DISTINCT id_order) FROM `ps_ifirma_data_request`\n             WHERE status = 'pending'",
        ), $this->sentQueries());
    }

    public static function heldOrderCounts()
    {
        return array(
            'a count as text' => array('4', 4),
            'a count as a number' => array(7, 7),
            'a count of zero as text' => array('0', 0),
            'no row at all' => array(false, 0),
            'a null' => array(null, 0),
        );
    }

    /**
     * @dataProvider heldOrderCounts
     */
    public function testCountOrdersOnHoldIsAWholeNumber($answer, $expected)
    {
        Db::getInstance()->whenGetValue('/COUNT\(DISTINCT id_order\)/', $answer);

        $this->assertSame($expected, IfirmaDataRequest::countOrdersOnHold());
    }
}
