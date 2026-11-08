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

/**
 * Customer-facing view-model for the accounting-document block.
 *
 * Contract (consumed by views/templates/hook/order_detail.tpl as $ifirma_view and
 * customer_account.tpl as $ifirma_account):
 *
 *  - resolveState($receipt, $pendingRequest): exactly one of the STATE_* constants.
 *  - forOrder($order, $receipt, $pendingRequest, $context): view-model array with keys
 *    state, state_label, can_request_change, request_url, cancel_url, pending_request,
 *    auto_issue_enabled, trigger_state_name, document (issued/missing only), contact_shop.
 *    Context keys: data_request_enabled, has_other_pending, auto_issue_enabled,
 *    trigger_state_name, tolerance.
 *  - orderOptions(): the context of forOrder() for the order page, read from the configuration.
 *  - forAccount($receiptRows, $pendingCount): receipts, pending_count, visible.
 *
 * The view-model NEVER carries last_error, sync_error, duplicate candidates or payloads.
 * Pure, except orderOptions(), which reads the configuration, the language of the context and the order
 * state: the other methods only read the objects passed in.
 */
class IfirmaCustomerView
{
    const STATE_NOT_ISSUED = 'not_issued';
    const STATE_HELD = 'held';
    const STATE_VERIFYING = 'verifying';
    const STATE_ISSUED = 'issued';
    const STATE_MISSING = 'missing';

    /** @var Module */
    protected $module;

    /** @var Link */
    protected $link;

    /**
     * @param Module $module
     * @param Link   $link
     */
    public function __construct($module, $link)
    {
        $this->module = $module;
        $this->link = $link;
    }

    /**
     * @param IfirmaReceipt|null     $receipt
     * @param IfirmaDataRequest|null $pendingRequest
     * @return string one STATE_* constant
     */
    public function resolveState($receipt, $pendingRequest)
    {
        if ($pendingRequest && $this->prop($pendingRequest, 'status') === IfirmaDataRequest::STATUS_PENDING) {
            return self::STATE_HELD;
        }
        if (!$receipt) {
            return self::STATE_NOT_ISSUED;
        }
        $status = (string) $this->prop($receipt, 'status');
        if ($status === IfirmaReceipt::STATUS_HELD) {
            return self::STATE_NOT_ISSUED;
        }
        if ($this->prop($receipt, 'ifirma_id') && $status !== IfirmaReceipt::STATUS_ERROR) {
            return $this->prop($receipt, 'remote_missing') ? self::STATE_MISSING : self::STATE_ISSUED;
        }
        if ($status === IfirmaReceipt::STATUS_UNKNOWN || $status === IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED) {
            return self::STATE_VERIFYING;
        }

        return self::STATE_NOT_ISSUED;
    }

    /**
     * @param Order                  $order
     * @param IfirmaReceipt|null     $receipt
     * @param IfirmaDataRequest|null $pendingRequest
     * @param array                  $context
     * @return array
     */
    public function forOrder($order, $receipt, $pendingRequest, array $context = array())
    {
        $state = $this->resolveState($receipt, $pendingRequest);
        $params = array('id_order' => (int) $this->prop($order, 'id'));
        $autoIssue = !empty($context['auto_issue_enabled']);

        $view = array(
            'state' => $state,
            'state_label' => $this->stateLabel($state),
            'can_request_change' => !empty($context['data_request_enabled'])
                && $state === self::STATE_NOT_ISSUED
                && empty($context['has_other_pending']),
            'request_url' => $this->link->getModuleLink('ifirma', 'datarequest', $params, true),
            'cancel_url' => $this->link->getModuleLink('ifirma', 'datarequest', $params + array('cancel' => 1), true),
            'pending_request' => null,
            'auto_issue_enabled' => $autoIssue,
            'trigger_state_name' => ($autoIssue && !empty($context['trigger_state_name']))
                ? (string) $context['trigger_state_name'] : '',
            'contact_shop' => $state === self::STATE_ISSUED || $state === self::STATE_MISSING,
        );

        if ($state === self::STATE_HELD) {
            $requestId = $this->prop($pendingRequest, 'id_ifirma_data_request');
            if (!$requestId) {
                $requestId = $this->prop($pendingRequest, 'id');
            }
            $view['pending_request'] = array(
                'id' => (int) $requestId,
                'date_add' => (string) $this->prop($pendingRequest, 'date_add'),
            );
        }
        if ($state === self::STATE_ISSUED || $state === self::STATE_MISSING) {
            $view['document'] = $this->buildDocument($order, $receipt, $state, $context);
        }

        return $view;
    }

