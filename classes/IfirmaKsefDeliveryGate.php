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

require_once dirname(__FILE__) . '/IfirmaKsefService.php';
require_once dirname(__FILE__) . '/IfirmaKsefStatus.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';

/**
 * The one definition of "this document is held back from the customer until KSeF accepted it", shared by the
 * pipeline, the cron jobs, the admin panel and the customer pages. A document that goes to KSeF under the configured
 * mode is not e-mailed at issuance: an invoice given outside KSeF should carry its KSeF number.
 */
class IfirmaKsefDeliveryGate
{
    /** @var IfirmaKsefService */
    private $ksef;

    /**
     * @param IfirmaKsefService $ksef
     */
    public function __construct(IfirmaKsefService $ksef)
    {
        $this->ksef = $ksef;
    }

    /**
     * Whether delivery of a just issued document must wait for KSeF: a channel that delivers, a document that goes to
     * KSeF under the mode (the submitter does not matter), and no acceptance yet. An external document is never held,
     * as the module does not deliver those at all.
     *
     * @param IfirmaReceipt $receipt
     * @param Order|null    $order
     * @return bool
     */
    public function mustWait(IfirmaReceipt $receipt, ?Order $order = null)
    {
        return (string) Configuration::get('IFIRMA_SEND_CHANNEL') !== 'none'
            && $receipt->source !== IfirmaReceipt::SOURCE_EXTERNAL
            && $this->ksef->goesToKsef($receipt, $order)
            && !$this->ksef->isAccepted($receipt);
    }

    /**
     * Whether a recorded document is still held back. It reads the receipt's own fields only, so the customer page and
     * the download make no API call.
     *
     * @param IfirmaReceipt $receipt
     * @return bool
     */
    public function isWaiting(IfirmaReceipt $receipt)
    {
        return (string) $receipt->delivery_status === IfirmaReceipt::DELIVERY_AWAITING_KSEF
            && !IfirmaKsefStatus::isAccepted($receipt->ksef_status, $receipt->ksef_number);
    }
}
