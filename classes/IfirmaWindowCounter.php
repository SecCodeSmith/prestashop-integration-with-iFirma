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

/**
 * Takes and gives back slots of one counting window. The counter is a Configuration row "<bucket>:<count>"; each
 * change is one atomic UPDATE, so concurrent requests cannot lose increments or exceed the cap.
 */
class IfirmaWindowCounter
{
    /**
     * Takes one slot of the window.
     *
     * @param IfirmaRateWindow $window
     * @param int              $now
     * @return int|null null when the slot was taken, else the count that makes the window full
     */
    public function take(IfirmaRateWindow $window, $now)
    {
        $bucket = $window->bucketAt($now);
        $cap = $window->getCap();
        $key = $window->getCounterKey();

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'configuration';
        $b = pSQL($bucket);
        // One atomic statement: reset or increment the counter, but only while the cap is not reached.
        $db->execute(
            "UPDATE `" . $table . "` SET `value` = IF(SUBSTRING_INDEX(`value`, ':', 1) = '" . $b . "', "
            . "CONCAT('" . $b . ":', SUBSTRING_INDEX(`value`, ':', -1) + 1), '" . $b . ":1') "
            . "WHERE `name` = '" . pSQL($key) . "' AND (SUBSTRING_INDEX(`value`, ':', 1) <> '" . $b . "' "
            . "OR SUBSTRING_INDEX(`value`, ':', -1) < " . (int) $cap . ")"
        );
        if ((int) $db->Affected_Rows() >= 1) {
            return null;
        }

        $stored = $db->getValue("SELECT `value` FROM `" . $table . "` WHERE `name` = '" . pSQL($key) . "'", false);
        if ($stored === false || $stored === null) {
            // No row yet (the module was never installed with the default): start the window.
            Configuration::updateValue($key, $bucket . ':1');

            return null;
        }

        $parts = explode(':', (string) $stored, 2);

        return (count($parts) === 2 && $parts[0] === $bucket) ? (int) $parts[1] : $cap;
    }

    /**
     * Gives back a slot taken in the given bucket. Nothing changes when the counter has moved on to another bucket
     * (the slot is gone with its window) or is already zero.
     *
     * @param IfirmaRateWindow $window
     * @param string           $bucket the bucket the slot was taken in
     */
    public function giveBack(IfirmaRateWindow $window, $bucket)
    {
        $b = pSQL($bucket);
        Db::getInstance()->execute(
            "UPDATE `" . _DB_PREFIX_ . "configuration` SET `value` = CONCAT('" . $b . ":', SUBSTRING_INDEX(`value`, ':', -1) - 1) "
            . "WHERE `name` = '" . pSQL($window->getCounterKey()) . "' AND SUBSTRING_INDEX(`value`, ':', 1) = '" . $b . "' "
            . "AND SUBSTRING_INDEX(`value`, ':', -1) > 0"
        );
    }
}
