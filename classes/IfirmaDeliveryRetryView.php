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

require_once dirname(__FILE__) . '/IfirmaDeliveryRetryPolicy.php';

/**
 * The one sentence that tells an employee where the automatic retry of a failed delivery stands. The order panel, the
 * Orders-list tooltip and the Documents list all use it, so the wording lives here only. Pure: the values of a record and a
 * table of words in, an unescaped text out (the caller escapes for its output).
 */
final class IfirmaDeliveryRetryView
{
    const DELIVERY_FAILED = 'failed';
    /** @var string a zero or older due time is the empty date of an unset column, not a schedule */
    const EARLIEST_DUE = '2000-01-01 00:00:00';
    /** @var int length of "Y-m-d H:i": the seconds of the due time are not shown */
    const SHOWN_DUE_LENGTH = 16;

    /** @var string[] */
    private $words;

    /**
     * @param string[] $words key => text; a missing key falls back to the English default
     */
    public function __construct(array $words = array())
    {
        $this->words = array_merge(self::defaultWords(), $words);
    }

    /**
     * @return string[]
     */
    public static function defaultWords()
    {
        return array(
            'retry_scheduled' => 'Attempt %1$d of %2$d, next attempt at %3$s',
            'retry_exhausted' => 'No automatic attempt remains',
            'retry_not_made' => 'No automatic attempt will be made',
            'retry_not_scheduled' => 'No automatic attempt is scheduled',
        );
    }

    /**
     * @param object $module the module (its l() translates a string of this class)
     * @return string[] the words in the language of the back office
     */
    public static function translatedWords($module)
    {
        return array(
            'retry_scheduled' => $module->l('Attempt %1$d of %2$d, next attempt at %3$s', 'ifirmadeliveryretryview'),
            'retry_exhausted' => $module->l('No automatic attempt remains', 'ifirmadeliveryretryview'),
            'retry_not_made' => $module->l('No automatic attempt will be made', 'ifirmadeliveryretryview'),
            'retry_not_scheduled' => $module->l('No automatic attempt is scheduled', 'ifirmadeliveryretryview'),
        );
    }

    /**
     * @param string      $deliveryStatus the delivery status of the record
     * @param int         $attemptsUsed   automatic attempts used so far
     * @param string|null $retryAt        'Y-m-d H:i:s' of the next attempt; empty or a zero date when none is due
     * @return string '' unless the delivery failed
     */
    public function text($deliveryStatus, $attemptsUsed, $retryAt)
    {
        if ($deliveryStatus !== self::DELIVERY_FAILED) {
            return '';
        }
        $attempts = (int) $attemptsUsed;
        $due = (string) $retryAt;
        if ($due !== '' && $due > self::EARLIEST_DUE) {
            return sprintf(
                $this->words['retry_scheduled'],
                $attempts,
                IfirmaDeliveryRetryPolicy::MAX_ATTEMPTS,
                substr($due, 0, self::SHOWN_DUE_LENGTH)
            );
        }
        if ($attempts >= IfirmaDeliveryRetryPolicy::MAX_ATTEMPTS) {
            return $this->words['retry_exhausted'];
        }

        return $this->words[$attempts > 0 ? 'retry_not_made' : 'retry_not_scheduled'];
    }
}
