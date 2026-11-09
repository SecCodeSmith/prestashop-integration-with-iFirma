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

require_once dirname(__FILE__) . '/IfirmaCronContext.php';
require_once dirname(__FILE__) . '/IfirmaCronJobReport.php';

/**
 * One piece of work of the cron endpoint. The runner calls the jobs in a fixed order; a job that reports it was
 * rate limited stops the later ones, which are then asked to skip (they report what is left, but do nothing).
 */
interface IfirmaCronJob
{
    /**
     * @return string the key of the job's block in the cron summary
     */
    public function name();

    /**
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function run(IfirmaCronContext $context);

    /**
     * Reports without doing anything, because an earlier job was rate limited.
     *
     * @param IfirmaCronContext $context
     * @return IfirmaCronJobReport
     */
    public function skip(IfirmaCronContext $context);
}
