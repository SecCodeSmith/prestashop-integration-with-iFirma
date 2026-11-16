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
 * What the module does with a request to PrestaShop's built-in invoice link: let PrestaShop render its own invoice,
 * send the customer to the protected download of the iFirma document, or answer that no invoice is available.
 *
 * Immutable; made by one of the three named constructors.
 */
final class IfirmaNativeInvoiceOutcome
{
    const PASS_THROUGH = 'pass_through';
    const REDIRECT = 'redirect';
    const NOT_AVAILABLE = 'not_available';

    /** @var string one of the constants above */
    private $kind;

    /** @var string */
    private $url;

    /**
     * @param string $kind
     * @param string $url
     */
    private function __construct($kind, $url)
    {
        $this->kind = $kind;
        $this->url = $url;
    }

    /**
     * @return self PrestaShop renders its own invoice
     */
    public static function passThrough()
    {
        return new self(self::PASS_THROUGH, '');
    }

    /**
     * @param string $url the protected download of the document, built by the module and never from request input
     * @return self
     * @throws InvalidArgumentException when there is no url to go to
     */
    public static function redirectTo($url)
    {
        $url = (string) $url;
        if ($url === '') {
            throw new InvalidArgumentException('A redirect needs the URL to go to.');
        }

        return new self(self::REDIRECT, $url);
    }

    /**
     * @return self no invoice is handed out
     */
    public static function notAvailable()
    {
        return new self(self::NOT_AVAILABLE, '');
    }

    /**
     * @return bool
     */
    public function isPassThrough()
    {
        return $this->kind === self::PASS_THROUGH;
    }

    /**
     * @return bool
     */
    public function isRedirect()
    {
        return $this->kind === self::REDIRECT;
    }

    /**
     * @return bool
     */
    public function isNotAvailable()
    {
        return $this->kind === self::NOT_AVAILABLE;
    }

    /**
     * @return string the redirect target; '' for the other outcomes
     */
    public function url()
    {
        return $this->url;
    }
}
