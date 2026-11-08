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
 * A database advisory lock (GET_LOCK / RELEASE_LOCK) that serialises a check-then-write sequence across
 * parallel requests. The lock belongs to the database connection, so it is released at the latest when the
 * request ends, even if a caller forgets; callers release it in a finally block anyway.
 *
 * Public contract:
 *  - acquire(name, seconds): true when the lock is held, false when it was not granted in time (or the
 *    database could not say). The name is made of the caller's fixed prefix and an integer.
 *  - release(name): gives the lock back. Call it only after acquire() returned true.
 */
class IfirmaNamedLock
{
    /**
     * @param string $name    up to 64 characters of [a-z0-9_]
     * @param int    $seconds how long to wait for the lock
     * @return bool
     */
    public static function acquire($name, $seconds)
    {
        $granted = Db::getInstance()->getValue(
            'SELECT GET_LOCK(\'' . pSQL(self::clean($name)) . '\', ' . max(0, (int) $seconds) . ')'
        );

        return (string) $granted === '1';
    }

    /**
     * @param string $name
     * @return void
     */
    public static function release($name)
    {
        Db::getInstance()->getValue('SELECT RELEASE_LOCK(\'' . pSQL(self::clean($name)) . '\')');
    }

    /**
     * @param string $name
     * @return string the name reduced to the characters a lock name may hold here
     */
    private static function clean($name)
    {
        return substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $name)), 0, 64);
    }
}