    /**
     * The block of the guest tracking page: the view of forOrder() with the document reduced to its number
     * (document = number, summary_only; the page is open to anyone who knows the reference and the e-mail),
     * and with the change and cancel links pointing at the guest page of the order.
     *
     * @param Order                  $order
     * @param IfirmaReceipt|null     $receipt
     * @param IfirmaDataRequest|null $pendingRequest
     * @param array                  $context
     * @return array
     */
    public function forGuestOrder($order, $receipt, $pendingRequest, array $context = array())
    {
        $view = $this->forOrder($order, $receipt, $pendingRequest, $context);
        $guestUrl = $this->link->getModuleLink(
            'ifirma',
            'guestrequest',
            array('reference' => (string) $this->prop($order, 'reference')),
            true
        );
        $view['request_url'] = $guestUrl;
        $view['cancel_url'] = $guestUrl;
        if (isset($view['document'])) {
            // the state and the number, nothing else: no link, KSeF number, dates, payment or delivery details
            $view['document'] = array('number' => $view['document']['number'], 'summary_only' => true);
        }

        return $view;
    }

    /**
     * The context of forOrder() for the order page of the customer: the data request and auto issue
     * switches, the name of the state that triggers the issuing, and the amount tolerance, as the
     * configuration holds them.
     *
     * @return array data_request_enabled (bool), auto_issue_enabled (bool), trigger_state_name (string,
     *               '' without a trigger state), tolerance (the configured value, false when none),
     *               has_other_pending (false)
     */
    public function orderOptions()
    {
        $triggerState = (int) Configuration::get('IFIRMA_AUTO_CREATE_STATE');

        return array(
            'data_request_enabled' => (bool) Configuration::get('IFIRMA_ALLOW_DATA_REQUEST'),
            'auto_issue_enabled' => $triggerState > 0,
            'trigger_state_name' => $this->orderStateName($triggerState),
            'tolerance' => Configuration::get('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE'),
            'has_other_pending' => false,
        );
    }

    /**
     * @param array $receiptRows rows from IfirmaReceipt::getByCustomer()
     * @param int   $pendingCount
     * @return array
     */
    public function forAccount(array $receiptRows, $pendingCount)
    {
        $receipts = array();
        foreach ($receiptRows as $row) {
            if (empty($row['ifirma_id']) || (isset($row['status']) && $row['status'] === IfirmaReceipt::STATUS_ERROR)
                || !empty($row['remote_missing'])) {
                continue;
            }
            $status = isset($row['status']) ? (string) $row['status'] : '';
            $receipts[] = array(
                'number' => $this->numberOf($row),
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'download_url' => $this->downloadUrl(isset($row['security_token']) ? $row['security_token'] : ''),
                'issue_date' => $this->issueDateOf($row),
                'is_external' => isset($row['source']) && $row['source'] === 'external',
            );
        }
        $pendingCount = max(0, (int) $pendingCount);

        return array(
            'receipts' => $receipts,
            'pending_count' => $pendingCount,
            'visible' => count($receipts) > 0 || $pendingCount > 0,
        );
    }

    /**
     * @param string $state
     * @return string translated label
     */
    public function stateLabel($state)
    {
        switch ($state) {
            case self::STATE_HELD:
                return $this->module->l('On hold pending your request', 'ifirmacustomerview');
            case self::STATE_VERIFYING:
                return $this->module->l('Being verified', 'ifirmacustomerview');
            case self::STATE_ISSUED:
                return $this->module->l('Issued', 'ifirmacustomerview');
            case self::STATE_MISSING:
                return $this->module->l('No longer available', 'ifirmacustomerview');
            default:
                return $this->module->l('Not issued yet', 'ifirmacustomerview');
        }
    }

