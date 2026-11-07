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

/**
 * Recorder of the PrestaShop Media class: addJsDef() merges its argument into $jsDefs, so a test can
 * read back the variables a hook hands to the scripts. Cleared by reset() (IfirmaTestState::resetAll()).
 */
class Media
{
    /** @var array variables the scripts receive, merged from every addJsDef() call since the last reset */
    public static $jsDefs = array();

    public static function reset()
    {
        self::$jsDefs = array();
    }

    /**
     * @param array $definitions variable name => value, as PrestaShop's Media::addJsDef()
     */
    public static function addJsDef($definitions)
    {
        self::$jsDefs = array_merge(self::$jsDefs, $definitions);
    }
}
