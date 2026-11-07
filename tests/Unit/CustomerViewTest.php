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

if (!class_exists('IfirmaDataRequest')) {
    require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequest.php';
}

class CustomerViewTest extends TestCase
{
    /** @var IfirmaCustomerView */
    private $view;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->view = new IfirmaCustomerView(new Module(), Context::getContext()->link);
    }

    private function order($total = 100.0)
    {
        $o = new Order();
        $o->id = 7;
        $o->total_paid_tax_incl = $total;

        return $o;
    }

    private function receipt(array $props = array())
    {
        $r = new IfirmaReceipt();
        $defaults = array(
            'status' => 'created', 'source' => 'module', 'ifirma_id' => 555, 'ifirma_number' => '12/2026',
            'issue_date' => '2026-11-01', 'date_add' => '2026-11-01 10:00:00', 'security_token' => 'tok123',
            'remote_paid_amount' => null, 'delivery_status' => 'none', 'remote_missing' => 0,
        );
        foreach (array_merge($defaults, $props) as $k => $v) {
            $r->$k = $v;
        }

        return $r;
    }

    private function pending()
    {
        $p = new IfirmaDataRequest();
        $p->id = 3;
        $p->id_ifirma_data_request = 3;
        $p->status = IfirmaDataRequest::STATUS_PENDING;
        $p->date_add = '2026-11-01 09:30:00';

        return $p;
    }

    public function testNotIssuedWithoutReceipt()
    {
        $v = $this->view->forOrder($this->order(), null, null);
        $this->assertSame('not_issued', $v['state']);
        $this->assertArrayNotHasKey('document', $v);
        $this->assertFalse($v['contact_shop']);
    }

    public function testNotIssuedTriggerNameAndCta()
    {
        $v = $this->view->forOrder($this->order(), null, null, array(
            'auto_issue_enabled' => true, 'trigger_state_name' => 'Payment accepted', 'data_request_enabled' => true,
        ));
        $this->assertSame('Payment accepted', $v['trigger_state_name']);
        $this->assertTrue($v['can_request_change']);
        $this->assertStringContainsString('datarequest', $v['request_url']);
        $this->assertStringContainsString('id_order=7', $v['request_url']);

        $off = $this->view->forOrder($this->order(), null, null, array(
            'auto_issue_enabled' => false, 'trigger_state_name' => 'Payment accepted', 'data_request_enabled' => false,
        ));
        $this->assertSame('', $off['trigger_state_name']);
        $this->assertFalse($off['can_request_change']);

        $other = $this->view->forOrder($this->order(), null, null, array('data_request_enabled' => true, 'has_other_pending' => true));
        $this->assertFalse($other['can_request_change']);
    }

    public function testHeldReceiptWithoutPendingRequestIsNotIssued()
    {
        $held = $this->receipt(array('status' => 'held', 'ifirma_id' => ''));
        $v = $this->view->forOrder($this->order(), $held, null, array('data_request_enabled' => true));
        $this->assertSame('not_issued', $v['state']);
        $this->assertArrayNotHasKey('document', $v);

        // a held record with an id-less pending request is still shown as held
        $this->assertSame('held', $this->view->forOrder($this->order(), $held, $this->pending(), array())['state']);
    }

    /**
     * A held record never reads as issued, even when it already carries an iFirma id.
     */
    public function testHeldReceiptThatAlreadyHasADocumentIdIsNotIssued()
    {
        $held = $this->receipt(array('status' => 'held', 'ifirma_id' => 555));

        $this->assertSame('not_issued', $this->view->resolveState($held, null));
        $v = $this->view->forOrder($this->order(), $held, null);
        $this->assertSame('not_issued', $v['state']);
        $this->assertArrayNotHasKey('document', $v);
    }

    public function testHeldShowsRequestDateAndCancelUrl()
    {
        $v = $this->view->forOrder($this->order(), $this->receipt(), $this->pending(), array('data_request_enabled' => true));
        $this->assertSame('held', $v['state']);
        $this->assertSame('2026-11-01 09:30:00', $v['pending_request']['date_add']);
        $this->assertSame(3, $v['pending_request']['id']);
        $this->assertStringContainsString('cancel=1', $v['cancel_url']);
        $this->assertFalse($v['can_request_change']);
        $this->assertArrayNotHasKey('document', $v);
    }

    public function testResolvedRequestIsNotHeld()
    {
        $p = $this->pending();
        $p->status = IfirmaDataRequest::STATUS_APPROVED;
        $this->assertSame('not_issued', $this->view->resolveState(null, $p));
    }

    /**
     * @dataProvider verifyingProvider
     */
    public function testVerifyingStatuses($status)
    {
        $r = $this->receipt(array('status' => $status, 'ifirma_id' => null, 'last_error' => 'boom'));
        $this->assertSame('verifying', $this->view->forOrder($this->order(), $r, null)['state']);
    }

    public function verifyingProvider()
    {
        return array(array('duplicate_suspected'), array('unknown'));
    }

    public function testErrorWithoutDocumentIsNotIssuedAndHidesError()
    {
        $r = $this->receipt(array('status' => 'error', 'ifirma_id' => null, 'last_error' => 'SECRET-ERR-TEXT'));
        $v = $this->view->forOrder($this->order(), $r, null);
        $this->assertSame('not_issued', $v['state']);
        $this->assertStringNotContainsString('SECRET-ERR-TEXT', json_encode($v));
    }

    /**
     * An error record is not shown as issued, even when it already carries an iFirma id.
     */
    public function testErrorReceiptThatAlreadyHasADocumentIdIsNotIssued()
    {
        $r = $this->receipt(array('status' => 'error', 'ifirma_id' => 555, 'last_error' => 'boom'));

        $this->assertSame('not_issued', $this->view->resolveState($r, null));
        $v = $this->view->forOrder($this->order(), $r, null);
        $this->assertSame('not_issued', $v['state']);
        $this->assertArrayNotHasKey('document', $v);
    }

    public function testExactlyOneStateForAllCombinations()
    {
        $valid = array('not_issued', 'held', 'verifying', 'issued', 'missing');
        $receipts = array(null);
        foreach (array('created', 'paid', 'error', 'unknown', 'duplicate_suspected') as $st) {
            foreach (array(null, 55) as $id) {
                foreach (array(0, 1) as $missing) {
                    $receipts[] = $this->receipt(array('status' => $st, 'ifirma_id' => $id, 'remote_missing' => $missing));
                }
            }
        }
        foreach ($receipts as $r) {
            foreach (array(null, $this->pending()) as $p) {
                $state = $this->view->resolveState($r, $p);
                $this->assertContains($state, $valid);
                $this->assertSame($state, $this->view->forOrder($this->order(), $r, $p)['state']);
            }
        }
    }

    public function testIssuedPaidAndSent()
    {
        $r = $this->receipt(array('remote_paid_amount' => 100.0, 'delivery_status' => 'sent', 'delivered_at' => '2026-11-02 08:00:00'));
        $v = $this->view->forOrder($this->order(100.0), $r, null);
        $d = $v['document'];
        $this->assertSame('issued', $v['state']);
        $this->assertSame('12/2026', $d['number']);
        $this->assertTrue($d['number_known']);
        $this->assertSame('2026-11-01', $d['issue_date']);
        $this->assertTrue($d['paid']);
        $this->assertTrue($d['sent']);
        $this->assertSame('2026-11-02 08:00:00', $d['sent_at']);
        $this->assertStringContainsString('token=tok123', $d['download_url']);
        $this->assertStringContainsString('/receipt', $d['download_url']);
        $this->assertFalse($d['is_external']);
        $this->assertTrue($v['contact_shop']);
    }

    public function testKsefNumberIsExposedWhenKnown()
    {
        $v = $this->view->forOrder($this->order(), $this->receipt(array('ksef_number' => '1111112228-20260702-6505FF400000-26')), null);
        $this->assertSame('1111112228-20260702-6505FF400000-26', $v['document']['ksef_number']);

        $v = $this->view->forOrder($this->order(), $this->receipt(), null);
        $this->assertSame('', $v['document']['ksef_number']);
    }

    public function testIssuedUnpaidNotSent()
    {
        $r = $this->receipt(array('remote_paid_amount' => 40.0));
        $d = $this->view->forOrder($this->order(100.0), $r, null)['document'];
        $this->assertFalse($d['paid']);
        $this->assertFalse($d['sent']);
        $this->assertSame('', $d['sent_at']);
    }

    public function testPaidFallsBackToStatusWhenNoRemoteAmount()
    {
        $d = $this->view->forOrder($this->order(), $this->receipt(array('status' => 'paid')), null)['document'];
        $this->assertTrue($d['paid']);
    }

    public function testToleranceAndRemoteSent()
    {
        $r = $this->receipt(array('remote_paid_amount' => 99.995, 'remote_sent' => 1));
        $d = $this->view->forOrder($this->order(100.0), $r, null)['document'];
        $this->assertTrue($d['paid']);
        $this->assertTrue($d['sent']);
    }

    public function testNumberUnknownFallsBackToId()
    {
        $r = $this->receipt(array('ifirma_number' => null, 'ifirma_id' => 987, 'issue_date' => null));
        $d = $this->view->forOrder($this->order(), $r, null)['document'];
        $this->assertFalse($d['number_known']);
        $this->assertSame('Document #987', $d['number']);
        $this->assertSame('2026-11-01', $d['issue_date']);
    }

    public function testExternalDocumentHasSameFields()
    {
        $d = $this->view->forOrder($this->order(), $this->receipt(array('source' => 'external')), null)['document'];
        $this->assertTrue($d['is_external']);
        $this->assertNotEmpty($d['download_url']);
        $this->assertSame('12/2026', $d['number']);
    }

    /** Spec customer-receipt-visibility, "Generator unavailable": a record without a token offers no download. */
    public function testARecordWithoutATokenHasNoDownloadUrl()
    {
        $v = $this->view->forOrder($this->order(), $this->receipt(array('security_token' => '')), null);
        $this->assertSame('issued', $v['state']);
        $this->assertNull($v['document']['download_url']);
    }

    public function testMissingHasNoDownloadUrl()
    {
        $v = $this->view->forOrder($this->order(), $this->receipt(array('remote_missing' => 1)), null);
        $this->assertSame('missing', $v['state']);
        $this->assertNull($v['document']['download_url']);
        $this->assertTrue($v['contact_shop']);
        $this->assertStringNotContainsString('tok123', json_encode($v));
    }

    public function testViewModelNeverLeaksInternalData()
    {
        $r = $this->receipt(array(
            'status' => 'duplicate_suspected', 'ifirma_id' => null,
            'last_error' => 'LAST-ERROR-TEXT', 'sync_error' => 'SYNC-ERROR-TEXT',
            'duplicate_candidates' => '[{"number":"CAND-99/2026"}]',
            'request_payload' => 'REQ-PAYLOAD', 'response_payload' => 'RESP-PAYLOAD',
        ));
        $issued = $this->receipt(array(
            'last_error' => 'LAST-ERROR-TEXT', 'sync_error' => 'SYNC-ERROR-TEXT',
            'duplicate_candidates' => '[{"number":"CAND-99/2026"}]',
            'request_payload' => 'REQ-PAYLOAD', 'response_payload' => 'RESP-PAYLOAD',
        ));
        foreach (array($r, $issued) as $rec) {
            $v = $this->view->forOrder($this->order(), $rec, null);
            $json = json_encode($v);
            foreach (array('LAST-ERROR-TEXT', 'SYNC-ERROR-TEXT', 'CAND-99', 'REQ-PAYLOAD', 'RESP-PAYLOAD',
                'last_error', 'sync_error', 'duplicate_candidates', 'request_payload', 'response_payload') as $needle) {
                $this->assertStringNotContainsString($needle, $json);
            }
        }
    }

    public function testForAccountListsIssuedAndPending()
    {
        $rows = array(
            array('ifirma_id' => 1, 'ifirma_number' => 'A/1', 'status' => 'paid', 'security_token' => 't1', 'source' => 'module', 'issue_date' => '2026-10-01', 'remote_missing' => 0),
            array('ifirma_id' => 2, 'ifirma_number' => '', 'status' => 'created', 'security_token' => 't2', 'source' => 'external', 'date_add' => '2026-10-02 10:00:00', 'remote_missing' => 0),
            array('ifirma_id' => 3, 'ifirma_number' => 'GONE', 'status' => 'created', 'security_token' => 't3', 'remote_missing' => 1),
            array('ifirma_id' => null, 'ifirma_number' => 'NOID', 'status' => 'unknown', 'security_token' => 't4'),
        );
        $a = $this->view->forAccount($rows, 1);
        $this->assertCount(2, $a['receipts']);
        $this->assertSame(1, $a['pending_count']);
        $this->assertTrue($a['visible']);
        $this->assertSame('A/1', $a['receipts'][0]['number']);
        $this->assertSame('Document #2', $a['receipts'][1]['number']);
        $this->assertTrue($a['receipts'][1]['is_external']);
        $this->assertStringContainsString('token=t1', $a['receipts'][0]['download_url']);
        $this->assertStringNotContainsString('GONE', json_encode($a));
        $this->assertStringNotContainsString('t3', json_encode($a['receipts']));
    }

    public function testForAccountSkipsErrorRowsThatCarryADocumentId()
    {
        $rows = array(
            array('ifirma_id' => 9, 'ifirma_number' => 'ERR/9', 'status' => 'error', 'security_token' => 'tok-err', 'source' => 'module', 'issue_date' => '2026-10-03', 'remote_missing' => 0),
            array('ifirma_id' => 1, 'ifirma_number' => 'A/1', 'status' => 'created', 'security_token' => 't1', 'source' => 'module', 'issue_date' => '2026-10-01', 'remote_missing' => 0),
        );

        $a = $this->view->forAccount($rows, 0);

        $this->assertCount(1, $a['receipts']);
        $this->assertSame('A/1', $a['receipts'][0]['number']);
        $this->assertStringNotContainsString('ERR/9', json_encode($a));
        $this->assertStringNotContainsString('tok-err', json_encode($a));
    }

    public function testForAccountLabelsPaidAndIssuedRows()
    {
        $rows = array(
            array('ifirma_id' => 1, 'ifirma_number' => 'A/1', 'status' => 'paid', 'security_token' => 't1', 'source' => 'module', 'issue_date' => '2026-10-01', 'remote_missing' => 0),
            array('ifirma_id' => 2, 'ifirma_number' => 'A/2', 'status' => 'created', 'security_token' => 't2', 'source' => 'module', 'issue_date' => '2026-10-02', 'remote_missing' => 0),
        );

        $receipts = $this->view->forAccount($rows, 0)['receipts'];

        $this->assertSame(array('paid', 'created'), array_column($receipts, 'status'));
        $this->assertSame(array('Paid', 'Issued'), array_column($receipts, 'status_label'));
    }

    public function testForAccountNothingIsInvisible()
    {
        $a = $this->view->forAccount(array(), 0);
        $this->assertFalse($a['visible']);
        $this->assertSame(array(), $a['receipts']);
        $this->assertTrue($this->view->forAccount(array(), 2)['visible']);
    }

    public function testStateLabelIsNonEmptyForEveryState()
    {
        foreach (array('not_issued', 'held', 'verifying', 'issued', 'missing') as $s) {
            $this->assertNotSame('', $this->view->stateLabel($s));
        }
    }

    // ---- orderOptions(): the options of the order page, read from the configuration ------------------

    private function registerState($id, $name)
    {
        $state = new OrderState();
        $state->id = $id;
        $state->name = $name;
        OrderState::register($state);
    }

    /**
     * @param string     $key
     * @param mixed|null $value null leaves the key out of the configuration
     */
    private function setting($key, $value)
    {
        if ($value === null) {
            Configuration::deleteByName($key);
        } else {
            Configuration::updateValue($key, $value);
        }
    }

    public function testOrderOptionsAreTheConfiguredOnes()
    {
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 1);
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', '5');
        Configuration::updateValue('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', '0.01');
        $this->registerState(5, 'Payment accepted');

        // assertSame on an array also pins the keys, their order and the types of the values
        $this->assertSame(array(
            'data_request_enabled' => true,
            'auto_issue_enabled' => true,
            'trigger_state_name' => 'Payment accepted',
            'tolerance' => '0.01',
            'has_other_pending' => false,
        ), $this->view->orderOptions());
    }

    public function testOrderOptionsOfAShopWithNothingConfigured()
    {
        $this->assertSame(array(
            'data_request_enabled' => false,
            'auto_issue_enabled' => false,
            'trigger_state_name' => '',
            'tolerance' => false,
            'has_other_pending' => false,
        ), $this->view->orderOptions());
        $this->assertSame(array(), OrderState::$loads);
    }

    public function testTheToleranceIsHandedOverAsConfigured()
    {
        Configuration::updateValue('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', 0.5);

        $this->assertSame(0.5, $this->view->orderOptions()['tolerance']);
    }

    /**
     * @return array[] label => array(allow data request setting (null = not set), enabled)
     */
    public function dataRequestSettings()
    {
        return array(
            'on' => array(1, true),
            'on, saved as text' => array('1', true),
            'off' => array(0, false),
            'off, saved as text' => array('0', false),
            'not set' => array(null, false),
        );
    }

    /**
     * @dataProvider dataRequestSettings
     */
    public function testTheDataRequestOptionIsAFlag($setting, $enabled)
    {
        $this->setting('IFIRMA_ALLOW_DATA_REQUEST', $setting);

        $this->assertSame($enabled, $this->view->orderOptions()['data_request_enabled']);
    }

    /**
     * @return array[] label => array(trigger state setting (null = not set), auto issue, name, ids loaded)
     */
    public function triggerStateSettings()
    {
        return array(
            'not set' => array(null, false, '', array()),
            'zero' => array(0, false, '', array()),
            'zero, saved as text' => array('0', false, '', array()),
            'negative' => array(-1, false, '', array()),
            'not a number' => array('abc', false, '', array()),
            'the lowest state id' => array(1, true, '', array(1)),
            'a state' => array(5, true, 'Payment accepted', array(5)),
            'a state, saved as text' => array('5', true, 'Payment accepted', array(5)),
        );
    }

    /**
     * @dataProvider triggerStateSettings
     */
    public function testTheTriggerStateSwitchesAutoIssueOnAndIsNamed($setting, $autoIssue, $name, $loaded)
    {
        $this->registerState(5, 'Payment accepted');
        $this->setting('IFIRMA_AUTO_CREATE_STATE', $setting);
        OrderState::$loads = array();

        $options = $this->view->orderOptions();

        $this->assertSame($autoIssue, $options['auto_issue_enabled']);
        $this->assertSame($name, $options['trigger_state_name']);
        $this->assertSame($loaded, OrderState::$loads, 'only a state that is set is looked up, as a number');
    }

    /**
     * @return array[] label => array(name of the state, id of the language of the shop, name shown)
     */
    public function triggerStateNames()
    {
        $names = array(1 => 'Paid', 2 => 'Zaplacono');

        return array(
            'a plain name' => array('Payment accepted', 1, 'Payment accepted'),
            'a plain name, another language' => array('Payment accepted', 2, 'Payment accepted'),
            'a name that is not text' => array(123, 1, '123'),
            'the name in the language of the shop' => array($names, 1, 'Paid'),
            'the name in the other language of the shop' => array($names, 2, 'Zaplacono'),
            'the language id is text' => array($names, '2', 'Zaplacono'),
            'the first name when the language has none' => array(array(1 => 'Paid', 3 => 'Bezahlt'), 2, 'Paid'),
            'the first of the list, not the lowest id' => array(array(3 => 'Bezahlt', 1 => 'Paid'), 2, 'Bezahlt'),
            'a language without text counts as none' => array(array(3 => 'Bezahlt', 1 => null), 1, 'Bezahlt'),
            'a language with an empty text keeps it' => array(array(3 => 'Bezahlt', 1 => ''), 1, ''),
            'no names at all' => array(array(), 1, ''),
            'a state without a name' => array(null, 1, ''),
        );
    }

    /**
     * @dataProvider triggerStateNames
     */
    public function testTheTriggerStateIsNamedInTheLanguageOfTheShop($name, $idLang, $shown)
    {
        $this->registerState(5, $name);
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 5);
        Context::getContext()->language->id = $idLang;

        $this->assertSame($shown, $this->view->orderOptions()['trigger_state_name']);
        $this->assertSame(array(5), OrderState::$loads);
        $this->assertSame(array((int) $idLang), OrderState::$langs, 'the state is read in the language of the context, as a number');
    }

    /**
     * Module::$context is protected in PrestaShop and in the stub, so the language has to come from the context
     * itself and never from the module the view was given. The module here holds a context of its own, a blank
     * one without a language.
     */
    public function testTheLanguageComesFromTheContextAndNotFromTheModule()
    {
        $view = new IfirmaCustomerView(new Module(null, new Context()), Context::getContext()->link);
        $this->registerState(5, array(1 => 'Paid', 2 => 'Zaplacono'));
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 5);
        Context::getContext()->language->id = 2;

        $this->assertSame('Zaplacono', $view->orderOptions()['trigger_state_name']);
        $this->assertSame(array(2), OrderState::$langs);
    }

    public function testAStateThatIsNotRegisteredHasNoName()
    {
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 9);

        $options = $this->view->orderOptions();

        $this->assertTrue($options['auto_issue_enabled']);
        $this->assertSame('', $options['trigger_state_name']);
        $this->assertSame(array(9), OrderState::$loads);
    }

    public function testOrderOptionsAreTheContextForOrderTakes()
    {
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 1);
        Configuration::updateValue('IFIRMA_AUTO_CREATE_STATE', 5);
        Configuration::updateValue('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', '0.5');
        $this->registerState(5, 'Payment accepted');

        $notIssued = $this->view->forOrder($this->order(), null, null, $this->view->orderOptions());
        $this->assertTrue($notIssued['can_request_change']);
        $this->assertTrue($notIssued['auto_issue_enabled']);
        $this->assertSame('Payment accepted', $notIssued['trigger_state_name']);

        // 99.6 of 100.0 is paid within half a zloty, and not within the default cent
        $paid = $this->receipt(array('remote_paid_amount' => 99.6));
        $this->assertTrue($this->view->forOrder($this->order(100.0), $paid, null, $this->view->orderOptions())['document']['paid']);
        $this->assertFalse($this->view->forOrder($this->order(100.0), $paid, null)['document']['paid']);
    }
}
