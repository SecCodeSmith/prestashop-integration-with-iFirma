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
 * IfirmaCreateResponse: the immutable answer of iFirma to the request that creates a document, as
 * IfirmaReceiptService::parseCreateResponse() reads it (refactor-srp-splits 5.2). How the service
 * reads an answer into it is pinned by CreateReceiptOutcomeTest.
 */
class IfirmaCreateResponseTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    public function testAnAcceptedAnswerCarriesTheIdentifierAndTheNumber()
    {
        $r = IfirmaCreateResponse::accepted('77', '7/2026');

        $this->assertTrue($r->isAccepted());
        $this->assertSame('77', $r->getIdentifier());
        $this->assertSame('7/2026', $r->getNumber());
        $this->assertNull($r->getMessage());
    }

    public function testAnAcceptedAnswerCanCarryNoNumberAndNoIdentifier()
    {
        $r = IfirmaCreateResponse::accepted('', null);

        $this->assertTrue($r->isAccepted());
        $this->assertSame('', $r->getIdentifier());
        $this->assertNull($r->getNumber());
    }

    public function testAnEmptyNumberIsNotTheSameAsNoNumber()
    {
        $this->assertSame('', IfirmaCreateResponse::accepted('77', '')->getNumber());
        $this->assertNull(IfirmaCreateResponse::accepted('77', null)->getNumber());
    }

    public function testTheIdentifierAndTheNumberAreKeptAsStrings()
    {
        $r = IfirmaCreateResponse::accepted(77, 12);

        $this->assertSame('77', $r->getIdentifier());
        $this->assertSame('12', $r->getNumber());
    }

    public function testARejectionCarriesTheReasonAndNothingElse()
    {
        $r = IfirmaCreateResponse::rejected('bad');

        $this->assertFalse($r->isAccepted());
        $this->assertSame('bad', $r->getMessage());
        $this->assertSame('', $r->getIdentifier());
        $this->assertNull($r->getNumber());
    }

    public function testARejectionWithoutAReasonHasNone()
    {
        $this->assertNull(IfirmaCreateResponse::rejected(null)->getMessage());
    }

    public function testARejectionKeepsAReasonThatIsNotAStringAsItCame()
    {
        foreach (array(12, 0, false, '', array('x')) as $reason) {
            $this->assertSame($reason, IfirmaCreateResponse::rejected($reason)->getMessage());
        }
    }

    /**
     * @return array field => the way to write it
     */
    public function fields()
    {
        return array('accepted' => array('accepted'), 'message' => array('message'), 'identifier' => array('identifier'), 'number' => array('number'));
    }

    /**
     * @dataProvider fields
     */
    public function testItsFieldsCannotBeWrittenFromOutside($field)
    {
        $r = IfirmaCreateResponse::accepted('77', '7/2026');

        try {
            $r->$field = 'changed';
            $this->fail('the ' . $field . ' field accepted a write from outside');
        } catch (Error $e) {
            $this->assertStringContainsString('private property', $e->getMessage());
        }

        $this->assertTrue($r->isAccepted());
        $this->assertNull($r->getMessage());
        $this->assertSame('77', $r->getIdentifier());
        $this->assertSame('7/2026', $r->getNumber());
    }

    public function testItIsBuiltOnlyThroughItsTwoNamedForms()
    {
        // immutable: the two named forms and the four readers are the whole public surface
        $this->assertSame(
            array('accepted', 'rejected', 'isAccepted', 'getMessage', 'getIdentifier', 'getNumber'),
            get_class_methods(IfirmaCreateResponse::class)
        );

        try {
            new IfirmaCreateResponse(true, null, '77', null);
            $this->fail('the constructor accepted a call from outside');
        } catch (Error $e) {
            $this->assertStringContainsString('private IfirmaCreateResponse::__construct()', $e->getMessage());
        }
    }
}
