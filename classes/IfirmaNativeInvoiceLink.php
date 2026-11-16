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

require_once dirname(__FILE__) . '/IfirmaNativeInvoiceOutcome.php';
require_once dirname(__FILE__) . '/IfirmaCustomerView.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaDataRequest.php';

/**
 * Decides what happens when a request reaches PrestaShop's built-in invoice link (the PDF invoice of Order history
 * and order details) for an order. PrestaShop has already accepted the request: invoices are enabled, the order
 * exists and belongs to the logged-in customer or the secure key matched. This policy only decides; the module class
 * acts on the answer.
 *
 * An order is handled by the module when it holds a document record in any state or has a pending data change
 * request. For a handled order the customer gets the iFirma document or a generic "not available yet", never a
 * second, PrestaShop invoice. The download itself stays in the protected receipt controller, which re-checks login,
 * token, ownership and KSeF and applies the download limits; the policy only names its URL from the stored token.
 */
class IfirmaNativeInvoiceLink
{
    const SETTING_KEY = 'IFIRMA_NATIVE_INVOICE_LINK';

    /** @var callable returns whether the setting is on */
    private $enabled;

    /** @var callable id_order => IfirmaReceipt|null */
    private $receiptLookup;

    /** @var callable id_order => IfirmaDataRequest|null (the pending one) */
    private $pendingLookup;

    /** @var IfirmaCustomerView the rule that decides whether a document can be handed out */
    private $view;

    /**
     * @param callable           $enabled       called without arguments; true while the setting is on
     * @param callable           $receiptLookup called with the order id; the document record or null
     * @param callable           $pendingLookup called with the order id; the pending data change request or null
     * @param IfirmaCustomerView $view          decides the issued state and the download URL
     */
    public function __construct(callable $enabled, callable $receiptLookup, callable $pendingLookup, IfirmaCustomerView $view)
    {
        $this->enabled = $enabled;
        $this->receiptLookup = $receiptLookup;
        $this->pendingLookup = $pendingLookup;
        $this->view = $view;
    }

    /**
     * The production wiring: the stored setting and the record lookups of the module's tables.
     *
     * @param Module $module
     * @param Link   $link
     * @return self
     */
    public static function create($module, $link)
    {
        return new self(
            function () {
                return (bool) (int) Configuration::get(IfirmaNativeInvoiceLink::SETTING_KEY);
            },
            array('IfirmaReceipt', 'getByOrder'),
            array('IfirmaDataRequest', 'getPendingByOrder'),
            new IfirmaCustomerView($module, $link)
        );
    }

    /**
     * @param int  $idOrder         the order of the invoice link
     * @param bool $requesterIsOwner true only for a logged-in customer who owns the order, not for a request that
     *                               PrestaShop authorised by the secure key alone
     * @return IfirmaNativeInvoiceOutcome
     */
    public function decide($idOrder, $requesterIsOwner)
    {
        if (!call_user_func($this->enabled)) {
            return IfirmaNativeInvoiceOutcome::passThrough();
        }
        $idOrder = (int) $idOrder;
        $receipt = call_user_func($this->receiptLookup, $idOrder);
        $pending = call_user_func($this->pendingLookup, $idOrder);
        if (!$receipt && !$pending) {
            return IfirmaNativeInvoiceOutcome::passThrough();
        }
        if (!$requesterIsOwner) {
            return IfirmaNativeInvoiceOutcome::notAvailable();
        }
        $url = $this->downloadUrl($receipt, $pending);

        return $url === null ? IfirmaNativeInvoiceOutcome::notAvailable() : IfirmaNativeInvoiceOutcome::redirectTo($url);
    }

    /**
     * @param IfirmaReceipt|null     $receipt
     * @param IfirmaDataRequest|null $pending
     * @return string|null null while the document cannot be handed out
     */
    private function downloadUrl($receipt, $pending)
    {
        if ($this->view->resolveState($receipt, $pending) !== IfirmaCustomerView::STATE_ISSUED) {
            return null;
        }

        return $this->view->downloadUrlFor($receipt->delivery_status, $receipt->ksef_status, $receipt->ksef_number, $receipt->security_token);
    }
}
