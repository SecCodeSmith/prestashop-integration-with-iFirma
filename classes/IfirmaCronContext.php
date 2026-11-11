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
 * What every job of one cron run shares: the cap on the items one job handles, the clock and the time budget of the
 * run. The budget is a safety net against a slow mail server or iFirma keeping the run lock (and the request) for
 * minutes: a job asks allowsAnotherItem() before it starts a record and never interrupts one that has started.
 */
class IfirmaCronContext
{
    /** Seconds after the start of a run during which a job may still start a record. */
    const DEFAULT_TIME_BUDGET_SECONDS = 45;

    /** @var int */
    private $maxItems;

    /** @var callable|null */
    private $clock;

    /** @var int */
    private $timeBudget;

    /** @var int unix time the run started */
    private $startedAt;

    /** @var bool whether a record was refused because the budget was used up */
    private $budgetUsedUp = false;

    /**
     * @param int           $maxItems          most items a job handles in this run
     * @param callable|null $clock             returns the unix time; time() when null
     * @param int           $timeBudgetSeconds how long after the start of the run a record may still be started
     */
    public function __construct($maxItems, $clock = null, $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS)
    {
        $this->maxItems = max(1, (int) $maxItems);
        $this->clock = $clock;
        $this->timeBudget = max(1, (int) $timeBudgetSeconds);
        $this->startedAt = $this->now();
    }

    /**
     * Asked by a job right before it starts a record. A refusal is remembered, so the run can report it.
     *
     * @return bool false once the time budget of the run is used up
     */
    public function allowsAnotherItem()
    {
        if ($this->now() - $this->startedAt >= $this->timeBudget) {
            $this->budgetUsedUp = true;
        }

        return !$this->budgetUsedUp;
    }

    /**
     * @return bool true when a job or the runner was refused a record because of the time budget
     */
    public function timeBudgetWasUsedUp()
    {
        return $this->budgetUsedUp;
    }

    /**
     * @return int
     */
    public function getMaxItems()
    {
        return $this->maxItems;
    }

    /**
     * @return int unix time
     */
    public function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
