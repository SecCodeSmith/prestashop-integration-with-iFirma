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

require_once dirname(__FILE__) . '/IfirmaRemoteLimit.php';
require_once dirname(__FILE__) . '/IfirmaLimitCheck.php';

/**
 * The employee's request to read the remaining limit: it runs the forced check only when the request is allowed (a
 * POST of an employee who may configure the module, with the abonent key set) and puts the detail of a check that got
 * no answer into the shop log, where it belongs: the page only ever shows a generic notice.
 */
class IfirmaLimitCheckAction
{
    /** PrestaShopLogger severity of an error. */
    const LOG_ERROR = 3;

    /** Longest detail written to the log, so a remote answer cannot flood it. */
    const MAX_DETAIL_LENGTH = 200;

    /** @var IfirmaRemoteLimit */
    private $limit;

    /** @var callable returns true when the request may run */
    private $allowed;

    /** @var callable called with the message to log */
    private $log;

    /**
     * @param IfirmaRemoteLimit $limit
     * @param callable          $allowed returns true when the request is a POST of an employee who may configure the
     *                                   module and the abonent key is set
     * @param callable          $log     called with the line for the shop log
     */
    public function __construct(IfirmaRemoteLimit $limit, $allowed, $log)
    {
        $this->limit = $limit;
        $this->allowed = $allowed;
        $this->log = $log;
    }

    /**
     * @return IfirmaLimitCheck|null null when the request is not allowed: nothing was asked and nothing changed
     */
    public function run()
    {
        if (!call_user_func($this->allowed)) {
            return null;
        }

        $check = $this->limit->check();
        if ($check->status() === IfirmaLimitCheck::UNAVAILABLE) {
            call_user_func($this->log, 'iFirma: the remaining limit could not be read: ' . $this->oneLine($check->detail()));
        }

        return $check;
    }

    /**
     * @param string $detail
     * @return string
     */
    private function oneLine($detail)
    {
        $line = trim((string) preg_replace('/\s+/', ' ', $detail));

        return substr($line, 0, self::MAX_DETAIL_LENGTH);
    }
}
