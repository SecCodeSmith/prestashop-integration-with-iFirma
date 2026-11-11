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
require_once dirname(__FILE__) . '/IfirmaDeliveryService.php';
require_once dirname(__FILE__) . '/IfirmaNamedLock.php';
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';

/**
 * Retries the failed deliveries whose due time has come, the earliest due time first, at most the item cap of the
 * run. The delivery service decides what a failure leads to (see IfirmaDeliveryRetryPolicy); this job only picks the
 * records and makes sure no customer is e-mailed twice:
 *  - the due records are selected once, with the time the job started, so a delivery that an earlier job of the same
 *    run has just failed (due 15 minutes later) is not picked up again in that run;
 *  - every record is delivered under the order's lock, taken without waiting, and read again under the lock: an
 *    employee's resend, another cron run or the pipeline that got there first leaves nothing to do;
 *  - the job stops at the first refusal by the request limit, and while the credentials are paused.
 */
class IfirmaDeliveryRetryJob implements IfirmaCronJob
{
    /** @var IfirmaDeliveryService */
    private $delivery;

    /** @var callable|null returns true while the credentials are paused */
    private $authBlocked;

    /**
     * @param IfirmaDeliveryService $delivery
     * @param callable|null         $authBlocked
     */
    public function __construct(IfirmaDeliveryService $delivery, $authBlocked = null)
    {
        $this->delivery = $delivery;
        $this->authBlocked = $authBlocked;
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'delivery_retry';
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function run(IfirmaCronContext $context)
    {
        $startedAt = $context->now();
        $guard = new IfirmaRateGuard(function () use ($context) {
            return $context->now();
        });
        $handled = 0;
        $succeeded = 0;
        $failed = 0;
        $window = IfirmaRateDecision::WINDOW_NONE;
        foreach (IfirmaReceipt::findDeliveriesDueForRetry($context->getMaxItems(), $startedAt) as $record) {
            if (!$context->allowsAnotherItem()) {
                break;
            }
            $window = $guard->blockedBy();
            if ($window !== IfirmaRateDecision::WINDOW_NONE || $this->credentialsPaused()) {
                break;
            }
            $sent = $this->retryUnderLock($record, $startedAt);
            if ($sent === null) {
                continue;   // not due any more, or another run holds the order: it is not counted
            }
            $handled++;
            if ($sent) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        $report = IfirmaCronJobReport::counts(
            $this->name(),
            $handled,
            $succeeded,
            $failed,
            IfirmaReceipt::countDeliveriesDueForRetry($context->now())
        );

        return $window !== IfirmaRateDecision::WINDOW_NONE ? $report->limited($window) : $report;
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function skip(IfirmaCronContext $context)
    {
        return IfirmaCronJobReport::skipped($this->name(), IfirmaReceipt::countDeliveriesDueForRetry($context->now()));
    }

    /**
     * @param IfirmaReceipt $selected
     * @param int           $startedAt unix time the job started
     * @return bool|null true when the delivery was sent or needed no sending, false when it failed, null when nothing
     *                   was attempted
     */
    private function retryUnderLock(IfirmaReceipt $selected, $startedAt)
    {
        $lock = IfirmaDeliveryService::ORDER_LOCK_PREFIX . (int) $selected->id_order;
        if (!IfirmaNamedLock::acquire($lock, 0)) {
            return null;
        }
        try {
            $record = IfirmaReceipt::loadFresh((int) $selected->id);
            if (!$this->isDue($record, $startedAt)) {
                return null;
            }
            $order = new Order((int) $record->id_order);

            return $this->delivery->deliver(
                $record,
                Validate::isLoadedObject($order) ? $order : null,
                array(
                    IfirmaDeliveryService::OPTION_CLAIM_ATTEMPT => true,
                    IfirmaDeliveryService::OPTION_SINGLE_PDF_ATTEMPT => true,
                )
            )->isSent();
        } finally {
            IfirmaNamedLock::release($lock);
        }
    }

    /**
     * @param IfirmaReceipt $record
     * @param int           $now
     * @return bool true while the record is still a failed delivery whose retry has come
     */
    private function isDue(IfirmaReceipt $record, $now)
    {
        $due = $record->getDeliveryRetryAt();

        return $record->delivery_status === IfirmaReceipt::DELIVERY_FAILED && $due !== null && strtotime($due) <= (int) $now;
    }

    /**
     * @return bool
     */
    private function credentialsPaused()
    {
        return $this->authBlocked !== null && (bool) call_user_func($this->authBlocked);
    }
}
