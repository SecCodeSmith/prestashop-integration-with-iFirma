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

require_once __DIR__ . '/../smoke/SmokeCheckFloor.php';

class SmokeCheckFloorTest extends TestCase
{
    public function testScenariosAtOrAboveTheirFloorPass()
    {
        $problems = SmokeCheckFloor::violations(array('A' => 12, 'B' => 9), array('A' => 12, 'B' => 7), array('A', 'B'));

        $this->assertSame(array(), $problems);
    }

    public function testScenarioUnderItsFloorIsReported()
    {
        $problems = SmokeCheckFloor::violations(array('A' => 11), array('A' => 12), array('A'));

        $this->assertSame(array('check floor: scenario A executed 11 checks, the committed floor is 12'), $problems);
    }

    public function testSelectedScenarioMissingFromTheFloorIsReported()
    {
        $problems = SmokeCheckFloor::violations(array('T' => 5), array('A' => 12), array('T'));

        $this->assertSame(array('check floor: scenario T has no entry in check-floor.json; add it on purpose'), $problems);
    }

    public function testSelectedScenarioThatExecutedNothingIsUnderItsFloor()
    {
        $problems = SmokeCheckFloor::violations(array(), array('A' => 12, 'R' => 50), array('A'));

        $this->assertSame(array('check floor: scenario A executed 0 checks, the committed floor is 12'), $problems);
    }

    public function testFloorFileIsWrittenFromAGreenRun()
    {
        $json = SmokeCheckFloor::render(array('B' => 4, 'A' => 12), 0, array());

        $this->assertSame(array('A' => 12, 'B' => 4), json_decode($json, true));
        $this->assertSame("\n", substr($json, -1));
    }

    public function testRegenerationKeepsEntriesOfScenariosThatDidNotRun()
    {
        $json = SmokeCheckFloor::render(array('A' => 13), 0, array('A' => 12, 'R' => 50));

        $this->assertSame(array('A' => 13, 'R' => 50), json_decode($json, true));
    }

    public function testWritingIsRefusedWhenAnyCheckFailed()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to write');

        SmokeCheckFloor::render(array('A' => 12), 1, array());
    }

    public function testReadingAMalformedFloorFileFailsLoudly()
    {
        $this->expectException(RuntimeException::class);

        SmokeCheckFloor::parse('{"A": "many"}');
    }

    public function testReadingAValidFloorFileReturnsTheCounts()
    {
        $this->assertSame(array('A' => 12), SmokeCheckFloor::parse('{"A": 12}'));
    }
}
