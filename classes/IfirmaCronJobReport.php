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

require_once dirname(__FILE__) . '/IfirmaRateDecision.php';

/**
 * What one cron job did: how many items it handled, how many went well and how many failed, how many are left,
 * and whether the rate limit stopped it (with the window). The status sync reports its own summary instead, which
 * the cron answer keeps at its top level.
 */
class IfirmaCronJobReport
{
    /** @var string */
    private $name;

    /** @var int[] handled, succeeded, failed, left */
    private $counts = array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 0);

    /** @var bool */
    private $rateLimited = false;

    /** @var string */
    private $rateWindow = IfirmaRateDecision::WINDOW_NONE;

    /** @var array|null the summary kept at the top level of the cron answer; null for a job with a block */
    private $topLevel = null;

    /**
     * @param string $name
     */
    private function __construct($name)
    {
        $this->name = (string) $name;
    }

    /**
     * @param string $name
     * @param int    $handled
     * @param int    $succeeded
     * @param int    $failed
     * @param int    $left
     * @return IfirmaCronJobReport
     */
    public static function counts($name, $handled, $succeeded, $failed, $left)
    {
        $report = new self($name);
        $report->counts = array(
            'handled' => (int) $handled,
            'succeeded' => (int) $succeeded,
            'failed' => (int) $failed,
            'left' => (int) $left,
        );

        return $report;
    }

    /**
     * @param string $name
     * @param int    $left items that wait for the next run
     * @return IfirmaCronJobReport a job that did nothing
     */
    public static function skipped($name, $left = 0)
    {
        return self::counts($name, 0, 0, 0, $left);
    }

    /**
     * @param string $name
     * @param array  $summary keys kept at the top level of the cron answer
     * @return IfirmaCronJobReport
     */
    public static function summary($name, array $summary)
    {
        $report = new self($name);
        $report->topLevel = $summary;

        return $report;
    }

    /**
     * @param string $window one of the IfirmaRateDecision::WINDOW_* constants
     * @return IfirmaCronJobReport a copy that says the rate limit stopped the job
     */
    public function limited($window)
    {
        $copy = clone $this;
        $copy->rateLimited = true;
        $copy->rateWindow = (string) $window;

        return $copy;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return array handled, succeeded, failed, left
     */
    public function block()
    {
        return $this->counts;
    }

    /**
     * @return array|null the keys kept at the top level of the cron answer, null for a job that has a block
     */
    public function topLevel()
    {
        return $this->topLevel;
    }

    /**
     * @return bool
     */
    public function isRateLimited()
    {
        return $this->rateLimited;
    }

    /**
     * @return string
     */
    public function getRateWindow()
    {
        return $this->rateWindow;
    }
}
