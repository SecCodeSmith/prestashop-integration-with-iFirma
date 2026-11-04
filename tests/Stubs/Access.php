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

/** Core Access stub: isGranted() answers from a static grant list. */
class Access
{
    /** @var array "ROLE|idProfile" => true */
    public static $grants = [];

    public static function reset()
    {
        self::$grants = [];
    }

    /** Test helper: grant a role to a profile. */
    public static function grant($role, $idProfile)
    {
        self::$grants[$role . '|' . (int) $idProfile] = true;
    }

    public static function isGranted($role, $idProfile)
    {
        return isset(self::$grants[$role . '|' . (int) $idProfile]);
    }
}
