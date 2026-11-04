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
    /** @var bool */
    public $ok = false;

    /** @var string */
    public $outcome = 'error';

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
}
