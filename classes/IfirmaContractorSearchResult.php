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
 * Immutable value object returned by IfirmaContractorService::search(): the contractor rows iFirma
 * answered with and, when the search failed, the message of the failure.
 *
 * A failed search counts as "no match" (no rows), and the message tells the caller why; an empty
 * message means the search itself worked, whether or not it found anything.
 */
class IfirmaContractorSearchResult
{
    /** @var array */
    private $rows;

    /** @var string */
    private $error;

    /**
     * @param array  $rows  contractor rows, as iFirma returned them
     * @param string $error failure message; empty when the search worked
     */
    public function __construct(array $rows = array(), $error = '')
    {
        $this->rows = $rows;
        $this->error = (string) $error;
    }

    /**
     * @return array list of contractor rows
     */
    public function getRows()
    {
        return $this->rows;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }
}
