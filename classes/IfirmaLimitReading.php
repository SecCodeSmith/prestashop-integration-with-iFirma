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
 * One reading of the account's day limit as iFirma reported it: the day it belongs to, the requests used and granted
 * and the unix time it was taken.
 */
final class IfirmaLimitReading
{
    /** @var string */
    private $day;

    /** @var int */
    private $used;

    /** @var int */
    private $granted;

    /** @var int */
    private $at;

    /**
     * @param string $day     the day as Ymd
     * @param int    $used
     * @param int    $granted
     * @param int    $at      unix time of the reading
     */
    public function __construct($day, $used, $granted, $at)
    {
        $this->day = (string) $day;
        $this->used = (int) $used;
        $this->granted = (int) $granted;
        $this->at = (int) $at;
    }

    /**
     * @return string the day as Ymd
     */
    public function day()
    {
        return $this->day;
    }

    /**
     * @return int
     */
    public function used()
    {
        return $this->used;
    }

    /**
     * @return int
     */
    public function granted()
    {
        return $this->granted;
    }

    /**
     * @return int requests left of the granted limit, never below zero
     */
    public function remaining()
    {
        return max(0, $this->granted - $this->used);
    }

    /**
     * @return int unix time of the reading
     */
    public function at()
    {
        return $this->at;
    }
}
