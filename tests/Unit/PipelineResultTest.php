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

    public function testTheOutcomeConstantsKeepTheStringsThatAreStoredAndShown()
    {
        $constants = (new ReflectionClass('IfirmaPipelineResult'))->getConstants();

        $this->assertSame(
            array(
                'OUTCOME_ISSUED' => 'issued',
                'OUTCOME_ALREADY_ISSUED' => 'already_issued',
                'OUTCOME_ADOPTED' => 'adopted',
                'OUTCOME_HELD' => 'held',
                'OUTCOME_SUSPECTED' => 'suspected',
                'OUTCOME_INCONCLUSIVE' => 'inconclusive',
                'OUTCOME_UNKNOWN' => 'unknown',
                'OUTCOME_RATE_LIMITED' => 'rate_limited',
                'OUTCOME_ERROR' => 'error',
                'OUTCOME_DISABLED' => 'disabled',
                'OUTCOME_NOT_CONFIGURED' => 'not_configured',
            ),
            $constants
        );
    }

    public function testToArrayOfANewResultIsTheErrorOutcomeAndNoStoppingStep()
    {
        $this->assertSame(
            array('outcome' => 'error', 'stopped_at' => null),
            (new IfirmaPipelineResult())->toArray()
        );
    }

    public function testToArrayCarriesOnlyTheOutcomeAndTheStoppingStep()
    {
        $r = new IfirmaPipelineResult();
        $r->ok = true;
        $r->outcome = IfirmaPipelineResult::OUTCOME_ISSUED;
        $r->stoppedAt = IfirmaIssuancePipeline::STEP_DELIVER;
        $r->message = 'document issued';
        $r->receipt = new IfirmaReceipt();
        $r->guard = new IfirmaGuardResult();
        $r->delivery = array('ok' => false, 'status' => 'failed', 'channel' => '', 'message' => 'boom');
        $r->steps = array(array('step' => 'deliver', 'status' => 'stopped', 'message' => 'boom'));
        $r->trigger = IfirmaIssuancePipeline::TRIGGER_RESUME;

        $this->assertSame(array('outcome' => 'issued', 'stopped_at' => 'deliver'), $r->toArray());
    }

    public function testToArrayReadsTheResultAsItIsWhenCalled()
    {
        $r = new IfirmaPipelineResult();
        $first = $r->toArray();

        $r->outcome = IfirmaPipelineResult::OUTCOME_HELD;
        $r->stoppedAt = IfirmaIssuancePipeline::STEP_HOLD;

        $this->assertSame(array('outcome' => 'error', 'stopped_at' => null), $first);
        $this->assertSame(array('outcome' => 'held', 'stopped_at' => 'hold'), $r->toArray());
    }
}
