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

require_once __DIR__ . '/../smoke/SmokeSelection.php';

class SmokeSelectionTest extends TestCase
{
    private $known = array('J', 'A', 'A2', 'B', 'R', 'S');

    public function testNoVariablesSelectEveryScenario()
    {
        $selection = SmokeSelection::parse('', '', $this->known);

        $this->assertSame($this->known, $selection->selected());
        $this->assertSame(array(), $selection->skipped());
    }

    public function testLetterStringKeepsItsOldMeaning()
    {
        $selection = SmokeSelection::parse('abs', '', $this->known);

        $this->assertSame(array('A', 'B', 'S'), $selection->selected());
    }

    public function testLetterStringDoesNotSelectTheMultiCharacterScenario()
    {
        $selection = SmokeSelection::parse('A', '', $this->known);

        $this->assertFalse($selection->runs('A2'));
    }

    public function testASingleMultiCharacterNameIsSelectedAsItself()
    {
        $selection = SmokeSelection::parse('A2', '', $this->known);

        $this->assertSame(array('A2'), $selection->selected());
    }

    public function testCommaListSelectsMultiCharacterScenarios()
    {
        $selection = SmokeSelection::parse('B, a2 ,A', '', $this->known);

        $this->assertSame(array('A', 'A2', 'B'), $selection->selected());
    }

    public function testUnknownNameInTheOnlyListIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMOKE_ONLY names an unknown scenario: Z');

        SmokeSelection::parse('A,Z', '', $this->known);
    }

    public function testUnknownLetterInTheLetterStringIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMOKE_ONLY names an unknown scenario: Z');

        SmokeSelection::parse('ZZ', '', $this->known);
    }

    public function testSkipListRemovesScenariosAndReportsThemAsSkippedOnPurpose()
    {
        $selection = SmokeSelection::parse('', 'R', $this->known);

        $this->assertSame(array('J', 'A', 'A2', 'B', 'S'), $selection->selected());
        $this->assertSame(array('R'), $selection->skipped());
    }

    public function testANewScenarioRunsWithoutBeingListedWhenOnlyTheBurstIsSkipped()
    {
        $selection = SmokeSelection::parse('', 'R', array_merge($this->known, array('T')));

        $this->assertTrue($selection->runs('T'));
        $this->assertFalse($selection->runs('R'));
    }

    public function testSkipWinsOverOnly()
    {
        $selection = SmokeSelection::parse('A,B', 'B', $this->known);

        $this->assertSame(array('A'), $selection->selected());
        $this->assertSame(array('B'), $selection->skipped());
    }

    public function testUnknownNameInTheSkipListIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMOKE_SKIP names an unknown scenario: X');

        SmokeSelection::parse('', 'R,X', $this->known);
    }

    public function testDescriptionNamesSelectedAndSkippedScenarios()
    {
        $selection = SmokeSelection::parse('', 'R', $this->known);

        $this->assertSame('selected: J, A, A2, B, S; skipped on purpose: R', $selection->describe());
    }

    public function testDescriptionSaysNoneWasSkipped()
    {
        $selection = SmokeSelection::parse('A', '', $this->known);

        $this->assertSame('selected: A; skipped on purpose: none', $selection->describe());
    }

    public function testScenariosLeftOutBySelectionAreListed()
    {
        $selection = SmokeSelection::parse('A', 'R', $this->known);

        $this->assertSame(array('J', 'A2', 'B', 'S'), $selection->notSelected());
    }
}
