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

require_once dirname(__FILE__) . '/IfirmaInput.php';

/**
 * Whether the request carries the employee's back-office token. Before PrestaShop 9 the token is a fixed hash that the
 * module's own forms send back and that is compared as it is. From 9.0 on the token is a Symfony CSRF token that looks
 * different on every page view (it is randomised), so a plain comparison would refuse every valid request: only the
 * core's own check can tell. The core check is used whenever the shop offers one.
 */
class IfirmaBackOfficeToken
{
    /** @var callable returns the token the request carries */
    private $posted;

    /** @var callable returns the token this employee's forms carry (the fixed hash before PrestaShop 9) */
    private $expected;

    /** @var callable|null returns the core's verdict on the token of the request, null when the shop has none */
    private $coreVerdict;

    /**
     * @param callable      $posted      the token of the request (a form field or the address of the form)
     * @param callable      $expected    the fixed token of the employee, compared when the core offers no check
     * @param callable|null $coreVerdict the core's check of the request token, when the shop has one
     */
    public function __construct($posted, $expected, $coreVerdict = null)
    {
        $this->posted = $posted;
        $this->expected = $expected;
        $this->coreVerdict = $coreVerdict;
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        $posted = IfirmaInput::scalar(call_user_func($this->posted));
        if ($posted === '') {
            return false;
        }
        if ($this->coreVerdict !== null) {
            return (bool) call_user_func($this->coreVerdict);
        }
        $expected = IfirmaInput::scalar(call_user_func($this->expected));

        return $expected !== '' && hash_equals($expected, $posted);
    }
}
