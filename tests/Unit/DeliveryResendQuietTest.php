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
 * Spec receipt-delivery, "A manual resend right after a delivery is refused": a manual Send again of a document whose
 * delivery was recorded as sent less than 30 seconds earlier sends nothing, changes nothing and says the document was
 * sent a moment ago; each document of a bulk resend is treated the same.
 */
class DeliveryResendQuietTest extends TestCase
{
    const PDF = "%PDF-1.4\nfake body\n%%EOF";
    const NOW = 1800000000;
    const JUST_SENT_MESSAGE = 'This document was sent a moment ago. Wait before sending it again.';

    /** @var int */
    public $now;

    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaDeliveryService */
    private $svc;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        Configuration::$values['IFIRMA_SHOP_URL'] = 'https://www.Hialumed.pl/';
        $this->now = self::NOW;
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $module = new Module();
        $self = $this;
        $this->svc = new IfirmaDeliveryService(
            $client,
            new IfirmaReceiptService($client, $module),
            $module,
            function () use ($self) {
                return $self->now;
            },
            function ($seconds) {
            }
        );
        $c = new Customer();
        $c->id = 5;
        $c->email = 'jan@example.test';
        $c->firstname = 'Jan';
        $c->lastname = 'Kowalski';
        Customer::register($c);
        $o = new Order();
        $o->id = 15;
        $o->id_customer = 5;
        $o->id_lang = 2;
        $o->reference = 'ABCDEFGHI';
        Order::register($o);
        $this->t->queue('GET', '#\.pdf#', function () {
            return array('body' => DeliveryResendQuietTest::PDF);
        });
    }

    /** A saved receipt that carries $fields; the seeding writes are cleared. */
    private function seeded(array $fields)
    {
        $r = new IfirmaReceipt();
        $r->id_order = 15;
        $r->document_type = 'fakturaparagon';
        $r->ifirma_id = '77';
        $r->ifirma_number = '7/2026';
        $r->status = 'created';
        $r->source = 'module';
        $r->issue_date = '2026-11-01';
        foreach ($fields as $name => $value) {
            $r->$name = $value;
        }
        $r->save();
        IfirmaTestState::clearWrites();

        return $r;
    }

    private function sentSecondsAgo($seconds)
    {
        return $this->seeded(array(
            'delivery_status' => 'sent',
            'delivery_channel' => 'shop_mail',
            'delivered_at' => date('Y-m-d H:i:s', $this->now - $seconds),
        ));
    }

    private function row()
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return reset($rows);
    }

    public function testASecondClickTwoSecondsAfterTheEmailWentOutSendsNothingAndSaysSo()
    {
        $r = $this->seeded(array('delivery_status' => 'none'));
        $first = $this->svc->resend($r);
        $this->now += 2;

        $second = $this->svc->resend($r);

        $this->assertTrue($first->isSent());
        $this->assertCount(1, Mail::$sent, 'one e-mail only');
        $this->assertFalse($second->isSent());
        $this->assertSame('failed', $second->getStatus());
        $this->assertSame(self::JUST_SENT_MESSAGE, $second->getMessage());
        $this->assertTrue($second->isJustSent());
        $this->assertFalse($second->refusedLocally());
    }

    public function testADeliveryTheCronMadeFiveSecondsAgoIsNotSentAgain()
    {
        $r = $this->sentSecondsAgo(5);

        $res = $this->svc->resend($r);

        $this->assertSame(array(), Mail::$sent);
        $this->assertCount(0, $this->t->calls, 'the PDF is not even fetched');
        $this->assertTrue($res->isJustSent());
        $this->assertSame(self::JUST_SENT_MESSAGE, $res->getMessage());
    }

    public function testTheRefusalChangesNothingInTheRecord()
    {
        $r = $this->sentSecondsAgo(5);
        $before = $this->row();

        $this->svc->resend($r);

        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertSame($before, $this->row());
    }

    public function testAResendAtTheThirtiethSecondWorksAsBefore()
    {
        $r = $this->sentSecondsAgo(30);

        $res = $this->svc->resend($r);

        $this->assertTrue($res->isSent());
        $this->assertCount(1, Mail::$sent);
    }

    public function testAResendTwentyNineSecondsAfterTheDeliveryIsStillRefused()
    {
        $r = $this->sentSecondsAgo(29);

        $this->assertTrue($this->svc->resend($r)->isJustSent());
    }

    public function testAResendOneMinuteAfterTheLastDeliveryEmailsTheCustomerAsBefore()
    {
        $r = $this->sentSecondsAgo(60);

        $res = $this->svc->resend($r);

        $this->assertTrue($res->isSent());
        $this->assertCount(1, Mail::$sent);
        $this->assertSame(date('Y-m-d H:i:s', $this->now), $this->row()['delivered_at']);
    }

    public function testAFailedDeliveryCanBeResentAtOnce()
    {
        $r = $this->seeded(array(
            'delivery_status' => 'failed',
            'delivery_error' => 'smtp down',
            'delivered_at' => date('Y-m-d H:i:s', $this->now - 2),
        ));

        $res = $this->svc->resend($r);

        $this->assertTrue($res->isSent());
        $this->assertFalse($res->isJustSent());
        $this->assertCount(1, Mail::$sent);
    }

    public function testADocumentThatWasNeverDeliveredCanBeSentAtOnce()
    {
        $r = $this->seeded(array('delivery_status' => 'none'));

        $this->assertTrue($this->svc->resend($r)->isSent());
    }

    public function testADeliveryTimeInTheFutureDoesNotBlockTheResend()
    {
        $r = $this->sentSecondsAgo(-3600);

        $this->assertTrue($this->svc->resend($r)->isSent());
    }

    public function testTheCallersStaleCopyDoesNotHideARecentDelivery()
    {
        $stale = $this->seeded(array('delivery_status' => 'failed', 'delivery_error' => 'x'));
        // the cron delivered between the caller's load and the lock
        $stored = IfirmaReceipt::loadFresh((int) $stale->id);
        $stored->delivery_status = 'sent';
        $stored->delivered_at = date('Y-m-d H:i:s', $this->now - 3);
        $stored->save();

        $res = $this->svc->resend($stale);

        $this->assertTrue($res->isJustSent());
        $this->assertSame(array(), Mail::$sent);
    }

    public function testTheAutomaticDeliveryAtIssuanceIsNotAffected()
    {
        $r = $this->sentSecondsAgo(1);

        $res = $this->svc->deliver($r);

        $this->assertFalse($res->isJustSent());
    }

    public function testABulkResendRunAgainWithinThirtySecondsReportsEveryDocumentAsJustSentAndDoesNotStop()
    {
        $delivery = new class {
            public function resend($receipt, $order = null)
            {
                return new IfirmaDeliveryResult(false, 'failed', 'shop_mail', DeliveryResendQuietTest::JUST_SENT_MESSAGE, IfirmaDeliveryResult::REFUSAL_JUST_SENT);
            }
        };
        $ids = array();
        foreach (array(15, 16, 17) as $idOrder) {
            $r = $this->seeded(array('id_order' => $idOrder, 'delivery_status' => 'sent', 'delivered_at' => date('Y-m-d H:i:s', $this->now - 4)));
            $ids[] = (int) $r->id;
        }
        $runner = new IfirmaBulkRunner(new Module(), function () {
            return DeliveryResendQuietTest::NOW;
        });

        $data = $runner->setDeliveryService($delivery)->run('resend', $ids, 9);

        $this->assertSame('', $data['stopped']);
        $this->assertSame(array(), $data['remaining']);
        $this->assertCount(3, $data['results']);
        foreach ($data['results'] as $result) {
            $this->assertSame('skipped', $result['outcome']);
            $this->assertSame(DeliveryResendQuietTest::JUST_SENT_MESSAGE, $result['message']);
        }
    }

    public function testTheBulkRunnerWithTheRealServiceSendsNothingForARecentlyDeliveredDocument()
    {
        $r = $this->sentSecondsAgo(4);
        $runner = new IfirmaBulkRunner(new Module(), function () {
            return DeliveryResendQuietTest::NOW;
        });

        $data = $runner->setDeliveryService($this->svc)->run('resend', array((int) $r->id), 9);

        $this->assertSame('skipped', $data['results'][0]['outcome']);
        $this->assertSame(array(), Mail::$sent);
    }
}
