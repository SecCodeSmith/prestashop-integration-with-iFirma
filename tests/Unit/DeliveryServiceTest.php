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

class DeliveryServiceTest extends TestCase
{
    const PDF = "%PDF-1.4\nfake body\n%%EOF";

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaDeliveryService */
    private $svc;

    /** @var IfirmaApiClient */
    private $client;

    /** @var int[] seconds requested from the injected sleeper */
    private $waits = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        Configuration::$values['IFIRMA_SHOP_URL'] = 'https://www.Hialumed.pl/';
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->client = $client;
        $module = new Module();
        $this->waits = array();
        $waits = &$this->waits;
        $this->svc = new IfirmaDeliveryService(
            $client,
            new IfirmaReceiptService($client, $module),
            $module,
            function () {
                return 1800000000;
            },
            function ($seconds) use (&$waits) {
                $waits[] = $seconds;
            }
        );

        $c = new Customer();
        $c->id = 5;
        $c->email = 'jan@example.test';
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        Customer::register($c);
        $this->registerOrder(15, 2);
    }

    private function registerOrder($id, $idLang)
    {
        $o = new Order();
        $o->id = $id;
        $o->id_customer = 5;
        $o->id_lang = $idLang;
        $o->reference = 'ABCDEFGHI';
        Order::register($o);

        return $o;
    }

    private function receipt($type = 'fakturaparagon', $source = 'module')
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->document_type = $type;
        $r->ifirma_id = '77';
        $r->ifirma_number = '7/2026';
        $r->status = 'created';
        $r->source = $source;
        $r->issue_date = '2026-11-01';
        $r->save();

        return $r;
    }

    private function pdfOk()
    {
        $this->t->queue('GET', '#\.pdf#', array('body' => self::PDF));
    }

    private function kod($kod, $info = '')
    {
        return array('body' => json_encode(array('response' => array('Kod' => $kod, 'Informacja' => $info))));
    }

    private function row()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return reset($rows);
    }

    /**
     * A saved receipt that already carries $fields; the seeding writes are cleared, so the write log
     * that follows holds only what the delivery does.
     *
     * @param array  $fields field => value
     * @param string $type
     * @param string $source
     * @return IfirmaReceipt
     */
    private function seeded(array $fields, $type = 'fakturaparagon', $source = 'module')
    {
        $r = $this->receipt($type, $source);
        foreach ($fields as $name => $value) {
            $r->$name = $value;
        }
        $r->save();
        IfirmaTestState::clearWrites();

        return $r;
    }

    /** @return string[] op (add|update) of each receipt write since the last reset, oldest first */
    private function receiptWriteOps()
    {
        return array_column(IfirmaTestState::writes('ifirma_receipt'), 'op');
    }

    /**
     * The four values of a delivery result, keyed as deliver() reported them before it returned an object.
     *
     * @return array array('ok' => bool, 'status' => string, 'channel' => string, 'message' => string)
     */
    private function fieldsOf(IfirmaDeliveryResult $res)
    {
        return array(
            'ok' => $res->isSent(),
            'status' => $res->getStatus(),
            'channel' => $res->getChannel(),
            'message' => $res->getMessage(),
        );
    }

    public function testShopMailSuccess()
    {
        $this->pdfOk();
        $r = $this->receipt();
        $res = $this->svc->deliver($r);

        $this->assertTrue($res->isSent());
        $this->assertSame('sent', $res->getStatus());
        $this->assertCount(1, Mail::$sent);
        $m = Mail::$sent[0];
        $this->assertSame('receipt_issued', $m['template']);
        $this->assertSame(2, $m['idLang']);
        $this->assertSame('jan@example.test', $m['to']);
        $this->assertSame('7/2026', $m['templateVars']['{document_number}']);
        $this->assertSame('ABCDEFGHI', $m['templateVars']['{order_reference}']);
        $this->assertSame('application/pdf', $m['fileAttachment']['mime']);
        $this->assertSame(self::PDF, $m['fileAttachment']['content']);
        $this->assertSame('hialumed.pl-7_2026.pdf', $m['fileAttachment']['name']);
        $this->assertSame('https://www.Hialumed.pl/', $m['templateVars']['{ifirma_shop_link}']);
        $this->assertStringEndsWith('ifirma/mails/', str_replace('\\', '/', $m['templatePath']));
        $this->assertStringContainsString('Test Shop', $m['subject']);
        $this->assertStringContainsString('7/2026', $m['subject']);

        $row = $this->row();
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame(date('Y-m-d H:i:s', 1800000000), $row['delivered_at']);
        $this->assertSame('', $row['delivery_error']);
    }

    public function testAttachmentIsNamedWithTheShopDomainAndTheNumber()
    {
        $this->pdfOk();
        $r = $this->receipt();
        $r->ifirma_number = 'FV 7/2026';
        $this->svc->deliver($r);

        $this->assertSame('hialumed.pl-FV_7_2026.pdf', Mail::$sent[0]['fileAttachment']['name']);
        $this->assertSame(IfirmaShopLink::pdfFileName('FV 7/2026'), Mail::$sent[0]['fileAttachment']['name']);
    }

    public function testAttachmentWithoutANumberUsesTheIdentifier()
    {
        $this->pdfOk();
        $r = $this->receipt();
        $r->ifirma_number = '';
        $this->svc->deliver($r);

        $this->assertSame('hialumed.pl-77.pdf', Mail::$sent[0]['fileAttachment']['name']);
    }

    public function testTemplatesExistAndContainPlaceholders()
    {
        foreach (array('en', 'pl') as $iso) {
            foreach (array('html', 'txt') as $ext) {
                $file = _PS_MODULE_DIR_ . 'ifirma/mails/' . $iso . '/receipt_issued.' . $ext;
                $this->assertFileExists($file);
                $content = file_get_contents($file);
                foreach (array('{shop_name}', '{firstname}', '{document_number}', '{order_reference}', '{issue_date}') as $ph) {
                    $this->assertStringContainsString($ph, $content, $iso . '/' . $ext . ' ' . $ph);
                }
            }
        }
    }

    public function testPdfFetchFailureSendsNothing()
    {
        for ($i = 0; $i < 3; $i++) {
            $this->t->queue('GET', '#\.pdf#', $this->kod(201, 'Brak dokumentu'));
        }
        $r = $this->receipt();
        $res = $this->svc->deliver($r);

        $this->assertFalse($res->isSent());
        $this->assertSame('failed', $res->getStatus());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(3, 7), $this->waits);
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertNotSame('', $row['delivery_error']);
        $this->assertTrue($r->isIssued());
    }

    private function notFound()
    {
        return $this->kod(404, 'Nie znaleziono dokumentu');
    }

    private function pdfCalls()
    {
        $n = 0;
        foreach ($this->t->calls as $call) {
            if (strpos($call['url'], '.pdf') !== false) {
                $n++;
            }
        }

        return $n;
    }

    public function testPdfReadyOnSecondAttempt()
    {
        $this->t->queue('GET', '#\.pdf#', $this->notFound());
        $this->pdfOk();
        $res = $this->svc->deliver($this->receipt());

        $this->assertTrue($res->isSent());
        $this->assertSame('sent', $res->getStatus());
        $this->assertCount(1, Mail::$sent);
        $this->assertSame(self::PDF, Mail::$sent[0]['fileAttachment']['content']);
        $this->assertSame(array(3), $this->waits);
        $this->assertSame(2, $this->pdfCalls());
        $this->assertSame('sent', $this->row()['delivery_status']);
    }

    public function testPdfNeverReadyFailsAfterThreeAttempts()
    {
        for ($i = 0; $i < 3; $i++) {
            $this->t->queue('GET', '#\.pdf#', $this->notFound());
        }
        $res = $this->svc->deliver($this->receipt());

        $this->assertFalse($res->isSent());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array(3, 7), $this->waits);
        $this->assertSame(3, $this->pdfCalls());
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertStringContainsString('3', $row['delivery_error']);
        $this->assertStringContainsString('Nie znaleziono dokumentu', $row['delivery_error']);
    }

    public function testAuthenticationErrorIsNotRetried()
    {
        $this->t->queue('GET', '#\.pdf#', $this->kod(403, 'Brak uprawnien'));
        $res = $this->svc->deliver($this->receipt());

        $this->assertFalse($res->isSent());
        $this->assertSame(1, $this->pdfCalls());
        $this->assertSame(array(), $this->waits);
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertStringContainsString('Brak uprawnien', $row['delivery_error']);
        $this->assertStringNotContainsString('attempts', $row['delivery_error']);
    }

    public function testRateGuardRefusalIsNotRetried()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);
        $this->client->setClock(function () use ($now) {
            return $now;
        });
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '3');
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $now) . ':3');
        $res = $this->svc->deliver($this->receipt());

        $this->assertFalse($res->isSent());
        $this->assertSame(0, $this->pdfCalls());
        $this->assertSame(array(), $this->waits);
        $this->assertCount(0, Mail::$sent);
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertStringContainsString('rate limit guard', $row['delivery_error']);
    }

    public function testTheAutomaticDeliveryRecordsARateGuardRefusalAndIsNotFlaggedRefusedLocally()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);
        $this->client->setClock(function () use ($now) {
            return $now;
        });
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '3');
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $now) . ':3');

        $res = $this->svc->deliver($this->receipt());

        $this->assertFalse($res->refusedLocally());
        $this->assertSame('', $res->getRefusal());
        $this->assertSame('failed', $this->row()['delivery_status']);
    }

    public function testAResendTheRateGuardRefusesIsReportedAndLeavesTheRecordAlone()
    {
        $now = mktime(10, 15, 20, 11, 1, 2026);
        $this->client->setClock(function () use ($now) {
            return $now;
        });
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', '3');
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $now) . ':3');
        $r = $this->seeded(array('delivery_status' => 'sent', 'delivery_error' => '', 'delivered_at' => '2026-10-30 08:00:00'));

        $res = $this->svc->resend($r);

        $this->assertFalse($res->isSent());
        $this->assertSame('failed', $res->getStatus());
        $this->assertTrue($res->refusedLocally());
        $this->assertSame('rate_limited', $res->getRefusal());
        $this->assertStringContainsString('rate limit guard', $res->getMessage());
        $this->assertSame(array(), $this->receiptWriteOps(), 'nothing is saved');
        $this->assertSame('sent', $this->row()['delivery_status']);
        $this->assertSame('', (string) $this->row()['delivery_error']);
        $this->assertSame(0, $this->pdfCalls());
    }

    public function testAResendTheCredentialCoolDownRefusesIsReportedAndLeavesTheRecordAlone()
    {
        $now = time();
        $this->client->setClock(function () use ($now) {
            return $now;
        });
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($now - 120) . '|Bad hash');
        $r = $this->seeded(array('delivery_status' => 'sent'));

        $res = $this->svc->resend($r);

        $this->assertTrue($res->refusedLocally());
        $this->assertSame('auth_blocked', $res->getRefusal());
        $this->assertSame(array(), $this->receiptWriteOps());
        $this->assertSame('sent', $this->row()['delivery_status']);
    }

    public function testAResendThatReachedIFirmaAndFailedIsStillRecorded()
    {
        $this->t->queue('GET', '#\.pdf#', $this->kod(403, 'Brak uprawnien'));
        $r = $this->seeded(array('delivery_status' => 'sent'));

        $res = $this->svc->resend($r);

        $this->assertFalse($res->isSent());
        $this->assertFalse($res->refusedLocally());
        $this->assertNotSame(array(), $this->receiptWriteOps());
        $this->assertSame('failed', $this->row()['delivery_status']);
    }

    public function testResendRetriesTheSameWay()
    {
        $this->t->queue('GET', '#\.pdf#', $this->notFound());
        $this->pdfOk();
        $res = $this->svc->resend($this->receipt());

        $this->assertTrue($res->isSent());
        $this->assertSame(array(3), $this->waits);
        $this->assertSame(2, $this->pdfCalls());
        $this->assertCount(1, Mail::$sent);
    }

    public function testCustomerDownloadPathMakesASingleAttempt()
    {
        $this->t->queue('GET', '#\.pdf#', $this->notFound());
        $service = new IfirmaReceiptService($this->client, new Module());
        try {
            $service->fetchPdf($this->receipt());
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('Nie znaleziono dokumentu', $e->getMessage());
        }

        $this->assertSame(1, $this->pdfCalls());
        $this->assertSame(array(), $this->waits);
    }

    public function testShopCopyIsBlindCopiedWithSameAttachment()
    {
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'shop');
        $this->pdfOk();
        $res = $this->svc->deliver($this->receipt());

        $this->assertTrue($res->isSent());
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('shop@example.test', Mail::$sent[0]['bcc']);
        $this->assertSame('jan@example.test', Mail::$sent[0]['to']);
        $this->assertSame(self::PDF, Mail::$sent[0]['fileAttachment']['content']);
        $this->assertFalse(Mail::$sent[0]['die']);
        $this->assertNull(Mail::$sent[0]['idShop']);
    }

    private function registerEmployee($id, $email, $active = 1)
    {
        $e = new Employee();
        $e->id = $id;
        $e->firstname = 'Anna';
        $e->lastname = 'Nowak';
        $e->email = $email;
        $e->active = $active;
        Employee::register($e);

        return $e;
    }

    public function testEmployeeCopy()
    {
        $this->registerEmployee(3, 'anna@example.test');
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'employee');
        Configuration::updateValue('IFIRMA_SEND_COPY_EMPLOYEE', 3);
        $this->pdfOk();
        $res = $this->svc->deliver($this->receipt());

        $this->assertTrue($res->isSent());
        $this->assertSame('anna@example.test', Mail::$sent[0]['bcc']);
        $this->assertCount(0, PrestaShopLogger::$logs);
    }

    public function unusableEmployeeProvider()
    {
        return array(
            'deleted' => array(null, 'employee not found'),
            'inactive' => array(array('anna@example.test', 0), 'employee inactive'),
            'invalid e-mail' => array(array('not-an-email', 1), 'invalid e-mail'),
        );
    }

    /**
     * @dataProvider unusableEmployeeProvider
     */
    public function testUnusableEmployeeSendsWithoutCopy($employee, $reason)
    {
        if ($employee !== null) {
            $this->registerEmployee(3, $employee[0], $employee[1]);
        }
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'employee');
        Configuration::updateValue('IFIRMA_SEND_COPY_EMPLOYEE', 3);
        $this->pdfOk();
        $res = $this->svc->deliver($this->receipt());

        $this->assertTrue($res->isSent());
        $this->assertSame('sent', $this->row()['delivery_status']);
        $this->assertCount(1, Mail::$sent);
        $this->assertNull(Mail::$sent[0]['bcc']);
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertStringContainsString('order #15', PrestaShopLogger::$logs[0]['message']);
        $this->assertStringContainsString($reason, PrestaShopLogger::$logs[0]['message']);
        $this->assertSame(2, PrestaShopLogger::$logs[0]['severity']);
    }

    public function testCopyEqualToCustomerAddressIsSkippedIgnoringCase()
    {
        Configuration::updateValue('PS_SHOP_EMAIL', 'JAN@Example.test');
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'shop');
        $this->pdfOk();
        $res = $this->svc->deliver($this->receipt());

        $this->assertTrue($res->isSent());
        $this->assertCount(1, Mail::$sent);
        $this->assertNull(Mail::$sent[0]['bcc']);
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertStringContainsString('same as customer', PrestaShopLogger::$logs[0]['message']);
    }

    public function testCopyModeNoneAndUnknownCarryNoBcc()
    {
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'none');
        $this->pdfOk();
        $this->svc->deliver($this->receipt());
        $this->assertNull(Mail::$sent[0]['bcc']);

        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'bogus');
        $this->assertSame('none', $this->svc->getCopyMode());
        $this->assertSame(array('none', 'shop', 'employee'), IfirmaDeliveryService::copyModes());
        $this->assertCount(0, PrestaShopLogger::$logs);
    }

    public function testResendCarriesTheCopy()
    {
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'shop');
        $this->pdfOk();
        $res = $this->svc->resend($this->receipt());

        $this->assertTrue($res->isSent());
        $this->assertSame('shop@example.test', Mail::$sent[0]['bcc']);
    }

    public function testIfirmaMailChannelNeverCopies()
    {
        Configuration::updateValue('IFIRMA_SEND_COPY_MODE', 'shop');
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        $this->t->queue('POST', '#fakturakraj/send/77\.json#', $this->kod(0, 'ok'));
        $res = $this->svc->deliver($this->receipt('fakturakraj'));

        $this->assertTrue($res->isSent());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame('POST', $this->t->calls[0]['method']);
        $this->assertStringContainsString('"SkrzynkaEmailOdbiorcy":"jan@example.test"', $this->t->calls[0]['body']);
        $this->assertStringNotContainsString('shop@example.test', $this->t->calls[0]['body']);
    }

    public function testIfirmaMailSuccess()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        Configuration::updateValue('IFIRMA_SEND_TEXT', 'Dziekujemy');
        $this->t->queue('POST', '#fakturakraj/send/77\.json#', $this->kod(0, 'ok'));
        $res = $this->svc->deliver($this->receipt('fakturakraj'));

        $this->assertTrue($res->isSent());
        $this->assertSame('ifirma_mail', $res->getChannel());
        $this->assertCount(0, Mail::$sent);
        $body = $this->t->calls[0]['body'];
        $this->assertStringContainsString('"SkrzynkaEmailOdbiorcy":"jan@example.test"', $body);
        $this->assertStringContainsString('"Tekst":"Dziekujemy"', $body);
        $row = $this->row();
        $this->assertSame('ifirma_mail', $row['delivery_channel']);
        $this->assertSame('sent', $row['delivery_status']);
    }

    public function testIfirmaMailNonZeroKodFails()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        $this->t->queue('POST', '#send/77\.json#', $this->kod(201, 'Niepoprawny adres'));
        $res = $this->svc->deliver($this->receipt('fakturakraj'));

        $this->assertFalse($res->isSent());
        $this->assertSame('failed', $res->getStatus());
        $this->assertNotSame('', $res->getMessage());
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame('ifirma_mail', $row['delivery_channel']);
        $this->assertSame($res->getMessage(), $row['delivery_error']);
    }

    public function testIfirmaMailUnparseableResponseFails()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        $this->t->queue('POST', '#send/77\.json#', array('body' => 'not json'));
        $res = $this->svc->deliver($this->receipt('fakturakraj'));

        $this->assertFalse($res->isSent());
        $this->assertSame('failed', $res->getStatus());
        $this->assertNotSame('', $res->getMessage());
    }

    public function testLongTekstIsTruncated()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        Configuration::updateValue('IFIRMA_SEND_TEXT', str_repeat('a', 1500));
        $this->t->queue('POST', '#send/77\.json#', $this->kod(0));
        $this->svc->deliver($this->receipt('fakturakraj'));

        $sent = json_decode($this->t->calls[0]['body'], true);
        $this->assertSame(1000, strlen($sent['Tekst']));
    }

    public function testIfirmaMailFallsBackForReceiptType()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        $this->pdfOk();
        $res = $this->svc->deliver($this->receipt('fakturaparagon'));

        $this->assertTrue($res->isSent());
        $this->assertSame('shop_mail_fallback', $res->getChannel());
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('shop_mail_fallback', $this->row()['delivery_channel']);
        $this->assertCount(1, $this->t->calls);
        $this->assertStringContainsString('.pdf', $this->t->calls[0]['url']);
    }

    public function testMailSystemFailureKeepsDocumentIssued()
    {
        Mail::$result = false;
        $this->pdfOk();
        $r = $this->receipt();
        $res = $this->svc->deliver($r);

        $this->assertFalse($res->isSent());
        $this->assertSame('failed', $res->getStatus());
        $this->assertTrue($r->isIssued());
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertStringContainsString('refused', $row['delivery_error']);
        $this->assertSame('7/2026', $row['ifirma_number']);
    }

    public function testCustomerWithoutEmailFailsWithoutMail()
    {
        $this->pdfOk();
        $r = $this->receipt();
        $order = $this->registerOrder(15, 2);
        $order->id_customer = 999;
        $res = $this->svc->deliver($r, $order);

        $this->assertFalse($res->isSent());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame('failed', $this->row()['delivery_status']);
    }

    public function testResendAfterFailureSucceeds()
    {
        Mail::$result = false;
        $this->pdfOk();
        $r = $this->receipt();
        $this->svc->deliver($r);
        $this->assertSame('failed', $this->row()['delivery_status']);
        $this->assertNull($this->row()['delivered_at']);

        Mail::$result = true;
        $this->pdfOk();
        $res = $this->svc->resend($r);

        $this->assertTrue($res->isSent());
        $row = $this->row();
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame('', $row['delivery_error']);
        $this->assertSame(date('Y-m-d H:i:s', 1800000000), $row['delivered_at']);
    }

    public function testResendOfExternalDocumentDelivers()
    {
        $this->pdfOk();
        $r = $this->receipt('fakturakraj', 'external');
        $res = $this->svc->resend($r);

        $this->assertTrue($res->isSent());
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('sent', $this->row()['delivery_status']);
        $this->assertStringStartsWith('Test Shop: Invoice ', Mail::$sent[0]['subject']);
    }

    public function testExternalNotManualIsSkipped()
    {
        $r = $this->receipt('fakturakraj', 'external');
        $res = $this->svc->deliver($r);

        $this->assertTrue($res->isSent());
        $this->assertSame('skipped', $res->getStatus());
        $this->assertCount(0, Mail::$sent);
        $this->assertCount(0, $this->t->calls);
        $row = $this->row();
        $this->assertSame('skipped', $row['delivery_status']);
        $this->assertSame('external document - not auto-delivered', $row['delivery_error']);
    }

    public function testChannelNoneAtIssuanceIsSkipped()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $res = $this->svc->deliver($this->receipt());

        $this->assertSame('skipped', $res->getStatus());
        $this->assertCount(0, Mail::$sent);
        $this->assertCount(0, $this->t->calls);
        $row = $this->row();
        $this->assertSame('skipped', $row['delivery_status']);
        $this->assertSame('none', $row['delivery_channel']);
        $this->assertNull($row['delivered_at']);
        $this->assertSame('', $row['delivery_error']);
    }

    public function testChannelNoneManualIsDisabledAndRecordUnchanged()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $r = $this->receipt();
        $before = $this->row();
        $res = $this->svc->resend($r);

        $this->assertFalse($res->isSent());
        $this->assertSame('disabled', $res->getStatus());
        $this->assertSame('Delivery is disabled (channel none)', $res->getMessage());
        $this->assertCount(0, Mail::$sent);
        $this->assertSame($before, $this->row());
    }

    // ---- recorded outcomes: the fields set and the number of writes ------------------------

    public function testChannelNoneClearsTheStampOfAnEarlierSendInOneWrite()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'none');
        $r = $this->seeded(array(
            'delivery_status' => 'sent',
            'delivery_channel' => 'shop_mail',
            'delivered_at' => '2026-01-03 10:00:00',
            'delivery_error' => 'earlier error',
        ));

        $res = $this->svc->deliver($r);

        $this->assertSame(array('ok' => true, 'status' => 'skipped', 'channel' => 'none', 'message' => ''), $this->fieldsOf($res));
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $row = $this->row();
        $this->assertSame('skipped', $row['delivery_status']);
        $this->assertSame('none', $row['delivery_channel']);
        $this->assertNull($row['delivered_at']);
        $this->assertSame('', $row['delivery_error']);
        $this->assertSame('created', $row['status']);
    }

    public function testExternalSkipRecordsTheConfiguredChannelAndKeepsTheStamp()
    {
        $r = $this->seeded(array(
            'delivery_status' => 'failed',
            'delivery_channel' => 'ifirma_mail',
            'delivered_at' => '2026-01-03 10:00:00',
            'delivery_error' => 'earlier error',
        ), 'fakturakraj', 'external');

        $res = $this->svc->deliver($r);

        $this->assertSame(
            array('ok' => true, 'status' => 'skipped', 'channel' => 'shop_mail', 'message' => 'external document - not auto-delivered'),
            $this->fieldsOf($res)
        );
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $row = $this->row();
        $this->assertSame('skipped', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame('external document - not auto-delivered', $row['delivery_error']);
        $this->assertSame('2026-01-03 10:00:00', $row['delivered_at']);
        $this->assertCount(0, $this->t->calls);
    }

    public function testSendReplacesAnEarlierOutcomeAndStampsTheClockInOneWrite()
    {
        $this->pdfOk();
        $r = $this->seeded(array(
            'delivery_status' => 'failed',
            'delivery_channel' => 'ifirma_mail',
            'delivered_at' => '2026-01-03 10:00:00',
            'delivery_error' => 'earlier error',
        ));

        $res = $this->svc->resend($r);

        $this->assertSame(array('ok' => true, 'status' => 'sent', 'channel' => 'shop_mail', 'message' => ''), $this->fieldsOf($res));
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $row = $this->row();
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame(date('Y-m-d H:i:s', 1800000000), $row['delivered_at']);
        $this->assertSame('', $row['delivery_error']);
    }

    public function testFailureKeepsTheStampOfAnEarlierSendInOneWrite()
    {
        Mail::$result = false;
        $this->pdfOk();
        $r = $this->seeded(array(
            'delivery_status' => 'sent',
            'delivery_channel' => 'shop_mail',
            'delivered_at' => '2026-01-03 10:00:00',
            'delivery_error' => '',
        ));

        $res = $this->svc->resend($r);

        $this->assertSame(
            array('ok' => false, 'status' => 'failed', 'channel' => 'shop_mail', 'message' => 'The shop mail system refused to send the e-mail.'),
            $this->fieldsOf($res)
        );
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame('The shop mail system refused to send the e-mail.', $row['delivery_error']);
        $this->assertSame('2026-01-03 10:00:00', $row['delivered_at']);
    }

    public function testAFailedShopMailFallbackRecordsTheFallbackChannel()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        Mail::$result = false;
        $this->pdfOk();
        $r = $this->seeded(array());

        $res = $this->svc->deliver($r);

        $this->assertSame(
            array('ok' => false, 'status' => 'failed', 'channel' => 'shop_mail_fallback', 'message' => 'The shop mail system refused to send the e-mail.'),
            $this->fieldsOf($res)
        );
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame('shop_mail_fallback', $row['delivery_channel']);
    }

    public function testChannelOptionOverridesTheConfiguredChannel()
    {
        $this->t->queue('POST', '#fakturakraj/send/77\.json#', $this->kod(0, 'ok'));
        $r = $this->seeded(array(), 'fakturakraj');

        $res = $this->svc->deliver($r, null, array('channel' => 'ifirma_mail'));

        $this->assertSame(array('ok' => true, 'status' => 'sent', 'channel' => 'ifirma_mail', 'message' => ''), $this->fieldsOf($res));
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $this->assertSame('ifirma_mail', $this->row()['delivery_channel']);
    }

    public function testUnknownChannelOptionIsIgnored()
    {
        $this->pdfOk();
        $r = $this->seeded(array());

        $res = $this->svc->deliver($r, null, array('channel' => 'carrier_pigeon'));

        $this->assertSame('shop_mail', $res->getChannel());
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('shop_mail', $this->row()['delivery_channel']);
    }

    public function testAnExceptionWhileSendingIsRecordedAsAFailureInOneWrite()
    {
        $this->pdfOk();
        $r = $this->seeded(array());
        $order = new class extends Order {
            public function getCustomer()
            {
                throw new RuntimeException('customer lookup failed');
            }
        };
        $order->id = 15;
        $order->id_lang = 2;

        $res = $this->svc->deliver($r, $order);

        $this->assertSame(
            array('ok' => false, 'status' => 'failed', 'channel' => 'shop_mail', 'message' => 'customer lookup failed'),
            $this->fieldsOf($res)
        );
        $this->assertCount(0, Mail::$sent);
        $this->assertSame(array('update'), $this->receiptWriteOps());
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame('customer lookup failed', $row['delivery_error']);
        $this->assertNull($row['delivered_at']);
    }

    public function testUnissuedReceiptRecordsNothing()
    {
        $r = $this->seeded(array('ifirma_id' => ''));

        $res = $this->svc->deliver($r);

        $this->assertSame('failed', $res->getStatus());
        $this->assertSame(array(), $this->receiptWriteOps());
        $this->assertSame('none', $this->row()['delivery_status']);
    }

    public function testUnissuedReceiptFails()
    {
        $r = $this->receipt();
        $r->ifirma_id = '';
        $res = $this->svc->deliver($r);

        $this->assertSame('failed', $res->getStatus());
        $this->assertCount(0, Mail::$sent);
        $this->assertCount(0, $this->t->calls);
    }

    public function testUnsupportedLanguageFallsBackToEnglish()
    {
        Language::$languages[] = array('id_lang' => 3, 'iso_code' => 'xx', 'name' => 'Xx');
        $this->registerOrder(15, 3);
        $this->pdfOk();
        $this->svc->deliver($this->receipt());

        $this->assertSame(1, Mail::$sent[0]['idLang']);
    }

    public function testInvalidChannelDefaultsToShopMail()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'carrier_pigeon');
        $this->assertSame('shop_mail', $this->svc->getChannel());
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', '');
        $this->assertSame('shop_mail', $this->svc->getChannel());
    }

    public function testDescribeDelivery()
    {
        $r = new IfirmaReceipt();
        $this->assertSame('Not sent', $this->svc->describeDelivery($r));

        $r->delivery_status = 'sent';
        $r->delivery_channel = 'shop_mail';
        $r->delivered_at = '2026-11-01 10:00:00';
        $this->assertSame('Sent on 2026-11-01 10:00:00 via shop e-mail', $this->svc->describeDelivery($r));

        $r->delivery_status = 'failed';
        $r->delivery_error = 'boom';
        $this->assertSame('Failed: boom', $this->svc->describeDelivery($r));

        $r->delivery_status = 'skipped';
        $r->source = 'external';
        $this->assertSame('Skipped (external)', $this->svc->describeDelivery($r));
    }

    public function testBareHttp403IsNotRetried()
    {
        $this->t->queue('GET', '#\.pdf#', array('status' => 403, 'body' => ''));
        $res = $this->svc->deliver($this->receipt());

        $this->assertFalse($res->isSent());
        $this->assertSame(1, $this->pdfCalls());
        $this->assertCount(1, $this->t->calls);
        $this->assertSame(array(), $this->waits);
        $this->assertCount(0, Mail::$sent);
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertStringContainsString('HTTP 403', $row['delivery_error']);
        $this->assertStringNotContainsString('attempts', $row['delivery_error']);
        $this->assertMatchesRegularExpression('/^\d{10}\|HTTP 403 returned by iFirma\.$/', (string) Configuration::get('IFIRMA_AUTH_FAILURE'));
    }

    public function testHttp401WithAnHtmlBodyIsNotRetried()
    {
        $this->t->queue('GET', '#\.pdf#', array('status' => 401, 'body' => '<html>Unauthorized</html>'));
        $this->svc->deliver($this->receipt());

        $this->assertSame(1, $this->pdfCalls());
        $this->assertSame(array(), $this->waits);
    }

    public function testCallsInsideTheCoolDownAreNotSentNorRetried()
    {
        $now = time();
        $this->client->setClock(function () use ($now) {
            return $now;
        });
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', ($now - 120) . '|Bad hash');
        $res = $this->svc->deliver($this->receipt());

        $this->assertFalse($res->isSent());
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), $this->waits);
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertStringContainsString('calls are paused until', $row['delivery_error']);
        $this->assertStringNotContainsString('attempts', $row['delivery_error']);
    }

    public function testMarkupInANameIsEscapedInTheMailVariables()
    {
        $this->pdfOk();
        Customer::$instances[5]->firstname = '<script>alert(1)</script>';
        Customer::$instances[5]->lastname = 'O\'Neil & "Sons"';
        $this->registerOrder(15, 2)->reference = 'AB<b>C';
        $r = $this->receipt();

        $res = $this->svc->deliver($r);

        $this->assertTrue($res->isSent());
        $vars = Mail::$sent[0]['templateVars'];
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $vars['{firstname}']);
        $this->assertSame('O&#039;Neil &amp; &quot;Sons&quot;', $vars['{lastname}']);
        $this->assertSame('AB&lt;b&gt;C', $vars['{order_reference}']);
        $this->assertSame('7/2026', $vars['{document_number}']);
        $this->assertSame('<script>alert(1)</script> O\'Neil & "Sons"', Mail::$sent[0]['toName'], 'the recipient name is a header, not a template value');
    }
}
