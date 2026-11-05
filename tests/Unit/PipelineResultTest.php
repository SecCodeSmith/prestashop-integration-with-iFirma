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
        $r->delivery = new IfirmaDeliveryResult(false, 'failed', '', 'boom');
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

    // ---- the intent methods: what the pipeline records instead of writing the fields ------------

    /**
     * A result half-way through a run: every field holds something an intent method could overwrite,
     * and ok is false so that an intent that sets it shows.
     */
    private function usedResult()
    {
        $r = new IfirmaPipelineResult();
        $r->ok = false;
        $r->outcome = IfirmaPipelineResult::OUTCOME_UNKNOWN;
        $r->stoppedAt = IfirmaIssuancePipeline::STEP_CREATE;
        $r->message = 'earlier text';
        $r->receipt = new IfirmaReceipt();
        $r->guard = new IfirmaGuardResult();
        $r->delivery = new IfirmaDeliveryResult(true, 'sent', 'shop_mail', '');
        $r->steps = array(array('step' => 'hold', 'status' => 'ok', 'message' => ''));
        $r->trigger = IfirmaIssuancePipeline::TRIGGER_RESUME;

        return $r;
    }

    /** The fields that an intent method must not touch: the entities, the trigger and the step log. */
    private function untouchedBy(IfirmaPipelineResult $before, IfirmaPipelineResult $after)
    {
        $this->assertSame($before->receipt, $after->receipt);
        $this->assertSame($before->guard, $after->guard);
        $this->assertSame($before->delivery, $after->delivery);
        $this->assertSame($before->trigger, $after->trigger);
    }

    public function testAddStepAppendsOneEntryAfterTheOthers()
    {
        $r = new IfirmaPipelineResult();

        $r->addStep(IfirmaIssuancePipeline::STEP_HOLD, 'ok', '');
        $r->addStep(IfirmaIssuancePipeline::STEP_GUARD, 'skipped', 'duplicate check disabled');
        $r->addStep(IfirmaIssuancePipeline::STEP_KSEF, 'failed', 'KSeF niedostepny');

        $this->assertSame(
            array(
                array('step' => 'hold', 'status' => 'ok', 'message' => ''),
                array('step' => 'guard', 'status' => 'skipped', 'message' => 'duplicate check disabled'),
                array('step' => 'ksef', 'status' => 'failed', 'message' => 'KSeF niedostepny'),
            ),
            $r->steps
        );
    }

    public function testAddStepKeepsTheMessageAsAString()
    {
        $r = new IfirmaPipelineResult();

        $r->addStep('deliver', 'ok', 0);
        $r->addStep('deliver', 'ok', null);

        $this->assertSame('0', $r->steps[0]['message']);
        $this->assertSame('', $r->steps[1]['message']);
    }

    public function testAddStepChangesNothingButTheStepLog()
    {
        $r = $this->usedResult();
        $before = clone $r;

        $r->addStep('guard', 'ok', 'bypassed');

        $this->assertFalse($r->ok);
        $this->assertSame('unknown', $r->outcome);
        $this->assertSame('create', $r->stoppedAt);
        $this->assertSame('earlier text', $r->message);
        $this->untouchedBy($before, $r);
        $this->assertCount(2, $r->steps);
        $this->assertSame($before->steps[0], $r->steps[0]);
    }

    /**
     * @return array intent method => array(method, outcome, message)
     */
    public function outcomeIntents()
    {
        return array(
            'markIssued' => array('markIssued', 'issued', 'document issued'),
            'markAdopted' => array('markAdopted', 'adopted', 'existing document adopted'),
            'alreadyIssued' => array('alreadyIssued', 'already_issued', 'document already issued'),
        );
    }

    /** @dataProvider outcomeIntents */
    public function testAnOutcomeIntentMakesTheRunOkWithItsOutcomeAndMessage($method, $outcome, $message)
    {
        $r = new IfirmaPipelineResult();

        $r->$method();

        $this->assertTrue($r->ok);
        $this->assertSame($outcome, $r->outcome);
        $this->assertSame($message, $r->message);
        $this->assertNull($r->stoppedAt);
        $this->assertSame(array(), $r->steps);
    }

    /** @dataProvider outcomeIntents */
    public function testAnOutcomeIntentOverwritesAnEarlierOutcomeAndMessageAndNothingElse($method, $outcome, $message)
    {
        $r = $this->usedResult();
        $before = clone $r;

        $r->$method();

        $this->assertTrue($r->ok);
        $this->assertSame($outcome, $r->outcome);
        $this->assertSame($message, $r->message);
        // where an earlier step stopped, and what was logged, are not the intent's to change
        $this->assertSame('create', $r->stoppedAt);
        $this->assertSame($before->steps, $r->steps);
        $this->untouchedBy($before, $r);
    }

    public function testTheOutcomeIntentsUseTheOutcomeConstants()
    {
        $issued = new IfirmaPipelineResult();
        $issued->markIssued();
        $adopted = new IfirmaPipelineResult();
        $adopted->markAdopted();
        $already = new IfirmaPipelineResult();
        $already->alreadyIssued();

        $this->assertSame(IfirmaPipelineResult::OUTCOME_ISSUED, $issued->outcome);
        $this->assertSame(IfirmaPipelineResult::OUTCOME_ADOPTED, $adopted->outcome);
        $this->assertSame(IfirmaPipelineResult::OUTCOME_ALREADY_ISSUED, $already->outcome);
    }

    /**
     * @return array label => array(outcome, step, message): the stops the pipeline makes
     */
    public function stops()
    {
        return array(
            'held at the hold step' => array(IfirmaPipelineResult::OUTCOME_HELD, IfirmaIssuancePipeline::STEP_HOLD, 'held: data request #4 pending'),
            'suspected at the guard' => array(IfirmaPipelineResult::OUTCOME_SUSPECTED, IfirmaIssuancePipeline::STEP_GUARD, 'Suspected duplicate: 1 matching document(s) found in iFirma'),
            'rate limited at the guard' => array(IfirmaPipelineResult::OUTCOME_RATE_LIMITED, IfirmaIssuancePipeline::STEP_GUARD, 'rate limit guard'),
            'inconclusive at the guard' => array(IfirmaPipelineResult::OUTCOME_INCONCLUSIVE, IfirmaIssuancePipeline::STEP_GUARD, 'connection refused'),
            'unknown at the create step' => array(IfirmaPipelineResult::OUTCOME_UNKNOWN, IfirmaIssuancePipeline::STEP_CREATE, 'timeout'),
            'error at the create step' => array(IfirmaPipelineResult::OUTCOME_ERROR, IfirmaIssuancePipeline::STEP_CREATE, 'bad structure'),
        );
    }

    /** @dataProvider stops */
    public function testStopAtEndsTheRunWithoutADocumentAndLogsTheStoppedStep($outcome, $step, $message)
    {
        $r = new IfirmaPipelineResult();
        $r->ok = true;

        $r->stopAt($outcome, $step, $message);

        $this->assertFalse($r->ok);
        $this->assertSame($outcome, $r->outcome);
        $this->assertSame($step, $r->stoppedAt);
        $this->assertSame($message, $r->message);
        $this->assertSame(array(array('step' => $step, 'status' => 'stopped', 'message' => $message)), $r->steps);
    }

    public function testStopAtAddsTheStoppedStepAfterTheOnesLoggedBefore()
    {
        $r = $this->usedResult();
        $before = clone $r;

        $r->stopAt(IfirmaPipelineResult::OUTCOME_SUSPECTED, IfirmaIssuancePipeline::STEP_GUARD, 'two candidates');

        $this->assertSame(
            array(
                array('step' => 'hold', 'status' => 'ok', 'message' => ''),
                array('step' => 'guard', 'status' => 'stopped', 'message' => 'two candidates'),
            ),
            $r->steps
        );
        $this->assertSame('guard', $r->stoppedAt);
        $this->untouchedBy($before, $r);
    }

    public function testStopAtKeepsTheMessageAsAStringOnTheStepAndOnTheResult()
    {
        $r = new IfirmaPipelineResult();

        $r->stopAt(IfirmaPipelineResult::OUTCOME_ERROR, IfirmaIssuancePipeline::STEP_CREATE, 404);

        $this->assertSame('404', $r->message);
        $this->assertSame('404', $r->steps[0]['message']);
    }

    public function testStopStepLogsAndNamesTheStoppedStepWhileTheRunKeepsItsDocument()
    {
        $r = new IfirmaPipelineResult();
        $r->markIssued();
        $r->addStep(IfirmaIssuancePipeline::STEP_NUMBER, 'ok', '');
        $before = clone $r;

        $r->stopStep(IfirmaIssuancePipeline::STEP_DELIVER, 'mail server down');

        $this->assertTrue($r->ok);
        $this->assertSame('issued', $r->outcome);
        $this->assertSame('document issued', $r->message);
        $this->assertSame('deliver', $r->stoppedAt);
        $this->assertSame(
            array(
                array('step' => 'number', 'status' => 'ok', 'message' => ''),
                array('step' => 'deliver', 'status' => 'stopped', 'message' => 'mail server down'),
            ),
            $r->steps
        );
        $this->untouchedBy($before, $r);
    }

    public function testStopStepKeepsTheMessageAsAString()
    {
        $r = new IfirmaPipelineResult();

        $r->stopStep(IfirmaIssuancePipeline::STEP_DELIVER, 0);

        $this->assertSame('0', $r->steps[0]['message']);
    }
}
