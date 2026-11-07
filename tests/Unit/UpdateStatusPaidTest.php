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
 * IfirmaReceiptService::updateStatusPaid(): registers a payment at iFirma and records it on the
 * receipt. Pins the columns each path stores and the number of database writes it makes (the
 * ObjectModel stub logs one write per save()), so that moving the paid status into the receipt
 * entity cannot widen or drop a write.
 */
class UpdateStatusPaidTest extends TestCase
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaReceiptService */
    private $service;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->service = new IfirmaReceiptService($client, new Module());
    }

    /**
     * An issued, unpaid receipt of order 15 that holds a value in every column a payment must
     * leave alone, so that a stray assignment shows up as a change.
     *
     * @return IfirmaReceipt saved, with the writes of saving it forgotten
     */
    private function issuedReceipt()
    {
        $values = array(
            'id_order' => 15,
            'id_customer' => 5,
            'id_shop' => 1,
            'document_type' => 'fakturakraj',
            'ifirma_id' => '77',
            'ifirma_number' => '7/2026',
            'status' => IfirmaReceipt::STATUS_CREATED,
            'paid_amount' => 5.5,
            'security_token' => 'tok-abc',
            'request_payload' => '{"request":1}',
            'response_payload' => '{"response":"earlier"}',
            'last_error' => 'earlier error',
            'source' => IfirmaReceipt::SOURCE_EXTERNAL,
            'order_reference' => 'PS-ORDER-15-ABCDEFGHI',
            'issue_date' => '2026-01-02',
            'delivery_status' => IfirmaReceipt::DELIVERY_SENT,
            'delivery_channel' => 'shop_mail',
            'delivered_at' => '2026-01-03 10:00:00',
            'delivery_error' => 'earlier delivery error',
            'remote_paid_amount' => 5.5,
            'remote_sent' => true,
            'remote_draft' => true,
            'remote_missing' => true,
            'ksef_status' => 'OK',
            'ksef_number' => 'KSEF-1',
            'ksef_sent_at' => '2026-01-04 11:00:00',
            'ksef_error' => 'earlier ksef error',
            'linked_document' => 'KOR 1/2026',
            'last_sync_at' => '2026-01-05 12:00:00',
            'sync_error' => 'earlier sync error',
            'duplicate_candidates' => '[{"id":1}]',
            'id_data_request' => 9,
        );
        $receipt = new IfirmaReceipt();
        foreach ($values as $column => $value) {
            $receipt->$column = $value;
        }
        $receipt->save();
        IfirmaTestState::clearWrites();

        return $receipt;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @return array the stored columns of the receipt
     */
    private function storedRow(IfirmaReceipt $receipt)
    {
        $rows = IfirmaTestState::objects('ifirma_receipt');

        return $rows[(int) $receipt->id];
    }

    /**
     * @return array the one write the path must make: op, table, id, row
     */
    private function onlyWrite()
    {
        $writes = IfirmaTestState::writes('ifirma_receipt');
        $this->assertSame(array('update'), array_column($writes, 'op'), 'exactly one update and nothing else');

        return $writes[0];
    }

    /**
     * Asserts that a write stored $set and left every other column as it was.
     *
     * @param array $before the stored columns before the call
     * @param array $set    column => the value the path had to store
     * @param array $row    the stored columns after it
     */
    private function assertStoredOnly(array $before, array $set, array $row)
    {
        // The ObjectModel stub stamps date_upd on every update.
        unset($before['date_upd'], $row['date_upd']);

        $this->assertSame(array_keys($before), array_keys($row), 'the same columns are stored');
        foreach ($before as $column => $value) {
            $this->assertSame(array_key_exists($column, $set) ? $set[$column] : $value, $row[$column], $column);
        }
    }

    /**
     * @return array the POST requests that were sent
     */
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

    private function queueAccepted($body = '{"response":{"Kod":0,"Informacja":"ok"}}')
    {
        $this->t->queue('POST', '#faktury/wplaty/#', array('body' => $body));
    }

    public function testAcceptedPaymentStoresTheStatusAmountAndAnswerInOneWrite()
    {
        $receipt = $this->issuedReceipt();
        $before = $this->storedRow($receipt);
        $this->queueAccepted('{"response":{"Kod":0,"Informacja":"Wpłata zarejestrowana"}}');

        $result = $this->service->updateStatusPaid($receipt, 123.456);

        $this->assertSame($receipt, $result);
        $write = $this->onlyWrite();
        $this->assertSame((int) $receipt->id, $write['id']);
        // paid_amount is the rounded amount, last_error is cleared and response_payload is the
        // decoded answer, pretty-printed with the Polish letters unescaped.
        $this->assertStoredOnly($before, array(
            'status' => 'paid',
            'paid_amount' => 123.46,
            'last_error' => '',
            'response_payload' => "{\n    \"response\": {\n        \"Kod\": 0,\n        \"Informacja\": \"Wpłata zarejestrowana\"\n    }\n}",
        ), $write['row']);
        $posts = $this->posts();
        $this->assertCount(1, $posts);
        $this->assertStringContainsString('faktury/wplaty/prz_faktura_kraj/77.json', $posts[0]['url']);
        $this->assertSame('{"Kwota":123.46}', $posts[0]['body']);
    }

    public function testWithoutAnAmountTheOrdersRealPaymentIsRegistered()
    {
        $order = new Order();
        $order->id = 15;
        $order->total_paid_tax_incl = 150.456;
        $order->totalPaidReal = 99.999;
        Order::register($order);
        $receipt = $this->issuedReceipt();
        $before = $this->storedRow($receipt);
        $this->queueAccepted();

        $this->service->updateStatusPaid($receipt);

        $this->assertStoredOnly($before, array(
            'status' => 'paid',
            'paid_amount' => 100.0,
            'last_error' => '',
            'response_payload' => "{\n    \"response\": {\n        \"Kod\": 0,\n        \"Informacja\": \"ok\"\n    }\n}",
        ), $this->onlyWrite()['row']);
        $this->assertSame('{"Kwota":100}', $this->posts()[0]['body']);
    }

    public function testWithoutAnAmountTheOrderTotalIsRegisteredWhenPaymentIsMarkedOnCreate()
    {
        Configuration::updateValue('IFIRMA_MARK_PAID_ON_CREATE', 1);
        $order = new Order();
        $order->id = 15;
        $order->total_paid_tax_incl = 150.456;
        $order->totalPaidReal = 99.999;
        Order::register($order);
        $receipt = $this->issuedReceipt();
        $this->queueAccepted();

        $this->service->updateStatusPaid($receipt);

        $this->assertSame(150.46, $this->onlyWrite()['row']['paid_amount']);
        $this->assertSame('{"Kwota":150.46}', $this->posts()[0]['body']);
    }

    public function testRejectedPaymentRecordsTheMessageInOneWriteAndKeepsTheRest()
    {
        $receipt = $this->issuedReceipt();
        $before = $this->storedRow($receipt);
        $this->queueAccepted('{"response":{"Kod":201,"Informacja":"Kwota przekracza należność"}}');

        try {
            $this->service->updateStatusPaid($receipt, 50);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame('iFirma rejected the payment: Kwota przekracza należność', $e->getMessage());
        }

        // Neither the status, the amount nor the stored answer change on a rejection.
        $this->assertStoredOnly($before, array('last_error' => 'Kwota przekracza należność'), $this->onlyWrite()['row']);
    }

    public function testRejectionWithoutAMessageRecordsTheGenericOne()
    {
        $receipt = $this->issuedReceipt();
        $before = $this->storedRow($receipt);
        $this->queueAccepted('{"response":{"Kod":201}}');

        try {
            $this->service->updateStatusPaid($receipt, 50);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame('iFirma rejected the payment: Unknown iFirma error.', $e->getMessage());
        }

        $this->assertStoredOnly($before, array('last_error' => 'Unknown iFirma error.'), $this->onlyWrite()['row']);
    }

    public function testUnreachableIFirmaRecordsTheTransportErrorInOneWriteAndKeepsTheRest()
    {
        $receipt = $this->issuedReceipt();
        $before = $this->storedRow($receipt);
        $this->t->queue('POST', '#faktury/wplaty/#', array('status' => 0, 'error' => 'timeout'));

        try {
            $this->service->updateStatusPaid($receipt, 50);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame('Could not reach iFirma: cURL error: timeout', $e->getMessage());
        }

        $this->assertStoredOnly($before, array('last_error' => 'cURL error: timeout'), $this->onlyWrite()['row']);
    }

    public function testReceiptWithoutAnIssuedDocumentIsRefusedBeforeAnyCallOrWrite()
    {
        $receipt = new IfirmaReceipt();
        $receipt->id_order = 15;
        $receipt->status = IfirmaReceipt::STATUS_ERROR;
        $receipt->save();
        IfirmaTestState::clearWrites();

        try {
            $this->service->updateStatusPaid($receipt, 50);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame('This order has no issued iFirma document yet.', $e->getMessage());
        }

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
        $this->assertSame('error', $receipt->status);
    }

    private function registerOrder($total)
    {
        $order = new Order();
        $order->id = 15;
        $order->total_paid_tax_incl = $total;
        Order::register($order);
    }

    private function assertAmountRefused($amount, $message)
    {
        $receipt = $this->issuedReceipt();
        try {
            $this->service->updateStatusPaid($receipt, $amount);
            $this->fail('expected an exception');
        } catch (Exception $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame(array(), $this->t->calls, 'no iFirma call');
        $this->assertSame(array(), IfirmaTestState::writes('ifirma_receipt'));
    }

    public function testCommaDecimalAccepted()
    {
        $this->registerOrder(20.0);
        $receipt = $this->issuedReceipt();
        $this->queueAccepted();

        $this->service->updateStatusPaid($receipt, '12,50');

        $this->assertSame('{"Kwota":12.5}', $this->posts()[0]['body']);
    }

    public function testNonNumericAmountRefused()
    {
        $this->registerOrder(20.0);
        $this->assertAmountRefused('abc', 'The amount must be a positive number with at most two decimals.');
    }

    public function testArrayAmountRefused()
    {
        $this->registerOrder(20.0);
        $this->assertAmountRefused(array(1), 'The amount must be a positive number with at most two decimals.');
    }

    public function testNegativeAmountRefused()
    {
        $this->registerOrder(20.0);
        $this->assertAmountRefused('-5', 'The amount must be a positive number with at most two decimals.');
    }

    public function testZeroAmountRefused()
    {
        $this->registerOrder(20.0);
        $this->assertAmountRefused('0', 'The amount must be a positive number with at most two decimals.');
    }

    public function testAmountAboveTheOrderTotalRefused()
    {
        $this->registerOrder(20.0);
        $this->assertAmountRefused('25', 'The amount cannot exceed the order total (20.00).');
    }

    public function testAmountEqualToTheOrderTotalAccepted()
    {
        $this->registerOrder(20.0);
        $receipt = $this->issuedReceipt();
        $this->queueAccepted();

        $this->service->updateStatusPaid($receipt, '20.00');

        $this->assertSame('{"Kwota":20}', $this->posts()[0]['body']);
    }

    public function testEmptyAmountUsesTheOrderAmount()
    {
        $order = new Order();
        $order->id = 15;
        $order->total_paid_tax_incl = 20.0;
        $order->totalPaidReal = 20.0;
        Order::register($order);
        $receipt = $this->issuedReceipt();
        $this->queueAccepted();

        $this->service->updateStatusPaid($receipt, null);

        $this->assertSame('{"Kwota":20}', $this->posts()[0]['body']);
    }
}
