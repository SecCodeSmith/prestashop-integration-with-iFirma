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
 * Decides whether a request to the module's configuration page may do what it asks. PrestaShop 9.1 lets an employee
 * with the Module Manager's view right reach getContent() without the module's Configure permission, so the module
 * asks for the permission itself on every version, and lets an action that changes state run only for a POST that
 * carries a valid back-office token.
 */
class IfirmaConfigPageGuard
{
    /** The request may run the action it names. */
    const ALLOWED = 'allowed';

    /** The employee may not configure the module: nothing of the page is shown. */
    const DENIED = 'denied';

    /** The request is not a POST: the page is shown and no action runs. */
    const NOT_A_POST = 'not-a-post';

    /** The POST carries no valid back-office token: no action runs and the page says the request was refused. */
    const BAD_TOKEN = 'bad-token';

    /** @var callable returns true when the employee may configure the module */
    private $mayConfigure;

    /** @var callable returns true when the request is a POST */
    private $isPost;

    /** @var callable returns true when the request carries the employee's back-office token */
    private $tokenIsValid;

    /**
     * @param callable $mayConfigure returns true when the employee holds the module's Configure permission
     * @param callable $isPost       returns true when the request method is POST
     * @param callable $tokenIsValid returns true when the request carries a valid back-office token
     */
    public function __construct($mayConfigure, $isPost, $tokenIsValid)
    {
        $this->mayConfigure = $mayConfigure;
        $this->isPost = $isPost;
        $this->tokenIsValid = $tokenIsValid;
    }

    /**
     * @return bool whether the employee may see the page at all
     */
    public function mayConfigure()
    {
        return (bool) call_user_func($this->mayConfigure);
    }

    /**
     * @return string one of the constants; the checks run in the order permission, method, token, so a refusal never
     *                says more than the first check that failed
     */
    public function verdict()
    {
        if (!$this->mayConfigure()) {
            return self::DENIED;
        }
        if (!call_user_func($this->isPost)) {
            return self::NOT_A_POST;
        }

        return call_user_func($this->tokenIsValid) ? self::ALLOWED : self::BAD_TOKEN;
    }

    /**
     * @return bool whether an action that changes state may run
     */
    public function allowsStateChange()
    {
        return $this->verdict() === self::ALLOWED;
    }
}
