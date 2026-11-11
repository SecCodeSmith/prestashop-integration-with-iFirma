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

require_once dirname(__FILE__) . '/IfirmaPdfCopyStore.php';

/**
 * Decides how long the shop keeps a stored copy of a document PDF (IFIRMA_PDF_COPY_DAYS).
 *
 * The setting is read on every call, so a lowered value takes effect at once: a copy older than the new
 * number of days stops being served. A missing or malformed value reads as the installed default.
 */
class IfirmaPdfCopyRetention
{
    const SETTING = 'IFIRMA_PDF_COPY_DAYS';
    const MAX_DAYS = 365;
    const SECONDS_PER_DAY = 86400;

    /** @var callable returns the stored value of a configuration key */
    private $settings;

    /** @var callable returns the unix time */
    private $clock;

    /**
     * @param callable|null $settings called with a configuration key; Configuration::get() when null
     * @param callable|null $clock    returns the unix time; time() when null
     */
    public function __construct($settings = null, $clock = null)
    {
        $this->settings = $settings !== null ? $settings : array('Configuration', 'get');
        $this->clock = $clock !== null ? $clock : 'time';
    }

    /**
     * @return int 0 to 365; 0 means copies are not stored
     */
    public function days()
    {
        $stored = trim((string) call_user_func($this->settings, self::SETTING));
        if ($stored !== '' && ctype_digit($stored) && (int) $stored <= self::MAX_DAYS) {
            return (int) $stored;
        }
        $defaults = IfirmaSchema::configurationDefaults();

        return (int) $defaults[self::SETTING];
    }

    /**
     * @return bool
     */
    public function isStoring()
    {
        return $this->days() > 0;
    }

    /**
     * Removes every stored copy when storing is off, so that turning it off leaves no copy behind.
     *
     * @param IfirmaPdfCopyStore $store
     * @return bool false when the copies could not be deleted; true when they were, or when storing is on
     */
    public function clearWhenOff(IfirmaPdfCopyStore $store)
    {
        if ($this->isStoring()) {
            return true;
        }

        return $store->deleteAll();
    }

    /**
     * @return int unix time before which a stored copy is too old
     */
    public function cutoffTimestamp()
    {
        return (int) call_user_func($this->clock) - $this->days() * self::SECONDS_PER_DAY;
    }

    /**
     * @param string|null $storedAt when the copy was stored, 'Y-m-d H:i:s'
     * @return bool true when copies are kept and this one is not older than the kept days
     */
    public function covers($storedAt)
    {
        if (!$this->isStoring()) {
            return false;
        }
        $storedTime = strtotime((string) $storedAt);

        return $storedTime !== false && $storedTime >= $this->cutoffTimestamp();
    }
}
