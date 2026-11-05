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

require_once dirname(__FILE__) . '/IfirmaContractorMap.php';

/**
 * The words for the codes that say how a customer came to be linked to an iFirma contractor
 * (IfirmaContractorMap::BY_*): one map for every screen that shows them.
 *
 * The labels arrive already translated. The translation scanner only finds the string literals that
 * ifirma.php passes to its own translator, so the module owns them (Ifirma::matchLabels()) and hands
 * them over keyed by code.
 */
class IfirmaMatchLabels
{
    /** @var string[] translated label of each code, in display order */
    private $labels;

    /**
     * @param string[] $labels IfirmaContractorMap::BY_* code => translated label
     */
    public function __construct(array $labels)
    {
        $this->labels = $labels;
    }

    /**
     * @param string $code a stored matched_by value
     * @return string the label of the code; the code itself when it has none (a value of an older version)
     */
    public function label($code)
    {
        $code = (string) $code;

        return isset($this->labels[$code]) ? $this->labels[$code] : $code;
    }

    /**
     * @return string[] code => label of every known code, in display order
     */
    public function labels()
    {
        return $this->labels;
    }

    /**
     * The codes of a customer linked to a contractor that already existed in iFirma: every code but
     * the module's own, which names the contractor the module created itself.
     *
     * @return string[]
     */
    public function linkCodes()
    {
        return array_values(array_diff(array_keys($this->labels), array(IfirmaContractorMap::BY_MODULE)));
    }
}
