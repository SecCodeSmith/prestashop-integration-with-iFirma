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
require_once dirname(__FILE__) . '/IfirmaKsefService.php';
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';

/**
 * Submits to KSeF the invoices whose submission was deferred (the state read was refused or failed during issuance),
 * oldest first, at most the item cap of the run. Each submission reads the document's KSeF state first, as the
 * pipeline step does. With submitter ifirma the module never submits, so the job does nothing.
 */
class IfirmaKsefRetryJob implements IfirmaCronJob
{
    /** @var IfirmaKsefService */
    private $ksef;

    /**
     * @param IfirmaKsefService $ksef
     */
    public function __construct(IfirmaKsefService $ksef)
    {
        $this->ksef = $ksef;
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'ksef';
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function run(IfirmaCronContext $context)
    {
        if ($this->ksef->submitter() === IfirmaKsefService::SUBMITTER_IFIRMA) {
            return IfirmaCronJobReport::skipped($this->name());
        }

        $handled = 0;
        $succeeded = 0;
        $failed = 0;
        $limited = false;
        foreach (IfirmaReceipt::findAwaitingKsefSubmission($context->getMaxItems()) as $record) {
            $order = new Order((int) $record->id_order);
            $answer = $this->ksef->submit($record, Validate::isLoadedObject($order) ? $order : null);
            $handled++;
            if (!empty($answer['ok']) || (isset($answer['outcome']) && $answer['outcome'] === 'already')) {
                $succeeded++;
                continue;
            }
            if (!empty($answer['rate_limited'])) {
                $limited = true;
                break;
            }
            $failed++;
        }

        $report = IfirmaCronJobReport::counts($this->name(), $handled, $succeeded, $failed, IfirmaReceipt::countAwaitingKsefSubmission());
        if (!$limited) {
            return $report;
        }
        $guard = new IfirmaRateGuard(function () use ($context) {
            return $context->now();
        });

        return $report->limited($guard->blockedBy());
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function skip(IfirmaCronContext $context)
    {
        return IfirmaCronJobReport::skipped($this->name(), IfirmaReceipt::countAwaitingKsefSubmission());
    }
}
