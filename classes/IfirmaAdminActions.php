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
require_once dirname(__FILE__) . '/IfirmaContractorService.php';
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

        try {
            $pipeline = IfirmaIssuancePipeline::build($this->module);
            $options = array(
                'trigger' => $bypass ? IfirmaIssuancePipeline::TRIGGER_BYPASS : IfirmaIssuancePipeline::TRIGGER_MANUAL,
                'id_employee' => (int) $idEmployee,
            );
            if ($bypass) {
                $options['bypass_guard'] = true;
            }
            $r = $pipeline->run($order, $options);
            $data = array(
                'outcome' => $r->outcome,
                'stopped_at' => $r->stoppedAt,
                'receipt' => $r->receipt instanceof IfirmaReceipt ? $this->receiptToArray($r->receipt) : array(),
            );

            return $this->result($r->ok, $pipeline->describe($r), $data);
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
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
        try {
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
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param int        $idOrder
     * @param float|null $amount
     * @return array
     */
    public function updateStatus($idOrder, $amount = null)
    {
        try {
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
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param int $idOrder
     * @return array
     */
    public function resend($idOrder)
    {
        try {
            $receipt = IfirmaReceipt::getByOrder((int) $idOrder);
            $order = $this->loadOrder($idOrder);
            if (!$receipt || !$receipt->isIssued() || !$order) {
                return $this->result(false, $this->module->l('No issued iFirma document for this order.', 'ifirmaadminactions'));
            }
            $delivery = IfirmaDeliveryService::build($this->module);
            $d = $delivery->resend($receipt, $order);
            $receipt = new IfirmaReceipt((int) $receipt->id);
            $message = isset($d['message']) ? (string) $d['message'] : '';
            if (!empty($d['ok'])) {
                $message = $this->module->l('Document sent.', 'ifirmaadminactions') . ($message !== '' ? ' ' . $message : '');
            } elseif ($message === '') {
                $message = $this->module->l('The document could not be sent.', 'ifirmaadminactions');
            }

            return $this->result(!empty($d['ok']), $message, array(
                'receipt' => $this->receiptToArray($receipt),
                'delivery' => $delivery->describeDelivery($receipt),
            ));
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * Submits the issued document to KSeF regardless of the configured mode (manual button).
     *
     * @param int $idOrder
     * @return array
     */
    public function sendToKsef($idOrder)
    {
        try {
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
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param int $idOrder
     * @return array
     */
    public function refreshStatus($idOrder)
    {
        try {
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
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param string $matchedBy
     * @return string
     */
    private function matchedByLabel($matchedBy)
    {
        switch ($matchedBy) {
            case 'nip':
            case 'import-nip':
                return $this->module->l('NIP', 'ifirmaadminactions');
            case 'email':
            case 'import-email':
                return $this->module->l('e-mail', 'ifirmaadminactions');
            case 'name':
            case 'import-name':
                return $this->module->l('name', 'ifirmaadminactions');
            case 'document':
                return $this->module->l('issued document', 'ifirmaadminactions');
            default:
                return $this->module->l('manual link', 'ifirmaadminactions');
        }
    }

    /**
     * Registers the order customer as an iFirma contractor without issuing a document.
     *
     * @param int $idOrder
     * @return array
     */
    public function registerContractor($idOrder)
    {
        try {
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
            $identifier = $result['identifier'] !== null ? (string) $result['identifier'] : (string) $contractor['Identyfikator'];
            $matchedBy = isset($result['matched_by']) ? (string) $result['matched_by'] : '';
            $data = array(
                'identifier' => $identifier,
                'action' => $result['action'],
                'message' => $result['message'],
                'matched_by' => $matchedBy,
            );
            $linked = in_array($matchedBy, array('nip', 'name', 'email', 'manual', 'document', 'import-nip', 'import-email', 'import-name'), true)
                && $identifier !== (string) $contractor['Identyfikator'];
            if ($linked) {
                $matchedLabel = $this->matchedByLabel($matchedBy);
                $linkNote = sprintf(
                    $this->module->l('linked to existing contractor %s (matched by %s)', 'ifirmaadminactions'),
                    $identifier,
                    $matchedLabel
                );
            }

            switch ($result['action']) {
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
                            $result['message']
                        ),
                        $data
                    );
            }

            if ($linked) {
                $message = ucfirst($linkNote) . '. ' . $message;
            }

            return $this->result(true, $message, $data);
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
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
        try {
            $request = IfirmaDataRequest::getById((int) $idRequest);
            if (!$request) {
                return $this->result(false, $this->module->l('The request was not found.', 'ifirmaadminactions'));
            }
            $service = IfirmaDataRequestService::build($this->module);
            $service->approve($request, (int) $idEmployee, $edited, $note);

            return $this->resolved($request, $this->module->l('Request approved.', 'ifirmaadminactions'));
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    /**
     * @param int    $idRequest
     * @param int    $idEmployee
     * @param string $reason
     * @return array
     */
    public function rejectRequest($idRequest, $idEmployee, $reason)
    {
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

            return $this->failure($e);
        } catch (Exception $e) {
            return $this->failure($e);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
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
