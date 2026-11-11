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
 * Counts requests per second in one Configuration row and answers for the last 60 seconds, so a cap holds for every
 * period of 60 seconds wherever it starts (a calendar-minute counter lets twice the cap through around the turn of
 * a minute).
 *
 * The row is "<unix second>=<count>" pairs joined by commas, oldest first, pruned to the window on every change.
 * A row written by module 1.9.1 or older ("<YmdHi>:<count>") counts as that many requests in the last second of its
 * calendar minute, which is the safe reading. Requests dated after the current time (the server clock was set back)
 * are never forgotten: they count as requests of the current second, and a refusal writes the row back that way so
 * they start to age. A change is a compare-and-set UPDATE: it succeeds only while the row
 * still holds the value that was read, so concurrent requests cannot lose an increment or pass the cap together.
 */
class IfirmaRollingWindowCounter
{
    /** The window holds this second and the 60 before it, so any 60 real seconds are covered. */
    const WINDOW_SECONDS = 60;

    /**
     * Reads and writes tried before the request is refused because other requests keep changing the row. Each round
     * has exactly one winner, so this many requests that reach the row at the same instant are all served.
     */
    const MAX_ATTEMPTS = 20;

    /** A count above this is a damaged row, not traffic; it is cut so sums stay integers. */
    const MAX_COUNT_PER_SECOND = 1000000;

