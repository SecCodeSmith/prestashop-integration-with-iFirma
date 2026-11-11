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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaSchema.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPdfCopyRetention.php';

class PdfCopyRetentionTest extends TestCase
{
    use IfirmaPdfCopyDb;

    const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const NOW = 1800000000;
    const DAY = 86400;

    /**
     * @param mixed $days the stored setting; null leaves the key unset
     * @return IfirmaPdfCopyRetention
     */
    private function retention($days)
    {
        $values = $days === null ? array() : array('IFIRMA_PDF_COPY_DAYS' => $days);
        $settings = function ($key) use ($values) {
            return isset($values[$key]) ? $values[$key] : false;
        };

        return new IfirmaPdfCopyRetention($settings, function () {
            return PdfCopyRetentionTest::NOW;
        });
    }

    private static function storedDaysAgo($days)
    {
        return date('Y-m-d H:i:s', self::NOW - $days * self::DAY);
    }

    public function testACopyStoredWithinTheKeptDaysIsCovered()
    {
        $this->assertTrue($this->retention('30')->covers(self::storedDaysAgo(29)));
    }

    public function testACopyOlderThanTheKeptDaysIsNotCovered()
    {
        $this->assertFalse($this->retention('30')->covers(self::storedDaysAgo(31)));
    }

    public function testACopyStoredExactlyTheKeptDaysAgoIsCovered()
    {
        $this->assertTrue($this->retention('30')->covers(self::storedDaysAgo(30)));
    }

    public function testALoweredSettingUncoversACopyThatWasCoveredBefore()
    {
        $stored = self::storedDaysAgo(10);

        $this->assertTrue($this->retention('30')->covers($stored));
        $this->assertFalse($this->retention('7')->covers($stored));
    }

    public function testZeroDaysStoresNothingAndCoversNothing()
    {
        $retention = $this->retention('0');

        $this->assertSame(0, $retention->days());
        $this->assertFalse($retention->isStoring());
        $this->assertFalse($retention->covers(self::storedDaysAgo(0)));
    }

    public function testAnUnparsableStoredTimeIsNotCovered()
    {
        $this->assertFalse($this->retention('30')->covers('not a time'));
        $this->assertFalse($this->retention('30')->covers(''));
        $this->assertFalse($this->retention('30')->covers(null));
    }

    public function testTheCutoffIsNowMinusTheKeptDays()
    {
        $this->assertSame(self::NOW - 30 * self::DAY, $this->retention('30')->cutoffTimestamp());
    }

    public function testAPositiveSettingIsStoring()
    {
        $this->assertTrue($this->retention('1')->isStoring());
    }

    /**
     * @return array[] stored value => array(stored value)
     */
    public function unusableSettings()
    {
        return array(
            'missing' => array(null),
            'empty' => array(''),
            'text' => array('abc'),
            'negative' => array('-1'),
            'fraction' => array('1.5'),
            'above the range' => array('400'),
        );
    }

    /**
     * @dataProvider unusableSettings
     */
    public function testAMissingOrMalformedSettingReadsAsTheInstalledDefault($stored)
    {
        $defaults = IfirmaSchema::configurationDefaults();

        $this->assertSame((int) $defaults['IFIRMA_PDF_COPY_DAYS'], $this->retention($stored)->days());
    }

    public function testTheHighestAllowedSettingIsKept()
    {
        $this->assertSame(365, $this->retention('365')->days());
    }

    private function threeStoredCopies()
    {
        IfirmaTestState::resetAll();
        $this->scriptPdfCopyDb();
        foreach (array(1, 2, 3) as $idReceipt) {
            $this->seedPdfCopy($idReceipt, '%PDF-' . $idReceipt, self::FINGERPRINT, '2026-11-10 00:00:00');
        }

        return new IfirmaPdfCopyStore();
    }

    public function testStoringOffClearsEveryStoredCopy()
    {
        $store = $this->threeStoredCopies();

        $this->assertTrue($this->retention('0')->clearWhenOff($store));

        $this->assertSame(0, $this->pdfCopyRowCount());
    }

    public function testStoringOnKeepsEveryStoredCopy()
    {
        $store = $this->threeStoredCopies();

        $this->assertTrue($this->retention('30')->clearWhenOff($store));

        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testAFailedDeleteIsReportedWhenStoringIsOff()
    {
        $store = $this->threeStoredCopies();
        Db::getInstance()->whenExecute('/^DELETE FROM `ps_ifirma_pdf_copy`/', false);

        $this->assertFalse($this->retention('0')->clearWhenOff($store));

        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testWithoutInjectedSeamsTheConfigurationAndTheClockAreRead()
    {
        IfirmaTestState::resetAll();
        Configuration::updateValue('IFIRMA_PDF_COPY_DAYS', '12');
        $retention = new IfirmaPdfCopyRetention();

        $this->assertSame(12, $retention->days());
        $this->assertTrue($retention->covers(date('Y-m-d H:i:s', time() - 11 * self::DAY)));
        $this->assertFalse($retention->covers(date('Y-m-d H:i:s', time() - 13 * self::DAY)));
    }
}
