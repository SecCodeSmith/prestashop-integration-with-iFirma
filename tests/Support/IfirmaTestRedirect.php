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

/** Thrown by Tools::redirect()/redirectLink() instead of sending a header and exiting. */
class IfirmaTestRedirect extends RuntimeException
{
    /** @var string */
    public $url;

    public function __construct($url)
    {
        $this->url = (string) $url;
        parent::__construct('Redirect to ' . $this->url);
    }

    public function getUrl()
    {
        return $this->url;
    }
}
