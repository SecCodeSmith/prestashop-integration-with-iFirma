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
 * Immutable value object returned by IfirmaContractorService::ensure(): which iFirma contractor a
 * document is issued to, and how the module got there.
 *
 * It replaces the array ensure() used to return; toArray() gives that array back, key for key.
 *  - identifier          the contractor to reference; null when the document has to go without one
 *  - action              kept | created | updated | fallback | disabled (IfirmaContractorService::ACTION_*)
 *  - message             the failure text of a fallback; empty otherwise
 *  - matchedBy           how an existing contractor was found (IfirmaContractorMap::BY_*); empty without an identifier
 *  - linkedIdentifier    the identifier the customer link points at; null without an identifier or a match
 *  - note                structured note (code, params) the back office renders in its own language; null when none
 *  - assignedIdentifier  the identifier iFirma stored a created contractor under, when it differs from the one sent
 *  - readback            what the read-back of a created contractor saw: tried, search, search_error
 */
class IfirmaContractorResolution
{
    /** @var string|null */
    private $identifier;

    /** @var string */
    private $action;

    /** @var string */
    private $message;

    /** @var string */
    private $matchedBy;

    /** @var string|null */
    private $linkedIdentifier;

    /** @var array|null */
    private $note;

    /** @var string|null */
    private $assignedIdentifier;

    /** @var array */
    private $readback;

    /**
     * @param string|null $identifier
     * @param string      $action
     * @param string      $message            the failure text of a fallback; empty for the other actions
     * @param string      $matchedBy          left out when there is no identifier
     * @param array|null  $note
     * @param string|null $assignedIdentifier left out when there is no identifier
     * @param array|null  $readback           null when no contractor was created, so nothing was read back
     */
    public function __construct($identifier, $action, $message = '', $matchedBy = '', $note = null, $assignedIdentifier = null, $readback = null)
    {
        $matchedBy = (string) $matchedBy;
        $this->identifier = $identifier === null ? null : (string) $identifier;
        $this->action = (string) $action;
        $this->message = (string) $message;
        $this->matchedBy = $this->identifier === null ? '' : $matchedBy;
        $this->linkedIdentifier = ($this->identifier !== null && $matchedBy !== '') ? $this->identifier : null;
        $this->note = is_array($note) ? $note : null;
        $this->assignedIdentifier = ($this->identifier !== null && $assignedIdentifier !== null) ? (string) $assignedIdentifier : null;
        $this->readback = is_array($readback) ? $readback : array('tried' => array(), 'search' => 'none', 'search_error' => '');
    }

    /**
     * @return string|null
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return string
     */
    public function getMatchedBy()
    {
        return $this->matchedBy;
    }

    /**
     * @return string|null
     */
    public function getLinkedIdentifier()
    {
        return $this->linkedIdentifier;
    }

    /**
     * @return array|null code and params of the note
     */
    public function getNote()
    {
        return $this->note;
    }

    /**
     * @return string|null
     */
    public function getAssignedId()
    {
        return $this->assignedIdentifier;
    }

    /**
     * @return array tried (identifier, answer), search (none|ambiguous|error|found), search_error
     */
    public function getReadback()
    {
        return $this->readback;
    }

    /**
     * @return array identifier, action, message, matched_by, linked_identifier, note, assigned_identifier
     */
    public function toArray()
    {
        return array(
            'identifier' => $this->identifier,
            'action' => $this->action,
            'message' => $this->message,
            'matched_by' => $this->matchedBy,
            'linked_identifier' => $this->linkedIdentifier,
            'note' => $this->note,
            'assigned_identifier' => $this->assignedIdentifier,
        );
    }
}
