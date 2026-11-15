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
require_once dirname(__FILE__) . '/IfirmaRemoteLimit.php';

/**
 * Reads the monthly API limit from iFirma at the start of a cron run and reports it in the answer. The reading is only
 * stored and shown: it changes no request counter and no cap. Without the "abonent" key (or when iFirma does not answer)
 * it reports nulls; it never stops the later jobs.
 */
class IfirmaRemoteLimitJob implements IfirmaCronJob
{
    /** @var IfirmaRemoteLimit */
    private $remoteLimit;

    /**
     * @param IfirmaRemoteLimit $remoteLimit
     */
    public function __construct(IfirmaRemoteLimit $remoteLimit)
    {
        $this->remoteLimit = $remoteLimit;
    }

    /**
     * @return string
     */
    public function name()
    {
        return 'limit';
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function run(IfirmaCronContext $context)
    {
        $reading = $this->remoteLimit->refresh();

        return IfirmaCronJobReport::values($this->name(), $reading !== null ? $reading : self::unknown());
    }

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function skip(IfirmaCronContext $context)
    {
        return IfirmaCronJobReport::values($this->name(), self::unknown());
    }

    /**
     * @return array
     */
    private static function unknown()
    {
        return array('used' => null, 'granted' => null, 'remaining' => null);
    }
}
