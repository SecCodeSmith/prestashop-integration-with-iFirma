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

require_once dirname(__FILE__) . '/IfirmaRateWindow.php';
require_once dirname(__FILE__) . '/IfirmaRateDecision.php';
require_once dirname(__FILE__) . '/IfirmaWindowCounter.php';

/**
 * A named budget of PDF downloads per calendar minute and, optionally, per calendar day. It is independent of the
 * rate guard: downloads by customers or employees count here first, so they cannot use up the request limit that
 * issuance, the status sync and the cron share.
 *
 * Counters are Configuration rows (see IfirmaWindowCounter). A unit is taken before the request is sent and given
 * back when the request was refused locally, so the count equals the downloads that reached iFirma.
 */
class IfirmaDownloadBudget
{
    const CUSTOMER_MINUTE_KEY = 'IFIRMA_DL_CUSTOMER_MINUTE';
    const CUSTOMER_DAY_KEY = 'IFIRMA_DL_CUSTOMER_DAY';
    const EMPLOYEE_MINUTE_KEY = 'IFIRMA_DL_EMPLOYEE_MINUTE';
    const EMPLOYEE_DAY_KEY = 'IFIRMA_DL_EMPLOYEE_DAY';

    const CUSTOMER_MINUTE_CAP = 20;
    const CUSTOMER_DAY_CAP = 2000;
    const EMPLOYEE_MINUTE_CAP = 30;
    const EMPLOYEE_DAY_CAP = 2000;

    const MINUTE_BUCKET_FORMAT = 'YmdHi';
    const DAY_BUCKET_FORMAT = 'Ymd';
    const SECONDS_PER_MINUTE = 60;

    /** @var IfirmaRateWindow[] */
    private $windows;

    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /** @var IfirmaWindowCounter */
    private $counter;

    /** @var array window name => bucket of the unit taken last, for give-back */
    private $taken = array();

    /**
     * @param IfirmaRateWindow[]       $windows checked in the given order
     * @param callable|null            $clock
     * @param IfirmaWindowCounter|null $counter
     */
    public function __construct(array $windows, $clock = null, IfirmaWindowCounter $counter = null)
    {
        $this->windows = $windows;
        $this->clock = $clock;
        $this->counter = $counter !== null ? $counter : new IfirmaWindowCounter();
    }

    /**
     * @param callable|null $clock
     * @return IfirmaDownloadBudget 20 downloads per minute and 2,000 per day for all customers together
     */
    public static function forCustomers($clock = null)
    {
        return new self(array(
            new IfirmaRateWindow(IfirmaRateDecision::WINDOW_MINUTE, self::CUSTOMER_MINUTE_KEY, self::MINUTE_BUCKET_FORMAT, self::CUSTOMER_MINUTE_CAP),
            new IfirmaRateWindow(IfirmaRateDecision::WINDOW_DAY, self::CUSTOMER_DAY_KEY, self::DAY_BUCKET_FORMAT, self::CUSTOMER_DAY_CAP),
        ), $clock);
    }

    /**
     * @param callable|null $clock
     * @return IfirmaDownloadBudget 30 per minute and 2,000 per day for all employees together
     */
    public static function forEmployees($clock = null)
    {
        return new self(array(
            new IfirmaRateWindow(IfirmaRateDecision::WINDOW_MINUTE, self::EMPLOYEE_MINUTE_KEY, self::MINUTE_BUCKET_FORMAT, self::EMPLOYEE_MINUTE_CAP),
            new IfirmaRateWindow(IfirmaRateDecision::WINDOW_DAY, self::EMPLOYEE_DAY_KEY, self::DAY_BUCKET_FORMAT, self::EMPLOYEE_DAY_CAP),
        ), $clock);
    }

    /**
     * Takes one unit in every window. A refusal by a later window gives back what the earlier ones took.
     *
     * @return bool false when a window is full (nothing is spent then)
     */
    public function take()
    {
        $now = $this->now();
        $this->taken = array();
        foreach ($this->windows as $window) {
            if ($this->counter->take($window, $now) !== null) {
                $this->release();

                return false;
            }
            $this->taken[$window->getName()] = $window->bucketAt($now);
        }

        return true;
    }

    /**
     * Gives back the unit the last take() spent; a no-op when it took none or the window has moved on.
     */
    public function release()
    {
        foreach ($this->windows as $window) {
            if (isset($this->taken[$window->getName()])) {
                $this->counter->giveBack($window, $this->taken[$window->getName()]);
            }
        }
        $this->taken = array();
    }

    /**
     * @return int seconds until the next calendar minute, 1 to 60
     */
    public function secondsToNextMinute()
    {
        return self::SECONDS_PER_MINUTE - ($this->now() % self::SECONDS_PER_MINUTE);
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
