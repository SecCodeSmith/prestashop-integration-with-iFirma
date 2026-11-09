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
    use IfirmaThrowableProviders;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaKsefService */
    private $svc;

    /** @var array what the document detail answers (the KSeF state iFirma reports) */
    public $detailAnswer;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        // every submission reads the document's KSeF state first (design D5); by default iFirma reports none
        $this->detailAnswer = array('body' => '{"response":{"Kod":0,"KsefStatus":"","KsefNumer":""}}');
        $self = $this;
        $this->t->queue('GET', '#fakturakraj/77\.json#', function () use ($self) {
            return $self->detailAnswer;
        });
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

    public function testTheSubmitterIsTheModuleByDefault()
    {
        $this->assertSame('module', $this->svc->submitter());
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', '');
        $this->assertSame('module', $this->svc->submitter());
    }

    /**
     * @return array[]
     */
    public function submitterValues()
    {
        return array(
            'module' => array('module', 'module'),
            'ifirma' => array('ifirma', 'ifirma'),
            'upper case and padded' => array(' IFIRMA ', 'ifirma'),
            'both is not a submitter' => array('both', 'module'),
            'null' => array(null, 'module'),
            'false' => array(false, 'module'),
            'an array' => array(array('ifirma'), 'module'),
        );
    }

    /**
     * @dataProvider submitterValues
     */
    public function testAnUnknownSubmitterIsNormalisedToTheModule($stored, $expected)
    {
        $this->assertSame($expected, IfirmaKsefService::normalizeSubmitter($stored));
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', $stored);
        $this->assertSame($expected, $this->svc->submitter());
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
        $this->assertCount(2, $this->t->calls, 'the state read, then the send');
        $call = $this->t->calls[1];
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

    /** @dataProvider throwables */
    public function testSubmitRecordsAThrowableFromTheClientAsTheError(Throwable $failure)
    {
        $this->t->queue('POST', '#ksef/send#', function () use ($failure) {
            throw $failure;
        });
        $r = $this->receipt('1234567890');

        $res = $this->svc->submit($r, $this->order());

        $this->assertSame(array('ok' => false, 'message' => $failure->getMessage()), array_intersect_key($res, array('ok' => 1, 'message' => 1)));
        $this->assertCount(2, $this->t->calls, 'the state read, then the send');
        $row = $this->row();
        $this->assertSame('BLAD', $row['ksef_status']);
        $this->assertSame($failure->getMessage(), $row['ksef_error']);
        $this->assertEmpty($row['ksef_sent_at']);
    }

    /**
     * @return array name => array(Throwable)
     */
    public function throwablesWithoutAMessage()
    {
        return array(
            'Exception with an empty message' => array(new RuntimeException('')),
            'Exception with a whitespace-only message' => array(new RuntimeException('   ')),
            'Error with an empty message' => array(new TypeError('')),
            'Error with a whitespace-only message' => array(new TypeError('   ')),
        );
    }

    /** @dataProvider throwablesWithoutAMessage */
    public function testSubmitRecordsAThrowableWithoutAMessageAsAFailureWithTheFallbackText(Throwable $failure)
    {
        $this->t->queue('POST', '#ksef/send#', function () use ($failure) {
            throw $failure;
        });
        $r = $this->receipt('1234567890');

        $res = $this->svc->submit($r, $this->order());

        $this->assertSame(array('ok' => false, 'message' => 'iFirma did not confirm the KSeF submission'), array_intersect_key($res, array('ok' => 1, 'message' => 1)));
        $this->assertCount(2, $this->t->calls, 'the state read, then the send');
        $row = $this->row();
        $this->assertSame('BLAD', $row['ksef_status']);
        $this->assertSame('iFirma did not confirm the KSeF submission', $row['ksef_error']);
        $this->assertEmpty($row['ksef_sent_at']);
    }

    /**
     * @return array name => array(Throwable)
     */
    public function paddedThrowables()
    {
        $message = " \t connection reset \r\n";

        return array(
            'Exception' => array(new RuntimeException($message)),
            'Error' => array(new TypeError($message)),
        );
    }

    /** @dataProvider paddedThrowables */
    public function testSubmitRecordsTheTrimmedMessageOfAThrowableAsTheError(Throwable $failure)
    {
        $this->t->queue('POST', '#ksef/send#', function () use ($failure) {
            throw $failure;
        });
        $r = $this->receipt('1234567890');

        $res = $this->svc->submit($r, $this->order());

        $this->assertSame(array('ok' => false, 'message' => 'connection reset'), array_intersect_key($res, array('ok' => 1, 'message' => 1)));
        $row = $this->row();
        $this->assertSame('BLAD', $row['ksef_status']);
        $this->assertSame('connection reset', $row['ksef_error']);
        $this->assertEmpty($row['ksef_sent_at']);
    }

    public function testSubmitOfADocumentThatIsNotIssuedIsRefusedWithoutCallingIfirma()
    {
        $notIssued = new IfirmaReceipt();
        $notIssued->document_type = 'fakturakraj';

        $res = $this->svc->submit($notIssued, $this->order(), true);

        $this->assertSame(array('ok' => false, 'message' => 'The document has not been issued yet.'), array_intersect_key($res, array('ok' => 1, 'message' => 1)));
        $this->assertCount(0, $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    /**
     * @return array name => array(response body, message of the result)
     */
    public function acceptedAnswers()
    {
        return array(
            'without an information text' => array('{"response":{"Kod":0}}', ''),
            'with a padded information text' => array('{"response":{"Kod":0,"Informacja":"  Wyslano.\n"}}', 'Wyslano.'),
            'without the response wrapper' => array('{"Kod":0,"Informacja":"Wyslano."}', 'Wyslano.'),
        );
    }

    /** @dataProvider acceptedAnswers */
    public function testSubmitRecordsAnAnswerWithKodZeroAsSent($body, $message)
    {
        $this->t->queue('POST', '#ksef/send#', array('body' => $body));
        $r = $this->receipt('1234567890');
        $r->ksef_status = 'BLAD';
        $r->ksef_error = 'earlier failure';

        $res = $this->svc->submit($r, $this->order());

        $this->assertSame(array('ok' => true, 'message' => $message), array_intersect_key($res, array('ok' => 1, 'message' => 1)));
        $row = $this->row();
        $this->assertSame('WYSLANA', $row['ksef_status']);
        $this->assertSame('', $row['ksef_error']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['ksef_sent_at']);
    }

    /**
     * @return array name => array(scripted response, text recorded as the error)
     */
    public function refusedAnswers()
    {
        $fallback = 'iFirma did not confirm the KSeF submission';

        return array(
            'a Kod other than 0' => array(
                array('body' => '{"response":{"Kod":200,"Informacja":"Faktura nie moze byc wyslana"}}'),
                'Invalid request structure (Kod 200): Faktura nie moze byc wyslana',
            ),
            'a Kod other than 0 without an information text' => array(
                array('body' => '{"response":{"Kod":200}}'),
                'Invalid request structure (Kod 200)',
            ),
            'a failed transport' => array(array('status' => 0, 'error' => 'timeout'), 'cURL error: timeout'),
            'an HTTP error status' => array(array('status' => 503, 'body' => ''), 'HTTP 503 returned by iFirma.'),
            'no Kod but an information text' => array(
                array('body' => '{"response":{"Informacja":"  Brak kodu\n"}}'),
                'Brak kodu',
            ),
            'no Kod and a blank information text' => array(array('body' => '{"response":{"Informacja":"   "}}'), $fallback),
            'no Kod and no information text' => array(array('body' => '{"response":{}}'), $fallback),
            'a body that is not JSON' => array(array('body' => 'not json'), $fallback),
            'an empty body' => array(array('body' => ''), $fallback),
        );
    }

    /** @dataProvider refusedAnswers */
    public function testSubmitRecordsAnAnswerWithoutKodZeroAsTheError(array $response, $error)
    {
        $this->t->queue('POST', '#ksef/send#', $response);
        $r = $this->receipt('1234567890');

        $res = $this->svc->submit($r, $this->order());

        $this->assertSame(array('ok' => false, 'message' => $error), array_intersect_key($res, array('ok' => 1, 'message' => 1)));
        $this->assertCount(2, $this->t->calls, 'the state read, then the send');
        $row = $this->row();
        $this->assertSame('BLAD', $row['ksef_status']);
        $this->assertSame($error, $row['ksef_error']);
        $this->assertEmpty($row['ksef_sent_at']);
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
        $this->assertCount(2, $this->t->calls, 'the state read, then the send');

        $accepted = $this->receipt('1');
        $accepted->ksef_number = '1111112228-20260702-6505FF400000-26';
        $res = $this->svc->submit($accepted, $this->order(), true);
        $this->assertFalse($res['ok']);
        $this->assertCount(2, $this->t->calls, 'the state read, then the send');
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
    // ---- who submits, and the state read before every submission (design D5) --------------------------------

    /** Scripts what iFirma reports about document 77: the KSeF status and number ('' for none). */
    private function remoteState($status, $number = '')
    {
        $this->detailAnswer = array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'KsefStatus' => $status, 'KsefNumer' => $number,
        ))));
    }

    private function posts()
    {
        $posts = array();
        foreach ($this->t->calls as $call) {
            if ($call['method'] === 'POST') {
                $posts[] = $call;
            }
        }

        return $posts;
    }

    public function testTheStateIsReadBeforeTheInvoiceIsSent()
    {
        $this->t->queue('POST', '#fakturakraj/ksef/send/77\.json#', $this->kod(0, 'ok'));

        $res = $this->svc->submit($this->receipt('1234567890'), $this->order());

        $this->assertTrue($res['ok']);
        $this->assertSame('sent', $res['outcome']);
        $this->assertSame(array('GET', 'POST'), array_column($this->t->calls, 'method'));
        $this->assertStringEndsWith('/fakturakraj/77.json', $this->t->calls[0]['url']);
    }

    /**
     * @return array[] label => array(status, number)
     */
    public function stateIfirmaAlreadyReports()
    {
        return array(
            'sent' => array('WYSLANA', ''),
            'scheduled' => array('ZAPLANOWANA', ''),
            'an unknown status' => array('PRZETWARZANIE', ''),
            'accepted' => array('PRZYJETA', 'NIP-20261101-ABC'),
        );
    }

    /**
     * @dataProvider stateIfirmaAlreadyReports
     */
    public function testNothingIsSentWhenIfirmaAlreadyHoldsTheDocumentInKsef($status, $number)
    {
        $this->remoteState($status, $number);
        $r = $this->receipt('1234567890');

        $res = $this->svc->submit($r, $this->order());

        $this->assertFalse($res['ok']);
        $this->assertSame('already', $res['outcome']);
        $this->assertSame(array(), $this->posts());
        $row = $this->row();
        $this->assertSame($status, $row['ksef_status'], 'the reported state is stored');
        $this->assertSame($number, (string) $row['ksef_number']);
    }

    public function testTheMessageForADocumentSentByIfirmaSaysSo()
    {
        $this->remoteState('WYSLANA');

        $res = $this->svc->submit($this->receipt('1234567890'), $this->order());

        $this->assertSame('already sent by iFirma', $res['message']);
    }

    public function testARejectedDocumentIsNotSubmittedAgainAutomatically()
    {
        $this->remoteState('ODRZUCONA');

        $res = $this->svc->submit($this->receipt('1234567890'), $this->order());

        $this->assertFalse($res['ok']);
        $this->assertSame('refused', $res['outcome']);
        $this->assertSame(array(), $this->posts());
    }

    public function testARejectedDocumentCanBeSubmittedAgainByTheEmployee()
    {
        $this->remoteState('ODRZUCONA');
        $this->t->queue('POST', '#ksef/send#', $this->kod(0, 'ok'));

        $res = $this->svc->submit($this->receipt('1234567890'), $this->order(), true);

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $this->posts());
        $this->assertSame('WYSLANA', $this->row()['ksef_status']);
    }

    public function testTheEmployeeIsToldWhenIfirmaHasAlreadySentOrScheduledTheDocument()
    {
        $this->remoteState('ZAPLANOWANA');

        $res = $this->svc->submit($this->receipt('1234567890'), $this->order(), true);

        $this->assertFalse($res['ok']);
        $this->assertSame('iFirma has already sent or scheduled this document (status ZAPLANOWANA).', $res['message']);
        $this->assertSame(array(), $this->posts());
        $this->assertSame('ZAPLANOWANA', $this->row()['ksef_status']);
    }

    public function testAnUnreadableStateDefersTheSubmissionAndChangesNothing()
    {
        $this->detailAnswer = array('status' => 0, 'error' => 'timeout');
        $r = $this->receipt('1234567890');

        $res = $this->svc->submit($r, $this->order());

        $this->assertFalse($res['ok']);
        $this->assertSame('deferred', $res['outcome']);
        $this->assertFalse($res['rate_limited']);
        $this->assertStringContainsString('timeout', $res['message']);
        $this->assertSame(array(), $this->posts());
        $this->assertSame('', (string) $r->ksef_status);
    }

    public function testAStateRefusedByTheRateLimitDefersTheSubmissionAndSaysSo()
    {
        $fixed = mktime(10, 15, 20, 11, 1, 2026);
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $client->setClock(function () use ($fixed) {
            return $fixed;
        });
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $fixed) . ':80');
        $svc = new IfirmaKsefService($client, new Module());

        $res = $svc->submit($this->receipt('1234567890'), $this->order());

        $this->assertSame('deferred', $res['outcome']);
        $this->assertTrue($res['rate_limited']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheSubmitterIfirmaMakesNoCallAtAll()
    {
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');
        $r = $this->receipt('1234567890');

        $this->assertSame('sent by iFirma', $this->svc->skipReason($r, $this->order()));
        $res = $this->svc->submit($r, $this->order());

        $this->assertFalse($res['ok']);
        $this->assertSame('refused', $res['outcome']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testTheEmployeeCanStillSendWhenTheSubmitterIsIfirma()
    {
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');
        $this->t->queue('POST', '#ksef/send#', $this->kod(0, 'ok'));

        $res = $this->svc->submit($this->receipt('1234567890'), $this->order(), true);

        $this->assertTrue($res['ok']);
        $this->assertSame(array('GET', 'POST'), array_column($this->t->calls, 'method'), 'the state is read first whatever the submitter');
    }

    public function testTheModeOffIgnoresTheSubmitter()
    {
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'off');
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');
        $r = $this->receipt('1234567890');

        $this->assertFalse($this->svc->goesToKsef($r, $this->order()));
        $this->assertSame('KSeF disabled', $this->svc->skipReason($r, $this->order()));
    }

    /**
     * @return array[] label => array(mode, nip, type, issued, expected)
     */
    public function scopeCases()
    {
        return array(
            'a business invoice in b2b mode' => array('b2b', '1234567890', 'fakturakraj', true, true),
            'a private buyer in b2b mode' => array('b2b', null, 'fakturakraj', true, false),
            'a private buyer in all mode' => array('all', null, 'fakturakraj', true, true),
            'an unsupported type' => array('all', '1234567890', 'fakturaproformakraj', true, false),
            'mode off' => array('off', '1234567890', 'fakturakraj', true, false),
            'a document that is not issued' => array('all', '1234567890', 'fakturakraj', false, false),
        );
    }

    /**
     * @dataProvider scopeCases
     */
    public function testWhichDocumentsGoToKsef($mode, $nip, $type, $issued, $expected)
    {
        Configuration::updateValue('IFIRMA_KSEF_MODE', $mode);
        $r = $this->receipt($nip, $type);
        if (!$issued) {
            $r->ifirma_id = '';
        }

        $this->assertSame($expected, $this->svc->goesToKsef($r, $this->order()));
    }

    public function testGoesToKsefIgnoresTheSubmitterAndTheCurrentState()
    {
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');
        $r = $this->receipt('1234567890');
        $r->ksef_status = 'PRZYJETA';
        $r->ksef_number = 'N-1';

        $this->assertTrue($this->svc->goesToKsef($r, $this->order()));
    }
    // ---- the five readable KSeF states of the panel (spec ksef-submission "Status visibility") ---------------

    public function testNotSentCarriesTheReason()
    {
        Configuration::updateValue('IFIRMA_KSEF_MODE', 'off');

        $this->assertSame('not sent (KSeF disabled)', $this->svc->describe($this->receipt('1234567890'), $this->order()));
    }

    public function testWaitingForIfirmaWhenTheSubmitterIsIfirmaAndNoStateIsReported()
    {
        Configuration::updateValue('IFIRMA_KSEF_SUBMITTER', 'ifirma');

        $this->assertSame('waiting for iFirma to send the document', $this->svc->describe($this->receipt('1234567890'), $this->order()));
    }

    public function testSentAndAwaitingAcceptanceShowsTheTimeOfTheModule()
    {
        $r = $this->receipt('1234567890');
        $r->ksef_status = 'WYSLANA';
        $r->ksef_sent_at = '2026-11-01 10:15:00';

        $this->assertSame('sent 2026-11-01 10:15, awaiting KSeF', $this->svc->describe($r, $this->order()));
    }

    public function testSentOrScheduledByIfirmaShowsTheStatusAsReported()
    {
        $r = $this->receipt('1234567890');
        $r->ksef_status = 'ZAPLANOWANA';

        $this->assertSame('sent or scheduled by iFirma: ZAPLANOWANA', $this->svc->describe($r, $this->order()));
    }

    public function testRejectedByKsefIsAnErrorThatSaysSo()
    {
        $r = $this->receipt('1234567890');
        $r->ksef_status = 'ODRZUCONA';

        $this->assertSame('error: rejected by KSeF', $this->svc->describe($r, $this->order()));
    }

    public function testASentStatusWithoutOurTimestampIsSentByIfirma()
    {
        $r = $this->receipt('1234567890');
        $r->ksef_status = 'WYSLANA';

        $this->assertSame('sent or scheduled by iFirma: WYSLANA', $this->svc->describe($r, $this->order()));
    }
}
