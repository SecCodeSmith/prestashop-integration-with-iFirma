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

require_once dirname(__FILE__) . '/IfirmaRequestKind.php';
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';

/**
 * Statistics only: how many requests of each kind the module sent today. Each kind is one Configuration row
 * IFIRMA_USAGE_<KIND> holding "YYYY-MM-DD:count", raised by one atomic UPDATE like the guard's windows, so
 * concurrent requests (hook, cron, a customer download) lose no count. The minute and day totals are not kept here:
 * they are read from the guard's windows.
 */
class IfirmaApiUsage
{
    const KEY_PREFIX = 'IFIRMA_USAGE_';

    /** PrestaShopLogger severity of an error. */
    const LOG_ERROR = 3;

    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /** @var IfirmaRateGuard */
    private $guard;

    /**
     * @param callable|null        $clock
     * @param IfirmaRateGuard|null $guard reads the minute and day windows; by default one on the same clock
     */
    public function __construct($clock = null, IfirmaRateGuard $guard = null)
    {
        $this->clock = $clock;
        $this->guard = $guard !== null ? $guard : new IfirmaRateGuard($clock);
    }

    /**
     * @param string $kind
     * @return string the Configuration key of a kind
     */
    public static function keyOf($kind)
    {
        return self::KEY_PREFIX . strtoupper(self::whitelisted($kind));
    }

    /**
     * Counts one sent request. A failure to write is logged and swallowed: statistics must not stop an issuance.
     *
     * @param string $kind an IfirmaRequestKind::KIND_* constant; anything else counts as KIND_OTHER
     */
    public function record($kind)
    {
        $key = self::keyOf($kind);
        $day = $this->day();
        try {
            $this->raise($key, $day);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('iFirma: the request usage of ' . $key . ' could not be written: ' . $e->getMessage(), self::LOG_ERROR);
        }
    }

    /**
     * @return array kind => requests sent today, for every kind, zeros included, in IfirmaRequestKind::all() order
     */
    public function todayByKind()
    {
        $day = $this->day();
        $counts = array();
        foreach (IfirmaRequestKind::all() as $kind) {
            $counts[$kind] = $this->countOn($kind, $day);
        }

        return $counts;
    }

    /**
     * @return array array('minute' => int, 'day' => int, 'by_kind' => array kind => int): the guard's counts of the
     *               current minute and day and today's count per kind
     */
    public function summary()
    {
        $windows = $this->windows();

        return array(
            'minute' => $windows[IfirmaRateDecision::WINDOW_MINUTE]['used'],
            'day' => $windows[IfirmaRateDecision::WINDOW_DAY]['used'],
            'by_kind' => $this->todayByKind(),
        );
    }

    /**
     * @return array array('minute' => array('used' => int, 'cap' => int), 'day' => array('used' => int, 'cap' => int))
     */
    public function windows()
    {
        return $this->guard->windowUsage();
    }

    /**
     * @return int the unix time a running back-off ends, 0 when none runs
     */
    public function backoffUntil()
    {
        return $this->guard->backoffUntil();
    }

    /**
     * @param string $kind
     * @return string the kind when it is one of the constants, else KIND_OTHER (the kind ends up in SQL)
     */
    private static function whitelisted($kind)
    {
        return in_array($kind, IfirmaRequestKind::all(), true) ? $kind : IfirmaRequestKind::KIND_OTHER;
    }

    /**
     * @param string $key
     * @param string $day
     */
    private function raise($key, $day)
    {
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'configuration';
        $d = pSQL($day);
        // One atomic statement: raise the counter of today, or start it when it still holds an earlier day.
        $db->execute(
            "UPDATE `" . $table . "` SET `value` = IF(SUBSTRING_INDEX(`value`, ':', 1) = '" . $d . "', "
            . "CONCAT('" . $d . ":', SUBSTRING_INDEX(`value`, ':', -1) + 1), '" . $d . ":1') "
            . "WHERE `name` = '" . pSQL($key) . "'"
        );
        if ((int) $db->Affected_Rows() >= 1) {
            return;
        }

        $stored = $db->getValue("SELECT `value` FROM `" . $table . "` WHERE `name` = '" . pSQL($key) . "'", false);
        if ($stored === false || $stored === null) {
            // No row yet (installed before this counter existed): start it.
            Configuration::updateValue($key, $day . ':1');
        }
    }

    /**
     * @param string $kind
     * @param string $day
     * @return int
     */
    private function countOn($kind, $day)
    {
        $stored = Db::getInstance()->getValue(
            "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL(self::keyOf($kind)) . "'",
            false
        );
        $parts = explode(':', (string) $stored, 2);

        return (count($parts) === 2 && $parts[0] === $day) ? (int) $parts[1] : 0;
    }

    /**
     * @return string today's date in the shop timezone
     */
    private function day()
    {
        return date('Y-m-d', $this->clock !== null ? (int) call_user_func($this->clock) : time());
    }
}
