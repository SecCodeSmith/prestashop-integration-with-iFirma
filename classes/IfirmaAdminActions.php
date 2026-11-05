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

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaDataRequest.php';
require_once dirname(__FILE__) . '/IfirmaMatchLabels.php';
require_once dirname(__FILE__) . '/IfirmaContractorService.php';
require_once dirname(__FILE__) . '/IfirmaCustomerContractorService.php';
require_once dirname(__FILE__) . '/IfirmaReceiptService.php';
require_once dirname(__FILE__) . '/IfirmaIssuancePipeline.php';
require_once dirname(__FILE__) . '/IfirmaDuplicateGuard.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryService.php';
require_once dirname(__FILE__) . '/IfirmaKsefService.php';
require_once dirname(__FILE__) . '/IfirmaStatusSyncService.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestService.php';

/**
 * Admin action logic behind the AdminIfirma ajax endpoints.
 *
 * Every public action returns array('success' => bool, 'message' => string, 'data' => array)
 * and never throws, so the controller only has to translate the result into JSON.
 */
class IfirmaAdminActions
{
    /** @var Module */
    private $module;

    /** @var IfirmaCustomerContractorService|null */
    private $customerService;

    /**
     * @param Module $module
     */
    public function __construct(Module $module)
    {
        $this->module = $module;
    }

    /**
     * @param bool   $success
     * @param string $message
     * @param array  $data
     * @return array
     */
    private function result($success, $message, array $data = array())
    {
        return array('success' => (bool) $success, 'message' => (string) $message, 'data' => $data);
    }

    /**
     * @param Exception|Throwable $e
     * @return array
     */
    private function failure($e)
    {
        $data = array();
        if ($e instanceof IfirmaDataRequestException) {
            $data = array('errors' => $e->errors, 'code' => $e->code_name);
        }

        return $this->result(false, $e->getMessage(), $data);
    }

