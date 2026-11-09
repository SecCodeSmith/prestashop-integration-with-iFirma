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
require_once dirname(__FILE__) . '/IfirmaNamedLock.php';
require_once dirname(__FILE__) . '/IfirmaIssueQueueJob.php';
require_once dirname(__FILE__) . '/IfirmaStatusSyncJob.php';

/**
 * Runs the jobs of the cron endpoint one after the other, under a run lock so two cron requests never overlap.
 * After a job reports that the rate limit stopped it, the later jobs only report what is left.
 */
class IfirmaCronRunner
{
    const LOCK = 'ifirma_cron';
    const DEFAULT_MAX_ITEMS = 10;

    /** @var IfirmaCronJob[] */
    private $jobs;

    /** @var callable|null */
    private $clock;

    /**
     * @param IfirmaCronJob[] $jobs  in the order they run
     * @param callable|null   $clock
     */
    public function __construct(array $jobs, $clock = null)
    {
        $this->jobs = $jobs;
        $this->clock = $clock;
    }

    /**
     * The runner of the cron endpoint, with the jobs in the order they run.
     *
     * @param Module $module
     * @return IfirmaCronRunner
     */
    public static function build(Module $module)
    {
        $client = IfirmaApiClient::fromConfiguration();

        return new self(array(
            new IfirmaIssueQueueJob(IfirmaIssuancePipeline::build($module), function () use ($client) {
                return $client->isAuthBlocked();
            }),
            new IfirmaStatusSyncJob(IfirmaStatusSyncService::build($module)),
        ));
    }

    /**
     * @return array the cron answer: the status sync summary at the top level, a block per other job,
     *               rate_limited (0 or 1) and rate_limited_window; or array('skipped' => ...) when another run is busy
     */
    public function run()
    {
        if (!IfirmaNamedLock::acquire(self::LOCK, 0)) {
            return array('skipped' => 'another cron run is busy');
        }
        try {
            return $this->runJobs();
        } finally {
            IfirmaNamedLock::release(self::LOCK);
        }
    }

    /**
     * @return array
     */
    private function runJobs()
    {
        $context = new IfirmaCronContext($this->maxItems(), $this->clock);
        $summary = array();
        $window = IfirmaRateDecision::WINDOW_NONE;
        $limited = false;

        foreach ($this->jobs as $job) {
            $report = $limited ? $job->skip($context) : $job->run($context);
            if ($report->topLevel() !== null) {
                $summary = array_merge($summary, $report->topLevel());
            } else {
                $summary[$job->name()] = $report->block();
            }
            if (!$limited && $report->isRateLimited()) {
                $limited = true;
                $window = $report->getRateWindow();
            }
        }

        $summary['rate_limited'] = max(isset($summary['rate_limited']) ? (int) $summary['rate_limited'] : 0, $limited ? 1 : 0);
        $summary['rate_limited_window'] = $window;

        return $summary;
    }

    /**
     * @return int
     */
    private function maxItems()
    {
        $cap = (int) Configuration::get('IFIRMA_CRON_MAX_ITEMS');

        return $cap > 0 ? $cap : self::DEFAULT_MAX_ITEMS;
    }
}
