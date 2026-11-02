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
 * Value object returned by IfirmaDuplicateGuard::check().
 *
 * Outcomes: LOCAL_ISSUED (order already has an issued record), DISABLED (guard off),
 * BYPASSED (one-time Issue anyway), PROCEED (nothing found, safe to create),
 * ADOPT (confirmed match: `candidate` + `receipt`), SUSPECTED (unconfirmed candidates:
 * `candidates` + `receipt`), INCONCLUSIVE (list query failed: `error`, `rateLimited`).
 */
class IfirmaGuardResult
{
    const OUTCOME_LOCAL_ISSUED = 'local_issued';
    const OUTCOME_DISABLED = 'disabled';
    const OUTCOME_BYPASSED = 'bypassed';
    const OUTCOME_PROCEED = 'proceed';
    const OUTCOME_ADOPT = 'adopt';
    const OUTCOME_SUSPECTED = 'suspected';
    const OUTCOME_INCONCLUSIVE = 'inconclusive';

    /** @var string */
    public $outcome = self::OUTCOME_PROCEED;

    /** @var array|null list row of the adopted document (ADOPT) */
    public $candidate = null;

    /** @var array list rows of the suspected documents (SUSPECTED) */
    public $candidates = array();

    /** @var string */
    public $error = '';

    /** @var bool */
    public $rateLimited = false;

    /** @var int number of list calls made */
    public $queries = 0;

    /** @var IfirmaReceipt|null record written or reused by the guard */
    public $receipt = null;

    /**
     * @param string $outcome
     */
    public function __construct($outcome = self::OUTCOME_PROCEED)
    {
        $this->outcome = $outcome;
    }

    /**
     * @return bool true when a new document may be created
     */
    public function allowsCreation()
    {
        return in_array($this->outcome, array(self::OUTCOME_PROCEED, self::OUTCOME_DISABLED, self::OUTCOME_BYPASSED), true);
    }

    /**
     * @return bool true when issuance must stop (suspected or inconclusive)
     */
    public function isBlocking()
    {
        return in_array($this->outcome, array(self::OUTCOME_SUSPECTED, self::OUTCOME_INCONCLUSIVE), true);
    }
}
