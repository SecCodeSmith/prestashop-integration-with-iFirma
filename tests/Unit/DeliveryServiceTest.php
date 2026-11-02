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

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $module = new Module();
        $this->svc = new IfirmaDeliveryService(
            $client,
            new IfirmaReceiptService($client, $module),
            $module,
            function () {
                return 1800000000;
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

    public function testShopMailSuccess()
    {
        $this->pdfOk();
        $r = $this->receipt();
        $res = $this->svc->deliver($r);

        $this->assertTrue($res['ok']);
        $this->assertSame('sent', $res['status']);
        $this->assertCount(1, Mail::$sent);
        $m = Mail::$sent[0];
        $this->assertSame('receipt_issued', $m['template']);
        $this->assertSame(2, $m['idLang']);
        $this->assertSame('jan@example.test', $m['to']);
        $this->assertSame('7/2026', $m['templateVars']['{document_number}']);
        $this->assertSame('ABCDEFGHI', $m['templateVars']['{order_reference}']);
        $this->assertSame('application/pdf', $m['fileAttachment']['mime']);
        $this->assertSame(self::PDF, $m['fileAttachment']['content']);
        $this->assertSame('ifirma-7-2026.pdf', $m['fileAttachment']['name']);
        $this->assertStringEndsWith('ifirma/mails/', str_replace('\\', '/', $m['templatePath']));
        $this->assertStringContainsString('Test Shop', $m['subject']);
        $this->assertStringContainsString('7/2026', $m['subject']);

        $row = $this->row();
        $this->assertSame('sent', $row['delivery_status']);
        $this->assertSame('shop_mail', $row['delivery_channel']);
        $this->assertSame(date('Y-m-d H:i:s', 1800000000), $row['delivered_at']);
        $this->assertSame('', $row['delivery_error']);
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
        $this->t->queue('GET', '#\.pdf#', $this->kod(201, 'Brak dokumentu'));
        $r = $this->receipt();
        $res = $this->svc->deliver($r);

        $this->assertFalse($res['ok']);
        $this->assertSame('failed', $res['status']);
        $this->assertCount(0, Mail::$sent);
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertNotSame('', $row['delivery_error']);
        $this->assertTrue($r->isIssued());
    }

    public function testIfirmaMailSuccess()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        Configuration::updateValue('IFIRMA_SEND_TEXT', 'Dziekujemy');
        $this->t->queue('POST', '#fakturakraj/send/77\.json#', $this->kod(0, 'ok'));
        $res = $this->svc->deliver($this->receipt('fakturakraj'));

        $this->assertTrue($res['ok']);
        $this->assertSame('ifirma_mail', $res['channel']);
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

        $this->assertFalse($res['ok']);
        $this->assertSame('failed', $res['status']);
        $this->assertNotSame('', $res['message']);
        $row = $this->row();
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame('ifirma_mail', $row['delivery_channel']);
        $this->assertSame($res['message'], $row['delivery_error']);
    }

    public function testIfirmaMailUnparseableResponseFails()
    {
        Configuration::updateValue('IFIRMA_SEND_CHANNEL', 'ifirma_mail');
        $this->t->queue('POST', '#send/77\.json#', array('body' => 'not json'));
        $res = $this->svc->deliver($this->receipt('fakturakraj'));

        $this->assertFalse($res['ok']);
        $this->assertSame('failed', $res['status']);
        $this->assertNotSame('', $res['message']);
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

        $this->assertTrue($res['ok']);
        $this->assertSame('shop_mail_fallback', $res['channel']);
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

        $this->assertFalse($res['ok']);
        $this->assertSame('failed', $res['status']);
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

        $this->assertFalse($res['ok']);
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

        $this->assertTrue($res['ok']);
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

        $this->assertTrue($res['ok']);
        $this->assertCount(1, Mail::$sent);
        $this->assertSame('sent', $this->row()['delivery_status']);
        $this->assertStringStartsWith('Test Shop: Invoice ', Mail::$sent[0]['subject']);
    }

    public function testExternalNotManualIsSkipped()
    {
        $r = $this->receipt('fakturakraj', 'external');
        $res = $this->svc->deliver($r);

        $this->assertTrue($res['ok']);
        $this->assertSame('skipped', $res['status']);
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

        $this->assertSame('skipped', $res['status']);
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

        $this->assertFalse($res['ok']);
        $this->assertSame('disabled', $res['status']);
        $this->assertSame('Delivery is disabled (channel none)', $res['message']);
        $this->assertCount(0, Mail::$sent);
        $this->assertSame($before, $this->row());
    }

    public function testUnissuedReceiptFails()
    {
        $r = $this->receipt();
        $r->ifirma_id = '';
        $res = $this->svc->deliver($r);

        $this->assertSame('failed', $res['status']);
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
}
