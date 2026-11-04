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

class KsefServiceTest extends TestCase
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaKsefService */
    private $svc;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->svc = new IfirmaKsefService($client, new Module());

        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_address_invoice = 7;
        Order::register($o);
    }

    private function address($vat, $dni = '')
    {
        $a = new Address();
        $a->id = 7;
        $a->id_customer = 5;
        $a->vat_number = $vat;
        $a->dni = $dni;
        Address::register($a);
    }

    private function receipt($nip = null, $type = 'fakturakraj')
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->document_type = $type;
        $r->ifirma_id = '77';
        $r->ifirma_number = '7/2026';
        $r->status = 'created';
        if ($nip !== null) {
            $r->request_payload = json_encode(array('Kontrahent' => array('Nazwa' => 'ACME', 'NIP' => $nip)));
        }
        $r->save();

        return $r;
    }

    private function row()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return reset($rows);
    }

    private function order()
    {
        return new Order(15);
    }

    private function kod($kod, $info = '')
    {
        return array('body' => json_encode(array('response' => array('Kod' => $kod, 'Informacja' => $info))));
    }

    public function testModeDefaultsToB2b()
    {
        $this->assertSame('b2b', $this->svc->mode());
    }

    public function testModeNormalisation()
    {
        foreach (array('off' => 'off', 'ALL' => 'all', ' b2b ' => 'b2b', 'bogus' => 'b2b', '' => 'b2b') as $in => $out) {
            Configuration::updateValue('IFIRMA_KSEF_MODE', $in);
            $this->assertSame($out, $this->svc->mode(), 'input "' . $in . '"');
        }
        $this->assertSame('b2b', IfirmaKsefService::normalizeMode(null));
    }

    public function testBusinessBuyerFromPayloadNip()
    {
        $this->address('');
        $this->assertTrue($this->svc->isBusinessBuyer($this->receipt('1234567890'), $this->order()));
    }

    public function testPayloadWithoutNipIsPrivateEvenIfAddressHasOne()
    {
        $this->address('1234567890');
        $this->assertFalse($this->svc->isBusinessBuyer($this->receipt(''), $this->order()));
    }

    public function testBusinessBuyerFromAddressWhenNoPayload()
    {
        $this->address('PL 123-456-78-90');
        $this->assertTrue($this->svc->isBusinessBuyer($this->receipt(null), $this->order()));

        $this->address('', '1234567890');
        $this->assertTrue($this->svc->isBusinessBuyer($this->receipt(null), $this->order()));
    }

    public function testPrivateBuyer()
    {
        $this->address('');
        $this->assertFalse($this->svc->isBusinessBuyer($this->receipt(null), $this->order()));
        $this->assertFalse($this->svc->isBusinessBuyer($this->receipt(null), null));
    }

    public function testShouldSubmitMatrix()
    {
        $this->address('');
        $b2b = $this->receipt('1234567890');
        $private = $this->receipt('');

        Configuration::updateValue('IFIRMA_KSEF_MODE', 'off');
        $this->assertFalse($this->svc->shouldSubmit($b2b, $this->order()));
        $this->assertSame('KSeF disabled', $this->svc->skipReason($b2b, $this->order()));

        Configuration::updateValue('IFIRMA_KSEF_MODE', 'b2b');
        $this->assertTrue($this->svc->shouldSubmit($b2b, $this->order()));
        $this->assertFalse($this->svc->shouldSubmit($private, $this->order()));
        $this->assertSame('private buyer (B2B mode)', $this->svc->skipReason($private, $this->order()));

        Configuration::updateValue('IFIRMA_KSEF_MODE', 'all');
        $this->assertTrue($this->svc->shouldSubmit($private, $this->order()));
    }

    public function testShouldSubmitRejectsIneligibleDocuments()
    {
        $this->address('');
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'all');

        $unsupported = $this->receipt('1', 'fakturaproformakraj');
        $this->assertFalse($this->svc->shouldSubmit($unsupported, $this->order()));
        $this->assertSame('type not supported', $this->svc->skipReason($unsupported, $this->order()));

        $notIssued = new IfirmaReceipt();
        $notIssued->document_type = 'fakturakraj';
        $this->assertFalse($this->svc->shouldSubmit($notIssued, $this->order()));

        foreach (array(array('ksef_number', '1111112228-20260702-6505FF400000-26'), array('ksef_status', 'PRZYJETA'), array('ksef_status', 'WYSLANA')) as $case) {
            $r = $this->receipt('1');
            $r->{$case[0]} = $case[1];
            $this->assertFalse($this->svc->shouldSubmit($r, $this->order()), $case[1]);
            $this->assertSame('already in KSeF', $this->svc->skipReason($r, $this->order()));
        }

        $failed = $this->receipt('1');
        $failed->ksef_status = 'BLAD';
        $this->assertTrue($this->svc->shouldSubmit($failed, $this->order()));
    }

    public function testSubmitSuccessRecordsSentAndPostsToKsefEndpoint()
    {
        $this->t->queue('POST', '#fakturakraj/ksef/send/77\.json#', $this->kod(0, 'Faktura zostala wyslana do KSeF.'));
        $r = $this->receipt('1234567890');
        $r->ksef_error = 'old';

        $res = $this->svc->submit($r, $this->order());

        $this->assertTrue($res['ok']);
        $this->assertSame('Faktura zostala wyslana do KSeF.', $res['message']);
        $this->assertCount(1, $this->t->calls);
        $call = $this->t->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/fakturakraj/ksef/send/77.json', $call['url']);
        $this->assertSame('{"DataWysylki":null}', $call['body']);

        $row = $this->row();
        $this->assertSame('WYSLANA', $row['ksef_status']);
        $this->assertSame('', $row['ksef_error']);
        $this->assertNotEmpty($row['ksef_sent_at']);
    }

    public function testSubmitFailureRecordsErrorAndDoesNotThrow()
    {
        $this->t->queue('POST', '#ksef/send#', $this->kod(200, 'Faktura nie moze byc wyslana'));
        $r = $this->receipt('1234567890');

        $res = $this->svc->submit($r, $this->order());

        $this->assertFalse($res['ok']);
        $this->assertNotSame('', $res['message']);
        $row = $this->row();
        $this->assertSame('BLAD', $row['ksef_status']);
        $this->assertNotSame('', (string) $row['ksef_error']);
        $this->assertSame($res['message'], $row['ksef_error']);
    }

    public function testSubmitNotEligibleDoesNotCallIfirma()
    {
        $this->address('');
        $res = $this->svc->submit($this->receipt(''), $this->order());

        $this->assertFalse($res['ok']);
        $this->assertSame('private buyer (B2B mode)', $res['message']);
        $this->assertCount(0, $this->t->calls);
    }

    public function testForceIgnoresModeButNotAccepted()
    {
        $this->address('');
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'off');
        $this->t->queue('POST', '#ksef/send#', $this->kod(0, 'ok'));

        $res = $this->svc->submit($this->receipt(''), $this->order(), true);
        $this->assertTrue($res['ok']);
        $this->assertCount(1, $this->t->calls);

        $accepted = $this->receipt('1');
        $accepted->ksef_number = '1111112228-20260702-6505FF400000-26';
        $res = $this->svc->submit($accepted, $this->order(), true);
        $this->assertFalse($res['ok']);
        $this->assertCount(1, $this->t->calls);
    }

    public function testDescribe()
    {
        $this->address('');
        $r = $this->receipt('');
        $this->assertSame('not sent (private buyer (B2B mode))', $this->svc->describe($r, $this->order()));

        $r->ksef_status = 'WYSLANA';
        $r->ksef_sent_at = '2026-11-02 20:30:15';
        $this->assertSame('sent 2026-11-02 20:30, awaiting KSeF', $this->svc->describe($r));

        $r->ksef_status = 'BLAD';
        $r->ksef_error = 'boom';
        $this->assertSame('error: boom', $this->svc->describe($r));

        $r->ksef_status = 'PRZYJETA';
        $r->ksef_number = 'N-1';
        $this->assertSame('accepted: N-1', $this->svc->describe($r));
    }
}
