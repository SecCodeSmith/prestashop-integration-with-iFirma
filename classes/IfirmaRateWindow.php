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
 * One counting window of the local rate guard: a name, the Configuration row that holds its counter
 * ("<bucket>:<count>"), the date() format that names the bucket, and the cap.
 */
class IfirmaRateWindow
{
    /** @var string */
    private $name;

    /** @var string */
    private $counterKey;

    /** @var string */
    private $bucketFormat;

    /** @var int */
    private $cap;

    /**
     * @param string $name         IfirmaRateDecision::WINDOW_MINUTE or WINDOW_DAY
     * @param string $counterKey   Configuration row of the counter
     * @param string $bucketFormat date() format of the bucket the counter belongs to
     * @param int    $cap          most requests the window admits
     */
    public function __construct($name, $counterKey, $bucketFormat, $cap)
    {
        $this->name = (string) $name;
        $this->counterKey = (string) $counterKey;
        $this->bucketFormat = (string) $bucketFormat;
        $this->cap = (int) $cap;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getCounterKey()
    {
        return $this->counterKey;
    }

    /**
     * @return int
     */
    public function getCap()
    {
        return $this->cap;
    }

    /**
     * @param int $timestamp unix time
     * @return string the bucket the timestamp falls into (a calendar minute or day)
     */
    public function bucketAt($timestamp)
    {
        return date($this->bucketFormat, (int) $timestamp);
    }
}
