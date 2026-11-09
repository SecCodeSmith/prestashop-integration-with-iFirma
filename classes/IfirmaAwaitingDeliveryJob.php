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
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';

/**
 * Delivers the documents that waited for KSeF and have now been accepted, oldest first, at most the item cap of the
 * run, through the configured channel. A delivery that fails is recorded as failed and is not tried again by the
 * cron, so a customer is never e-mailed twice. A rejected document is not selected: it keeps waiting.
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
            $order = new Order((int) $record->id_order);
            $result = $this->delivery->deliver($record, Validate::isLoadedObject($order) ? $order : null);
            $handled++;
            if ($result->isSent()) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        $report = IfirmaCronJobReport::counts($this->name(), $handled, $succeeded, $failed, IfirmaReceipt::countAwaitingDelivery());

        return $window !== IfirmaRateDecision::WINDOW_NONE ? $report->limited($window) : $report;
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
