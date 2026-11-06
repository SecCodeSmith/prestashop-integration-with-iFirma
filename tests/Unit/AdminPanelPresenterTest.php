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
 * IfirmaAdminPanelPresenter (refactor-srp-splits 7.4): the state of a record and its label, the label of a
 * data change request status, the form fields of a request, the name of an employee and the contractor
 * note. The presenter gets its words from the module; these tests hand it words of their own ("L:...")
 * so that a word written into the presenter instead of read from the labels shows.
 */
class AdminPanelPresenterTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        // the presenter is loaded the way production loads it: through the module's require list
        IfirmaTestModuleLoader::loadModule();
        // building the module builds the context, whose employee is a load of its own
        Employee::$loads = array();
    }

    private function labels()
    {
        return array(
            'state' => array(
                IfirmaAdminPanelPresenter::STATE_HELD => 'L:held',
                IfirmaAdminPanelPresenter::STATE_SUSPECTED => 'L:suspected',
                IfirmaAdminPanelPresenter::STATE_UNKNOWN => 'L:unknown',
                IfirmaAdminPanelPresenter::STATE_ERROR => 'L:error',
                IfirmaAdminPanelPresenter::STATE_MISSING => 'L:missing',
                IfirmaAdminPanelPresenter::STATE_PAID => 'L:paid',
                IfirmaAdminPanelPresenter::STATE_ISSUED => 'L:issued',
                IfirmaAdminPanelPresenter::STATE_NOT_ISSUED => 'L:not issued',
            ),
            'request_status' => array(
                'pending' => 'L:pending',
                'approved' => 'L:approved',
                'rejected' => 'L:rejected',
                'cancelled' => 'L:cancelled',
            ),
            'request_fields' => array(
                'want_invoice_for_company' => 'L:invoice',
                'company' => 'L:company',
                'id_country' => 'L:country',
            ),
            'contractor' => array(
                'created' => 'L:created',
                'updated' => 'L:updated',
                'kept' => 'L:kept',
                'fallback' => 'L:fallback',
                'assigned' => 'L:assigned %s',
                'lookup_failed' => 'L:lookup of %1$s failed: %2$s',
                'update_failed' => 'L:update of %1$s failed: %2$s',
                'create_rejected' => 'L:create rejected: %s',
                'reference_rejected' => 'L:reference %1$s rejected: %2$s',
                'readback_failed' => 'L:not found: %1$s; search: %2$s',
                'ambiguous' => 'L:ambiguous',
                'search_failed' => 'L:search failed: %s',
                'found_nothing' => 'L:nothing',
                'attached' => 'L:attached %s',
            ),
        );
    }

    private function presenter()
    {
        return new IfirmaAdminPanelPresenter($this->labels());
    }

    private function receipt(array $props = array())
    {
        $r = new IfirmaReceipt();
        foreach ($props as $field => $value) {
            $r->$field = $value;
        }

        return $r;
    }

    private function employee($id, $firstname, $lastname)
    {
        $e = new Employee();
        $e->id = $id;
        $e->firstname = $firstname;
        $e->lastname = $lastname;

        return Employee::register($e);
    }

    // ---- state() and stateLabel() -------------------------------------------------------------

    public function testNoRecordIsNotIssued()
    {
        $this->assertSame('not_issued', $this->presenter()->state(null));
        $this->assertSame('not_issued', $this->presenter()->state(false));
    }

    /**
     * @return array[] case => array(status, ifirma_id, remote_missing, state)
     */
    public function states()
    {
        return array(
            'held' => array('held', '', 0, 'held'),
            'held, with a document id' => array('held', '77', 0, 'held'),
            'suspected' => array('duplicate_suspected', '', 0, 'suspected'),
            'suspected, with a document id' => array('duplicate_suspected', '77', 1, 'suspected'),
            'unknown' => array('unknown', '', 0, 'unknown'),
            'unknown, with a document id' => array('unknown', '77', 1, 'unknown'),
            'error' => array('error', '', 0, 'error'),
            'error, with a document id' => array('error', '77', 1, 'error'),
            'created, with a document' => array('created', '77', 0, 'issued'),
            'created, with a document iFirma lost' => array('created', '77', 1, 'missing'),
            'created, without a document' => array('created', '', 0, 'not_issued'),
            'created, without a document, flagged missing' => array('created', '', 1, 'not_issued'),
            'paid, with a document' => array('paid', '77', 0, 'paid'),
            'paid, with a document iFirma lost' => array('paid', '77', 1, 'missing'),
            'paid, without a document' => array('paid', '', 0, 'not_issued'),
            'a status of another version, with a document' => array('archived', '77', 0, 'issued'),
            'a status of another version, without a document' => array('archived', '', 0, 'not_issued'),
            'no status, with a document' => array(null, '77', 0, 'issued'),
        );
    }

    /**
     * @dataProvider states
     */
    public function testTheStateOfARecord($status, $ifirmaId, $missing, $state)
    {
        $r = $this->receipt(array('status' => $status, 'ifirma_id' => $ifirmaId, 'remote_missing' => $missing));

        $this->assertSame($state, $this->presenter()->state($r));
    }

    public function testTheStateConstantsAreTheStringsTheTemplateTests()
    {
        $this->assertSame(
            array('not_issued', 'held', 'suspected', 'unknown', 'error', 'missing', 'paid', 'issued'),
            array(
                IfirmaAdminPanelPresenter::STATE_NOT_ISSUED,
                IfirmaAdminPanelPresenter::STATE_HELD,
                IfirmaAdminPanelPresenter::STATE_SUSPECTED,
                IfirmaAdminPanelPresenter::STATE_UNKNOWN,
                IfirmaAdminPanelPresenter::STATE_ERROR,
                IfirmaAdminPanelPresenter::STATE_MISSING,
                IfirmaAdminPanelPresenter::STATE_PAID,
                IfirmaAdminPanelPresenter::STATE_ISSUED,
            )
        );
    }

    public function testTheLabelOfEveryState()
    {
        $p = $this->presenter();

        $this->assertSame('L:held', $p->stateLabel('held'));
        $this->assertSame('L:suspected', $p->stateLabel('suspected'));
        $this->assertSame('L:unknown', $p->stateLabel('unknown'));
        $this->assertSame('L:error', $p->stateLabel('error'));
        $this->assertSame('L:missing', $p->stateLabel('missing'));
        $this->assertSame('L:paid', $p->stateLabel('paid'));
        $this->assertSame('L:issued', $p->stateLabel('issued'));
        $this->assertSame('L:not issued', $p->stateLabel('not_issued'));
    }

    public function testAStateItDoesNotKnowReadsAsNotIssued()
    {
        $this->assertSame('L:not issued', $this->presenter()->stateLabel('bogus'));
        $this->assertSame('L:not issued', $this->presenter()->stateLabel(''));
        $this->assertSame('L:not issued', $this->presenter()->stateLabel(null));
    }

    // ---- requestStatusLabel() -----------------------------------------------------------------

    public function testTheLabelOfEveryRequestStatus()
    {
        $p = $this->presenter();

        $this->assertSame('L:pending', $p->requestStatusLabel('pending'));
        $this->assertSame('L:approved', $p->requestStatusLabel('approved'));
        $this->assertSame('L:rejected', $p->requestStatusLabel('rejected'));
        $this->assertSame('L:cancelled', $p->requestStatusLabel('cancelled'));
    }

    public function testARequestStatusItDoesNotKnowIsShownAsItIs()
    {
        $this->assertSame('archived', $this->presenter()->requestStatusLabel('archived'));
        $this->assertSame('Pending', $this->presenter()->requestStatusLabel('Pending'));
        $this->assertSame('', $this->presenter()->requestStatusLabel(''));
        $this->assertSame('', $this->presenter()->requestStatusLabel(null));
        $this->assertSame('0', $this->presenter()->requestStatusLabel(0));
    }

    // ---- requestFields() ----------------------------------------------------------------------

    public function testTheFieldsOfTheFormFollowTheOrderOfTheNamesWithTheirLabels()
    {
        $fields = $this->presenter()->requestFields(array('id_country', 'want_invoice_for_company', 'company'), array());

        $this->assertSame(array(
            array('name' => 'id_country', 'label' => 'L:country', 'value' => '', 'type' => 'text'),
            array('name' => 'want_invoice_for_company', 'label' => 'L:invoice', 'value' => '', 'type' => 'checkbox'),
            array('name' => 'company', 'label' => 'L:company', 'value' => '', 'type' => 'text'),
        ), $fields);
    }

    public function testAFieldWithoutALabelIsLabelledWithItsName()
    {
        $fields = $this->presenter()->requestFields(array('city'), array('city' => 'Warszawa'));

        $this->assertSame(array(array('name' => 'city', 'label' => 'city', 'value' => 'Warszawa', 'type' => 'text')), $fields);
    }

    public function testTheValuesAreStringsAndOnlyTheScalarOnesAreKept()
    {
        $fields = $this->presenter()->requestFields(
            array('company', 'want_invoice_for_company', 'id_country', 'city', 'address1', 'address2'),
            array('company' => array('a'), 'want_invoice_for_company' => true, 'id_country' => 14, 'city' => null, 'address1' => 0.5, 'unknown' => 'x')
        );

        $this->assertSame(array('', '1', '14', '', '0.5', ''), array_column($fields, 'value'));
    }

    public function testNoFieldsWithoutNames()
    {
        $this->assertSame(array(), $this->presenter()->requestFields(array(), array('company' => 'ACME')));
    }

    // ---- employeeName() -----------------------------------------------------------------------

    public function testTheNameOfAnEmployee()
    {
        $this->employee(3, 'Anna', 'Nowak');

        $this->assertSame('Anna Nowak', $this->presenter()->employeeName(3));
        $this->assertSame('Anna Nowak', $this->presenter()->employeeName('3'));
        $this->assertSame(array(3, 3), Employee::$loads);
    }

    public function testAnEmployeeWithOnlyOneNameIsNamedByIt()
    {
        $this->employee(4, 'Ewa', '');
        $this->employee(5, '', 'Kowalska');

        $this->assertSame('Ewa', $this->presenter()->employeeName(4));
        $this->assertSame('Kowalska', $this->presenter()->employeeName(5));
    }

    public function testAnEmployeeWithoutANameIsNamedByTheId()
    {
        $this->employee(6, '', '');
        $this->employee(7, '  ', ' ');

        $this->assertSame('#6', $this->presenter()->employeeName(6));
        $this->assertSame('#7', $this->presenter()->employeeName('7'));
        $this->assertSame('#9', $this->presenter()->employeeName(9), 'an employee that is not there');
        $this->assertSame('#8', $this->presenter()->employeeName('08'), 'the id as a number');
    }

    public function testNoEmployeeWhenThereIsNoId()
    {
        $p = $this->presenter();

        $this->assertSame('', $p->employeeName(null));
        $this->assertSame('', $p->employeeName(0));
        $this->assertSame('', $p->employeeName('0'));
        $this->assertSame('', $p->employeeName(''));
        $this->assertSame('', $p->employeeName(-3));
        $this->assertSame(array(), Employee::$loads, 'no employee is loaded');
    }

    // ---- contractorNote() ---------------------------------------------------------------------

    private function note($action, array $extra = array())
    {
        return $this->presenter()->contractorNote(array_merge(array('action' => $action, 'message' => '', 'note' => null, 'attached' => ''), $extra));
    }

    public function testNoStepNoNote()
    {
        $this->assertSame('', $this->presenter()->contractorNote(null));
    }

    public function testTheWordOfEachAction()
    {
        $this->assertSame('L:created', $this->note('created'));
        $this->assertSame('L:updated', $this->note('updated'));
        $this->assertSame('L:kept', $this->note('kept'));
        $this->assertSame('L:fallback', $this->note('fallback'));
    }

    public function testAnActionItDoesNotKnowHasNoNote()
    {
        $this->assertSame('', $this->note('bogus'));
        $this->assertSame('', $this->note('disabled', array('message' => 'x')));
        $this->assertSame('', $this->note('', array('message' => 'x')));
        $this->assertSame('', $this->note('1'));
        $this->assertSame('', $this->note('CREATED'));
    }

    public function testTheRawSentenceIsOnlyShownForAFallback()
    {
        $this->assertSame('L:created', $this->note('created', array('message' => 'raw')));
        $this->assertSame('L:updated', $this->note('updated', array('message' => 'raw')));
        $this->assertSame('L:kept', $this->note('kept', array('message' => 'raw')));
        $this->assertSame('L:fallback (raw)', $this->note('fallback', array('message' => 'raw')));
    }

    public function testACreatedContractorNamesTheIdentifierIFirmaAssigned()
    {
        $note = array('code' => 'assigned', 'params' => array('identifier' => 'IFI1909556979'));

        $this->assertSame('L:assigned IFI1909556979', $this->note('created', array('note' => $note, 'message' => 'raw')));
        $this->assertSame('L:assigned ', $this->note('created', array('note' => array('code' => 'assigned'))));
        $this->assertSame('L:updated', $this->note('updated', array('note' => $note)), 'only a created contractor has an assigned identifier');
        $this->assertSame('L:created', $this->note('created', array('note' => array('code' => 'other', 'params' => array('identifier' => 'X-1')))));
    }

    public function testTheSentenceOfEachFallbackCode()
    {
        $this->assertSame('L:lookup of PS-C5 failed: timeout', $this->note('fallback', array('message' => 'raw', 'note' => array('code' => 'lookup_failed', 'params' => array('identifier' => 'PS-C5', 'error' => 'timeout')))));
        $this->assertSame('L:update of PS-C5 failed: bad', $this->note('fallback', array('message' => 'raw', 'note' => array('code' => 'update_failed', 'params' => array('identifier' => 'PS-C5', 'error' => 'bad')))));
        $this->assertSame('L:create rejected: Zly kod', $this->note('fallback', array('message' => 'raw', 'note' => array('code' => 'create_rejected', 'params' => array('error' => 'Zly kod')))));
        $this->assertSame('L:reference PS-C5 rejected: nieprawidlowa', $this->note('fallback', array('message' => 'raw', 'note' => array('code' => 'reference_rejected', 'params' => array('identifier' => 'PS-C5', 'error' => 'nieprawidlowa')))));
    }

    public function testASentenceNeedsItsParametersButSurvivesWithoutThem()
    {
        $this->assertSame('L:lookup of  failed: ', $this->note('fallback', array('note' => array('code' => 'lookup_failed'))));
        $this->assertSame('L:lookup of  failed: ', $this->note('fallback', array('note' => array('code' => 'lookup_failed', 'params' => 'text'))));
        $this->assertSame('L:create rejected: 5', $this->note('fallback', array('note' => array('code' => 'create_rejected', 'params' => array('error' => 5)))));
    }

    public function testACodeWithoutWordingKeepsTheRawSentence()
    {
        $this->assertSame('L:fallback (old sentence)', $this->note('fallback', array('message' => 'old sentence', 'note' => array('code' => 'future_code', 'params' => array()))));
        $this->assertSame('L:fallback (old sentence)', $this->note('fallback', array('message' => 'old sentence', 'note' => array('params' => array('error' => 'x')))));
        $this->assertSame('L:fallback (old sentence)', $this->note('fallback', array('message' => 'old sentence', 'note' => 'lookup_failed')));
        $this->assertSame('L:fallback', $this->note('fallback', array('note' => array('code' => 'future_code'))));
    }

    public function testTheReadbackNoteListsEachAnswerOnce()
    {
        $miss = array('identifier' => 'PS-C1909', 'answer' => 'Kod 208: Nie znaleziono kontrahenta');
        $other = array('identifier' => '99', 'answer' => 'boom');

        $note = $this->note('fallback', array('note' => array('code' => 'readback_failed', 'params' => array('tried' => array($miss, $other, $miss), 'search' => 'none'))));

        $this->assertSame('L:not found: PS-C1909: Kod 208: Nie znaleziono kontrahenta; 99: boom; search: L:nothing', $note);
    }

    public function testTheReadbackNoteSkipsEntriesThatAreNotRecordsAndFillsMissingKeys()
    {
        $params = array('tried' => array('junk', 5, array('identifier' => 'PS-C1'), array('answer' => 'empty result'), array()), 'search' => 'none');

        $note = $this->note('fallback', array('note' => array('code' => 'readback_failed', 'params' => $params)));

        $this->assertSame('L:not found: PS-C1: ; : empty result; : ; search: L:nothing', $note);
    }

    public function testTheReadbackNoteWithoutEntries()
    {
        $this->assertSame('L:not found: ; search: L:nothing', $this->note('fallback', array('note' => array('code' => 'readback_failed', 'params' => array()))));
        $this->assertSame('L:not found: ; search: L:nothing', $this->note('fallback', array('note' => array('code' => 'readback_failed', 'params' => array('tried' => 'none')))));
    }

    public function testTheReadbackNoteSaysHowTheSearchWentOut()
    {
        $entry = array('identifier' => 'PS-C1', 'answer' => 'empty result');
        $note = function ($search, array $more = array()) use ($entry) {
            return $this->note('fallback', array('note' => array('code' => 'readback_failed', 'params' => array_merge(array('tried' => array($entry), 'search' => $search), $more))));
        };

        $this->assertSame('L:not found: PS-C1: empty result; search: L:ambiguous', $note('ambiguous'));
        $this->assertSame('L:not found: PS-C1: empty result; search: L:search failed: search timeout', $note('error', array('search_error' => 'search timeout')));
        $this->assertSame('L:not found: PS-C1: empty result; search: L:search failed: ', $note('error'));
        $this->assertSame('L:not found: PS-C1: empty result; search: L:nothing', $note('none', array('search_error' => 'ignored')));
        $this->assertSame('L:not found: PS-C1: empty result; search: L:nothing', $note('anything else'));
    }

    public function testAMissingSearchReadsAsNone()
    {
        $params = array('tried' => array(array('identifier' => 'PS-C1', 'answer' => 'x')));

        $this->assertSame('L:not found: PS-C1: x; search: L:nothing', $this->note('fallback', array('note' => array('code' => 'readback_failed', 'params' => $params))));
    }

    public function testTheAttachedContractorFollowsTheNote()
    {
        $this->assertSame('L:created L:attached AUTO-77', $this->note('created', array('attached' => 'AUTO-77')));
        $this->assertSame(
            'L:create rejected: Zly kod L:attached AUTO-77',
            $this->note('fallback', array('attached' => 'AUTO-77', 'note' => array('code' => 'create_rejected', 'params' => array('error' => 'Zly kod'))))
        );
        $this->assertSame('L:fallback (raw) L:attached AUTO-77', $this->note('fallback', array('message' => 'raw', 'attached' => 'AUTO-77')));
    }

    public function testTheAttachedContractorStandsAloneAfterAnActionWithoutNote()
    {
        $this->assertSame('L:attached AUTO-77', $this->note('bogus', array('attached' => 'AUTO-77')));
    }

    public function testTheNoteIsMadeOfTheLabelsItWasGiven()
    {
        $labels = $this->labels();
        foreach ($labels['contractor'] as $key => $word) {
            $labels['contractor'][$key] = str_replace('L:', 'M:', $word);
        }
        $p = new IfirmaAdminPanelPresenter($labels);

        $this->assertSame('M:created', $p->contractorNote(array('action' => 'created', 'message' => '', 'note' => null, 'attached' => '')));
        $this->assertSame('M:fallback (raw) M:attached X-1', $p->contractorNote(array('action' => 'fallback', 'message' => 'raw', 'note' => null, 'attached' => 'X-1')));
    }
}
