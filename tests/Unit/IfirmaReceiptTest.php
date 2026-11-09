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
 * An IfirmaReceipt that records its persistence calls, so a test can prove that an intent
 * method does not write on its own.
 */
class IfirmaReceiptWriteSpy extends IfirmaReceipt
{
    /** @var string[] persistence methods called, in order */
    public $writes = array();

    public function save($nullValues = false, $autoDate = true)
    {
        $this->writes[] = 'save';

        return parent::save($nullValues, $autoDate);
    }

    public function add($autoDate = true, $nullValues = false)
    {
        $this->writes[] = 'add';

        return parent::add($autoDate, $nullValues);
    }

    public function update($nullValues = false)
    {
        $this->writes[] = 'update';

        return parent::update($nullValues);
    }
}

/**
 * The status intents of the receipt entity: markError, markUnknown, markHeld, markSuspected,
 * markPaid and recordDelivery. Each one sets its fields in memory only; the caller keeps its own
 * save(), so the number of database writes on a path stays what it was.
 *
 * It also pins findDueForSync(), the finder of the receipts the status cron has to look at.
 */
class IfirmaReceiptTest extends TestCase
{
    use IfirmaSentQueries;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    /**
     * One value per persisted column, none of which an intent method writes: a field that an
     * intent touches by mistake shows up as a change.
     *
     * @return array column => value
     */
    private static function stored()
    {
        return array(
            'id_order' => 7,
            'id_customer' => 3,
            'id_shop' => 1,
            'document_type' => 'fakturakraj',
            'ifirma_id' => 'F-501',
            'ifirma_number' => '12/2026',
            'status' => IfirmaReceipt::STATUS_CREATED,
            'paid_amount' => 99.9,
            'security_token' => 'tok-abc',
            'request_payload' => '{"request":1}',
            'response_payload' => '{"response":1}',
            'last_error' => 'earlier error',
            'date_add' => '2026-01-01 08:00:00',
            'date_upd' => '2026-01-01 09:00:00',
            'source' => IfirmaReceipt::SOURCE_EXTERNAL,
            'order_reference' => 'ABCDEFGHI',
            'issue_date' => '2026-01-02',
            'delivery_status' => IfirmaReceipt::DELIVERY_SENT,
            'delivery_channel' => 'sentinel_channel',
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
            'last_download_at' => '2026-01-06 13:00:00',
            'sync_error' => 'earlier sync error',
            'duplicate_candidates' => '[{"id":1}]',
            'id_data_request' => 9,
        );
    }

    /**
     * @param string $class IfirmaReceipt, or a subclass of it
     * @return IfirmaReceipt a new, unsaved receipt holding stored()
     */
    private function receipt($class = 'IfirmaReceipt')
    {
        $receipt = new $class();
        foreach (self::stored() as $field => $value) {
            $receipt->$field = $value;
        }

        return $receipt;
    }

    /**
     * Asserts that $set holds on the receipt and that every other property is as it was.
     *
     * @param array         $set     field => value the intent had to set
     * @param array         $before  get_object_vars() taken before the intent
     * @param IfirmaReceipt $receipt
     */
    private function assertOnlySet(array $set, array $before, IfirmaReceipt $receipt)
    {
        $after = get_object_vars($receipt);

        $this->assertSame(array_keys($before), array_keys($after), 'no property was added or removed');
        foreach ($after as $field => $value) {
            $this->assertSame(array_key_exists($field, $set) ? $set[$field] : $before[$field], $value, $field);
        }
    }

    public function testFixtureCoversEveryPersistedColumn()
    {
        $columns = array_keys(IfirmaReceipt::$definition['fields']);
        $fixture = array_keys(self::stored());
        sort($columns);
        sort($fixture);

        $this->assertSame($columns, $fixture, 'Add the new column to stored() so the intent tests keep guarding it.');
    }

    public function testMarkErrorSetsStatusAndMessageOnly()
    {
        $receipt = $this->receipt();
        $before = get_object_vars($receipt);

        $receipt->markError('iFirma rejected the document: Kod 201');

        $this->assertOnlySet(
            array('status' => 'error', 'last_error' => 'iFirma rejected the document: Kod 201'),
            $before,
            $receipt
        );
    }

