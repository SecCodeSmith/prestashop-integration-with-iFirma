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
 * Decides which smoke scenarios run, from SMOKE_ONLY and SMOKE_SKIP.
 *
 * SMOKE_ONLY with a comma is a list of scenario names (A,A2,B). Without a comma it keeps its old meaning, a string of
 * single-letter names (ABC), unless the whole value is one known name (A2). Names are case-insensitive. An unknown
 * name is an error, so a typo can never silently run fewer scenarios.
 */
final class SmokeSelection
{
    private $selected;
    private $skipped;
    private $notSelected;

    private function __construct(array $selected, array $skipped, array $notSelected)
    {
        $this->selected = $selected;
        $this->skipped = $skipped;
        $this->notSelected = $notSelected;
    }

    /**
     * @param string   $only  value of SMOKE_ONLY ('' selects every scenario)
     * @param string   $skip  value of SMOKE_SKIP, comma-separated
     * @param string[] $known every scenario name, in run order
     *
     * @throws InvalidArgumentException on an unknown name
     */
    public static function parse($only, $skip, array $known)
    {
        $only = trim((string) $only);
        $wanted = $only === '' ? $known : self::namesOf($only, $known, 'SMOKE_ONLY');
        $skipped = self::namesOf(trim((string) $skip), $known, 'SMOKE_SKIP', true);

        $selected = array();
        $notSelected = array();
        foreach ($known as $name) {
            if (in_array($name, $skipped, true)) {
                continue;
            }
            if (in_array($name, $wanted, true)) {
                $selected[] = $name;
            } else {
                $notSelected[] = $name;
            }
        }

        return new self($selected, array_values(array_intersect($known, $skipped)), $notSelected);
    }

    public function selected()
    {
        return $this->selected;
    }

    public function skipped()
    {
        return $this->skipped;
    }

    /** Scenarios that exist but were neither selected nor skipped on purpose. */
    public function notSelected()
    {
        return $this->notSelected;
    }

    public function runs($name)
    {
        return in_array($name, $this->selected, true);
    }

    public function describe()
    {
        return 'selected: ' . implode(', ', $this->selected)
            . '; skipped on purpose: ' . ($this->skipped === array() ? 'none' : implode(', ', $this->skipped));
    }

    private static function namesOf($value, array $known, $variable, $alwaysList = false)
    {
        if ($value === '') {
            return array();
        }
        $value = strtoupper($value);
        if ($alwaysList || strpos($value, ',') !== false) {
            $names = array_filter(array_map('trim', explode(',', $value)), 'strlen');
        } elseif (in_array($value, $known, true)) {
            $names = array($value);
        } else {
            $names = str_split($value);
        }
        $unknown = array_values(array_unique(array_diff($names, $known)));
        if ($unknown !== array()) {
            throw new InvalidArgumentException(
                $variable . ' names an unknown scenario: ' . implode(', ', $unknown) . '. Known: ' . implode(', ', $known)
            );
        }

        return array_values($names);
    }
}
