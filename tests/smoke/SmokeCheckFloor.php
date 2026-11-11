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
 * The committed minimum of executed checks per smoke scenario (tests/smoke/check-floor.json), so that a silent drop
 * in the number of checks turns the run red. A check is executed when it passed or failed; skipped ones do not count.
 */
final class SmokeCheckFloor
{
    /**
     * @param array    $executed scenario name => checks executed in this run
     * @param array    $floor    scenario name => committed minimum
     * @param string[] $selected scenarios that ran
     *
     * @return string[] one line per scenario under its floor or missing from the floor file
     */
    public static function violations(array $executed, array $floor, array $selected)
    {
        $problems = array();
        foreach ($selected as $name) {
            if (!array_key_exists($name, $floor)) {
                $problems[] = 'check floor: scenario ' . $name . ' has no entry in check-floor.json; add it on purpose';
                continue;
            }
            $count = isset($executed[$name]) ? (int) $executed[$name] : 0;
            if ($count < (int) $floor[$name]) {
                $problems[] = 'check floor: scenario ' . $name . ' executed ' . $count
                    . ' checks, the committed floor is ' . (int) $floor[$name];
            }
        }

        return $problems;
    }

    /**
     * Everything the run has to say about the floor: a missing or malformed file counts like a scenario under its floor,
     * so a run can never pass without its check count being verified.
     *
     * @param array       $executed     scenario name => checks executed in this run
     * @param string|null $floorContent  the content of check-floor.json, null when the file does not exist
     * @param string[]    $selected      scenarios that ran
     *
     * @return string[] one line per problem
     */
    public static function problems(array $executed, $floorContent, array $selected)
    {
        if ($floorContent === null) {
            return array('check floor: tests/smoke/check-floor.json is missing; write it from a green run (SMOKE_WRITE_FLOOR=1) and commit it');
        }
        try {
            return self::violations($executed, self::parse($floorContent), $selected);
        } catch (RuntimeException $e) {
            return array('check floor: ' . $e->getMessage());
        }
    }

    /**
     * @return array scenario name => minimum
     *
     * @throws RuntimeException when the file is not a JSON object of non-negative integers
     */
    public static function parse($json)
    {
        $data = json_decode((string) $json, true);
        if (!is_array($data)) {
            throw new RuntimeException('check-floor.json is not a JSON object');
        }
        foreach ($data as $name => $count) {
            if (!is_int($count) || $count < 0) {
                throw new RuntimeException('check-floor.json: the floor of scenario ' . $name . ' is not a non-negative integer');
            }
        }

        return $data;
    }

    /**
     * The file content for a run: the executed counts merged over the existing floor, sorted by name.
     *
     * @throws RuntimeException when any check failed, because a red run must never lower or set a floor
     */
    public static function render(array $executed, $failedChecks, array $existing)
    {
        if ((int) $failedChecks > 0) {
            throw new RuntimeException('refusing to write check-floor.json: ' . (int) $failedChecks . ' check(s) failed');
        }
        $floor = array_merge($existing, $executed);
        ksort($floor);

        return json_encode($floor, JSON_PRETTY_PRINT) . "\n";
    }
}