    /**
     * @param string $status receipt status
     * @return string
     */
    protected function statusLabel($status)
    {
        if ($status === IfirmaReceipt::STATUS_PAID) {
            return $this->module->l('Paid', 'ifirmacustomerview');
        }

        return $this->module->l('Issued', 'ifirmacustomerview');
    }

    /**
     * @param int $idState
     * @return string name of the order state in the language of the shop, '' for no state
     */
    protected function orderStateName($idState)
    {
        if ($idState <= 0) {
            return '';
        }
        // the context the module holds: Module::$context is protected, the view cannot read it from the module
        $idLang = (int) Context::getContext()->language->id;
        $state = new OrderState((int) $idState, $idLang);
        $name = $state->name;
        if (is_array($name)) {
            $name = isset($name[$idLang]) ? $name[$idLang] : reset($name);
        }

        return (string) $name;
    }

    /**
     * @param Order         $order
     * @param IfirmaReceipt $receipt
     * @param string        $state
     * @param array         $context
     * @return array
     */
    protected function buildDocument($order, $receipt, $state, array $context)
    {
        $row = array(
            'ifirma_number' => $this->prop($receipt, 'ifirma_number'),
            'ifirma_id' => $this->prop($receipt, 'ifirma_id'),
            'issue_date' => $this->prop($receipt, 'issue_date'),
            'date_add' => $this->prop($receipt, 'date_add'),
        );
        $remotePaid = $this->prop($receipt, 'remote_paid_amount');
        if ($remotePaid !== null && $remotePaid !== '') {
            $tolerance = isset($context['tolerance']) ? (float) $context['tolerance'] : 0.01;
            $paid = (float) $remotePaid >= (float) $this->prop($order, 'total_paid_tax_incl') - $tolerance;
        } else {
            $paid = $this->prop($receipt, 'status') === IfirmaReceipt::STATUS_PAID;
        }
        $sent = $this->prop($receipt, 'delivery_status') === 'sent' || (bool) $this->prop($receipt, 'remote_sent');

        return array(
            'number' => $this->numberOf($row),
            'number_known' => (bool) $this->prop($receipt, 'ifirma_number'),
            'ifirma_id' => (string) $this->prop($receipt, 'ifirma_id'),
            'issue_date' => $this->issueDateOf($row),
            'paid' => (bool) $paid,
            'sent' => $sent,
            'sent_at' => (string) $this->prop($receipt, 'delivered_at'),
            'download_url' => $state === self::STATE_MISSING
                ? null : $this->downloadUrl($this->prop($receipt, 'security_token')),
            'is_external' => $this->prop($receipt, 'source') === 'external',
            'ksef_number' => trim((string) $this->prop($receipt, 'ksef_number')),
        );
    }

    /**
     * @param array $row
     * @return string number or "Document #id"
     */
    protected function numberOf(array $row)
    {
        if (!empty($row['ifirma_number'])) {
            return (string) $row['ifirma_number'];
        }

        return $this->module->l('Document', 'ifirmacustomerview') . ' #' . (string) $row['ifirma_id'];
    }

    /**
     * @param array $row
     * @return string
     */
    protected function issueDateOf(array $row)
    {
        if (!empty($row['issue_date'])) {
            return (string) $row['issue_date'];
        }

        return isset($row['date_add']) ? substr((string) $row['date_add'], 0, 10) : '';
    }

    /**
     * @param string $token
     * @return string
     */
    protected function downloadUrl($token)
    {
        // no token (the secure random generator was unavailable when the row was written): no link to offer
        if ((string) $token === '') {
            return null;
        }

        return $this->link->getModuleLink('ifirma', 'receipt', array('token' => (string) $token), true);
    }

    /**
     * @param object $object
     * @param string $name
     * @return mixed|null
     */
    protected function prop($object, $name)
    {
        return isset($object->$name) ? $object->$name : null;
    }
}
