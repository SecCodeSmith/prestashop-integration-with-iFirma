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
 * The words the module shows for a contractor link and for a contractor action:
 *
 * - the "matched by" label of every match code (IfirmaContractorMap::BY_*) on the order panel, on the
 *   customer panel and in the answer of the register action, all three in the same words;
 * - the note of every contractor action (created, updated, kept, fallback) on the order panel.
 *
 * Every case goes through the real hook or action with a stored link, so the tests keep holding when
 * the label code moves between classes.
 */
class ContractorLabelWordingTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    /** @var IfirmaAdminActions */
    private $actions;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        $this->actions = new IfirmaAdminActions($this->module);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function vars()
    {
        return Context::getContext()->smarty->vars;
    }

    // ---- the panels: match code => label ---------------------------------------------------------

    /**
     * @return array[] code => array(code, label) as both panels word it
     */
    public function panelWording()
    {
        return array(
            'module' => array('module', 'module contractor'),
            'nip' => array('nip', 'NIP'),
            'name' => array('name', 'name'),
            'email' => array('email', 'e-mail'),
            'import-nip' => array('import-nip', 'import, NIP'),
            'import-email' => array('import-email', 'import, e-mail'),
            'import-name' => array('import-name', 'import, name and postcode'),
            'document' => array('document', 'attached by iFirma to an issued document'),
            'manual' => array('manual', 'manual link'),
        );
    }

    /**
     * @dataProvider panelWording
     */
    public function testOrderPanelWordsTheLinkOfEveryMatchCode($code, $label)
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', $code, 'Jan Kowalski');

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        $this->assertSame(array(
            'identifier' => 'KOWALSKI-01',
            'matched_by' => $code,
            'matched_by_label' => $label,
            'name' => 'Jan Kowalski',
        ), $this->vars()['ifirma_contractor_map']);
    }

    public function testOrderPanelShowsAnUnknownMatchCodeAsItIsStored()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'legacy-code', 'Jan Kowalski');

        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        $map = $this->vars()['ifirma_contractor_map'];
        $this->assertSame('legacy-code', $map['matched_by']);
        $this->assertSame('legacy-code', $map['matched_by_label']);
    }

    /**
     * @dataProvider panelWording
     */
    public function testCustomerPanelWordsTheLinkOfEveryMatchCode($code, $label)
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', $code, 'Jan Kowalski');

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertTrue($v['ifirma_customer_link']['linked']);
        $this->assertSame($code, $v['ifirma_customer_link']['matched_by']);
        $this->assertSame($label, $v['ifirma_customer_match_label']);
    }

    public function testCustomerPanelShowsAnUnknownMatchCodeAsItIsStored()
    {
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'legacy-code', 'Jan Kowalski');

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));

        $this->assertSame('legacy-code', $this->vars()['ifirma_customer_match_label']);
    }

    public function testCustomerPanelHasNoMatchLabelWithoutALink()
    {
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertFalse($v['ifirma_customer_link']['linked']);
        $this->assertSame('', $v['ifirma_customer_match_label']);
    }

    public function testCustomerPanelHandsEveryMatchCodeAndItsLabelToItsScript()
    {
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));

        // assertSame on arrays also pins the order of the codes
        $this->assertSame(array(
            'module' => 'module contractor',
            'nip' => 'NIP',
            'name' => 'name',
            'email' => 'e-mail',
            'import-nip' => 'import, NIP',
            'import-email' => 'import, e-mail',
            'import-name' => 'import, name and postcode',
            'document' => 'attached by iFirma to an issued document',
            'manual' => 'manual link',
        ), json_decode($this->vars()['ifirma_customer_match_labels_json'], true));
    }

    /**
     * The labels are translations and their JSON lands in an inline script, so a label with markup, an
     * ampersand or a quote of either kind must reach the page hex-escaped, never raw.
     */
    public function testCustomerPanelHexEscapesTheMarkupAndQuotesOfAMatchLabel()
    {
        $label = '<NIP> & \'NIP\' "NIP"';
        $module = $this->getMockBuilder(get_class($this->module))->onlyMethods(array('l'))->getMock();
        $module->method('l')->willReturnCallback(function ($string) use ($label) {
            return $string === 'NIP' ? $label : $string;
        });

        $module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $json = $this->vars()['ifirma_customer_match_labels_json'];

        // escaping loses nothing: the script still reads the translated text
        $this->assertSame($label, json_decode($json, true)['nip']);
        // each of < > & ' " is written as the escape of its JSON_HEX_* flag
        $escapes = array('<' => '\u003C', '>' => '\u003E', '&' => '\u0026', "'" => '\u0027', '"' => '\u0022');
        foreach ($escapes as $char => $escape) {
            $this->assertStringContainsString($escape, $json, $char . ' must be written as ' . $escape);
        }
        // and none is left raw; a raw quote of the label would show as \" (the JSON's own delimiters stay)
        foreach (array('<', '>', '&', "'", '\"') as $raw) {
            $this->assertStringNotContainsString($raw, $json, $raw . ' must not appear in the JSON');
        }
    }

    // ---- the register action: the note names the match in the words of the panels ---------------------

    /**
     * The codes the register action reports as a link to an existing contractor, with the words of
     * its "(matched by %s)" note. They are the words of the panels; only the module's own code is
     * not a link to an existing contractor.
     *
     * @return array[] code => array(code, label)
     */
    public function registerNoteWording()
    {
        $cases = $this->panelWording();
        unset($cases['module']);

        return $cases;
    }

    /**
     * Stores the link of customer 5 and lets iFirma answer with a record that needs no update, so
     * registering the customer is one lookup that keeps the stored contractor.
     */
    private function storedLinkThatIsUpToDate($identifier, $code)
    {
        IfirmaContractorMap::link(5, $identifier, $code, 'ACME');
        $record = array_merge(
            IfirmaReceiptService::build($this->module)->buildContractor(Order::$instances[15]),
            array('Identyfikator' => $identifier)
        );
        $this->t->queue('GET', '#kontrahenci/id/' . preg_quote($identifier, '#') . '\.json#', array('body' => json_encode(array(
            'response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => array($record)),
        ))));
    }

    /**
     * @dataProvider registerNoteWording
     */
    public function testRegisterNoteNamesTheMatchOfEveryLinkedCode($code, $label)
    {
        $this->storedLinkThatIsUpToDate('ACME-02', $code);

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('ACME-02', $r['data']['identifier']);
        $this->assertSame($code, $r['data']['matched_by']);
        $this->assertSame(
            'Linked to existing contractor ACME-02 (matched by ' . $label . '). Contractor ACME-02 already exists in iFirma and is up to date.',
            $r['message']
        );
        $this->assertSame(array('GET'), array_column($this->t->calls, 'method'));
    }

    public function testRegisterNoteTranslatesTheMatchThroughTheModule()
    {
        $module = $this->getMockBuilder(get_class($this->module))->onlyMethods(array('l'))->getMock();
        $module->method('l')->willReturnCallback(function ($string) {
            return 'pl:' . $string;
        });
        $this->storedLinkThatIsUpToDate('ACME-02', 'import-nip');

        $r = (new IfirmaAdminActions($module))->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertStringContainsString('(matched by pl:import, NIP)', $r['message']);
    }

    public function testRegisterNoteSaysNothingOfAContractorTheModuleMadeItself()
    {
        $this->storedLinkThatIsUpToDate('ACME-02', 'module');

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('module', $r['data']['matched_by']);
        $this->assertSame('Contractor ACME-02 already exists in iFirma and is up to date.', $r['message']);
    }

    public function testRegisterNoteSaysNothingOfAnUnknownMatchCode()
    {
        $this->storedLinkThatIsUpToDate('ACME-02', 'legacy-code');

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('legacy-code', $r['data']['matched_by']);
        $this->assertSame('Contractor ACME-02 already exists in iFirma and is up to date.', $r['message']);
    }

    public function testRegisterNoteSaysNothingWhenTheLinkPointsAtTheModuleIdentifier()
    {
        // the stored code would count as a link, but the contractor is the one the module names itself
        $this->storedLinkThatIsUpToDate('PS-C5', 'nip');

        $r = $this->actions->registerContractor(15);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('PS-C5', $r['data']['identifier']);
        $this->assertSame('nip', $r['data']['matched_by']);
        $this->assertSame('Contractor PS-C5 already exists in iFirma and is up to date.', $r['message']);
    }

    // ---- the order panel: contractor action => note --------------------------------------------------

    /**
     * @return array[] case => array(the _contractor record of a receipt, the note the panel shows)
     */
    public function contractorActionWording()
    {
        return array(
            'created' => array(array('action' => 'created', 'message' => ''), 'created'),
            'created ignores the raw sentence' => array(array('action' => 'created', 'message' => 'raw sentence'), 'created'),
            'created with a note that does not name an assigned identifier' => array(
                array('action' => 'created', 'message' => '', 'note' => array('code' => 'other', 'params' => array('identifier' => 'X-1'))),
                'created',
            ),
            'updated' => array(array('action' => 'updated', 'message' => ''), 'updated'),
            'updated ignores the raw sentence' => array(array('action' => 'updated', 'message' => 'raw sentence'), 'updated'),
            'kept' => array(array('action' => 'kept', 'message' => ''), 'kept'),
            'kept ignores the raw sentence' => array(array('action' => 'kept', 'message' => 'raw sentence'), 'kept'),
            'fallback' => array(array('action' => 'fallback', 'message' => ''), 'fallback'),
            'fallback keeps the raw sentence of an older record' => array(
                array('action' => 'fallback', 'message' => 'old sentence'),
                'fallback (old sentence)',
            ),
            'fallback with a note code that has no wording' => array(
                array('action' => 'fallback', 'message' => 'old sentence', 'note' => array('code' => 'future_code', 'params' => array())),
                'fallback (old sentence)',
            ),
            'disabled has no note' => array(array('action' => 'disabled', 'message' => 'x'), ''),
            'an unknown action has no note' => array(array('action' => 'bogus', 'message' => 'x'), ''),
        );
    }

    private function contractorNoteFor(array $contractor)
    {
        $this->makeReceipt(array('request_payload' => json_encode(array('SposobZaplaty' => 'PRZ', '_contractor' => $contractor))));
        $this->module->hookDisplayAdminOrderMainBottom(array('id_order' => 15));

        return $this->vars()['ifirma_contractor_note'];
    }

    /**
     * @dataProvider contractorActionWording
     */
    public function testOrderPanelWordsTheContractorAction(array $contractor, $expected)
    {
        $this->assertSame($expected, $this->contractorNoteFor($contractor));
    }

    public function testOrderPanelListsOnlyTheReadbackEntriesThatAreRecords()
    {
        $note = $this->contractorNoteFor(array(
            'action' => 'fallback',
            'message' => 'x',
            'note' => array('code' => 'readback_failed', 'params' => array(
                'tried' => array('junk', array('identifier' => 'PS-C1', 'answer' => 'empty result')),
                'search' => 'none',
                'search_error' => '',
            )),
        ));

        $this->assertSame('fallback (contractor created but not found: PS-C1: empty result; search: found nothing)', $note);
    }
}
