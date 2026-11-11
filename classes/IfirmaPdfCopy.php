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
 * One stored copy of a document PDF, as the table holds it. Immutable. The content is null when the row was read
 * without it (a metadata read), and then the copy is never intact.
 */
class IfirmaPdfCopy
{
    const PDF_MARKER = '%PDF';

    /** @var int */
    private $receiptId;

    /** @var string */
    private $fingerprint;

    /** @var int the length recorded when the copy was stored */
    private $byteLength;

    /** @var string Y-m-d H:i:s */
    private $storedAt;

    /** @var string|null */
    private $content;

    /**
     * @param int         $receiptId
     * @param string      $fingerprint
     * @param int         $byteLength
     * @param string      $storedAt
     * @param string|null $content
     */
    public function __construct($receiptId, $fingerprint, $byteLength, $storedAt, $content = null)
    {
        $this->receiptId = (int) $receiptId;
        $this->fingerprint = (string) $fingerprint;
        $this->byteLength = (int) $byteLength;
        $this->storedAt = (string) $storedAt;
        $this->content = $content === null ? null : (string) $content;
    }

    /** @return int */
    public function receiptId()
    {
        return $this->receiptId;
    }

    /** @return string */
    public function fingerprint()
    {
        return $this->fingerprint;
    }

    /** @return int */
    public function byteLength()
    {
        return $this->byteLength;
    }

    /** @return string */
    public function storedAt()
    {
        return $this->storedAt;
    }

    /** @return string|null */
    public function content()
    {
        return $this->content;
    }

    /**
     * Whether the content is the one that was stored: present, of the recorded length and a PDF.
     *
     * @return bool
     */
    public function isIntact()
    {
        return $this->content !== null
            && strlen($this->content) === $this->byteLength
            && strpos($this->content, self::PDF_MARKER) === 0;
    }
}
