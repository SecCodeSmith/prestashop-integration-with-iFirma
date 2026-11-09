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
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';
require_once dirname(__FILE__) . '/IfirmaStatusSyncService.php';

/**
 * The status sync as a cron job. Its summary stays at the top level of the cron answer, so the keys that existed
 * before the cron ran other jobs keep their place and meaning.
 */
class IfirmaStatusSyncJob implements IfirmaCronJob
{
    /** @var IfirmaStatusSyncService */
    private $service;

    /**
     * @param IfirmaStatusSyncService $service
     */
    public function __construct(IfirmaStatusSyncService $service)
    {
        $this->service = $service;
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'sync';
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function run(IfirmaCronContext $context)
    {
        $summary = $this->service->runCron();
        $report = IfirmaCronJobReport::summary($this->name(), $summary);
        if (!empty($summary['rate_limited'])) {
            $guard = new IfirmaRateGuard(function () use ($context) {
                return $context->now();
            });

            return $report->limited($guard->blockedBy());
        }

        return $report;
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function skip(IfirmaCronContext $context)
    {
        return IfirmaCronJobReport::summary($this->name(), IfirmaStatusSyncService::emptySummary($context->now()));
    }
}
