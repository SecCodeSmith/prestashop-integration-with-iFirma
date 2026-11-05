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

/**
 * Data providers for the tests that pin a guard catching Throwable: one Exception and one Error, so
 * that a guard that only catches Exception fails the Error data set. For a PHPUnit TestCase; name a
 * provider in @dataProvider.
 *
 * - throwables(): the pair with the messages "exception raised" and "error raised";
 * - throwablesRaising($exceptionMessage, $errorMessage): the same two data sets with other messages,
 *   for a test class whose messages belong to its scenario (ErrorGuardTest::failures()).
 */
trait IfirmaThrowableProviders
{
    /**
     * @return array name => array(Throwable)
     */
    public function throwables()
    {
        return $this->throwablesRaising('exception raised', 'error raised');
    }

    /**
     * @param string $exceptionMessage message of the RuntimeException
     * @param string $errorMessage message of the TypeError
     * @return array name => array(Throwable)
     */
    protected function throwablesRaising($exceptionMessage, $errorMessage)
    {
        return array(
            'Exception' => array(new RuntimeException($exceptionMessage)),
            'Error' => array(new TypeError($errorMessage)),
        );
    }
}
