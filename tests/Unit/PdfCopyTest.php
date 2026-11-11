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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPdfCopy.php';

/**
 * The stored copy of a document PDF: what it reports and when it counts as intact.
 */
class PdfCopyTest extends TestCase
{
    const FINGERPRINT = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    const STORED_AT = '2026-11-11 09:30:00';

    private function copyOf($content, $byteLength = null)
    {
        return new IfirmaPdfCopy(
            '17',
            self::FINGERPRINT,
            $byteLength === null ? strlen($content) : $byteLength,
            self::STORED_AT,
            $content
        );
    }

    public function testItReportsTheValuesItWasBuiltWithAsTypedValues()
    {
        $copy = new IfirmaPdfCopy('17', self::FINGERPRINT, '12', self::STORED_AT, '%PDF-1.4 body');

        $this->assertSame(17, $copy->receiptId());
        $this->assertSame(self::FINGERPRINT, $copy->fingerprint());
        $this->assertSame(12, $copy->byteLength());
        $this->assertSame(self::STORED_AT, $copy->storedAt());
        $this->assertSame('%PDF-1.4 body', $copy->content());
    }

    public function testACopyWhoseContentMatchesItsLengthAndStartsWithThePdfMarkerIsIntact()
    {
        $this->assertTrue($this->copyOf("%PDF-1.4\n%binary \x00\xff end")->isIntact());
    }

    public function testATruncatedContentIsNotIntact()
    {
        $stored = "%PDF-1.4 the whole document";

        $this->assertFalse($this->copyOf(substr($stored, 0, 10), strlen($stored))->isIntact());
    }

    public function testAContentLongerThanTheRecordedLengthIsNotIntact()
    {
        $this->assertFalse($this->copyOf('%PDF-1.4 and more', 8)->isIntact());
    }

    public function testAContentThatIsNotAPdfIsNotIntact()
    {
        $this->assertFalse($this->copyOf('<html>not a pdf</html>')->isIntact());
    }

    public function testACopyReadWithoutItsContentIsNotIntactAndHasNoContent()
    {
        $copy = new IfirmaPdfCopy(17, self::FINGERPRINT, 1000, self::STORED_AT);

        $this->assertNull($copy->content());
        $this->assertFalse($copy->isIntact());
        $this->assertSame(1000, $copy->byteLength());
    }

    public function testAnEmptyContentIsNotIntact()
    {
        $this->assertFalse($this->copyOf('')->isIntact());
    }
}
