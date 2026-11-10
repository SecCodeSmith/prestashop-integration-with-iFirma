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
 * Limits the customer's document downloads: one document at most every DOCUMENT_WINDOW seconds, and
 * at most CUSTOMER_MAX downloads per customer within CUSTOMER_WINDOW seconds. A PDF download costs
 * a call to iFirma, so a loop over the token or the documents must not reach it.
 */
class IfirmaDownloadThrottle
{
    const DOCUMENT_WINDOW = 30;
    const CUSTOMER_WINDOW = 60;
    const CUSTOMER_MAX = 10;

    /** Downloads one customer can send to iFirma per calendar day, all documents together (see IfirmaDownloadDayCap). */
    const CUSTOMER_DAILY_MAX = 30;

    /** @var callable|null returns a unix timestamp */
    private $clock;

    /**
     * @param callable|null $clock test seam: returns a unix timestamp
     */
    public function __construct($clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * Claims the download: the per-customer count first, then the per-document slot in one conditional
     * UPDATE, so two parallel requests cannot both pass (a read followed by a write could).
     *
     * @param IfirmaReceipt $receipt
     * @return bool false when the customer downloaded too often or the document was downloaded in the last
     *              DOCUMENT_WINDOW seconds (another request took the slot)
     */
    public function claim(IfirmaReceipt $receipt)
    {
        $now = $this->now();

        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE id_customer = ' . (int) $receipt->id_customer . '
             AND last_download_at >= \'' . pSQL(date('Y-m-d H:i:s', $now - self::CUSTOMER_WINDOW)) . '\''
        );
        if ($count >= self::CUSTOMER_MAX) {
            return false;
        }

        $stamp = date('Y-m-d H:i:s', $now);
        $db = Db::getInstance();
        $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt`
             SET last_download_at = \'' . pSQL($stamp) . '\'
             WHERE id_ifirma_receipt = ' . (int) $receipt->id . '
             AND (last_download_at IS NULL OR last_download_at < \'' . pSQL(date('Y-m-d H:i:s', $now - self::DOCUMENT_WINDOW)) . '\')'
        );
        if ((int) $db->Affected_Rows() !== 1) {
            return false;
        }
        $receipt->last_download_at = $stamp;

        return true;
    }

    /**
     * Non-atomic read; kept for the smoke script. The front controller uses claim().
     *
     * @param IfirmaReceipt $receipt
     * @return bool false when the document or the customer downloaded too recently / too often
     */
    public function allows(IfirmaReceipt $receipt)
    {
        $now = $this->now();

        if (!empty($receipt->last_download_at)) {
            $last = strtotime((string) $receipt->last_download_at);
            if ($last !== false && $now - $last < self::DOCUMENT_WINDOW) {
                return false;
            }
        }

        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE id_customer = ' . (int) $receipt->id_customer . '
             AND last_download_at >= \'' . pSQL(date('Y-m-d H:i:s', $now - self::CUSTOMER_WINDOW)) . '\''
        );

        return $count < self::CUSTOMER_MAX;
    }

    /**
     * Records a download of the document now.
     *
     * @param IfirmaReceipt $receipt
     */
    public function stamp(IfirmaReceipt $receipt)
    {
        $stamp = date('Y-m-d H:i:s', $this->now());
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'ifirma_receipt`
             SET last_download_at = \'' . pSQL($stamp) . '\'
             WHERE id_ifirma_receipt = ' . (int) $receipt->id
        );
        $receipt->last_download_at = $stamp;
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }
}
