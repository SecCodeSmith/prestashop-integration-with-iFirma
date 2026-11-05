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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaMatchLabels.php';

/**
 * IfirmaMatchLabels, the one map from a match code (IfirmaContractorMap::BY_*) to its label, and the
 * map the module builds from its translated literals. How the screens word each code is pinned in
 * ContractorLabelWordingTest.
 */
class IfirmaMatchLabelsTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    /** A map of three codes with labels that cannot be mistaken for the codes. */
    private function labels()
    {
        return new IfirmaMatchLabels(array(
            'module' => 'M',
            'nip' => 'N',
            'manual' => 'H',
        ));
    }

    // ---- the map ---------------------------------------------------------------------------

    public function testALabelIsLookedUpByItsCode()
    {
        $labels = $this->labels();

        $this->assertSame('M', $labels->label('module'));
        $this->assertSame('N', $labels->label('nip'));
        $this->assertSame('H', $labels->label('manual'));
    }

    public function testACodeWithoutALabelIsShownAsItIs()
    {
        $labels = $this->labels();

        $this->assertSame('legacy-code', $labels->label('legacy-code'));
        $this->assertSame('', $labels->label(''));
    }

    public function testTheMapKeepsItsCodesInDisplayOrder()
    {
        $this->assertSame(array('module' => 'M', 'nip' => 'N', 'manual' => 'H'), $this->labels()->labels());
    }

    public function testEveryCodeButTheModulesOwnIsALink()
    {
        $this->assertSame(array('nip', 'manual'), $this->labels()->linkCodes());
    }

    public function testAnEmptyMapHasNothingToShow()
    {
        $labels = new IfirmaMatchLabels(array());

        $this->assertSame(array(), $labels->labels());
        $this->assertSame(array(), $labels->linkCodes());
        $this->assertSame('nip', $labels->label('nip'));
    }

    // ---- the map the module builds -----------------------------------------------------------

    /** The module with a translator that marks every string it translates. */
    private function translatingModule()
    {
        IfirmaTestModuleLoader::loadModule();
        $module = $this->getMockBuilder('Ifirma')->onlyMethods(array('l'))->getMock();
        $module->method('l')->willReturnCallback(function ($string) {
            return 'pl:' . $string;
        });

        return $module;
    }

    public function testTheModuleTranslatesTheLabelOfEveryCode()
    {
        $this->assertSame(array(
            'module' => 'pl:module contractor',
            'nip' => 'pl:NIP',
            'name' => 'pl:name',
            'email' => 'pl:e-mail',
            'import-nip' => 'pl:import, NIP',
            'import-email' => 'pl:import, e-mail',
            'import-name' => 'pl:import, name and postcode',
            'document' => 'pl:attached by iFirma to an issued document',
            'manual' => 'pl:manual link',
        ), $this->translatingModule()->matchLabels()->labels());
    }

    public function testTheModuleLabelsEveryCodeOfTheContractorMapAndNothingElse()
    {
        $codes = array();
        foreach ((new ReflectionClass('IfirmaContractorMap'))->getConstants() as $name => $value) {
            if (strpos($name, 'BY_') === 0) {
                $codes[] = $value;
            }
        }

        $labels = IfirmaTestModuleLoader::loadModule()->matchLabels();

        $this->assertCount(9, $codes);
        $this->assertEqualsCanonicalizing($codes, array_keys($labels->labels()));
    }

    public function testOnlyTheModulesOwnCodeIsNotALink()
    {
        $this->assertSame(
            array('nip', 'name', 'email', 'import-nip', 'import-email', 'import-name', 'document', 'manual'),
            IfirmaTestModuleLoader::loadModule()->matchLabels()->linkCodes()
        );
    }
}
