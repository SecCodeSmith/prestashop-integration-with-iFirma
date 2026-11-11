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

require_once __DIR__ . '/../smoke/SmokeOutputMask.php';

class SmokeOutputMaskTest extends TestCase
{
    public function testTokenInAUrlIsMaskedToItsLastFourCharacters()
    {
        $masked = SmokeOutputMask::mask('GET /index.php?fc=module&controller=cron&token=xxxxxxxxxxxx6789 -> 403');

        $this->assertSame('GET /index.php?fc=module&controller=cron&token=****6789 -> 403', $masked);
    }

    public function testLongHexKeyIsMasked()
    {
        $masked = SmokeOutputMask::mask('expected ' . str_repeat('1', 64) . ', got other');

        $this->assertSame('expected ****1111, got other', $masked);
    }

    public function testConfiguredSecretIsMaskedWhereverItAppears()
    {
        $masked = SmokeOutputMask::mask('cron says: s3cret-value-xyz ok', array('s3cret-value-xyz'));

        $this->assertSame('cron says: ****-xyz ok', $masked);
    }

    public function testShortSecretsAreNotUsedAsPatterns()
    {
        $this->assertSame('a 1 b', SmokeOutputMask::mask('a 1 b', array('1')));
    }

    public function testOrdinaryLabelsAreLeftAlone()
    {
        $label = 'IfirmaIssuancePipeline outcome issued, receipt duplicate_suspected_by_amount, order 123 -- HTTP 200';

        $this->assertSame($label, SmokeOutputMask::mask($label));
    }
}
