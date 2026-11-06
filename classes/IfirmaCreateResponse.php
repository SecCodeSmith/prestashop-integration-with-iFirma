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
 * Immutable value object returned by IfirmaReceiptService::parseCreateResponse(): what iFirma answered
 * to the request that creates a document.
 *  - accepted    Kod 0, the document exists
 *  - message     the Informacja of a rejection, as iFirma gave it; null when it gave none (or accepted)
 *  - identifier  the Identyfikator of the new document; empty when the answer names none (accepted only)
 *  - number      the number of the new document, PelnyNumer else Numer; null when the answer carries
 *                none, so that the caller can tell "no number" from an empty one (accepted only)
 */
class IfirmaCreateResponse
{
    /** @var bool */
    private $accepted;

    /** @var mixed|null */
    private $message;

    /** @var string */
    private $identifier;

    /** @var string|null */
    private $number;

    /**
     * @param bool       $accepted
     * @param mixed|null $message
     * @param mixed      $identifier
     * @param mixed|null $number
     */
    private function __construct($accepted, $message, $identifier, $number)
    {
        $this->accepted = $accepted;
        $this->message = $message;
        $this->identifier = (string) $identifier;
        $this->number = $number === null ? null : (string) $number;
    }

    /**
     * @param mixed      $identifier the Identyfikator of the new document as iFirma gave it, '' when the answer
     *                               names none; kept as a string
     * @param mixed|null $number     PelnyNumer else Numer as iFirma gave it, null when the answer carries
     *                               neither; kept as a string
     * @return IfirmaCreateResponse
     */
    public static function accepted($identifier, $number)
    {
        return new self(true, null, $identifier, $number);
    }

    /**
     * @param mixed|null $message the Informacja iFirma gave, kept as it came; null when it gave none
     * @return IfirmaCreateResponse
     */
    public static function rejected($message)
    {
        return new self(false, $message, '', null);
    }

    /**
     * @return bool true when iFirma answered Kod 0
     */
    public function isAccepted()
    {
        return $this->accepted;
    }

    /**
     * @return mixed|null the reason iFirma gave for a rejection; null when it gave none
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return string
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * @return string|null
     */
    public function getNumber()
    {
        return $this->number;
    }
}
