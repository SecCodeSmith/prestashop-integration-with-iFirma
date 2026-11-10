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

namespace PrestaShop\PrestaShop\Adapter {
    /**
     * The shop's service container as PrestaShop 9 gives it to a module during a back-office request: getInstance()
     * is null outside a Symfony request (like on PrestaShop 8.1 and in a command line run), which is the default here.
     */
    class SymfonyContainer
    {
        /** @var SymfonyContainer|null */
        public static $instance = null;

        /** @var array service id => service */
        public $services = array();

        public static function reset()
        {
            self::$instance = null;
        }

        public static function getInstance()
        {
            return self::$instance;
        }

        public function get($id)
        {
            return $this->services[$id];
        }
    }
}

namespace PrestaShopBundle\Security\Admin {
    /**
     * PrestaShop 9's check of the back-office token of the request: true for the Symfony CSRF token of the employee
     * in the request, whatever random form that token has (the token of one page view is not equal to the next).
     */
    class UserTokenManager
    {
        /** @var bool the answer of isTokenValid() */
        public $valid = false;

        /** @var Throwable|null thrown by isTokenValid(), like the core does for a token that is not a string */
        public $throws = null;

        public function isTokenValid(): bool
        {
            if ($this->throws !== null) {
                throw $this->throws;
            }

            return $this->valid;
        }
    }
}
