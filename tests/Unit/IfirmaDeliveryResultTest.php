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
 * IfirmaDeliveryResult: the immutable answer of IfirmaDeliveryService::deliver() and resend(). It
 * replaces the array('ok', 'status', 'channel', 'message') that deliver() used to return.
 */
class IfirmaDeliveryResultTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    public function testEachReaderAnswersWithItsOwnField()
    {
        // four different values, so a reader wired to another field shows
        $r = new IfirmaDeliveryResult(true, 'sent', 'ifirma_mail', 'delivered');

        $this->assertTrue($r->isSent());
        $this->assertSame('sent', $r->getStatus());
        $this->assertSame('ifirma_mail', $r->getChannel());
        $this->assertSame('delivered', $r->getMessage());
    }

    public function testRefusedLocallyFollowsTheRefusalReason()
    {
        $plain = new IfirmaDeliveryResult(false, 'failed', 'shop_mail', 'x');
        $this->assertFalse($plain->refusedLocally());
        $this->assertSame('', $plain->getRefusal());

        foreach (array('rate_limited', 'auth_blocked') as $reason) {
            $r = new IfirmaDeliveryResult(false, 'failed', 'shop_mail', 'x', $reason);
            $this->assertTrue($r->refusedLocally(), $reason);
            $this->assertSame($reason, $r->getRefusal());
        }
    }

    /**
     * The four results deliver() produces: the old ok flag is true for sent and skipped, false for
     * failed and disabled.
     *
     * @return array status => array(ok, status, expected isSent())
     */
    public function deliverResultProvider()
    {
        return array(
            'sent' => array(true, 'sent', true),
            'skipped' => array(true, 'skipped', true),
            'failed' => array(false, 'failed', false),
            'disabled' => array(false, 'disabled', false),
        );
    }

    /**
     * @dataProvider deliverResultProvider
     */
    public function testIsSentIsTheOldOkFlagThatTheCallersTest($ok, $status, $expected)
    {
        $r = new IfirmaDeliveryResult($ok, $status, 'shop_mail', '');

        $this->assertSame($expected, $r->isSent());
        $this->assertSame($status, $r->getStatus());
    }

    public function testValuesReadAsTheirDeclaredTypes()
    {
        // the readers of the old array cast the message and the status to string and tested the flag
        // for emptiness; the object does the same once, when it is built
        $loose = new IfirmaDeliveryResult(1, 200, 3, 42);
        $this->assertTrue($loose->isSent());
        $this->assertSame('200', $loose->getStatus());
        $this->assertSame('3', $loose->getChannel());
        $this->assertSame('42', $loose->getMessage());

        $unset = new IfirmaDeliveryResult(0, null, null, null);
        $this->assertFalse($unset->isSent());
        $this->assertSame('', $unset->getStatus());
        $this->assertSame('', $unset->getChannel());
        $this->assertSame('', $unset->getMessage());
    }

    public function fieldProvider()
    {
        return array(
            'ok' => array('ok'),
            'status' => array('status'),
            'channel' => array('channel'),
            'message' => array('message'),
        );
    }

    /**
     * @dataProvider fieldProvider
     */
    public function testNoFieldCanBeWrittenFromOutside($field)
    {
        $r = new IfirmaDeliveryResult(false, 'failed', 'shop_mail', 'refused');

        try {
            $r->$field = 'changed';
            $this->fail('the ' . $field . ' field accepted a write from outside');
        } catch (Error $e) {
            $this->assertStringContainsString('private property', $e->getMessage());
        }

        $this->assertFalse($r->isSent());
        $this->assertSame('failed', $r->getStatus());
        $this->assertSame('shop_mail', $r->getChannel());
        $this->assertSame('refused', $r->getMessage());
    }

    public function testItOffersNoMutator()
    {
        // immutable: the constructor and the readers are the whole public surface
        $this->assertSame(
            array('__construct', 'isSent', 'getStatus', 'getChannel', 'getMessage', 'refusedLocally', 'getRefusal'),
            get_class_methods(IfirmaDeliveryResult::class)
        );
    }
}
