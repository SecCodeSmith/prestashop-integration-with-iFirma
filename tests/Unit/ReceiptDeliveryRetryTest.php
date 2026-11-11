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
 * The retry state of a receipt (delivery_attempts, delivery_retry_at) and the finder of the deliveries that are due.
 */
class ReceiptDeliveryRetryTest extends TestCase
{
    const NOW = '2026-11-10 12:00:00';

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        IfirmaSchema::resetEnsureCache();
        Cache::$local = array();
        $this->scriptDueSelection();
    }

    /** Evaluates the finder's SQL against the stored rows: the timestamp of the query decides what is due. */
    private function scriptDueSelection()
    {
        $rows = function ($sql, $limit) {
            preg_match("/delivery_retry_at <= '([^']+)'/", $sql, $m);
            $now = isset($m[1]) ? $m[1] : '';
            $out = array();
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                $due = (string) $row['delivery_retry_at'];
                if ($row['delivery_status'] === 'failed' && $due !== '' && $due > '2000-01-01 00:00:00' && $due <= $now) {
                    $out[] = array('id_ifirma_receipt' => (string) $id, 'due' => $due, 'id' => $id);
                }
            }
            usort($out, function ($a, $b) {
                return strcmp($a['due'], $b['due']) ?: $a['id'] - $b['id'];
            });

            return array_slice($out, 0, $limit);
        };
        $regex = "/FROM `ps_ifirma_receipt` WHERE delivery_status = 'failed' AND ifirma_id != '' AND delivery_retry_at IS NOT NULL/";
        Db::getInstance()->whenExecuteS($regex, function ($sql) use ($rows) {
            return $rows($sql, preg_match('/LIMIT (\d+)/', $sql, $m) ? (int) $m[1] : 500);
        });
        Db::getInstance()->whenGetValue($regex, function ($sql) use ($rows) {
            return count($rows($sql, 100000));
        });
    }

    private function receipt($status, $due, $attempts = 1)
    {
        $r = new IfirmaReceipt();
        $r->id_order = 1;
        $r->delivery_status = $status;
        $r->delivery_attempts = $attempts;
        $r->delivery_retry_at = $due;
        $r->date_add = '2026-11-01 09:00:00';
        $r->save(false, false);

        return (int) $r->id;
    }

    private function ids(array $records)
    {
        return array_map(function ($r) {
            return (int) $r->id;
        }, $records);
    }

    private function now()
    {
        return strtotime(self::NOW);
    }

    public function testAFailedDeliveryWhoseDueTimeHasPassedIsFound()
    {
        $due = $this->receipt('failed', '2026-11-10 11:59:59');

        $this->assertSame(array($due), $this->ids(IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now())));
        $this->assertSame(1, IfirmaReceipt::countDeliveriesDueForRetry($this->now()));
    }

    public function testADeliveryDueExactlyNowIsFound()
    {
        $due = $this->receipt('failed', self::NOW);

        $this->assertSame(array($due), $this->ids(IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now())));
    }

    public function testADeliveryNotDueYetIsNotFound()
    {
        $this->receipt('failed', '2026-11-10 12:00:01');

        $this->assertSame(array(), IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now()));
        $this->assertSame(0, IfirmaReceipt::countDeliveriesDueForRetry($this->now()));
    }

    public function testAnOldFailureWithoutADueTimeIsNeverFound()
    {
        $this->receipt('failed', null, 0);

        $this->assertSame(array(), IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now()));
        $this->assertSame(0, IfirmaReceipt::countDeliveriesDueForRetry($this->now()));
    }

    public function testAZeroDateIsNeverFoundEvenWhenTheDatabaseStoredNullAsOne()
    {
        $this->receipt('failed', '0000-00-00 00:00:00');

        $this->assertSame(array(), IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now()));
    }

    public function testADeliveryThatIsNotFailedIsNotFound()
    {
        foreach (array('sent', 'skipped', 'none', 'awaiting_ksef') as $status) {
            $this->receipt($status, '2026-11-10 10:00:00');
        }

        $this->assertSame(array(), IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now()));
    }

    public function testTheOldestDueTimeComesFirstAndTheLimitApplies()
    {
        $late = $this->receipt('failed', '2026-11-10 11:30:00');
        $early = $this->receipt('failed', '2026-11-10 10:00:00');
        $middle = $this->receipt('failed', '2026-11-10 11:00:00');

        $this->assertSame(array($early, $middle), $this->ids(IfirmaReceipt::findDeliveriesDueForRetry(2, $this->now())));
        $this->assertSame(3, IfirmaReceipt::countDeliveriesDueForRetry($this->now()));
        $this->assertSame(array($early, $middle, $late), $this->ids(IfirmaReceipt::findDeliveriesDueForRetry(10, $this->now())));
    }

    public function testTheQueryCarriesAnIntegerLimitAndTheFormattedTimestamp()
    {
        IfirmaReceipt::findDeliveriesDueForRetry('7 OR 1=1', $this->now());
        IfirmaReceipt::findDeliveriesDueForRetry(0, $this->now());

        $sql = array_values(array_filter(Db::getInstance()->queries, function ($q) {
            return strpos($q, 'delivery_retry_at <=') !== false;
        }));
        $this->assertStringContainsString("delivery_retry_at <= '2026-11-10 12:00:00'", $sql[0]);
        $this->assertStringEndsWith('LIMIT 7', $sql[0]);
        $this->assertStringEndsWith('LIMIT 1', $sql[1], 'a limit below one is raised to one');
    }

    public function testRecordingASendWithoutADueTimeClearsItAndKeepsTheAttempts()
    {
        $r = new IfirmaReceipt();
        $r->delivery_attempts = 2;
        $r->delivery_retry_at = '2026-11-10 14:00:00';

        $r->recordDelivery(IfirmaReceipt::DELIVERY_SENT, 'shop_mail', '', $r->getDeliveryAttempts(), null);

        $this->assertNull($r->delivery_retry_at);
        $this->assertSame(2, $r->delivery_attempts);
    }

    public function testRecordingAFailureWithoutAScheduleLeavesTheStateAsItWas()
    {
        $r = new IfirmaReceipt();
        $r->delivery_attempts = 1;
        $r->delivery_retry_at = '2026-11-10 14:00:00';

        $r->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'boom');

        $this->assertSame(1, $r->delivery_attempts);
        $this->assertSame('2026-11-10 14:00:00', $r->delivery_retry_at);
    }

    public function testRecordingAFailureWithAScheduleStoresAttemptsAndDueTimeTogether()
    {
        $r = new IfirmaReceipt();

        $r->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'boom', 1, strtotime('2026-11-10 12:15:00'));

        $this->assertSame(1, $r->delivery_attempts);
        $this->assertSame('2026-11-10 12:15:00', $r->delivery_retry_at);
    }

    public function testRecordingAFailureWithoutADueTimeEndsTheRetries()
    {
        $r = new IfirmaReceipt();
        $r->delivery_retry_at = '2026-11-10 12:15:00';

        $r->recordDelivery(IfirmaReceipt::DELIVERY_FAILED, 'shop_mail', 'boom', 3, null);

        $this->assertSame(3, $r->delivery_attempts);
        $this->assertNull($r->delivery_retry_at);
    }

    public function testTheRetryStateIsExposedForTheDisplay()
    {
        $r = new IfirmaReceipt();
        $r->delivery_status = IfirmaReceipt::DELIVERY_FAILED;
        $r->delivery_attempts = '2';
        $r->delivery_retry_at = '2026-11-10 14:00:00';

        $this->assertSame(2, $r->getDeliveryAttempts());
        $this->assertSame(3, IfirmaReceipt::maxDeliveryAttempts());
        $this->assertSame('2026-11-10 14:00:00', $r->getDeliveryRetryAt());
        $this->assertTrue($r->hasDeliveryRetryScheduled());
    }

    public function testNoRetryIsScheduledForAnOldFailureOrAZeroDate()
    {
        $old = new IfirmaReceipt();
        $old->delivery_status = IfirmaReceipt::DELIVERY_FAILED;
        $this->assertNull($old->getDeliveryRetryAt());
        $this->assertFalse($old->hasDeliveryRetryScheduled());

        $zero = new IfirmaReceipt();
        $zero->delivery_status = IfirmaReceipt::DELIVERY_FAILED;
        $zero->delivery_retry_at = '0000-00-00 00:00:00';
        $this->assertNull($zero->getDeliveryRetryAt());
        $this->assertFalse($zero->hasDeliveryRetryScheduled());
    }
    /** Scripts the column probe as "missing" and records how many receipt writes had happened at each ALTER. */
    private function missingRetryColumns()
    {
        $writesAtAlter = array();
        $db = Db::getInstance();
        $db->whenGetValue("/COLUMN_NAME = 'delivery_/", '0');
        $db->whenExecute('/^ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `delivery_/', function ($sql) use (&$writesAtAlter) {
            $writesAtAlter[] = count(IfirmaTestState::writes('ifirma_receipt'));

            return true;
        });

        return function () use (&$writesAtAlter) {
            return $writesAtAlter;
        };
    }

    private function columnChecks()
    {
        return count(array_filter(Db::getInstance()->queries, function ($q) {
            return strpos($q, "COLUMN_NAME = 'delivery_attempts'") !== false;
        }));
    }

    public function testANewReceiptGetsTheRetryColumnsBeforeItsInsert()
    {
        $altersAt = $this->missingRetryColumns();

        $r = new IfirmaReceipt();
        $r->id_order = 1;
        $r->save();

        $this->assertSame(array(0, 0), $altersAt(), 'both ALTER statements ran before the first write');
        $this->assertCount(1, IfirmaTestState::writes('ifirma_receipt'));
    }

    public function testAnExistingReceiptGetsTheRetryColumnsBeforeItsUpdate()
    {
        $r = new IfirmaReceipt();
        $r->id_order = 1;
        $r->save();
        IfirmaSchema::resetEnsureCache();
        IfirmaTestState::clearWrites();
        $altersAt = $this->missingRetryColumns();

        $r->delivery_status = 'sent';
        $r->save();

        $this->assertSame(array(0, 0), $altersAt());
        $this->assertSame('update', IfirmaTestState::writes('ifirma_receipt')[0]['op']);
    }

    public function testASecondSaveInTheSameRequestChecksTheColumnsNoMore()
    {
        $this->missingRetryColumns();
        $r = new IfirmaReceipt();
        $r->id_order = 1;
        $r->save();
        $checks = $this->columnChecks();

        $r->delivery_status = 'sent';
        $r->save();

        $this->assertSame($checks, $this->columnChecks());
    }

    public function testNoAlterIsIssuedWhenTheColumnsExist()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = '(delivery|download)_/", '1');

        $r = new IfirmaReceipt();
        $r->id_order = 1;
        $r->save();

        $this->assertSame(array(), array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0;
        }));
    }

    public function testAFailedAlterDoesNotStopTheSaveFromBeingAttempted()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'delivery_/", '0');
        Db::getInstance()->whenExecute('/^ALTER TABLE `ps_ifirma_receipt`/', false);

        $r = new IfirmaReceipt();
        $r->id_order = 1;
        $r->save();

        $this->assertCount(1, IfirmaTestState::writes('ifirma_receipt'), 'the write is attempted and reports its own error');
    }

    public function testLoadFreshDropsTheCachedObjectOfThatIdOnly()
    {
        $id = $this->receipt('failed', null, 0);
        Cache::$local = array(
            'objectmodel_IfirmaReceipt_' . $id . '_1_0' => 'stale',
            'objectmodel_IfirmaReceipt_' . ($id + 1) . '_1_0' => 'other',
        );

        $fresh = IfirmaReceipt::loadFresh($id);

        $this->assertSame($id, (int) $fresh->id);
        $this->assertSame(array('objectmodel_IfirmaReceipt_' . ($id + 1) . '_1_0' => 'other'), Cache::$local);
    }
}
