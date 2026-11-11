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

require_once dirname(__FILE__) . '/IfirmaLimitReading.php';

/**
 * What the employee's request to read the remaining limit came to: a reading, a refusal by the module's own limits
 * (nothing was sent) or no usable answer. The detail is for the shop log only; a page never shows it.
 */
final class IfirmaLimitCheck
{
    const CHECKED = 'checked';
    const REFUSED = 'refused';
    const UNAVAILABLE = 'unavailable';

    /** The refusal came from the cool-down after iFirma rejected the credentials (the others name a rate window). */
    const WINDOW_AUTH = 'auth';

    /** @var string */
    private $status;

    /** @var IfirmaLimitReading|null */
    private $reading;

    /** @var string */
    private $window;

    /** @var string */
    private $detail;

    private function __construct($status, $reading, $window, $detail)
    {
        $this->status = $status;
        $this->reading = $reading;
        $this->window = $window;
        $this->detail = $detail;
    }

    /**
     * @param IfirmaLimitReading $reading
     * @return IfirmaLimitCheck
     */
    public static function checked(IfirmaLimitReading $reading)
    {
        return new self(self::CHECKED, $reading, '', '');
    }

    /**
     * @param string $window IfirmaRateDecision::WINDOW_MINUTE, WINDOW_DAY, WINDOW_BACKOFF or self::WINDOW_AUTH
     * @param string $detail
     * @return IfirmaLimitCheck
     */
    public static function refused($window, $detail)
    {
        return new self(self::REFUSED, null, (string) $window, (string) $detail);
    }

    /**
     * @param string $detail
     * @return IfirmaLimitCheck
     */
    public static function unavailable($detail)
    {
        return new self(self::UNAVAILABLE, null, '', (string) $detail);
    }

    /**
     * @return string CHECKED, REFUSED or UNAVAILABLE
     */
    public function status()
    {
        return $this->status;
    }

    /**
     * @return IfirmaLimitReading|null the reading of a checked limit, else null
     */
    public function reading()
    {
        return $this->reading;
    }

    /**
     * @return string the window that refused the check, '' unless it was refused
     */
    public function window()
    {
        return $this->window;
    }

    /**
     * @return string why the check failed, for the log; '' when it succeeded
     */
    public function detail()
    {
        return $this->detail;
    }
}
