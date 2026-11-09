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
 * What every job of one cron run shares: the cap on the items one job handles, and the clock.
 */
class IfirmaCronContext
{
    /** @var int */
    private $maxItems;

    /** @var callable|null */
    private $clock;

    /**
     * @param int           $maxItems most items a job handles in this run
     * @param callable|null $clock    returns the unix time; time() when null
     */
    public function __construct($maxItems, $clock = null)
    {
        $this->maxItems = max(1, (int) $maxItems);
        $this->clock = $clock;
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
