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
require_once dirname(__FILE__) . '/IfirmaDataRequest.php';
require_once dirname(__FILE__) . '/IfirmaContractorMap.php';
require_once dirname(__FILE__) . '/IfirmaAdminPanelPresenter.php';

/**
 * The variables of views/templates/hook/admin_order_panel.tpl for one order: the record and its state, what the
 * request stored, the duplicate candidates, the delivery and KSeF texts, the pending data change request with its
 * diff and form, the request history and the contractor link. It only reads; the words come from the presenter.
 */
class IfirmaAdminOrderPanelView
{
    /** @var IfirmaAdminPanelPresenter */
    private $presenter;

    /** @var IfirmaMatchLabels */
    private $matchLabels;

    /** @var IfirmaDataRequestService */
    private $requests;

    /** @var IfirmaDeliveryService */
    private $delivery;

    /** @var IfirmaKsefService */
    private $ksef;

    /**
     * @param IfirmaAdminPanelPresenter $presenter   the words and small decisions of the panel
     * @param IfirmaMatchLabels         $matchLabels the words for the way a customer was linked to a contractor
     * @param IfirmaDataRequestService  $requests
     * @param IfirmaDeliveryService     $delivery
     * @param IfirmaKsefService         $ksef
     */
    public function __construct(
        IfirmaAdminPanelPresenter $presenter,
        IfirmaMatchLabels $matchLabels,
        IfirmaDataRequestService $requests,
        IfirmaDeliveryService $delivery,
        IfirmaKsefService $ksef
    ) {
        $this->presenter = $presenter;
        $this->matchLabels = $matchLabels;
        $this->requests = $requests;
        $this->delivery = $delivery;
        $this->ksef = $ksef;
    }

    /**
     * @param int   $idOrder
     * @param array $module  what only the module class knows: version, build_stamp (the build installed when
     *                       the version number did not change), configured, ajax_url, token, can_issue and can_manage (the permission levels of the employee)
     * @return array the Smarty variables of the panel, in the order the module has always assigned them
     */
    public function build($idOrder, array $module)
    {
        $receipt = IfirmaReceipt::getByOrder($idOrder);
        $order = new Order($idOrder);
        $state = $this->presenter->state($receipt);
        $duplicates = $this->duplicates($receipt);
        $changes = $this->dataChangeRequests($idOrder, $order);

        return array(
            'ifirma_contractor_map' => $this->contractorMap($order),
            'ifirma_id_order' => $idOrder,
            'ifirma_receipt' => $receipt,
            'ifirma_payment_method' => $receipt ? $receipt->sentPaymentMethod() : '',
            'ifirma_contractor_note' => $receipt ? $this->presenter->contractorNote($receipt->contractorNote()) : '',
            'ifirma_request_payload' => $receipt ? (string) $receipt->request_payload : '',
            'ifirma_response_payload' => $receipt ? (string) $receipt->response_payload : '',
            'ifirma_module_version' => $module['version'],
            'ifirma_build_stamp' => $module['build_stamp'],
            'ifirma_has_receipt' => (bool) $receipt,
            'ifirma_is_issued' => (bool) ($receipt && $receipt->isIssued()),
            'ifirma_is_external' => (bool) ($receipt && $receipt->isExternal()),
            'ifirma_configured' => $module['configured'],
            'ifirma_state' => $state,
            'ifirma_state_label' => $this->presenter->stateLabel($state),
            'ifirma_can_create' => $receipt ? $receipt->canCreate() : true,
            'ifirma_can_issue_anyway' => $receipt ? $receipt->canIssueAnyway() : false,
            'ifirma_has_pending_request' => $changes['pending'],
            'ifirma_candidates' => $duplicates['candidates'],
            'ifirma_resolution' => $duplicates['resolution'],
            'ifirma_delivery_text' => $receipt ? $this->delivery->describeDelivery($receipt) : '',
            'ifirma_ksef_text' => $receipt && $receipt->isIssued() ? $this->ksef->describe($receipt, $order) : '',
            'ifirma_gross' => Validate::isLoadedObject($order) ? (float) $order->total_paid_tax_incl : 0.0,
            'ifirma_pending_request' => $changes['summary'],
            'ifirma_request_diff' => $changes['diff'],
            'ifirma_request_fields' => $changes['fields'],
            'ifirma_request_history' => $changes['history'],
            'ifirma_ajax_url' => $module['ajax_url'],
            'ifirma_token' => $module['token'],
            'ifirma_can_issue' => !empty($module['can_issue']),
            'ifirma_can_manage' => !empty($module['can_manage']),
        );
    }

