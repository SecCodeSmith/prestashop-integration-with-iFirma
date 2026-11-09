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
 * The documentation and support links shown on the module configuration page, which the
 * PrestaShop Addons technical validation asks for.
 */
class IfirmaHelpLinks
{
    const DOCUMENTATION_URL = 'https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/tree/master/docs';
    const SUPPORT_URL = 'https://github.com/SecCodeSmith/prestashop-integration-with-iFirma/blob/master/SUPPORT.md';

    /** @var string */
    private $documentationUrl;

    /** @var string */
    private $supportUrl;

    /**
     * @param string $documentationUrl
     * @param string $supportUrl
     */
    public function __construct($documentationUrl = self::DOCUMENTATION_URL, $supportUrl = self::SUPPORT_URL)
    {
        $this->documentationUrl = $documentationUrl;
        $this->supportUrl = $supportUrl;
    }

    /**
     * @param string $documentationLabel already translated
     * @param string $supportLabel already translated
     * @return string HTML
     */
    public function render($documentationLabel, $supportLabel)
    {
        return '<p class="ifirma-help-links">'
            . $this->link($this->documentationUrl, $documentationLabel)
            . ' &middot; '
            . $this->link($this->supportUrl, $supportLabel)
            . '</p>';
    }

    /**
     * @param string $url
     * @param string $label
     * @return string
     */
    private function link($url, $label)
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}
