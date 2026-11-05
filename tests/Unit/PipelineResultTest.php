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
 * IfirmaPipelineResult: the value object that one IfirmaIssuancePipeline::run() returns.
 */
class PipelineResultTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    public function testANewResultIsAnUnissuedErrorWithNothingRecorded()
    {
        $r = new IfirmaPipelineResult();

        $this->assertFalse($r->ok);
        $this->assertSame('error', $r->outcome);
        $this->assertNull($r->stoppedAt);
        $this->assertSame('', $r->message);
        $this->assertNull($r->receipt);
        $this->assertNull($r->guard);
        $this->assertNull($r->delivery);
        $this->assertSame(array(), $r->steps);
        $this->assertSame('manual', $r->trigger);
    }
}
