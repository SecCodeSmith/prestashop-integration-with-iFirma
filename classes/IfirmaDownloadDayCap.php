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

require_once dirname(__FILE__) . '/IfirmaDownloadThrottle.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaSchema.php';

/**
 * The most PDF downloads one customer can send to iFirma per calendar day (IfirmaDownloadThrottle::CUSTOMER_DAILY_MAX),
 * all their documents together, so one account cannot use up the budget all customers share.
 *
 * The count lives on the customer's receipt rows (download_day, download_count): no table of its own and no key per
 * customer. A download is counted before it is sent by one atomic UPDATE of its document, and given back when the
 * request was refused before it was sent. The check and the count are separate statements, so parallel requests that
 * all pass the check can overshoot the cap by the number of requests in flight; the throttle keeps that to a few.
 */
class IfirmaDownloadDayCap
{
    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /** @var string|null the day the last take() counted on, for give-back */
    private $takenDay;

    /**
     * @param callable|null $clock
     */
    public function __construct($clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @param int $idCustomer the owner of the document
     * @return bool true when the customer has already sent the day's downloads
     */
    public function isExhausted($idCustomer)
    {
        IfirmaSchema::ensureReceiptDownloadCountColumns();
        $total = (int) Db::getInstance()->getValue(
            'SELECT COALESCE(SUM(download_count), 0) FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE id_customer = ' . (int) $idCustomer . '
             AND download_day = \'' . pSQL($this->today()) . '\''
        );

        return $total >= IfirmaDownloadThrottle::CUSTOMER_DAILY_MAX;
    }

    /**
     * Counts one download of the document today: raises its count, or starts it at 1 when it holds an earlier day.
     *
     * @param IfirmaReceipt $receipt
     */
    public function take(IfirmaReceipt $receipt)
    {
        $this->takenDay = $this->today();
        $day = pSQL($this->takenDay);
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt`
             SET download_count = IF(download_day = \'' . $day . '\', download_count + 1, 1), download_day = \'' . $day . '\'
             WHERE id_ifirma_receipt = ' . (int) $receipt->id
        );
    }

    /**
     * Gives back the download the last take() counted; nothing changes when none was counted or the day has moved on.
     *
     * @param IfirmaReceipt $receipt
     */
    public function giveBack(IfirmaReceipt $receipt)
    {
        if ($this->takenDay === null) {
            return;
        }
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt`
             SET download_count = download_count - 1
             WHERE id_ifirma_receipt = ' . (int) $receipt->id . '
             AND download_day = \'' . pSQL($this->takenDay) . '\'
             AND download_count > 0'
        );
        $this->takenDay = null;
    }

    /**
     * @return string today in the shop time zone, Y-m-d
     */
    private function today()
    {
        return date('Y-m-d', $this->clock !== null ? (int) call_user_func($this->clock) : time());
    }
}
