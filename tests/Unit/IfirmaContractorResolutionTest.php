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
 * IfirmaContractorResolution: the immutable answer of IfirmaContractorService::ensure(). It
 * replaces the seven-key array ensure() used to return; toArray() gives that array back.
 */
class IfirmaContractorResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function note()
    {
        return array('code' => 'assigned', 'params' => array('identifier' => 'IFI-9'));
    }

    private function readback()
    {
        return array(
            'tried' => array(array('identifier' => 'PS-C7', 'answer' => 'Kod 208: Nie znaleziono kontrahenta'), array('identifier' => 'IFI-9', 'answer' => 'found')),
            'search' => 'none',
            'search_error' => '',
        );
    }

    private function emptyReadback()
    {
        return array('tried' => array(), 'search' => 'none', 'search_error' => '');
    }

    /** Every reader and the array form of a resolution that goes without an identifier. */
    private function assertAFallback(IfirmaContractorResolution $r, $message, $note, array $readback)
    {
        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertSame($message, $r->getMessage());
        $this->assertSame('', $r->getMatchedBy());
        $this->assertNull($r->getLinkedIdentifier());
        $this->assertSame($note, $r->getNote());
        $this->assertNull($r->getAssignedId());
        $this->assertSame($readback, $r->getReadback());
        $this->assertSame(
            array(
                'identifier' => null,
                'action' => 'fallback',
                'message' => $message,
                'matched_by' => '',
                'linked_identifier' => null,
                'note' => $note,
                'assigned_identifier' => null,
            ),
            $r->toArray()
        );
    }

    public function testEachReaderAnswersWithItsOwnField()
    {
        // every field has a value of its own, so a reader wired to another field shows
        $r = new IfirmaContractorResolution('IFI-9', 'created', 'some text', 'name', $this->note(), 'ASSIGNED-1', $this->readback());

        $this->assertSame('IFI-9', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertSame('some text', $r->getMessage());
        $this->assertSame('name', $r->getMatchedBy());
        $this->assertSame('IFI-9', $r->getLinkedIdentifier());
        $this->assertSame($this->note(), $r->getNote());
        $this->assertSame('ASSIGNED-1', $r->getAssignedId());
        $this->assertSame($this->readback(), $r->getReadback());
    }

    public function testToArrayIsExactlyTheAnswerEnsureUsedToGive()
    {
        $r = new IfirmaContractorResolution('IFI-9', 'created', '', 'module', $this->note(), 'IFI-9', $this->readback());

        // the seven keys, in this order, the readback is not part of it
        $this->assertSame(
            array(
                'identifier' => 'IFI-9',
                'action' => 'created',
                'message' => '',
                'matched_by' => 'module',
                'linked_identifier' => 'IFI-9',
                'note' => array('code' => 'assigned', 'params' => array('identifier' => 'IFI-9')),
                'assigned_identifier' => 'IFI-9',
            ),
            $r->toArray()
        );
    }

    public function testADisabledResolutionIsAllEmpty()
    {
        $r = new IfirmaContractorResolution(null, 'disabled', '');

        $this->assertSame(
            array('identifier' => null, 'action' => 'disabled', 'message' => '', 'matched_by' => '', 'linked_identifier' => null, 'note' => null, 'assigned_identifier' => null),
            $r->toArray()
        );
        $this->assertSame($this->emptyReadback(), $r->getReadback());
    }

    public function testAKeptContractorIsLinkedUnderItsIdentifier()
    {
        $r = new IfirmaContractorResolution('KOWALSKI-01', 'kept', '', 'import-nip');

        $this->assertSame(
            array('identifier' => 'KOWALSKI-01', 'action' => 'kept', 'message' => '', 'matched_by' => 'import-nip', 'linked_identifier' => 'KOWALSKI-01', 'note' => null, 'assigned_identifier' => null),
            $r->toArray()
        );
    }

    public function testWithoutAMatchNothingIsLinked()
    {
        // a contractor created for no customer has no match to name and no link to store
        $r = new IfirmaContractorResolution('PS-C7', 'created');

        $this->assertSame('PS-C7', $r->getIdentifier());
        $this->assertSame('', $r->getMatchedBy());
        $this->assertNull($r->getLinkedIdentifier());
        $this->assertSame('', $r->getMessage());
        $this->assertNull($r->getNote());
        $this->assertNull($r->getAssignedId());
    }

    public function testWithoutAnIdentifierThereIsNoMatchLinkOrAssignedIdentifier()
    {
        // a fallback keeps its message and note, whatever else it was given
        $r = new IfirmaContractorResolution(null, 'fallback', 'iFirma said no', 'nip', $this->note(), 'ASSIGNED-1', $this->readback());

        $this->assertNull($r->getIdentifier());
        $this->assertSame('fallback', $r->getAction());
        $this->assertSame('iFirma said no', $r->getMessage());
        $this->assertSame('', $r->getMatchedBy());
        $this->assertNull($r->getLinkedIdentifier());
        $this->assertNull($r->getAssignedId());
        $this->assertSame($this->note(), $r->getNote());
        $this->assertSame($this->readback(), $r->getReadback());
        $this->assertSame(
            array(
                'identifier' => null,
                'action' => 'fallback',
                'message' => 'iFirma said no',
                'matched_by' => '',
                'linked_identifier' => null,
                'note' => $this->note(),
                'assigned_identifier' => null,
            ),
            $r->toArray()
        );
    }

    public function fallbackProvider()
    {
        $note = array('code' => 'lookup_failed', 'params' => array('identifier' => 'PS-C7', 'error' => 'cURL error: timeout'));
        $readback = array(
            'tried' => array(array('identifier' => 'PS-C7', 'answer' => 'Kod 208: Nie znaleziono kontrahenta')),
            'search' => 'none',
            'search_error' => '',
        );
        $empty = array('tried' => array(), 'search' => 'none', 'search_error' => '');

        // message, note and read-back as given; then the message and the read-back as they read back
        return array(
            'a message alone' => array('cURL error: timeout', null, null, 'cURL error: timeout', $empty),
            'a message and a note' => array('cURL error: timeout', $note, null, 'cURL error: timeout', $empty),
            'a message, a note and the read-back of a created contractor' => array('cannot be read back', $note, $readback, 'cannot be read back', $readback),
            'no message' => array(null, $note, null, '', $empty),
            'a message that is not text' => array(42, null, null, '42', $empty),
        );
    }

    /**
     * @dataProvider fallbackProvider
     */
    public function testAFallbackBuiltTheLongWayHoldsOnlyItsMessageNoteAndReadback($message, $note, $readback, $text, $expectedReadback)
    {
        // the objects ensure() and the receipt service build for every answer without an identifier
        $r = new IfirmaContractorResolution(null, 'fallback', $message, '', $note, null, $readback);

        $this->assertAFallback($r, $text, $note, $expectedReadback);
    }

    public function testWithoutAReadbackItAnswersTheEmptyOne()
    {
        $this->assertSame($this->emptyReadback(), (new IfirmaContractorResolution('PS-C7', 'kept', '', 'module'))->getReadback());
        $this->assertSame($this->emptyReadback(), (new IfirmaContractorResolution('PS-C7', 'kept', '', 'module', null, null, null))->getReadback());
        // an empty list is a readback of its own, not the lack of one
        $own = array('tried' => array(), 'search' => 'ambiguous', 'search_error' => '');
        $this->assertSame($own, (new IfirmaContractorResolution(null, 'fallback', '', '', null, null, $own))->getReadback());
    }

    public function testValuesReadAsTheirDeclaredTypes()
    {
        // the readers of the old array cast the message to string; the object does that once, when it is built
        $loose = new IfirmaContractorResolution(5, 'kept', 42, 7, 'not a note', 99, 'not a readback');
        $this->assertSame('5', $loose->getIdentifier());
        $this->assertSame('kept', $loose->getAction());
        $this->assertSame('42', $loose->getMessage());
        $this->assertSame('7', $loose->getMatchedBy());
        $this->assertSame('5', $loose->getLinkedIdentifier());
        $this->assertNull($loose->getNote());
        $this->assertSame('99', $loose->getAssignedId());
        $this->assertSame($this->emptyReadback(), $loose->getReadback());

        $nulls = new IfirmaContractorResolution(null, 'disabled', null, null);
        $this->assertSame('', $nulls->getMessage());
        $this->assertSame('', $nulls->getMatchedBy());
    }

    public function testArraysAreHandedOutByValue()
    {
        $r = new IfirmaContractorResolution('IFI-9', 'created', '', 'module', $this->note(), 'IFI-9', $this->readback());

        $note = $r->getNote();
        $note['code'] = 'changed';
        $readback = $r->getReadback();
        $readback['search'] = 'changed';
        $array = $r->toArray();
        $array['identifier'] = 'changed';

        $this->assertSame('assigned', $r->getNote()['code']);
        $this->assertSame('none', $r->getReadback()['search']);
        $this->assertSame('IFI-9', $r->getIdentifier());
        $this->assertSame('IFI-9', $r->toArray()['identifier']);
    }

    public function fieldProvider()
    {
        return array(
            'identifier' => array('identifier'),
            'action' => array('action'),
            'message' => array('message'),
            'matchedBy' => array('matchedBy'),
            'linkedIdentifier' => array('linkedIdentifier'),
            'note' => array('note'),
            'assignedIdentifier' => array('assignedIdentifier'),
            'readback' => array('readback'),
        );
    }

    /**
     * @dataProvider fieldProvider
     */
    public function testNoFieldCanBeWrittenFromOutside($field)
    {
        $r = new IfirmaContractorResolution('IFI-9', 'created', 'some text', 'name', $this->note(), 'ASSIGNED-1', $this->readback());

        try {
            $r->$field = 'changed';
            $this->fail('the ' . $field . ' field accepted a write from outside');
        } catch (Error $e) {
            $this->assertStringContainsString('private property', $e->getMessage());
        }

        $this->assertSame('IFI-9', $r->getIdentifier());
        $this->assertSame('created', $r->getAction());
        $this->assertSame('some text', $r->getMessage());
        $this->assertSame('name', $r->getMatchedBy());
        $this->assertSame('IFI-9', $r->getLinkedIdentifier());
        $this->assertSame($this->note(), $r->getNote());
        $this->assertSame('ASSIGNED-1', $r->getAssignedId());
        $this->assertSame($this->readback(), $r->getReadback());
    }

    public function testItOffersNoMutator()
    {
        // immutable: the constructor, the eight readers and the array form are the whole public surface
        $this->assertSame(
            array(
                '__construct', 'getIdentifier', 'getAction', 'getMessage', 'getMatchedBy', 'getLinkedIdentifier',
                'getNote', 'getAssignedId', 'getReadback', 'toArray',
            ),
            get_class_methods(IfirmaContractorResolution::class)
        );
    }
}