    /**
     * Runs the body of an action. Whatever it throws, an Exception or an Error, becomes the failure
     * envelope instead of escaping to the controller.
     *
     * @param callable $action
     * @return array
     */
    private function guard(callable $action)
    {
        try {
            return $action();
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param int $idOrder
     * @return Order|null
     */
    private function loadOrder($idOrder)
    {
        $order = new Order((int) $idOrder);

        return Validate::isLoadedObject($order) ? $order : null;
    }

    /**
     * @param int  $idOrder
     * @param int  $idEmployee
     * @param bool $bypass
     * @return array
     */
    private function runPipeline($idOrder, $idEmployee, $bypass)
    {
        $order = $this->loadOrder($idOrder);
        if (!$order) {
            return $this->result(false, $this->module->l('Invalid order.', 'ifirmaadminactions'));
        }
        if (!$this->module->isConfigured()) {
            return $this->result(false, $this->module->l('The iFirma module is not configured yet.', 'ifirmaadminactions'));
        }

        return $this->guard(function () use ($order, $idEmployee, $bypass) {
            $pipeline = IfirmaIssuancePipeline::build($this->module);
            $options = array(
                'trigger' => $bypass ? IfirmaIssuancePipeline::TRIGGER_BYPASS : IfirmaIssuancePipeline::TRIGGER_MANUAL,
                'id_employee' => (int) $idEmployee,
            );
            if ($bypass) {
                $options['bypass_guard'] = true;
            }
            $r = $pipeline->run($order, $options);
            $data = $r->toArray();
            $data['receipt'] = $r->receipt instanceof IfirmaReceipt ? $this->receiptToArray($r->receipt) : array();

            return $this->result($r->ok, $pipeline->describe($r), $data);
        });
    }

    /**
     * @param int $idOrder
     * @param int $idEmployee
     * @return array
     */
    public function createReceipt($idOrder, $idEmployee)
    {
        return $this->runPipeline($idOrder, $idEmployee, false);
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
        return $this->runPipeline($idOrder, $idEmployee, true);
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
        return $this->guard(function () use ($idOrder, $fakturaId, $idEmployee) {
            $order = $this->loadOrder($idOrder);
            if (!$order) {
                return $this->result(false, $this->module->l('Invalid order.', 'ifirmaadminactions'));
            }
            $receipt = IfirmaReceipt::getByOrder((int) $idOrder);
            if (!$receipt || trim((string) $fakturaId) === '') {
                return $this->result(false, $this->module->l('There is no candidate to link for this order.', 'ifirmaadminactions'));
            }
            $guard = IfirmaDuplicateGuard::build($this->module);
            $linked = $guard->linkCandidate($order, $receipt, $fakturaId, (int) $idEmployee);

            return $this->result(
                true,
                sprintf(
                    $this->module->l('Document %s linked to the order.', 'ifirmaadminactions'),
                    $linked->ifirma_number ? $linked->ifirma_number : $linked->ifirma_id
                ),
                array('receipt' => $this->receiptToArray($linked))
            );
        });
    }

    /**
     * @param int        $idOrder
     * @param float|null $amount
     * @return array
     */
    public function updateStatus($idOrder, $amount = null)
    {
        return $this->guard(function () use ($idOrder, $amount) {
            $receipt = IfirmaReceipt::getByOrder((int) $idOrder);
            if (!$receipt || !$receipt->isIssued()) {
                return $this->result(false, $this->module->l('No issued iFirma document for this order.', 'ifirmaadminactions'));
            }
            $service = IfirmaReceiptService::build($this->module);
            $receipt = $service->updateStatusPaid($receipt, $amount);

            return $this->result(
                true,
                $this->module->l('Payment registered in iFirma.', 'ifirmaadminactions'),
                array('receipt' => $this->receiptToArray($receipt))
            );
        });
    }

    /**
     * @param int $idOrder
     * @return array
     */
    public function resend($idOrder)
    {
        return $this->guard(function () use ($idOrder) {
            $receipt = IfirmaReceipt::getByOrder((int) $idOrder);
            $order = $this->loadOrder($idOrder);
            if (!$receipt || !$receipt->isIssued() || !$order) {
                return $this->result(false, $this->module->l('No issued iFirma document for this order.', 'ifirmaadminactions'));
            }
            $delivery = IfirmaDeliveryService::build($this->module);
            $d = $delivery->resend($receipt, $order);
            $receipt = new IfirmaReceipt((int) $receipt->id);
            $message = $d->getMessage();
            if ($d->isSent()) {
                $message = $this->module->l('Document sent.', 'ifirmaadminactions') . ($message !== '' ? ' ' . $message : '');
            } elseif ($message === '') {
                $message = $this->module->l('The document could not be sent.', 'ifirmaadminactions');
            }

            return $this->result($d->isSent(), $message, array(
                'receipt' => $this->receiptToArray($receipt),
                'delivery' => $delivery->describeDelivery($receipt),
            ));
        });
    }

    /**
     * Submits the issued document to KSeF regardless of the configured mode (manual button).
     *
     * @param int $idOrder
     * @return array
     */
    public function sendToKsef($idOrder)
    {
        return $this->guard(function () use ($idOrder) {
            $receipt = IfirmaReceipt::getByOrder((int) $idOrder);
            $order = $this->loadOrder($idOrder);
            if (!$receipt || !$receipt->isIssued() || !$order) {
                return $this->result(false, $this->module->l('No issued iFirma document for this order.', 'ifirmaadminactions'));
            }
            $ksef = IfirmaKsefService::build($this->module);
            $k = $ksef->submit($receipt, $order, true);
            $receipt = new IfirmaReceipt((int) $receipt->id);
            $message = isset($k['message']) ? (string) $k['message'] : '';
            if (!empty($k['ok'])) {
                $message = $this->module->l('Document sent to KSeF.', 'ifirmaadminactions') . ($message !== '' ? ' ' . $message : '');
            } elseif ($message === '') {
                $message = $this->module->l('The document could not be sent to KSeF.', 'ifirmaadminactions');
            }

            return $this->result(!empty($k['ok']), $message, array(
                'receipt' => $this->receiptToArray($receipt),
                'ksef' => $ksef->describe($receipt, $order),
            ));
        });
    }

    /**
     * @param int $idOrder
     * @return array
     */
    public function refreshStatus($idOrder)
    {
        return $this->guard(function () use ($idOrder) {
            $receipt = IfirmaReceipt::getByOrder((int) $idOrder);
            if (!$receipt || !$receipt->isIssued()) {
                return $this->result(false, $this->module->l('No issued iFirma document for this order.', 'ifirmaadminactions'));
            }
            $sync = IfirmaStatusSyncService::build($this->module);
            $sync->syncReceipt($receipt);
            $receipt = new IfirmaReceipt((int) $receipt->id);
            $data = array('receipt' => $this->receiptToArray($receipt));
            if ((string) $receipt->sync_error !== '') {
                return $this->result(
                    false,
                    sprintf($this->module->l('Refresh from iFirma failed: %s', 'ifirmaadminactions'), $receipt->sync_error),
                    $data
                );
            }

            return $this->result(true, $this->module->l('Status refreshed from iFirma.', 'ifirmaadminactions'), $data);
        });
    }

    /**
     * Registers the order customer as an iFirma contractor without issuing a document.
     *
     * @param int $idOrder
     * @return array
     */
    public function registerContractor($idOrder)
    {
        return $this->guard(function () use ($idOrder) {
            $order = $this->loadOrder($idOrder);
            if (!$order) {
                return $this->result(false, $this->module->l('Invalid order.', 'ifirmaadminactions'));
            }
            if (!IfirmaContractorService::build($this->module)->isEnabled()) {
                return $this->result(
                    false,
                    $this->module->l('Contractor registration is disabled in the module settings.', 'ifirmaadminactions')
                );
            }

            $contractor = IfirmaReceiptService::build($this->module)->buildContractor(
                $order,
                IfirmaDataRequestService::build($this->module)->getOverrideForOrder($idOrder)
            );
            $receiptService = IfirmaReceiptService::build($this->module);
            $override = IfirmaDataRequestService::build($this->module)->getOverrideForOrder($idOrder);
            $result = IfirmaContractorService::build($this->module)->ensure(
                $contractor,
                (int) $order->id_customer,
                $receiptService->contractorHints($order, $contractor, $override)
            );
            $identifier = $result->getIdentifier() !== null ? (string) $result->getIdentifier() : (string) $contractor['Identyfikator'];
            $matchedBy = $result->getMatchedBy();
            $data = array(
                'identifier' => $identifier,
                'action' => $result->getAction(),
                'message' => $result->getMessage(),
                'matched_by' => $matchedBy,
                'note' => $result->getNote(),
                'assigned_identifier' => $result->getAssignedId(),
            );
            $matchLabels = $this->module->matchLabels();
            $linked = in_array($matchedBy, $matchLabels->linkCodes(), true)
                && $identifier !== (string) $contractor['Identyfikator'];
            if ($linked) {
                $matchedLabel = $matchLabels->label($matchedBy);
                $linkNote = sprintf(
                    $this->module->l('linked to existing contractor %s (matched by %s)', 'ifirmaadminactions'),
                    $identifier,
                    $matchedLabel
                );
            }

            switch ($result->getAction()) {
                case IfirmaContractorService::ACTION_CREATED:
                    $message = sprintf($this->module->l('Contractor %s created in iFirma.', 'ifirmaadminactions'), $identifier);
                    break;
                case IfirmaContractorService::ACTION_UPDATED:
                    $message = sprintf($this->module->l('Contractor %s updated in iFirma.', 'ifirmaadminactions'), $identifier);
                    break;
                case IfirmaContractorService::ACTION_KEPT:
                    $message = sprintf(
                        $this->module->l('Contractor %s already exists in iFirma and is up to date.', 'ifirmaadminactions'),
                        $identifier
                    );
                    break;
                default:
                    return $this->result(
                        false,
                        sprintf(
                            $this->module->l('Contractor %s could not be registered: %s', 'ifirmaadminactions'),
                            $identifier,
                            $result->getMessage()
                        ),
                        $data
                    );
            }

            if ($linked) {
                $message = ucfirst($linkNote) . '. ' . $message;
            }

            return $this->result(true, $message, $data);
        });
    }

    // ---- customer page (customer-contractor-panel) -----------------------------------------

    /**
     * Test seam: replaces the service built from the module configuration.
     *
     * @param IfirmaCustomerContractorService|null $service
     */
    public function setCustomerService(IfirmaCustomerContractorService $service = null)
    {
        $this->customerService = $service;
    }

    /**
     * @return IfirmaCustomerContractorService
     */
    private function customerService()
    {
        if ($this->customerService === null) {
            $this->customerService = IfirmaCustomerContractorService::build($this->module);
        }

        return $this->customerService;
    }

    /**
     * @param int $idCustomer
     * @return Customer|null
     */
    private function loadCustomer($idCustomer)
    {
        if ((int) $idCustomer <= 0) {
            return null;
        }
        $customer = new Customer((int) $idCustomer);

        return Validate::isLoadedObject($customer) ? $customer : null;
    }

    /**
     * @return array
     */
    private function invalidCustomer()
    {
        return $this->result(false, $this->module->l('Invalid customer.', 'ifirmaadminactions'));
    }

    /**
     * Looks the shown identifier up in iFirma once.
     *
     * @param int $idCustomer
     * @return array
     */
    public function customerCheck($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $check = $this->customerService()->check((int) $idCustomer);
            switch ($check['state']) {
                case IfirmaCustomerContractorService::CHECK_REGISTERED:
                    $check['label'] = sprintf($this->module->l('Registered in iFirma as %s', 'ifirmaadminactions'), $check['identifier']);
                    break;
                case IfirmaCustomerContractorService::CHECK_MISSING:
                    $check['label'] = $this->module->l('Not found in iFirma', 'ifirmaadminactions');
                    break;
                default:
                    $check['label'] = sprintf($this->module->l('Could not check: %s', 'ifirmaadminactions'), $check['message']);
            }

            return $this->result(
                $check['state'] !== IfirmaCustomerContractorService::CHECK_ERROR,
                $check['label'],
                array('check' => $check)
            );
        });
    }