    /**
     * Takes one slot of the window.
     *
     * @param string $key Configuration row of the counter
     * @param int    $cap most requests the window admits
     * @param int    $now unix time
     * @return int|null null when the slot was taken, else the count that makes the window full
     */
    public function take($key, $cap, $now)
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $stored = $this->storedValue($key);
            if ($stored === false || $stored === null) {
                Configuration::updateValue($key, $this->serialise(array((int) $now => 1)));

                return null;
            }
            $dated = $this->datedSeconds((string) $stored);
            $seconds = $this->windowOf($dated, (int) $now);
            $total = array_sum($seconds);
            if ($total >= $cap) {
                if ($this->hasSecondAfter($dated, (int) $now)) {
                    $this->replaceIfUnchanged($key, (string) $stored, $this->serialise($seconds));
                }

                return (int) $total;
            }
            $seconds[(int) $now] = (isset($seconds[(int) $now]) ? $seconds[(int) $now] : 0) + 1;
            if ($this->replaceIfUnchanged($key, (string) $stored, $this->serialise($seconds))) {
                return null;
            }
        }

        return (int) $cap;
    }

    /**
     * Gives back a slot that take() took at $second. Nothing changes when the row no longer holds a positive count
     * for that second (it left the window, or was re-dated), so a unit can never be given back twice or for a
     * request that was counted elsewhere. A slot taken at second S is to be given back at second S.
     *
     * @param string $key
     * @param int    $second the second the slot was taken at
     * @param int    $now    unix time
     */
    public function giveBack($key, $second, $now)
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $stored = $this->storedValue($key);
            if ($stored === false || $stored === null) {
                return;
            }
            $dated = $this->datedSeconds((string) $stored);
            if (!isset($dated[(int) $second]) || $dated[(int) $second] < 1) {
                return;
            }
            $seconds = $this->windowOf($dated, (int) $now);
            $counted = min((int) $second, (int) $now);
            if (!isset($seconds[$counted])) {
                return;
            }
            $seconds[$counted]--;
            if ($seconds[$counted] < 1) {
                unset($seconds[$counted]);
            }
            if ($this->replaceIfUnchanged($key, (string) $stored, $this->serialise($seconds))) {
                return;
            }
        }
    }

    /**
     * @param string $key
     * @param int    $cap
     * @param int    $now
     * @return int the unix second from which the window has room for a request again: $now when it has room already,
     *             else the second after the oldest counted requests that must age out first have left the window
     */
    public function freeAt($key, $cap, $now)
    {
        $seconds = $this->secondsOf((string) $this->storedValue($key), (int) $now);
        $total = array_sum($seconds);
        $freeAt = (int) $now;
        foreach ($seconds as $second => $count) {
            if ($total < $cap) {
                break;
            }
            $total -= $count;
            $freeAt = $second + self::WINDOW_SECONDS + 1;
        }

        return $freeAt;
    }

    /**
     * @param string $key
     * @param int    $now
     * @return int requests counted in the window that ends at $now, read from the database
     */
    public function count($key, $now)
    {
        return (int) array_sum($this->secondsOf((string) $this->storedValue($key), (int) $now));
    }

    /**
     * Reads the row from the database, never from the Configuration cache: a request that was counted a moment ago
     * by another process must be seen. The configuration table has no unique key on the name, so the first row is
     * always the one that is read.
     *
     * @param string $key
     * @return string|false|null the stored value, false or null when the row does not exist
     */
    protected function storedValue($key)
    {
        return Db::getInstance()->getValue(
            "SELECT `value` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` = '" . pSQL($key) . "' ORDER BY `id_configuration`",
            false
        );
    }

    /**
     * The values go through pSQL() with HTML allowed: pSQL() would otherwise strip tags from the value that was read
     * (a stored "1<2" would never match itself) and the row could not be replaced.
     *
     * @param string $key
     * @param string $expected the value that was read
     * @param string $new      the value to store
     * @return bool true when the row still held $expected and now holds $new
     */
    private function replaceIfUnchanged($key, $expected, $new)
    {
        $db = Db::getInstance();
        $db->execute(
            "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = '" . pSQL($new, true) . "' "
            . "WHERE `name` = '" . pSQL($key) . "' AND `value` = '" . pSQL($expected, true) . "'"
        );

        return (int) $db->Affected_Rows() >= 1;
    }

    /**
     * @param string $stored
     * @param int    $now
     * @return int[] unix second => requests of the window that ends at $now; unreadable parts are left out
     */
    private function secondsOf($stored, $now)
    {
        return $this->windowOf($this->datedSeconds($stored), $now);
    }

    /**
     * @param string $stored
     * @return int[] unix second => requests, as the row states them
     */
    private function datedSeconds($stored)
    {
        $seconds = $this->legacyMinute($stored);
        if ($seconds !== null) {
            return $seconds;
        }
        $seconds = array();
        foreach (explode(',', $stored) as $pair) {
            if (preg_match('/^(\d{1,12})=(\d{1,9})$/', $pair, $m)) {
                $seconds[(int) $m[1]] = min((int) $m[2], self::MAX_COUNT_PER_SECOND);
            }
        }

        return $seconds;
    }

    /**
     * @param int[] $dated
     * @param int   $now
     * @return int[] the seconds inside the window; requests dated after $now count as requests of $now
     */
    private function windowOf(array $dated, $now)
    {
        $inWindow = array();
        foreach ($dated as $second => $count) {
            $second = min($second, $now);
            if ($second >= $now - self::WINDOW_SECONDS && $count > 0) {
                $inWindow[$second] = (isset($inWindow[$second]) ? $inWindow[$second] : 0) + $count;
            }
        }
        ksort($inWindow);

        return $inWindow;
    }

    /**
     * @param int[] $dated
     * @param int   $now
     * @return bool
     */
    private function hasSecondAfter(array $dated, $now)
    {
        return $dated !== array() && max(array_keys($dated)) > $now;
    }

    /**
     * @param string $stored
     * @return int[]|null the requests of a "<YmdHi>:<count>" row dated to the last second of that minute (which may
     *                    still be ahead of the clock, see windowOf()), null when
     *                    the row is not in that format
     */
    private function legacyMinute($stored)
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2}):(\d{1,9})$/', $stored, $m)) {
            return null;
        }
        $lastSecond = mktime((int) $m[4], (int) $m[5], 59, (int) $m[2], (int) $m[3], (int) $m[1]);

        return array($lastSecond => min((int) $m[6], self::MAX_COUNT_PER_SECOND));
    }

    /**
     * @param int[] $seconds unix second => requests
     * @return string
     */
    private function serialise(array $seconds)
    {
        ksort($seconds);
        $pairs = array();
        foreach ($seconds as $second => $count) {
            $pairs[] = (int) $second . '=' . (int) $count;
        }

        return implode(',', $pairs);
    }
}
