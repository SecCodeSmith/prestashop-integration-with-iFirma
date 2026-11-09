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
 * What iFirma reports about a document's KSeF state, or why that could not be read.
 */
class IfirmaKsefRemoteState
{
    /** @var bool */
    private $readable;

    /** @var string */
    private $status;

    /** @var string */
    private $number;

    /** @var string */
    private $error;

    /** @var bool */
    private $rateLimited;

    /**
     * @param bool   $readable
     * @param string $status
     * @param string $number
     * @param string $error
     * @param bool   $rateLimited
     */
    private function __construct($readable, $status, $number, $error, $rateLimited)
    {
        $this->readable = (bool) $readable;
        $this->status = (string) $status;
        $this->number = (string) $number;
        $this->error = (string) $error;
        $this->rateLimited = (bool) $rateLimited;
    }

    /**
     * @param string $status '' when iFirma reports none
     * @param string $number
     * @return IfirmaKsefRemoteState
     */
    public static function reported($status, $number)
    {
        return new self(true, trim((string) $status), trim((string) $number), '', false);
    }

    /**
     * @param string $error
     * @param bool   $rateLimited whether the read was refused by the rate limit
     * @return IfirmaKsefRemoteState
     */
    public static function unreadable($error, $rateLimited)
    {
        return new self(false, '', '', $error, $rateLimited);
    }

    /**
     * @return bool
     */
    public function isReadable()
    {
        return $this->readable;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getNumber()
    {
        return $this->number;
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @return bool
     */
    public function isRateLimited()
    {
        return $this->rateLimited;
    }
}