    /**
     * @param array $criteria firstname, lastname, company, nip, email, phone
     * @return array
     */
    public function customerSearch(array $criteria)
    {
        return $this->guard(function () use ($criteria) {
            $search = $this->customerService()->searchCandidates($criteria);
            if (!empty($search['refused'])) {
                return $this->result(
                    false,
                    $this->module->l('iFirma searches contractors by name, company or NIP. E-mail and phone are only used to confirm the results; fill in a name, company or NIP.', 'ifirmaadminactions'),
                    array('search' => $search)
                );
            }

            $count = count($search['rows']);
            $message = $count > 0
                ? sprintf($this->module->l('Found %d contractor(s).', 'ifirmaadminactions'), $count)
                : $this->module->l('No contractor was found in iFirma.', 'ifirmaadminactions');
            if (!empty($search['errors'])) {
                $parts = array();
                foreach ($search['errors'] as $phrase => $error) {
                    $parts[] = $phrase . ': ' . $error;
                }
                $message .= ' ' . sprintf($this->module->l('Search failed for: %s', 'ifirmaadminactions'), implode('; ', $parts));
            }

            return $this->result(true, $message, array('search' => $search));
        });
    }

    /**
     * @param int    $idCustomer
     * @param string $identifier
     * @return array
     */
    public function customerLink($idCustomer, $identifier)
    {
        return $this->guard(function () use ($idCustomer, $identifier) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $service = $this->customerService();
            $link = $service->link((int) $idCustomer, $identifier);
            $data = array('link_state' => $service->linkState((int) $idCustomer));
            $shown = $link['identifier'];

            switch ($link['code']) {
                case IfirmaCustomerContractorService::LINK_LINKED:
                    return $this->result(true, sprintf($this->module->l('Customer linked to contractor %s.', 'ifirmaadminactions'), $shown), $data);
                case IfirmaCustomerContractorService::LINK_NOT_FOUND:
                    return $this->result(
                        false,
                        sprintf($this->module->l('Contractor %s was not found in iFirma; nothing was linked.', 'ifirmaadminactions'), $shown),
                        $data
                    );
                case IfirmaCustomerContractorService::LINK_ERROR:
                    return $this->result(
                        false,
                        sprintf($this->module->l('Contractor %s could not be checked: %s', 'ifirmaadminactions'), $shown, $link['message']),
                        $data
                    );
                default:
                    return $this->result(false, $this->module->l('Invalid contractor identifier.', 'ifirmaadminactions'), $data);
            }
        });
    }

    /**
     * @param int $idCustomer
     * @return array
     */
    public function customerUnlink($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $service = $this->customerService();
            $removed = $service->unlink((int) $idCustomer);
            $data = array('link_state' => $service->linkState((int) $idCustomer));

            if ($removed) {
                return $this->result(true, $this->module->l('The link was removed. Nothing was changed in iFirma.', 'ifirmaadminactions'), $data);
            }

            return $this->result(false, $this->module->l('The customer was not linked.', 'ifirmaadminactions'), $data);
        });
    }

    /**
     * Registers the customer through the newest order, using the order registration path.
     *
     * @param int $idCustomer
     * @return array data: contractor (as registerContractor), link_state
     */
    public function registerCustomer($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $orders = Order::getCustomerOrders((int) $idCustomer);
            if (empty($orders)) {
                return $this->result(false, $this->module->l('Registration needs at least one order of this customer.', 'ifirmaadminactions'));
            }
            $first = reset($orders);

            $result = $this->registerContractor((int) $first['id_order']);
            $result['data'] = array(
                'contractor' => $result['data'],
                'link_state' => $this->customerService()->linkState((int) $idCustomer),
            );

            return $result;
        });
    }

    /**
     * Alias with the naming of the other customer actions.
     *
     * @param int $idCustomer
     * @return array
     */
    public function customerRegister($idCustomer)
    {
        return $this->registerCustomer($idCustomer);
    }

    /**
     * @param int $idCustomer
     * @return array
     */
    public function customerCountRemote($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $count = $this->customerService()->remoteCount((int) $idCustomer);
            if (empty($count['ok'])) {
                return $this->result(
                    false,
                    sprintf($this->module->l('The documents could not be counted: %s', 'ifirmaadminactions'), $count['error']),
                    array('count' => $count)
                );
            }

            if (empty($count['complete']) && (string) $count['period_from'] === '') {
                // the call budget ran out before a single yearly window was listed completely
                return $this->result(
                    true,
                    sprintf(
                        $this->module->l('At least %1$d document(s) in iFirma (%2$d paid); the call limit was reached before a full year was listed.', 'ifirmaadminactions'),
                        (int) $count['total'],
                        (int) $count['paid']
                    ),
                    array('count' => $count)
                );
            }

            $format = !empty($count['complete'])
                ? $this->module->l('%1$d document(s) in iFirma (%2$d paid), %3$s – %4$s.', 'ifirmaadminactions')
                : $this->module->l('At least %1$d document(s) in iFirma (%2$d paid), %3$s – %4$s; the call limit was reached.', 'ifirmaadminactions');

            return $this->result(
                true,
                sprintf($format, (int) $count['total'], (int) $count['paid'], $count['period_from'], $count['period_to']),
                array('count' => $count)
            );
        });
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
        return $this->guard(function () use ($idRequest, $idEmployee, $edited, $note) {
            $request = IfirmaDataRequest::getById((int) $idRequest);
            if (!$request) {
                return $this->result(false, $this->module->l('The request was not found.', 'ifirmaadminactions'));
            }
            $service = IfirmaDataRequestService::build($this->module);
            $service->approve($request, (int) $idEmployee, $edited, $note);

            return $this->resolved($request, $this->module->l('Request approved.', 'ifirmaadminactions'));
        });
    }

    /**
     * @param int    $idRequest
     * @param int    $idEmployee
     * @param string $reason
     * @return array
     */
    public function rejectRequest($idRequest, $idEmployee, $reason)
    {
        return $this->guard(function () use ($idRequest, $idEmployee, $reason) {
            try {
                $request = IfirmaDataRequest::getById((int) $idRequest);
                if (!$request) {
                    return $this->result(false, $this->module->l('The request was not found.', 'ifirmaadminactions'));
                }
                $service = IfirmaDataRequestService::build($this->module);
                $service->reject($request, (int) $idEmployee, $reason);

                return $this->resolved($request, $this->module->l('Request rejected.', 'ifirmaadminactions'));
            } catch (IfirmaDataRequestException $e) {
                if ($e->code_name === 'reason_required') {
                    return $this->result(
                        false,
                        $this->module->l('A reason is required to reject the request.', 'ifirmaadminactions'),
                        array('code' => 'reason_required')
                    );
                }

                // every other refusal is the guard's to report
                throw $e;
            }
        });
    }

    /**
     * Resumes a held issuance after a request was resolved.
     *
     * @param IfirmaDataRequest $request
     * @param string            $message
     * @return array
     */
    private function resolved(IfirmaDataRequest $request, $message)
    {
        $pipeline = IfirmaIssuancePipeline::build($this->module);
        $result = $pipeline->resumeAfterRequest($request);
        $data = array('issuance_ran' => $result !== null, 'status' => $request->status, 'issued' => false);
        if ($result !== null) {
            $data['issued'] = (bool) $result->ok;
            $data['outcome'] = $result->outcome;
            $message .= ' ' . $pipeline->describe($result);
        }

        return $this->result(true, $message, $data);
    }

    /**
     * @param IfirmaReceipt $receipt
     * @return array
     */
    public function receiptToArray(IfirmaReceipt $receipt)
    {
        return array(
            'id' => (int) $receipt->id,
            'ifirma_id' => $receipt->ifirma_id,
            'ifirma_number' => $receipt->ifirma_number,
            'status' => $receipt->status,
            'source' => $receipt->source,
            'paid_amount' => (float) $receipt->paid_amount,
            'issue_date' => $receipt->issue_date,
            'remote_paid_amount' => $receipt->remote_paid_amount === null ? null : (float) $receipt->remote_paid_amount,
            'remote_sent' => (bool) $receipt->remote_sent,
            'remote_draft' => (bool) $receipt->remote_draft,
            'remote_missing' => (bool) $receipt->remote_missing,
            'ksef_status' => (string) $receipt->ksef_status,
            'ksef_number' => (string) $receipt->ksef_number,
            'ksef_sent_at' => $receipt->ksef_sent_at,
            'ksef_error' => (string) $receipt->ksef_error,
            'linked_document' => (string) $receipt->linked_document,
            'last_sync_at' => $receipt->last_sync_at,
            'sync_error' => (string) $receipt->sync_error,
            'delivery_status' => $receipt->delivery_status,
            'delivery_channel' => $receipt->delivery_channel,
            'delivered_at' => $receipt->delivered_at,
            'delivery_error' => $receipt->delivery_error,
        );
    }
}
