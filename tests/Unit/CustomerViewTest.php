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
}
