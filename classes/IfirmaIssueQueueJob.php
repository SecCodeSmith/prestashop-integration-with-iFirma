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

require_once dirname(__FILE__) . '/IfirmaCronJob.php';
require_once dirname(__FILE__) . '/IfirmaIssuancePipeline.php';
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';

/**
 * Issues the queued orders, oldest first, each through the whole pipeline (the duplicate guard included) and under
 * the order's lock. It handles at most the item cap of the run and stops at the first rate limit refusal.
 */
class IfirmaIssueQueueJob implements IfirmaCronJob
{
    /** @var IfirmaIssuancePipeline */
    private $pipeline;

    /** @var callable|null returns true while iFirma's rejection of the credentials pauses all calls */
    private $authBlocked;

    /**
     * @param IfirmaIssuancePipeline $pipeline
     * @param callable|null          $authBlocked
     */
    public function __construct(IfirmaIssuancePipeline $pipeline, $authBlocked = null)
    {
        $this->pipeline = $pipeline;
        $this->authBlocked = $authBlocked;
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'queue';
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function run(IfirmaCronContext $context)
    {
        $handled = 0;
        $succeeded = 0;
        $failed = 0;
        $window = null;

        foreach (IfirmaReceipt::findQueued($context->getMaxItems()) as $record) {
            if ($this->credentialsPaused()) {
                break;
            }
            $order = new Order((int) $record->id_order);
            if (!Validate::isLoadedObject($order)) {
                $record->markError('The order no longer exists.');
                $record->save();
                $handled++;
                $failed++;
                continue;
            }
            $result = $this->issue($order);
            if ($result === null) {
                continue;   // another run holds the order: it stays queued and is not counted
            }
            $handled++;
            if ($result->outcome === IfirmaPipelineResult::OUTCOME_RATE_LIMITED) {
                $window = $this->blockedBy($context);
                break;
            }
            if ($result->ok) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        $report = IfirmaCronJobReport::counts($this->name(), $handled, $succeeded, $failed, IfirmaReceipt::countQueued());

        return $window !== null ? $report->limited($window) : $report;
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function skip(IfirmaCronContext $context)
    {
        return IfirmaCronJobReport::skipped($this->name(), IfirmaReceipt::countQueued());
    }

    /**
     * @param Order $order
     * @return IfirmaPipelineResult|null null when the order's lock is not granted
     */
    private function issue(Order $order)
    {
        $lock = 'ifirma_order_' . (int) $order->id;
        if (!IfirmaNamedLock::acquire($lock, 0)) {
            return null;
        }
        try {
            return $this->pipeline->run($order, array('trigger' => IfirmaIssuancePipeline::TRIGGER_QUEUE));
        } finally {
            IfirmaNamedLock::release($lock);
        }
    }

    /**
     * @return bool
     */
    private function credentialsPaused()
    {
        return $this->authBlocked !== null && (bool) call_user_func($this->authBlocked);
    }

    /**
     * @param IfirmaCronContext $context
     * @return string
     */
    private function blockedBy(IfirmaCronContext $context)
    {
        $guard = new IfirmaRateGuard(function () use ($context) {
            return $context->now();
        });

        return $guard->blockedBy();
    }
}
