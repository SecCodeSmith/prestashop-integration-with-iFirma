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
 * Spec customer-receipt-visibility, "A customer's downloads are capped per day": a save writes the download accounting
 * (last_download_at, download_day, download_count) only when its object changed it, so a writer that loaded the
 * receipt before a download and saves a sync field afterwards neither resets the day's count nor reopens the
 * throttle window.
 */
class ReceiptDownloadStaleSaveTest extends TestCase
{
    const DOWNLOAD_COLUMNS = array('last_download_at', 'download_day', 'download_count');

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function savedReceipt(array $fields = array())
    {
        $r = new IfirmaReceipt();
        $r->id_order = 10;
        $r->id_customer = 5;
        $r->document_type = 'fakturaparagon';
        $r->ifirma_id = '710';
        $r->status = 'created';
        foreach ($fields as $name => $value) {
            $r->$name = $value;
        }
        $r->save(false, false);
        IfirmaTestState::clearWrites();

        return (int) $r->id;
    }

    private function row($id)
    {
        return IfirmaTestState::objects('ifirma_receipt')[$id];
    }

    private function lastWrittenColumns()
    {
        $log = IfirmaTestState::writes('ifirma_receipt');

        return $log[count($log) - 1]['columns'];
    }

    /** What a download does to the row, by its own atomic statements. */
    private function downloadedMeanwhile($id, $stamp, $day, $count)
    {
        ObjectModel::$registry['ifirma_receipt'][$id]['last_download_at'] = $stamp;
        ObjectModel::$registry['ifirma_receipt'][$id]['download_day'] = $day;
        ObjectModel::$registry['ifirma_receipt'][$id]['download_count'] = $count;
    }

    public function testAStaleSaveOfASyncFieldDoesNotResetTheDaysCountOrReopenTheThrottleWindow()
    {
        $id = $this->savedReceipt();
        $stale = new IfirmaReceipt($id);
        $this->downloadedMeanwhile($id, '2026-11-10 10:15:05', '2026-11-10', 7);

        $stale->sync_error = 'timeout';
        $stale->save();

        $row = $this->row($id);
        $this->assertSame('2026-11-10 10:15:05', $row['last_download_at']);
        $this->assertSame('2026-11-10', $row['download_day']);
        $this->assertSame(7, (int) $row['download_count']);
        $this->assertSame(array(), array_intersect(self::DOWNLOAD_COLUMNS, $this->lastWrittenColumns()));
        $this->assertContains('sync_error', $this->lastWrittenColumns());
    }

    public function testAnObjectFilledFromARowAlsoRemembersTheDownloadAccounting()
    {
        $id = $this->savedReceipt(array('download_day' => '2026-11-10', 'download_count' => 2));
        $stale = new IfirmaReceipt();
        $stale->hydrate(array('id_ifirma_receipt' => $id) + $this->row($id));
        $this->downloadedMeanwhile($id, '2026-11-10 10:15:05', '2026-11-10', 3);

        $stale->sync_error = 'x';
        $stale->save();

        $this->assertSame(3, (int) $this->row($id)['download_count']);
        $this->assertSame(array(), array_intersect(self::DOWNLOAD_COLUMNS, $this->lastWrittenColumns()));
    }

    public function testAColumnTheCallerChangedIsWritten()
    {
        $id = $this->savedReceipt(array('download_day' => '2026-11-09', 'download_count' => 4));
        $receipt = new IfirmaReceipt($id);

        $receipt->download_count = 0;
        $receipt->save();

        $this->assertSame(0, (int) $this->row($id)['download_count']);
        $this->assertContains('download_count', $this->lastWrittenColumns());
    }

    public function testASaveWithNoChangeToTheDownloadAccountingWritesNoneOfIt()
    {
        $id = $this->savedReceipt(array('last_download_at' => '2026-11-10 10:00:00', 'download_day' => '2026-11-10', 'download_count' => 1));
        $receipt = new IfirmaReceipt($id);

        $receipt->status = 'created';
        $receipt->last_sync_at = '2026-11-10 10:20:00';
        $receipt->save();

        $this->assertSame(array(), array_intersect(self::DOWNLOAD_COLUMNS, $this->lastWrittenColumns()));
    }

    public function testEveryWriteEnsuresTheDownloadCountColumnsFirst()
    {
        Db::getInstance()->whenGetValue("/COLUMN_NAME = 'download_/", '0');

        $r = new IfirmaReceipt();
        $r->id_order = 1;
        $r->save();

        $alters = array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'ALTER TABLE') === 0 && strpos($q, 'download_') !== false;
        }));
        $this->assertSame(array(
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `download_day` DATE NULL',
            'ALTER TABLE `ps_ifirma_receipt` ADD COLUMN `download_count` INT(11) UNSIGNED NOT NULL DEFAULT 0',
        ), $alters);
    }
}