    /**
     * The data change requests of the order, read in the order the panel always read them: the pending one, the
     * address it would change, and the history.
     *
     * @param int   $idOrder
     * @param Order $order
     * @return array pending (bool), summary, diff, fields and history
     */
    private function dataChangeRequests($idOrder, $order)
    {
        $pending = $this->requests->getPendingForOrder($idOrder);

        return array(
            'pending' => (bool) $pending,
            'summary' => $this->pendingSummary($pending),
            'diff' => $this->requestDiff($pending, $order),
            'fields' => $this->requestFields($pending),
            'history' => $this->history($idOrder, $order),
        );
    }

    /**
     * The duplicate candidates the record stored and how an employee settled them. A resolution counts only
     * with a list of candidates next to it.
     *
     * @param IfirmaReceipt|null $receipt
     * @return array candidates (list), resolution (array or null)
     */
    private function duplicates($receipt)
    {
        $stored = $receipt ? $receipt->getDuplicateCandidates() : array();
        if (isset($stored['candidates']) && is_array($stored['candidates'])) {
            return array(
                'candidates' => $stored['candidates'],
                'resolution' => isset($stored['resolution']) ? $stored['resolution'] : null,
            );
        }

        return array('candidates' => array(), 'resolution' => null);
    }

    /**
     * @param Order $order
     * @return array|null identifier, matched_by, matched_by_label, name; null when the order does not load, has
     *                    no customer, the customer is not linked or the link cannot be read
     */
    private function contractorMap($order)
    {
        if (!Validate::isLoadedObject($order) || (int) $order->id_customer <= 0) {
            return null;
        }
        try {
            $map = IfirmaContractorMap::getByCustomer((int) $order->id_customer);
        } catch (Exception $e) {
            return null;
        }
        if (!$map) {
            return null;
        }

        return array(
            'identifier' => (string) $map->ifirma_identifier,
            'matched_by' => (string) $map->matched_by,
            'matched_by_label' => $this->matchLabels->label((string) $map->matched_by),
            'name' => (string) $map->ifirma_name,
        );
    }

    /**
     * @param IfirmaDataRequest|null $pending
     * @return array|null the summary of the pending request with the customer's note; null without one
     */
    private function pendingSummary($pending)
    {
        if (!$pending) {
            return null;
        }
        $summary = $this->requests->summarize($pending);
        $summary['customer_note'] = (string) $pending->customer_note;

        return $summary;
    }

    /**
     * @param IfirmaDataRequest|null $pending
     * @param Order                  $order
     * @return array the fields the pending request would change on the invoice address; none when there is no
     *               pending request or the order does not load
     */
    private function requestDiff($pending, $order)
    {
        if (!$pending || !Validate::isLoadedObject($order)) {
            return array();
        }

        return $this->requests->buildDiff($pending->getRequestedData(), new Address((int) $order->id_address_invoice));
    }

    /**
     * @param IfirmaDataRequest|null $pending
     * @return array the form of the pending request; none without one
     */
    private function requestFields($pending)
    {
        if (!$pending) {
            return array();
        }

        return $this->presenter->requestFields($this->requests->fields(), $pending->getRequestedData());
    }

    /**
     * @param int $idOrder
     * @return array the summary of every request of the order, newest first, with the label of its status and the
     *               name of the employee who resolved it; a row whose request does not load is left out
     */
    private function history($idOrder, $order = null)
    {
        $history = array();
        foreach (IfirmaDataRequest::getByOrder($idOrder) as $row) {
            $request = IfirmaDataRequest::getById((int) $row['id_ifirma_data_request']);
            if (!$request) {
                continue;
            }
            $entry = $this->requests->summarize($request);
            $entry['status_label'] = $this->presenter->requestStatusLabel($request->status);
            $entry['employee'] = $this->presenter->resolverLabel($request->resolved_by, $request->status, $request->auto_mode);
            if ($request->isAutoApproved() && $order && Validate::isLoadedObject($order)) {
                // the module never changes the PrestaShop address, so the comparison stays meaningful
                $entry['accepted'] = $this->requests->buildDiff($request->getApprovedData(), new Address((int) $order->id_address_invoice));
            }
            $history[] = $entry;
        }

        return $history;
    }
}