    public function testMarkUnknownSetsStatusAndMessageOnly()
    {
        $receipt = $this->receipt();
        $before = get_object_vars($receipt);

        $receipt->markUnknown('cURL error: operation timed out');

        $this->assertOnlySet(
            array('status' => 'unknown', 'last_error' => 'cURL error: operation timed out'),
            $before,
            $receipt
        );
    }

    public function testMarkHeldSetsStatusAndReasonOnly()
    {
        $receipt = $this->receipt();
        $before = get_object_vars($receipt);

        $receipt->markHeld('held: data request #5 pending');

        $this->assertOnlySet(
            array('status' => 'held', 'last_error' => 'held: data request #5 pending'),
            $before,
            $receipt
        );
    }

    public function testMarkSuspectedSetsTheStatusTheReasonAndNoDocumentOnly()
    {
        // The fixture holds an issued external document, which a suspected record must not keep.
        $receipt = $this->receipt();
        $before = get_object_vars($receipt);

        $receipt->markSuspected('Suspected duplicate: 2 matching document(s) found in iFirma');

        // The candidates and the order reference depend on the order, so the guard stores them.
        $this->assertOnlySet(
            array(
                'status' => 'duplicate_suspected',
                'ifirma_id' => '',
                'source' => 'module',
                'last_error' => 'Suspected duplicate: 2 matching document(s) found in iFirma',
            ),
            $before,
            $receipt
        );
    }

    public function testMarkPaidSetsTheStatusOnly()
    {
        $receipt = $this->receipt();
        $before = get_object_vars($receipt);

        $receipt->markPaid();

        // The callers differ on the rest: registering a payment stores the amount, clears the
        // error and keeps iFirma's answer, a sync reads the amount into remote_paid_amount, and an
        // adopted document stores its amount whatever the status. So the amount, the error and
        // the answer are left to them.
        $this->assertOnlySet(array('status' => 'paid'), $before, $receipt);
    }

    public static function deliveryOutcomes()
    {
        return array(
            'sent' => array(IfirmaReceipt::DELIVERY_SENT, 'ifirma_mail', ''),
            'failed' => array(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail_fallback', 'SMTP: connection refused'),
            'skipped, channel none' => array(IfirmaReceipt::DELIVERY_SKIPPED, 'none', ''),
            'skipped, external document' => array(
                IfirmaReceipt::DELIVERY_SKIPPED,
                'shop_mail',
                'external document - not auto-delivered',
            ),
            'skipped, adopted external document' => array(
                IfirmaReceipt::DELIVERY_SKIPPED,
                '',
                'external document - not auto-delivered',
            ),
        );
    }

    /**
     * @dataProvider deliveryOutcomes
     */
    public function testRecordDeliverySetsStatusChannelAndErrorOnly($status, $channel, $error)
    {
        $receipt = $this->receipt();
        $before = get_object_vars($receipt);

        $receipt->recordDelivery($status, $channel, $error);

        $this->assertOnlySet(
            array('delivery_status' => $status, 'delivery_channel' => $channel, 'delivery_error' => $error),
            $before,
            $receipt
        );
        // The callers differ on the timestamp (a send stamps it, a disabled channel clears it,
        // the other outcomes keep it), so recordDelivery leaves it to them.
        $this->assertSame('2026-01-03 10:00:00', $receipt->delivered_at);
        $this->assertSame(IfirmaReceipt::STATUS_CREATED, $receipt->status);
    }

    public static function messages()
    {
        return array(
            'plain' => array('Could not reach iFirma: cURL error: timeout'),
            'empty' => array(''),
            'surrounding whitespace is kept' => array("  Kod 201:\r\nNie znaleziono kontrahenta \t\n"),
            'markup, quotes and Polish letters' => array('Odrzucono <b>"fakturę"</b> & \'zapłatę\' - łączność'),
            'long text is not truncated' => array(str_repeat('x', 5000)),
            // ReceiptService stores iFirma's Informacja node raw, which need not be a string.
            'null is kept' => array(null),
            'integer is kept' => array(201),
        );
    }

    /**
     * @dataProvider messages
     */
    public function testMessagesAreStoredAsGiven($message)
    {
        $error = $this->receipt();
        $error->markError($message);
        $unknown = $this->receipt();
        $unknown->markUnknown($message);
        $held = $this->receipt();
        $held->markHeld($message);
        $suspected = $this->receipt();
        $suspected->markSuspected($message);
        $delivery = $this->receipt();
        $delivery->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', $message);

        $this->assertSame($message, $error->last_error);
        $this->assertSame($message, $unknown->last_error);
        $this->assertSame($message, $held->last_error);
        $this->assertSame($message, $suspected->last_error);
        $this->assertSame($message, $delivery->delivery_error);
    }

    public function testALaterOutcomeReplacesTheEarlierOne()
    {
        $receipt = $this->receipt();

        $receipt->markHeld('held: data request #5 pending');
        $receipt->markUnknown('cURL error: operation timed out');
        $receipt->markError('iFirma rejected the document');
        $receipt->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'SMTP down');
        $receipt->recordDelivery(IfirmaReceipt::DELIVERY_SENT, 'shop_mail', '');

        $this->assertSame('error', $receipt->status);
        $this->assertSame('iFirma rejected the document', $receipt->last_error);
        $this->assertSame('sent', $receipt->delivery_status);
        $this->assertSame('', $receipt->delivery_error);
    }

