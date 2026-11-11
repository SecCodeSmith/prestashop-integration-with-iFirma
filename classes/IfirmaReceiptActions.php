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

require_once dirname(__FILE__) . '/IfirmaActionSupport.php';
require_once dirname(__FILE__) . '/IfirmaRateDecision.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaReceiptService.php';
require_once dirname(__FILE__) . '/IfirmaIssuancePipeline.php';
require_once dirname(__FILE__) . '/IfirmaDuplicateGuard.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryService.php';
require_once dirname(__FILE__) . '/IfirmaKsefService.php';
require_once dirname(__FILE__) . '/IfirmaStatusSyncService.php';

/**
 * Admin actions on the iFirma document of an order: issue it (also although the duplicate guard found
 * suspects), adopt a duplicate candidate, register a payment, send it again, submit it to KSeF and refresh
 * it from iFirma.
 *
 * Reached through IfirmaAdminActions.
 */
class IfirmaReceiptActions extends IfirmaActionSupport
{
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
            $summary = $sync->syncReceipt($receipt);
            $receipt = new IfirmaReceipt((int) $receipt->id);
            $data = array('receipt' => $this->receiptToArray($receipt));
            if (!empty($summary['rate_limited'])) {
                return $this->result(
                    false,
                    $this->refreshRefusedMessage(isset($summary['rate_limit_window']) ? $summary['rate_limit_window'] : ''),
                    $data
                );
            }
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
     * @param string $window one of the IfirmaRateDecision::WINDOW_* constants
     * @return string what the employee is told when the request limit refused the refresh
     */
    private function refreshRefusedMessage($window)
    {
        if ($window === IfirmaRateDecision::WINDOW_MINUTE) {
            return $this->module->l('The status was not refreshed: the minute request limit is reached. Try again in a moment.', 'ifirmaadminactions');
        }
        if ($window === IfirmaRateDecision::WINDOW_DAY) {
            return $this->module->l('The status was not refreshed: the day request limit is reached. Try again later.', 'ifirmaadminactions');
        }
        if ($window === IfirmaRateDecision::WINDOW_BACKOFF) {
            return $this->module->l('The status was not refreshed: iFirma asked the module to wait before the next request. Try again shortly.', 'ifirmaadminactions');
        }

        return $this->module->l('The status was not refreshed: iFirma reported that its request limit was exceeded. Try again shortly.', 'ifirmaadminactions');
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
