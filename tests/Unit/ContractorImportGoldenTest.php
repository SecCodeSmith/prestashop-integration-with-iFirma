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
 * Golden comparison of the contractor import.
 *
 * tests/fixtures/contractor-import/golden.json holds the scenarios of ContractorImportTest next to
 * what IfirmaContractorImport returned and stored for them before match() was split into
 * IfirmaCustomerIndex and IfirmaContractorMatcher (refactor-srp-splits 3.2): the full report of
 * every match() round, the number of mappings apply() wrote, the writes themselves and the links
 * left in the contractor map, and the full report of import(). Reports are compared key for key and
 * type for type, so a changed key order, count or cast fails here.
 */
class ContractorImportGoldenTest extends TestCase
{
    use IfirmaContractorMapDb;

    /** @var IfirmaContractorImport */
    private $import;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorImport.php';
        $this->import = new IfirmaContractorImport();
        $this->scriptContractorMapDb();
    }

    /**
     * @return array scenario name => array(scenario) for the scenarios that run match() and apply()
     */
    public function matchScenarios()
    {
        return $this->scenarios(false);
    }

    /**
     * @return array scenario name => array(scenario) for the scenarios that run import()
     */
    public function importScenarios()
    {
        return $this->scenarios(true);
    }

    /**
     * @param bool $import
     * @return array
     */
    private function scenarios($import)
    {
        $golden = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/contractor-import/golden.json'), true);
        $cases = array();
        foreach ($golden['scenarios'] as $scenario) {
            if (isset($scenario['import']) === $import) {
                $cases[$scenario['name']] = array($scenario);
            }
        }

        return $cases;
    }

    private function seedShop(array $scenario)
    {
        $db = Db::getInstance();
        $db->whenExecuteS('/FROM `ps_customer`/', $scenario['customers']);
        $db->whenExecuteS('/FROM `ps_address`/', $scenario['addresses']);
        foreach ($scenario['mappings'] as $stored) {
            IfirmaContractorMap::link($stored['id_customer'], $stored['ifirma_identifier'], $stored['matched_by']);
        }
        IfirmaTestState::clearWrites();
    }

    /**
     * @return array the persisted writes of the contractor map: op and the stored fields
     */
    private function writes()
    {
        $out = array();
        foreach (IfirmaTestState::writes('ifirma_contractor_map') as $write) {
            $out[] = array(
                'op' => $write['op'],
                'id_customer' => $write['row']['id_customer'],
                'ifirma_identifier' => $write['row']['ifirma_identifier'],
                'matched_by' => $write['row']['matched_by'],
                'ifirma_name' => $write['row']['ifirma_name'],
            );
        }

        return $out;
    }

    /**
     * @return array id_customer => the stored fields, by customer
     */
    private function storedLinks()
    {
        $out = array();
        foreach ($this->mapRows() as $stored) {
            $out[$stored['id_customer']] = array(
                'ifirma_identifier' => $stored['ifirma_identifier'],
                'matched_by' => $stored['matched_by'],
                'ifirma_name' => $stored['ifirma_name'],
            );
        }
        ksort($out);

        return $out;
    }

    /**
     * @dataProvider matchScenarios
     */
    public function testMatchAndApplyReproduceTheCapturedRounds(array $scenario)
    {
        $this->seedShop($scenario);

        foreach ($scenario['rounds'] as $number => $expected) {
            IfirmaTestState::clearWrites();

            $report = $this->import->match($scenario['rows']);
            $written = $this->import->apply($report);

            $this->assertSame($expected['report'], $report, 'round ' . ($number + 1) . ': report');
            $this->assertSame($expected['written'], $written, 'round ' . ($number + 1) . ': mappings written');
            $this->assertSame($expected['writes'], $this->writes(), 'round ' . ($number + 1) . ': contractor map writes');
        }
        $this->assertSame($scenario['map'], $this->storedLinks());
    }

    /**
     * @dataProvider importScenarios
     */
    public function testImportReproducesTheCapturedReport(array $scenario)
    {
        $this->seedShop($scenario);
        $content = isset($scenario['import']['file'])
            ? file_get_contents(dirname(__DIR__) . '/fixtures/' . $scenario['import']['file'])
            : $scenario['import']['content'];

        $report = $this->import->import($content);

        $this->assertSame($scenario['report'], $report);
        $this->assertSame($scenario['writes'], $this->writes());
        $this->assertSame($scenario['map'], $this->storedLinks());
    }

    public function testTheGoldenCoversEveryMatchScenarioOfContractorImportTest()
    {
        $covered = array();
        foreach (array_merge($this->matchScenarios(), $this->importScenarios()) as $case) {
            $covered[] = $case[0]['from'];
        }

        foreach (array(
            'testMatchesByNipThenEmail',
            'testMatchesByNameAndPostcode',
            'testAmbiguousNameAndPostcodeIsNotLinked',
            'testNipOutranksEmailAndFirstEqualMatchWins',
            'testFirstRowWinsAtEqualStrength',
            'testStrongerMatchReplacesAnExistingWeakerMapping',
            'testEqualOrWeakerMatchKeepsTheExistingMapping',
            'testReimportIsIdempotent',
            'testUnmatchedListIsCappedAtTwenty',
            'testApplyWritesMappingsWithTheContractorName',
            'testImportOfTheFixtureEndToEnd',
            'testImportRefusesAFileWithoutIdentifierColumn',
        ) as $test) {
            $this->assertContains('ContractorImportTest::' . $test, $covered);
        }
    }
}
