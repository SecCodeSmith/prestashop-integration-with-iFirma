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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Result of one IfirmaIssuancePipeline::run().
 *
 * ok          true when an issued document exists for the order after the run
 * outcome     issued | already_issued | adopted | held | suspected | inconclusive | unknown |
 *             rate_limited | error | disabled | not_configured
 * stoppedAt   step constant (hold, guard, create, number, deliver) that stopped the run, or null
 * message     text of the stopping condition, or what the run did (document issued, existing document
 *             adopted, document already issued)
 * receipt     IfirmaReceipt|null, guard IfirmaGuardResult|null, delivery IfirmaDeliveryResult|null (deliver() result)
 * steps       list of array('step', 'status' => ok|skipped|stopped|failed, 'message')
 * trigger     status | manual | resume | issue_anyway
 *
 * The pipeline records a step with addStep() and what happened to the run with the intent methods
 * markIssued(), markAdopted() and alreadyIssued(), which set ok, outcome and message; stopAt(), which
 * sets the same three and also names the step in stoppedAt and logs it as stopped; and stopStep(),
 * which only names the step and logs it, keeping ok, outcome and message.
 */
class IfirmaPipelineResult
{
    const OUTCOME_ISSUED = 'issued';
    const OUTCOME_ALREADY_ISSUED = 'already_issued';
    const OUTCOME_ADOPTED = 'adopted';
    const OUTCOME_HELD = 'held';
    const OUTCOME_SUSPECTED = 'suspected';
    const OUTCOME_INCONCLUSIVE = 'inconclusive';
    const OUTCOME_UNKNOWN = 'unknown';
    const OUTCOME_RATE_LIMITED = 'rate_limited';
    const OUTCOME_ERROR = 'error';
    const OUTCOME_DISABLED = 'disabled';
    const OUTCOME_NOT_CONFIGURED = 'not_configured';

    /** @var bool */
    public $ok = false;

    /** @var string one of the OUTCOME_* constants */
    public $outcome = self::OUTCOME_ERROR;

    /** @var string|null */
    public $stoppedAt = null;

    /** @var string */
    public $message = '';

    /** @var IfirmaReceipt|null */
    public $receipt = null;

    /** @var IfirmaGuardResult|null */
    public $guard = null;

    /** @var IfirmaDeliveryResult|null */
    public $delivery = null;

    /** @var array */
    public $steps = array();

    /** @var string */
    public $trigger = 'manual';

    /**
     * Appends one entry to the step log.
     *
     * @param string $step    step constant (hold, guard, create, number, ksef, deliver)
     * @param string $status  ok|skipped|stopped|failed
     * @param string $message kept as a string
     */
    public function addStep($step, $status, $message)
    {
        $this->steps[] = array('step' => $step, 'status' => $status, 'message' => (string) $message);
    }

    /**
     * The run issued a new document.
     */
    public function markIssued()
    {
        $this->ok = true;
        $this->outcome = self::OUTCOME_ISSUED;
        $this->message = 'document issued';
    }

    /**
     * The run found the document of the order at iFirma and adopted it.
     */
    public function markAdopted()
    {
        $this->ok = true;
        $this->outcome = self::OUTCOME_ADOPTED;
        $this->message = 'existing document adopted';
    }

    /**
     * The order already had an issued document, so the run did nothing.
     */
    public function alreadyIssued()
    {
        $this->ok = true;
        $this->outcome = self::OUTCOME_ALREADY_ISSUED;
        $this->message = 'document already issued';
    }

    /**
     * Ends the run without a document: logs the step as stopped, names it in stoppedAt, and sets ok
     * to false and the outcome and message that say why.
     *
     * @param string $outcome one of the OUTCOME_* constants
     * @param string $step    step constant that stopped the run
     * @param string $message kept as a string, on the step and on the result
     */
    public function stopAt($outcome, $step, $message)
    {
        $this->stopStep($step, $message);
        $this->ok = false;
        $this->outcome = $outcome;
        $this->message = (string) $message;
    }

    /**
     * Stops a step while the run keeps its document: logs the step as stopped and names it in
     * stoppedAt. ok, outcome and message stay (a delivery that failed after the document was issued).
     *
     * @param string $step    step constant that stopped
     * @param string $message kept as a string
     */
    public function stopStep($step, $message)
    {
        $this->addStep($step, 'stopped', $message);
        $this->stoppedAt = $step;
    }

    /**
     * The plain-data part of the result as the admin ajax payload reports it. The receipt is an
     * entity, so the caller formats it and adds it.
     *
     * @return array array('outcome' => string, 'stopped_at' => string|null)
     */
    public function toArray()
    {
        return array(
            'outcome' => $this->outcome,
            'stopped_at' => $this->stoppedAt,
        );
    }
}
