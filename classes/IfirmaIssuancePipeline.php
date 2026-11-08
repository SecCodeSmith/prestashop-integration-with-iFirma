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
require_once dirname(__FILE__) . '/IfirmaReceiptService.php';
require_once dirname(__FILE__) . '/IfirmaPayloadBuilder.php';
require_once dirname(__FILE__) . '/IfirmaDuplicateGuard.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryService.php';
require_once dirname(__FILE__) . '/IfirmaKsefService.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestService.php';
require_once dirname(__FILE__) . '/IfirmaNamedLock.php';

require_once dirname(__FILE__) . '/IfirmaPipelineResult.php';

/**
 * Issuance pipeline: hold check -> duplicate guard -> create -> number -> KSeF -> delivery.
 *
 * Contract (used by the status hook, the admin Create action and the data-request resume):
 *  - run(Order, options) -> IfirmaPipelineResult; never throws. Options: trigger (status|manual|resume|
 *    issue_anyway, default manual), bypass_guard (bool), id_employee (int|null), skip_delivery (bool),
 *    skip_ksef (bool).
 *  - onOrderStatusChange(idOrder, idNewState) -> IfirmaPipelineResult|null; null (nothing done) when the
 *    trigger status is 0, differs from the new state, or credentials are missing. Never throws.
 *  - shouldResume(Order) -> bool; resumeAfterRequest(IfirmaDataRequest) -> IfirmaPipelineResult|null.
 *  - describe(IfirmaPipelineResult) -> one-line admin message naming the step that stopped the run.
 *  - build(Module) shares ONE IfirmaApiClient (and so one rate counter) across all collaborators.
 *
 * Result outcomes: issued, already_issued, adopted (ok = true); held, suspected, inconclusive, unknown,
 * rate_limited, error (ok = false); disabled, not_configured are reserved for callers. A failed delivery
 * never changes ok/outcome: it sets stoppedAt = deliver and the deliver step to "stopped". A failed KSeF
 * submission does not stop the run either: the ksef step is "failed", delivery still runs, the outcome stays
 * issued and the message ends with "KSeF: <error>".
 */
class IfirmaIssuancePipeline
{
    const STEP_HOLD = 'hold';
    const STEP_GUARD = 'guard';
    const STEP_CREATE = 'create';
    const STEP_NUMBER = 'number';
    const STEP_KSEF = 'ksef';
    const STEP_DELIVER = 'deliver';

    const TRIGGER_STATUS = 'status';
    const TRIGGER_MANUAL = 'manual';
    const TRIGGER_RESUME = 'resume';
    const TRIGGER_BYPASS = 'issue_anyway';

    /** @var IfirmaReceiptService */
    private $service;

    /** @var IfirmaDuplicateGuard */
    private $guard;

    /** @var IfirmaDeliveryService */
    private $delivery;

    /** @var IfirmaKsefService */
    private $ksef;

    /** @var IfirmaDataRequestService */
    private $requests;

    /** @var Module */
    private $module;

    /** @var callable|null */
    private $clock;

    /**
     * @param IfirmaReceiptService     $service
     * @param IfirmaDuplicateGuard     $guard
     * @param IfirmaDeliveryService    $delivery
     * @param IfirmaDataRequestService $requests
     * @param Module                   $module
     * @param callable|null            $clock
     * @param IfirmaKsefService|null   $ksef
     */
    public function __construct(
        IfirmaReceiptService $service,
        IfirmaDuplicateGuard $guard,
        IfirmaDeliveryService $delivery,
        IfirmaDataRequestService $requests,
        Module $module,
        $clock = null,
        ?IfirmaKsefService $ksef = null
    ) {
        $this->service = $service;
        $this->guard = $guard;
        $this->delivery = $delivery;
        $this->requests = $requests;
        $this->module = $module;
        $this->clock = $clock;
        $this->ksef = $ksef !== null ? $ksef : IfirmaKsefService::build($module);
    }

