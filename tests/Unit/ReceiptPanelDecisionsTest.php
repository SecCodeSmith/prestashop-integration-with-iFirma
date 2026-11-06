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
 * What the record itself tells the admin order panel (refactor-srp-splits 7.4): whether to offer issuing
 * the document, whether to offer issuing it anyway, and what the stored request payload says about the
 * payment method and the contractor step. AdminOrderPanelTest pins the same answers through the hook.
 */
class ReceiptPanelDecisionsTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function receipt(array $props = array())
    {
        $r = new IfirmaReceipt();
        foreach ($props as $field => $value) {
            $r->$field = $value;
        }

        return $r;
    }

    // ---- canCreate() and canIssueAnyway() -----------------------------------------------------

    /**
     * @return array[] case => array(status, ifirma_id, can create, can issue anyway)
     */
    public function decisions()
    {
        return array(
            'created, with a document' => array('created', '77', false, false),
            'created, without a document' => array('created', '', true, false),
            'paid, with a document' => array('paid', '77', false, false),
            'paid, without a document' => array('paid', '', true, false),
            'error, without a document' => array('error', '', true, true),
            'error, with a document id' => array('error', '77', true, true),
            'unknown, without a document' => array('unknown', '', true, true),
            'unknown, with a document' => array('unknown', '77', false, false),
            'suspected, without a document' => array('duplicate_suspected', '', true, true),
            'suspected, with a document' => array('duplicate_suspected', '77', false, false),
            'held, without a document' => array('held', '', false, false),
            'held, with a document' => array('held', '77', false, false),
            'no status yet' => array(null, '', true, false),
            'a status of another version, without a document' => array('archived', '', true, false),
            'a status of another version, with a document' => array('archived', '77', false, false),
        );
    }

    /**
     * @dataProvider decisions
     */
    public function testWhatTheRecordOffers($status, $ifirmaId, $canCreate, $canIssueAnyway)
    {
        $r = $this->receipt(array('status' => $status, 'ifirma_id' => $ifirmaId));

        $this->assertSame($canCreate, $r->canCreate());
        $this->assertSame($canIssueAnyway, $r->canIssueAnyway());
    }

    public function testTheDecisionsChangeNothingOnTheRecord()
    {
        $r = $this->receipt(array('status' => 'error', 'ifirma_id' => '', 'request_payload' => '{"a":1}'));
        $before = get_object_vars($r);

        $r->canCreate();
        $r->canIssueAnyway();
        $r->sentPaymentMethod();
        $r->contractorNote();

        $this->assertSame($before, get_object_vars($r));
        $this->assertSame(array(), ObjectModel::$writeLog);
    }

    // ---- sentPaymentMethod() ------------------------------------------------------------------

    /**
     * @return array[] case => array(stored request payload, payment method)
     */
    public function paymentMethods()
    {
        return array(
            'no payload' => array(null, ''),
            'an empty payload' => array('', ''),
            'a payload of zero' => array('0', ''),
            'not json' => array('{broken', ''),
            'a json string' => array('"PRZ"', ''),
            'a json number' => array('5', ''),
            'a json list' => array('["PRZ"]', ''),
            'an object without a method' => array('{"Kontrahent":{}}', ''),
            'a method' => array('{"SposobZaplaty":"PRZ"}', 'PRZ'),
            'a numeric method' => array('{"SposobZaplaty":5}', '5'),
            'a null method' => array('{"SposobZaplaty":null}', ''),
            'an empty method' => array('{"SposobZaplaty":""}', ''),
            'a method next to a contractor' => array('{"SposobZaplaty":"GTK","_contractor":{"action":"kept"}}', 'GTK'),
        );
    }

    /**
     * @dataProvider paymentMethods
     */
    public function testThePaymentMethodOfTheStoredRequest($payload, $method)
    {
        $this->assertSame($method, $this->receipt(array('request_payload' => $payload))->sentPaymentMethod());
    }

    // ---- contractorNote() ---------------------------------------------------------------------

    /**
     * @return array[] case => array(stored request payload) that records no contractor action
     */
    public function payloadsWithoutAContractorAction()
    {
        return array(
            'no payload' => array(null),
            'an empty payload' => array(''),
            'not json' => array('{broken'),
            'a json string' => array('"created"'),
            'a json list' => array('[{"action":"created"}]'),
            'no contractor' => array('{"SposobZaplaty":"PRZ"}'),
            'a contractor that is a string' => array('{"_contractor":"created"}'),
            'a contractor that is a list' => array('{"_contractor":["created"]}'),
            'a contractor without an action' => array('{"_contractor":{"message":"x","identifier":"A-1"}}'),
            'a contractor with a null action' => array('{"_contractor":{"action":null}}'),
        );
    }

    /**
     * @dataProvider payloadsWithoutAContractorAction
     */
    public function testNoContractorNoteWhenTheStoredRequestRecordsNoAction($payload)
    {
        $this->assertNull($this->receipt(array('request_payload' => $payload))->contractorNote());
    }

    /**
     * @return array[] case => array(the _contractor entry, the note it gives)
     */
    public function contractorSteps()
    {
        $note = array('code' => 'create_rejected', 'params' => array('error' => 'Zly kod'));

        return array(
            'an action alone' => array(
                array('action' => 'created'),
                array('action' => 'created', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'an action of any kind' => array(
                array('action' => 'bogus'),
                array('action' => 'bogus', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'a numeric action' => array(
                array('action' => 1),
                array('action' => '1', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'an action of false' => array(
                array('action' => false),
                array('action' => '', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'the raw sentence of an older record' => array(
                array('action' => 'fallback', 'message' => 'old sentence'),
                array('action' => 'fallback', 'message' => 'old sentence', 'note' => null, 'attached' => ''),
            ),
            'a numeric sentence' => array(
                array('action' => 'fallback', 'message' => 5),
                array('action' => 'fallback', 'message' => '5', 'note' => null, 'attached' => ''),
            ),
            'a structured note' => array(
                array('action' => 'fallback', 'message' => 'raw', 'note' => $note),
                array('action' => 'fallback', 'message' => 'raw', 'note' => $note, 'attached' => ''),
            ),
            'a note that is a string' => array(
                array('action' => 'fallback', 'note' => 'create_rejected'),
                array('action' => 'fallback', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'a note that is a number' => array(
                array('action' => 'fallback', 'note' => 5),
                array('action' => 'fallback', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'an empty note' => array(
                array('action' => 'fallback', 'note' => array()),
                array('action' => 'fallback', 'message' => '', 'note' => array(), 'attached' => ''),
            ),
            'the contractor iFirma attached' => array(
                array('action' => 'fallback', 'identifier' => '', 'document_identifier' => 'AUTO-77'),
                array('action' => 'fallback', 'message' => '', 'note' => null, 'attached' => 'AUTO-77'),
            ),
            'the attached contractor, trimmed' => array(
                array('action' => 'kept', 'identifier' => 'A-1', 'document_identifier' => '  AUTO-77 '),
                array('action' => 'kept', 'message' => '', 'note' => null, 'attached' => 'AUTO-77'),
            ),
            'no identifier referenced' => array(
                array('action' => 'kept', 'document_identifier' => 'AUTO-77'),
                array('action' => 'kept', 'message' => '', 'note' => null, 'attached' => 'AUTO-77'),
            ),
            'the attached contractor is the referenced one' => array(
                array('action' => 'updated', 'identifier' => 'A-1', 'document_identifier' => 'A-1'),
                array('action' => 'updated', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'the same contractor, whatever the spaces' => array(
                array('action' => 'updated', 'identifier' => ' A-1 ', 'document_identifier' => 'A-1 '),
                array('action' => 'updated', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'the same contractor as a number and a string' => array(
                array('action' => 'kept', 'identifier' => '5', 'document_identifier' => 5),
                array('action' => 'kept', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'a blank attached contractor' => array(
                array('action' => 'kept', 'identifier' => 'A-1', 'document_identifier' => '   '),
                array('action' => 'kept', 'message' => '', 'note' => null, 'attached' => ''),
            ),
            'no attached contractor' => array(
                array('action' => 'kept', 'identifier' => 'A-1'),
                array('action' => 'kept', 'message' => '', 'note' => null, 'attached' => ''),
            ),
        );
    }

    /**
     * @dataProvider contractorSteps
     */
    public function testTheContractorNoteOfTheStoredRequest(array $contractor, array $expected)
    {
        $r = $this->receipt(array('request_payload' => json_encode(array('SposobZaplaty' => 'PRZ', '_contractor' => $contractor))));

        $this->assertSame($expected, $r->contractorNote());
    }

    public function testTheContractorNoteOfThePayloadAsStored()
    {
        // the entry as the pipeline stored it, its own keys in any order; the answer's keys are fixed
        $r = $this->receipt(array('request_payload' => '{"_contractor":{"note":{"params":{"identifier":"PS-C5"},"code":"lookup_failed"},"message":"m","action":"fallback"}}'));

        $this->assertSame(array(
            'action' => 'fallback',
            'message' => 'm',
            'note' => array('params' => array('identifier' => 'PS-C5'), 'code' => 'lookup_failed'),
            'attached' => '',
        ), $r->contractorNote());
    }
}