    public static function intents()
    {
        return array(
            'markError' => array(
                function (IfirmaReceipt $receipt) {
                    $receipt->markError('iFirma rejected the document');
                },
                array('status' => 'error', 'last_error' => 'iFirma rejected the document'),
            ),
            'markUnknown' => array(
                function (IfirmaReceipt $receipt) {
                    $receipt->markUnknown('cURL error: operation timed out');
                },
                array('status' => 'unknown', 'last_error' => 'cURL error: operation timed out'),
            ),
            'markHeld' => array(
                function (IfirmaReceipt $receipt) {
                    $receipt->markHeld('held: data request #5 pending');
                },
                array('status' => 'held', 'last_error' => 'held: data request #5 pending'),
            ),
            'markSuspected' => array(
                function (IfirmaReceipt $receipt) {
                    $receipt->markSuspected('Suspected duplicate: 1 matching document(s) found in iFirma');
                },
                array(
                    'status' => 'duplicate_suspected',
                    'ifirma_id' => '',
                    'source' => 'module',
                    'last_error' => 'Suspected duplicate: 1 matching document(s) found in iFirma',
                ),
            ),
            'markPaid' => array(
                function (IfirmaReceipt $receipt) {
                    $receipt->markPaid();
                },
                array('status' => 'paid'),
            ),
            'recordDelivery' => array(
                function (IfirmaReceipt $receipt) {
                    $receipt->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'SMTP down');
                },
                array('delivery_status' => 'failed', 'delivery_channel' => 'shop_mail', 'delivery_error' => 'SMTP down'),
            ),
        );
    }

    /**
     * @dataProvider intents
     */
    public function testAnIntentOnANewRowWritesNothing(Closure $intent, array $set)
    {
        $receipt = $this->receipt('IfirmaReceiptWriteSpy');

        $intent($receipt);

        $this->assertSame(array(), $receipt->writes);
        $this->assertNull($receipt->id);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_receipt'));
        foreach ($set as $field => $value) {
            $this->assertSame($value, $receipt->$field, $field);
        }
    }

    /**
     * @dataProvider intents
     */
    public function testAStoredRowChangesOnlyWhenTheCallerSaves(Closure $intent, array $set)
    {
        $receipt = $this->receipt('IfirmaReceiptWriteSpy');
        $receipt->save();
        $receipt->writes = array();
        $storedBefore = IfirmaTestState::objects('ifirma_receipt');
        $this->assertCount(1, $storedBefore);

        $intent($receipt);

        $this->assertSame(array(), $receipt->writes, 'the intent wrote on its own');
        $this->assertSame($storedBefore, IfirmaTestState::objects('ifirma_receipt'), 'the stored row changed before save()');

        $this->assertTrue($receipt->save());

        $this->assertCount(1, array_keys($receipt->writes, 'save'), 'exactly one save() per path');
        $reloaded = new IfirmaReceipt($receipt->id);
        foreach ($set as $field => $value) {
            $this->assertSame($value, $reloaded->$field, $field);
        }
    }

    // ---- findDueForSync(): the receipts the status cron has to look at -----------------------

    /**
     * The query findDueForSync() sends, line by line. The indentation inside the string is part of
     * the text; the line endings are not (see sentQueries()).
     *
     * @param string $staleBefore the text of the cut-off, as it stands in the SQL
     * @param int    $limit
     * @return string
     */
    private function dueReceiptsSql($staleBefore, $limit)
    {
        return implode("\n", array(
            'SELECT id_ifirma_receipt FROM `ps_ifirma_receipt`',
            "             WHERE ifirma_id != '' AND status != 'error'",
            "             AND (status != 'paid' OR remote_sent = 0 OR last_sync_at IS NULL",
            // deliberate change (task 5.3): a document that waits for KSeF is always due, so the cron sees its acceptance
            "                  OR last_sync_at < '" . $staleBefore . "' OR delivery_status = 'awaiting_ksef')",
            '             ORDER BY last_sync_at ASC, id_ifirma_receipt ASC',
            '             LIMIT ' . $limit,
        ));
    }

    /**
     * @param int $idOrder tells the stored receipts apart
     * @return IfirmaReceipt a saved receipt
     */
    private function storedReceipt($idOrder)
    {
        $receipt = $this->receipt();
        $receipt->id_order = $idOrder;
        $receipt->save();

        return $receipt;
    }

    /**
     * Scripts the query to answer with these receipts' ids, in this order.
     *
     * @param array $ids ids as the database gives them: the values of id_ifirma_receipt
     */
    private function dueRowsAre(array $ids)
    {
        $rows = array();
        foreach ($ids as $id) {
            $rows[] = array('id_ifirma_receipt' => (string) $id);
        }
        Db::getInstance()->whenExecuteS('/FROM `ps_ifirma_receipt`/', $rows);
    }

    public function testFindDueForSyncSendsOneQueryWithTheGivenCutOffAndLimit()
    {
        IfirmaReceipt::findDueForSync('2026-10-31 12:00:00', 50);

        $this->assertSame(array($this->dueReceiptsSql('2026-10-31 12:00:00', 50)), $this->sentQueries());
    }

    public function testFindDueForSyncEscapesTheCutOff()
    {
        IfirmaReceipt::findDueForSync("2026-10-31 12:00:00' OR '1'='1", 5);

        $this->assertSame(
            array($this->dueReceiptsSql("2026-10-31 12:00:00\\' OR \\'1\\'=\\'1", 5)),
            $this->sentQueries()
        );
    }

    public static function dueLimits()
    {
        return array(
            'a limit of 1 is sent as it is' => array(1, 1),
            'a limit of 0 is raised to 1' => array(0, 1),
            'a negative limit is raised to 1' => array(-5, 1),
            'a numeric string is sent as a whole number' => array('25', 25),
            'a fraction is cut down to a whole number' => array(7.9, 7),
        );
    }

    /**
     * @dataProvider dueLimits
     */
    public function testFindDueForSyncSendsAWholeLimitOfAtLeastOne($given, $sent)
    {
        IfirmaReceipt::findDueForSync('2026-10-31 12:00:00', $given);

        $this->assertSame(array($this->dueReceiptsSql('2026-10-31 12:00:00', $sent)), $this->sentQueries());
    }

    public function testFindDueForSyncReturnsTheStoredReceiptsInTheOrderOfTheRows()
    {
        $first = $this->storedReceipt(31);
        $second = $this->storedReceipt(32);
        $third = $this->storedReceipt(33);
        $this->dueRowsAre(array($third->id, $first->id, $second->id));

        $receipts = IfirmaReceipt::findDueForSync('2026-10-31 12:00:00', 500);

        // the keys are 0, 1, 2, and the order is the database's, not the id order
        $this->assertSame(array(0, 1, 2), array_keys($receipts));
        $this->assertSame(array(33, 31, 32), array_map(function ($receipt) {
            return $receipt->id_order;
        }, $receipts));
        $this->assertContainsOnlyInstancesOf('IfirmaReceipt', $receipts);
        $this->assertEquals(new IfirmaReceipt((int) $third->id), $receipts[0]);
    }

    public function testFindDueForSyncSkipsARowWhoseReceiptDoesNotLoad()
    {
        $first = $this->storedReceipt(34);
        $second = $this->storedReceipt(35);
        $this->dueRowsAre(array($first->id, 9999, 0, $second->id));

        $receipts = IfirmaReceipt::findDueForSync('2026-10-31 12:00:00', 500);

        $this->assertSame(array(0, 1), array_keys($receipts));
        $this->assertSame(array(34, 35), array_map(function ($receipt) {
            return $receipt->id_order;
        }, $receipts));
    }

    public function testFindDueForSyncReturnsAnEmptyListWhenNoRowIsDue()
    {
        $this->assertSame(array(), IfirmaReceipt::findDueForSync('2026-10-31 12:00:00', 500));
    }
    public function testMarkQueuedSetsStatusAndMessageOnly()
    {
        $receipt = $this->receipt();
        $receipt->ifirma_id = '';
        $before = get_object_vars($receipt);

        $receipt->markQueued('iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.');

        $this->assertOnlySet(
            array('status' => 'queued', 'last_error' => 'iFirma rate limit guard: 80 requests in the current minute (cap 80). Retry shortly.'),
            $before,
            $receipt
        );
    }

    public function testAnIssuedRecordCannotBeQueued()
    {
        $receipt = $this->receipt();
        $before = get_object_vars($receipt);

        try {
            $receipt->markQueued('refused');
            $this->fail('expected LogicException');
        } catch (LogicException $e) {
            $this->assertSame('A document that is already issued cannot be queued.', $e->getMessage());
        }

        $this->assertOnlySet(array(), $before, $receipt);
    }

    public function testAQueuedRecordCanStillBeCreatedAndIsNotOfferedIssueAnyway()
    {
        $receipt = $this->receipt();
        $receipt->ifirma_id = '';
        $receipt->markQueued('');

        $this->assertTrue($receipt->canCreate());
        $this->assertFalse($receipt->canIssueAnyway());
        $this->assertFalse($receipt->isIssued());
    }

    public function testFindQueuedAsksForTheQueuedRecordsOldestFirstUpToTheLimit()
    {
        IfirmaReceipt::findQueued(25);

        $this->assertSame(array(implode("\n", array(
            "SELECT id_ifirma_receipt FROM `ps_ifirma_receipt` WHERE status = 'queued'",
            '             ORDER BY date_upd ASC, id_ifirma_receipt ASC',
            '             LIMIT 25',
        ))), $this->sentQueries());
    }

    public function testFindQueuedRaisesALimitBelowOneToOne()
    {
        IfirmaReceipt::findQueued(0);

        $this->assertStringContainsString('LIMIT 1', $this->sentQueries()[0]);
    }

    public function testFindQueuedReturnsTheStoredRecordsInTheOrderOfTheRows()
    {
        $first = $this->storedReceipt(41);
        $second = $this->storedReceipt(42);
        $this->dueRowsAre(array($second->id, $first->id));

        $records = IfirmaReceipt::findQueued(10);

        $this->assertSame(array(42, 41), array_map(function ($record) {
            return $record->id_order;
        }, $records));
    }

    public function testCountQueuedCountsOnlyTheQueuedRecords()
    {
        Db::getInstance()->whenGetValue('/SELECT COUNT\(\*\) FROM `ps_ifirma_receipt` WHERE status = \'queued\'/', '4');

        $this->assertSame(4, IfirmaReceipt::countQueued());
    }
}
