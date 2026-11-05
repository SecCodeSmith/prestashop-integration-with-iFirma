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
 * message     text of the stopping condition (empty on success)
 * receipt     IfirmaReceipt|null, guard IfirmaGuardResult|null, delivery array|null (deliver() result)
 * steps       list of array('step', 'status' => ok|skipped|stopped|failed, 'message')
 * trigger     status | manual | resume | issue_anyway
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

    /** @var array|null */
    public $delivery = null;

    /** @var array */
    public $steps = array();

    /** @var string */
    public $trigger = 'manual';

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