    /**
     * @param Module $module
     * @return IfirmaIssuancePipeline
     */
    public static function build(Module $module)
    {
        $client = IfirmaApiClient::fromConfiguration();

        $service = new IfirmaReceiptService($client, $module);
        IfirmaSchema::ensureReceiptKsefColumns();

        return new self(
            $service,
            new IfirmaDuplicateGuard($client),
            new IfirmaDeliveryService($client, $service, $module),
            new IfirmaDataRequestService($module),
            $module,
            null,
            new IfirmaKsefService($client, $module)
        );
    }

    /**
     * Status hook entry point.
     *
     * @param int $idOrder
     * @param int $idNewState
     * @return IfirmaPipelineResult|null null when no run was due
     */
    public function onOrderStatusChange($idOrder, $idNewState)
    {
        try {
            $trigger = $this->triggerState();
            if ($trigger <= 0 || $trigger !== (int) $idNewState) {
                return null;
            }
            if (!$this->hasCredentials()) {
                return null;
            }

            return $this->run(new Order((int) $idOrder), array('trigger' => self::TRIGGER_STATUS));
        } catch (Throwable $e) {
            $this->log('iFirma pipeline: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * @param Order $order
     * @return bool true when auto-issue is on and the order history contains the trigger status
     */
    public function shouldResume(Order $order)
    {
        $trigger = $this->triggerState();
        if ($trigger <= 0) {
            return false;
        }
        if ((int) $order->current_state === $trigger) {
            return true;
        }
        $history = $order->getHistory((int) $order->id_lang);
        if (is_array($history)) {
            foreach ($history as $row) {
                if (isset($row['id_order_state']) && (int) $row['id_order_state'] === $trigger) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Runs the pipeline after a data-change request was resolved when the trigger status was already reached.
     *
     * @param IfirmaDataRequest $request
     * @return IfirmaPipelineResult|null
     */
    public function resumeAfterRequest(IfirmaDataRequest $request)
    {
        try {
            $order = new Order((int) $request->id_order);
            if (!Validate::isLoadedObject($order)) {
                return null;
            }
            // One resume per order at a time: the document is looked up again under the lock, so parallel
            // resumes cannot both pass "not issued yet" and issue twice.
            $lock = 'ifirma_order_' . (int) $order->id;
            if (!IfirmaNamedLock::acquire($lock, 5)) {
                $this->log('iFirma pipeline resume: the lock of order ' . (int) $order->id . ' was not granted, another run is busy');

                return null;
            }
            try {
                $existing = IfirmaReceipt::getByOrder((int) $order->id);
                if ($existing && $existing->isIssued()) {
                    return null;
                }
                if (!$this->shouldResume($order) || !$this->hasCredentials()) {
                    return null;
                }

                return $this->run($order, array('trigger' => self::TRIGGER_RESUME));
            } finally {
                IfirmaNamedLock::release($lock);
            }
        } catch (Throwable $e) {
            $this->log('iFirma pipeline resume: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * @param Order $order
     * @param array $options trigger, bypass_guard, id_employee, skip_delivery
     * @return IfirmaPipelineResult never throws
     */
    public function run(Order $order, array $options = array())
    {
        $result = new IfirmaPipelineResult();
        $result->trigger = isset($options['trigger']) ? (string) $options['trigger'] : self::TRIGGER_MANUAL;

        try {
            return $this->execute($order, $options, $result);
        } catch (Throwable $e) {
            $this->fail($order, $result, $e->getMessage());
        }

        return $result;
    }

    /**
     * @param IfirmaPipelineResult $r
     * @return string one-line admin message naming the step that stopped the run
     */
    public function describe(IfirmaPipelineResult $r)
    {
        if ($r->ok) {
            $number = $r->receipt instanceof IfirmaReceipt ? ($r->receipt->ifirma_number ?: $r->receipt->ifirma_id) : '';
            $text = $r->outcome === IfirmaPipelineResult::OUTCOME_ADOPTED
                ? $this->module->l('Existing iFirma document adopted: %s', 'ifirmaissuancepipeline')
                : $this->module->l('Document issued: %s', 'ifirmaissuancepipeline');
            $text = sprintf($text, $number);
            if ($r->stoppedAt === self::STEP_DELIVER) {
                $text .= ' ' . sprintf(
                    $this->module->l('Delivery step failed: %s', 'ifirmaissuancepipeline'),
                    $this->stepMessage($r, self::STEP_DELIVER)
                );
            }
            if ($this->stepStatus($r, self::STEP_KSEF) === 'failed') {
                $text .= ' ' . sprintf(
                    $this->module->l('KSeF submission failed: %s', 'ifirmaissuancepipeline'),
                    $this->stepMessage($r, self::STEP_KSEF)
                );
            }

            return $text;
        }

        return sprintf(
            $this->module->l('Stopped at the "%1$s" step (%2$s): %3$s', 'ifirmaissuancepipeline'),
            (string) $r->stoppedAt,
            $r->outcome,
            $r->message
        );
    }

    /**
     * Runs the steps in order. A step that ends the run returns the finished result and the steps after it do
     * not run; a step that lets the run go on returns null. The steps that can never end a run (number, KSeF
     * and delivery: a failure of the last two is logged, not terminal) return nothing.
     *
     * @param Order                $order
     * @param array                $options
     * @param IfirmaPipelineResult $result
     * @return IfirmaPipelineResult the finished result
     */
    private function execute(Order $order, array $options, IfirmaPipelineResult $result)
    {
        if ($stop = $this->stepHold($order, $result)) {
            return $stop;
        }
        if ($stop = $this->stepGuard($order, $options, $result)) {
            return $stop;
        }
        if ($stop = $this->stepCreate($order, $options, $result)) {
            return $stop;
        }
        $this->stepNumber($result);
        $this->stepKsef($order, $options, $result);
        $this->stepDeliver($order, $options, $result);

        return $result;
    }

    /**
     * 1. HOLD: a pending data request holds the run. An issued document is only noted here; the guard ends
     * that run.
     *
     * @param Order                $order
     * @param IfirmaPipelineResult $result
     * @return IfirmaPipelineResult|null the finished result when the run is held, null to go on
     */
    private function stepHold(Order $order, IfirmaPipelineResult $result)
    {
        $idOrder = (int) $order->id;
        $existing = IfirmaReceipt::getByOrder($idOrder);
        if ($existing && $existing->isIssued()) {
            $result->addStep(self::STEP_HOLD, 'skipped', 'document already issued');

            return null;
        }
        if (!$this->requests->hasPendingForOrder($idOrder)) {
            $result->addStep(self::STEP_HOLD, 'ok', '');

            return null;
        }
        $pending = $this->requests->getPendingForOrder($idOrder);
        $reason = 'held: data request #' . ($pending ? (int) $pending->id : 0) . ' pending';
        $row = $this->ensureRow($order, $isNew);
        $row->markHeld($reason);
        $row->save();

        $result->receipt = $row;
        $result->stopAt(IfirmaPipelineResult::OUTCOME_HELD, self::STEP_HOLD, $reason);

        return $result;
    }

    /**
     * 2. GUARD: an issued document, a suspected duplicate and an inconclusive check end the run. An adopted
     * document goes on to KSeF and delivery without a create; any other answer goes on to the create.
     *
     * @param Order                $order
     * @param array                $options
     * @param IfirmaPipelineResult $result
     * @return IfirmaPipelineResult|null the finished result when the guard ends the run, null to go on
     */
    private function stepGuard(Order $order, array $options, IfirmaPipelineResult $result)
    {
        $g = $this->guard->check($order, array('bypass' => !empty($options['bypass_guard'])));
        $result->guard = $g;
        if ($g->outcome === IfirmaGuardResult::OUTCOME_LOCAL_ISSUED) {
            return $this->stopForIssued($result, $g);
        }
        if ($g->outcome === IfirmaGuardResult::OUTCOME_SUSPECTED) {
            return $this->stopForSuspected($result, $g);
        }
        if ($g->outcome === IfirmaGuardResult::OUTCOME_INCONCLUSIVE) {
            return $this->stopForInconclusive($order, $result, $g);
        }
        if ($g->outcome === IfirmaGuardResult::OUTCOME_ADOPT) {
            $result->receipt = $g->receipt;
            $result->addStep(self::STEP_GUARD, 'ok', 'existing document adopted');
            $result->markAdopted();
        } elseif ($g->outcome === IfirmaGuardResult::OUTCOME_DISABLED) {
            $result->addStep(self::STEP_GUARD, 'skipped', 'duplicate check disabled');
        } else {
            $result->addStep(self::STEP_GUARD, 'ok', $g->outcome === IfirmaGuardResult::OUTCOME_BYPASSED ? 'bypassed' : '');
        }

        return null;
    }

    /**
     * The guard found the order's document already issued: every remaining step is skipped.
     *
     * @param IfirmaPipelineResult $result
     * @param IfirmaGuardResult    $g
     * @return IfirmaPipelineResult
     */
    private function stopForIssued(IfirmaPipelineResult $result, IfirmaGuardResult $g)
    {
        $result->receipt = $g->receipt;
        foreach (array(self::STEP_GUARD, self::STEP_CREATE, self::STEP_NUMBER, self::STEP_KSEF, self::STEP_DELIVER) as $s) {
            $result->addStep($s, 'skipped', 'document already issued');
        }
        $result->alreadyIssued();

        return $result;
    }

    /**
     * The guard found documents that may be the order's: nothing is issued.
     *
     * @param IfirmaPipelineResult $result
     * @param IfirmaGuardResult    $g
     * @return IfirmaPipelineResult
     */
    private function stopForSuspected(IfirmaPipelineResult $result, IfirmaGuardResult $g)
    {
        $result->receipt = $g->receipt;
        $message = $g->receipt instanceof IfirmaReceipt ? (string) $g->receipt->last_error : 'suspected duplicate';
        $result->stopAt(IfirmaPipelineResult::OUTCOME_SUSPECTED, self::STEP_GUARD, $message);

        return $result;
    }

    /**
     * The guard could not tell whether the order has a document. A rate limited check leaves no record; any
     * other failure is recorded on the order's receipt row.
     *
     * @param Order                $order
     * @param IfirmaPipelineResult $result
     * @param IfirmaGuardResult    $g
     * @return IfirmaPipelineResult
     */
    private function stopForInconclusive(Order $order, IfirmaPipelineResult $result, IfirmaGuardResult $g)
    {
        if ($g->rateLimited) {
            $result->stopAt(IfirmaPipelineResult::OUTCOME_RATE_LIMITED, self::STEP_GUARD, (string) $g->error);

            return $result;
        }
        $row = $this->ensureRow($order, $isNew);
        if ($isNew) {
            $row->markError((string) $g->error);
        } else {
            $row->last_error = (string) $g->error;
        }
        $row->save();
        $result->receipt = $row;
        $result->stopAt(IfirmaPipelineResult::OUTCOME_INCONCLUSIVE, self::STEP_GUARD, (string) $g->error);

        return $result;
    }

    /**
     * 3. CREATE: issues the document, unless the guard adopted one. A create that fails ends the run.
     *
     * @param Order                $order
     * @param array                $options
     * @param IfirmaPipelineResult $result
     * @return IfirmaPipelineResult|null the finished result when the create failed, null to go on
     */
    private function stepCreate(Order $order, array $options, IfirmaPipelineResult $result)
    {
        if ($this->isAdopted($result)) {
            $result->addStep(self::STEP_CREATE, 'skipped', 'adopted document');

            return null;
        }
        $idOrder = (int) $order->id;
        try {
            $receipt = $this->service->createReceipt($order, array(
                'override' => $this->requests->getOverrideForOrder($idOrder),
            ));
        } catch (Exception $e) {
            $row = IfirmaReceipt::getByOrder($idOrder);
            $result->receipt = $row;
            $unknown = $row && $row->status === IfirmaReceipt::STATUS_UNKNOWN;
            $outcome = $unknown ? IfirmaPipelineResult::OUTCOME_UNKNOWN : IfirmaPipelineResult::OUTCOME_ERROR;
            $result->stopAt($outcome, self::STEP_CREATE, $e->getMessage());

            return $result;
        }
        $result->receipt = $receipt;
        $result->addStep(self::STEP_CREATE, 'ok', '');

        if ($result->guard->outcome === IfirmaGuardResult::OUTCOME_BYPASSED) {
            $idEmployee = isset($options['id_employee']) ? $options['id_employee'] : null;
            $this->guard->recordResolution($receipt, 'issue_anyway', $idEmployee);
        }

        return null;
    }

    /**
     * 4. NUMBER: createReceipt already looked the number up, so there is no further iFirma call; the step
     * says whether the number is there and marks the run issued. An adopted document skips it.
     *
     * @param IfirmaPipelineResult $result
     */
    private function stepNumber(IfirmaPipelineResult $result)
    {
        if ($this->isAdopted($result)) {
            $result->addStep(self::STEP_NUMBER, 'skipped', 'adopted document');

            return;
        }
        $result->addStep(self::STEP_NUMBER, 'ok', $result->receipt->ifirma_number ? '' : 'number not yet available');
        $result->markIssued();
    }

    /**
     * 5. KSEF: skipped with a reason, ok with iFirma's message, or failed. A failure never ends the run: the
     * step is logged as failed and the message of the result ends with the error.
     *
     * @param Order                $order
     * @param array                $options
     * @param IfirmaPipelineResult $result
     */
    private function stepKsef(Order $order, array $options, IfirmaPipelineResult $result)
    {
        if (!empty($options['skip_ksef'])) {
            $result->addStep(self::STEP_KSEF, 'skipped', 'KSeF skipped by caller');

            return;
        }
        $receipt = $result->receipt;
        if (!$receipt instanceof IfirmaReceipt) {
            $result->addStep(self::STEP_KSEF, 'skipped', 'no document');

            return;
        }
        try {
            $reason = $this->ksef->skipReason($receipt, $order);
            if ($reason !== '') {
                $result->addStep(self::STEP_KSEF, 'skipped', $reason);

                return;
            }
            $k = $this->ksef->submit($receipt, $order);
        } catch (Throwable $e) {
            $k = array('ok' => false, 'message' => $e->getMessage());
        }
        $message = isset($k['message']) ? (string) $k['message'] : '';
        if (!empty($k['ok'])) {
            $result->addStep(self::STEP_KSEF, 'ok', $message);

            return;
        }
        $result->addStep(self::STEP_KSEF, 'failed', $message);
        $result->message = rtrim((string) $result->message) . ' KSeF: ' . ($message !== '' ? $message : 'KSeF submission failed');
    }

    /**
     * 6. DELIVER: a delivery that fails never changes ok or the outcome: the step is logged as stopped and
     * named in stoppedAt.
     *
     * @param Order                $order
     * @param array                $options
     * @param IfirmaPipelineResult $result
     */
    private function stepDeliver(Order $order, array $options, IfirmaPipelineResult $result)
    {
        if (!empty($options['skip_delivery'])) {
            $result->addStep(self::STEP_DELIVER, 'skipped', 'delivery skipped by caller');

            return;
        }
        try {
            $d = $this->delivery->deliver($result->receipt, $order);
        } catch (Exception $e) {
            $d = new IfirmaDeliveryResult(false, 'failed', '', $e->getMessage());
        }
        $result->delivery = $d;
        $message = $d->getMessage();
        if ($d->isSent()) {
            $result->addStep(self::STEP_DELIVER, 'ok', $message !== '' ? $message : $d->getStatus());
        } else {
            $result->stopStep(self::STEP_DELIVER, $message);
        }
    }

    /**
     * @param IfirmaPipelineResult $result
     * @return bool true when the guard adopted the order's document, so that there is nothing to create
     */
    private function isAdopted(IfirmaPipelineResult $result)
    {
        return $result->outcome === IfirmaPipelineResult::OUTCOME_ADOPTED;
    }

    /**
     * @param Order                $order
     * @param IfirmaPipelineResult $result
     * @param string               $message
     */
    private function fail(Order $order, IfirmaPipelineResult $result, $message)
    {
        $this->log('iFirma pipeline: ' . $message);
        $result->ok = false;
        $result->outcome = IfirmaPipelineResult::OUTCOME_ERROR;
        $result->message = (string) $message;
        if ($result->stoppedAt === null) {
            $result->stoppedAt = $this->runningStep($result);
        }

        try {
            $row = $this->ensureRow($order, $isNew);
            if ($isNew) {
                $row->markError((string) $message);
            } else {
                $row->last_error = (string) $message;
            }
            $row->save();
            $result->receipt = $row;
        } catch (Throwable $e) {
            // recording is best effort
        }
    }

    /**
     * @param IfirmaPipelineResult $result
     * @return string|null step that was running when an exception escaped
     */
    private function runningStep(IfirmaPipelineResult $result)
    {
        $order = array(self::STEP_HOLD, self::STEP_GUARD, self::STEP_CREATE, self::STEP_NUMBER, self::STEP_KSEF, self::STEP_DELIVER);
        $done = count($result->steps);

        return isset($order[$done]) ? $order[$done] : null;
    }

    /**
     * Non-issued receipt row of the order (existing or new, not yet saved when new).
     *
     * @param Order $order
     * @param bool  $isNew set by reference
     * @return IfirmaReceipt
     */
    private function ensureRow(Order $order, &$isNew)
    {
        $row = IfirmaReceipt::getByOrder((int) $order->id);
        $isNew = false;
        if (!$row || $row->isIssued()) {
            $row = new IfirmaReceipt();
            $isNew = true;
        }
        $row->id_order = (int) $order->id;
        $row->id_customer = (int) $order->id_customer;
        $row->id_shop = (int) $order->id_shop;
        $row->order_reference = IfirmaPayloadBuilder::buildOrderReference($order);

        return $row;
    }

    /**
     * @param IfirmaPipelineResult $r
     * @param string               $step
     * @return string
     */
    private function stepMessage(IfirmaPipelineResult $r, $step)
    {
        foreach ($r->steps as $s) {
            if ($s['step'] === $step) {
                return $s['message'];
            }
        }

        return '';
    }

    /**
     * @param IfirmaPipelineResult $r
     * @param string               $step
     * @return string
     */
    private function stepStatus(IfirmaPipelineResult $r, $step)
    {
        foreach ($r->steps as $s) {
            if ($s['step'] === $step) {
                return $s['status'];
            }
        }

        return '';
    }

    /**
     * @return int
     */
    private function triggerState()
    {
        return (int) Configuration::get('IFIRMA_AUTO_CREATE_STATE');
    }

    /**
     * @return bool
     */
    private function hasCredentials()
    {
        return trim((string) Configuration::get('IFIRMA_USER')) !== ''
            && trim((string) Configuration::get('IFIRMA_KEY_FAKTURA')) !== '';
    }

    /**
     * @param string $message
     */
    private function log($message)
    {
        if (class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog($message, 3);
        }
    }
}
