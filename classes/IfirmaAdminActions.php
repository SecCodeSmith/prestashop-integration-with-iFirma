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

require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaCustomerContractorService.php';
require_once dirname(__FILE__) . '/IfirmaReceiptActions.php';
require_once dirname(__FILE__) . '/IfirmaCustomerActions.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestActions.php';

/**
 * Admin action logic behind the AdminIfirma ajax endpoints.
 *
 * Every public action returns array('success' => bool, 'message' => string, 'data' => array)
 * and never throws, so the controller only has to translate the result into JSON.
 *
 * The class the controller instantiates. The work is done by IfirmaReceiptActions (documents),
 * IfirmaCustomerActions (contractors) and IfirmaDataRequestActions (data requests), which share
 * IfirmaActionSupport.
 */
class IfirmaAdminActions
{
    /** @var IfirmaReceiptActions */
    private $receipts;

    /** @var IfirmaCustomerActions */
    private $customers;

    /** @var IfirmaDataRequestActions */
    private $dataRequests;

    /**
     * @param Module $module
     */
    public function __construct(Module $module)
    {
        $this->receipts = new IfirmaReceiptActions($module);
        $this->customers = new IfirmaCustomerActions($module);
        $this->dataRequests = new IfirmaDataRequestActions($module);
    }

    /**
     * @param int $idOrder
     * @param int $idEmployee
     * @return array
     */
    public function createReceipt($idOrder, $idEmployee)
    {
        return $this->receipts->createReceipt($idOrder, $idEmployee);
    }

    /**
     * Issues the document although the duplicate guard found suspects.
     *
     * @param int $idOrder
     * @param int $idEmployee
     * @return array
     */
    public function issueAnyway($idOrder, $idEmployee)
    {
        return $this->receipts->issueAnyway($idOrder, $idEmployee);
    }

    /**
     * Adopts a duplicate candidate as the order's document.
     *
     * @param int        $idOrder
     * @param string|int $fakturaId
     * @param int        $idEmployee
     * @return array
     */
    public function linkCandidate($idOrder, $fakturaId, $idEmployee)
    {
        return $this->receipts->linkCandidate($idOrder, $fakturaId, $idEmployee);
    }

    /**
     * @param int        $idOrder
     * @param float|null $amount
     * @return array
     */
    public function updateStatus($idOrder, $amount = null)
    {
        return $this->receipts->updateStatus($idOrder, $amount);
    }

    /**
     * @param int $idOrder
     * @return array
     */
    public function resend($idOrder)
    {
        return $this->receipts->resend($idOrder);
    }

    /**
     * @param int $idOrder
     * @param int $idEmployee
     * @return array
     */
    public function markSent($idOrder, $idEmployee)
    {
        return $this->receipts->markSent($idOrder, $idEmployee);
    }

    /**
     * Submits the issued document to KSeF regardless of the configured mode (manual button).
     *
     * @param int $idOrder
     * @return array
     */
    public function sendToKsef($idOrder)
    {
        return $this->receipts->sendToKsef($idOrder);
    }

    /**
     * @param int $idOrder
     * @return array
     */
    public function refreshStatus($idOrder)
    {
        return $this->receipts->refreshStatus($idOrder);
    }

    /**
     * Registers the order customer as an iFirma contractor without issuing a document.
     *
     * @param int $idOrder
     * @return array
     */
    public function registerContractor($idOrder)
    {
        return $this->customers->registerContractor($idOrder);
    }

    // ---- customer page (customer-contractor-panel) -----------------------------------------

    /**
     * Test seam: replaces the service built from the module configuration.
     *
     * @param IfirmaCustomerContractorService|null $service
     */
    public function setCustomerService(?IfirmaCustomerContractorService $service = null)
    {
        $this->customers->setCustomerService($service);
    }

    /**
     * Looks the shown identifier up in iFirma once.
     *
     * @param int $idCustomer
     * @return array
     */
    public function customerCheck($idCustomer)
    {
        return $this->customers->customerCheck($idCustomer);
    }

    /**
     * @param array $criteria firstname, lastname, company, nip, email, phone
     * @return array
     */
    public function customerSearch(array $criteria)
    {
        return $this->customers->customerSearch($criteria);
    }

    /**
     * @param int    $idCustomer
     * @param string $identifier
     * @return array
     */
    public function customerLink($idCustomer, $identifier)
    {
        return $this->customers->customerLink($idCustomer, $identifier);
    }

    /**
     * @param int $idCustomer
     * @return array
     */
    public function customerUnlink($idCustomer)
    {
        return $this->customers->customerUnlink($idCustomer);
    }

    /**
     * Registers the customer through the newest order, using the order registration path.
     *
     * @param int $idCustomer
     * @return array data: contractor (as registerContractor), link_state
     */
    public function registerCustomer($idCustomer)
    {
        return $this->customers->registerCustomer($idCustomer);
    }

    /**
     * @param int $idCustomer
     * @return array
     */
    public function customerCountRemote($idCustomer)
    {
        return $this->customers->customerCountRemote($idCustomer);
    }

    /**
     * @param int        $idRequest
     * @param int        $idEmployee
     * @param array|null $edited    edited field values, null to approve as requested
     * @param string     $note
     * @return array
     */
    public function approveRequest($idRequest, $idEmployee, ?array $edited = null, $note = '')
    {
        return $this->dataRequests->approveRequest($idRequest, $idEmployee, $edited, $note);
    }

    /**
     * @param int    $idRequest
     * @param int    $idEmployee
     * @param string $reason
     * @return array
     */
    public function rejectRequest($idRequest, $idEmployee, $reason)
    {
        return $this->dataRequests->rejectRequest($idRequest, $idEmployee, $reason);
    }

    /**
     * @param IfirmaReceipt $receipt
     * @return array
     */
    public function receiptToArray(IfirmaReceipt $receipt)
    {
        return $this->receipts->receiptToArray($receipt);
    }
}
