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
 * Delivers the documents that waited for KSeF and have now been accepted, oldest first, at most the item cap of the
 * run, through the configured channel. A delivery that fails is recorded as failed with one automatic attempt used;
 * IfirmaDeliveryRetryJob, the next job of the run, retries it later under the retry policy, so this job never
 * selects it again. A rejected document is not selected: it keeps waiting.
 */
class IfirmaAwaitingDeliveryJob implements IfirmaCronJob
{
    /** @var IfirmaDeliveryService */
    private $delivery;

    /**
     * @param IfirmaDeliveryService $delivery
     */
    public function __construct(IfirmaDeliveryService $delivery)
    {
        $this->delivery = $delivery;
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'delivery';
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function run(IfirmaCronContext $context)
    {
        $guard = new IfirmaRateGuard(function () use ($context) {
            return $context->now();
        });
        $handled = 0;
        $succeeded = 0;
        $failed = 0;
        $window = IfirmaRateDecision::WINDOW_NONE;
        foreach (IfirmaReceipt::findAwaitingDelivery($context->getMaxItems()) as $record) {
            $window = $guard->blockedBy();
            if ($window !== IfirmaRateDecision::WINDOW_NONE) {
                break;
            }
            $sent = $this->deliverUnderLock($record);
            if ($sent === null) {
                continue;   // another run or an employee holds the order, or it is delivered already: not counted
            }
            $handled++;
            if ($sent) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        $report = IfirmaCronJobReport::counts($this->name(), $handled, $succeeded, $failed, IfirmaReceipt::countAwaitingDelivery());

        return $window !== IfirmaRateDecision::WINDOW_NONE ? $report->limited($window) : $report;
    }

    /**
     * Delivers under the order's lock, taken without waiting, and reads the record again under it: an employee's
     * resend that got there first leaves nothing to send.
     *
     * @param IfirmaReceipt $selected
     * @return bool|null true when the delivery was sent or needed no sending, false when it failed, null when nothing
     *                   was attempted
     */
    private function deliverUnderLock(IfirmaReceipt $selected)
    {
        $lock = IfirmaDeliveryService::ORDER_LOCK_PREFIX . (int) $selected->id_order;
        if (!IfirmaNamedLock::acquire($lock, 0)) {
            return null;
        }
        try {
            $record = IfirmaReceipt::loadFresh((int) $selected->id);
            if ($record->delivery_status !== IfirmaReceipt::DELIVERY_AWAITING_KSEF) {
                return null;
            }
            $order = new Order((int) $record->id_order);

            return $this->delivery->deliver(
                $record,
                Validate::isLoadedObject($order) ? $order : null,
                array(IfirmaDeliveryService::OPTION_CLAIM_ATTEMPT => true)
            )->isSent();
        } finally {
            IfirmaNamedLock::release($lock);
        }
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function skip(IfirmaCronContext $context)
    {
        return IfirmaCronJobReport::skipped($this->name(), IfirmaReceipt::countAwaitingDelivery());
    }
}
