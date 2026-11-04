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
require_once dirname(__FILE__) . '/IfirmaDuplicateGuard.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryService.php';
require_once dirname(__FILE__) . '/IfirmaKsefService.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestService.php';

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
        $baseUrl = Configuration::get('IFIRMA_BASE_URL');
        $client = new IfirmaApiClient(
            Configuration::get('IFIRMA_USER'),
            array(
                'faktura' => Configuration::get('IFIRMA_KEY_FAKTURA'),
                'abonent' => Configuration::get('IFIRMA_KEY_ABONENT'),
                'rachunek' => Configuration::get('IFIRMA_KEY_RACHUNEK'),
            ),
            $baseUrl ?: IfirmaApiClient::BASE_URL
        );

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
        } catch (Exception $e) {
            $this->log('iFirma pipeline: ' . $e->getMessage());
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
            $existing = IfirmaReceipt::getByOrder((int) $order->id);
            if ($existing && $existing->isIssued()) {
                return null;
            }
            if (!$this->shouldResume($order) || !$this->hasCredentials()) {
                return null;
            }

            return $this->run($order, array('trigger' => self::TRIGGER_RESUME));
        } catch (Exception $e) {
            $this->log('iFirma pipeline resume: ' . $e->getMessage());
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
            $this->execute($order, $options, $result);
        } catch (Exception $e) {
            $this->fail($order, $result, $e->getMessage());
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
            $text = $r->outcome === 'adopted'
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
     * @param Order                $order
     * @param array                $options
     * @param IfirmaPipelineResult $result
     */
    private function execute(Order $order, array $options, IfirmaPipelineResult $result)
    {
        $idOrder = (int) $order->id;
        $bypass = !empty($options['bypass_guard']);
        $idEmployee = isset($options['id_employee']) ? $options['id_employee'] : null;

        // 1. HOLD
        $existing = IfirmaReceipt::getByOrder($idOrder);
        if ($existing && $existing->isIssued()) {
            $this->step($result, self::STEP_HOLD, 'skipped', 'document already issued');
        } elseif ($this->requests->hasPendingForOrder($idOrder)) {
            $pending = $this->requests->getPendingForOrder($idOrder);
            $reason = 'held: data request #' . ($pending ? (int) $pending->id : 0) . ' pending';
            $row = $this->ensureRow($order, $isNew);
            $row->status = IfirmaReceipt::STATUS_HELD;
            $row->last_error = $reason;
            $row->save();

            $result->receipt = $row;
            $this->stop($result, 'held', self::STEP_HOLD, $reason);

            return;
        } else {
            $this->step($result, self::STEP_HOLD, 'ok', '');
        }

        // 2. GUARD
        $g = $this->guard->check($order, array('bypass' => $bypass));
        $result->guard = $g;
        $adopted = false;
        switch ($g->outcome) {
            case IfirmaGuardResult::OUTCOME_LOCAL_ISSUED:
                $result->receipt = $g->receipt;
                foreach (array(self::STEP_GUARD, self::STEP_CREATE, self::STEP_NUMBER, self::STEP_KSEF, self::STEP_DELIVER) as $s) {
                    $this->step($result, $s, 'skipped', 'document already issued');
                }
                $result->ok = true;
                $result->outcome = 'already_issued';
                $result->message = 'document already issued';

                return;

            case IfirmaGuardResult::OUTCOME_ADOPT:
                $result->receipt = $g->receipt;
                $this->step($result, self::STEP_GUARD, 'ok', 'existing document adopted');
                $this->step($result, self::STEP_CREATE, 'skipped', 'adopted document');
                $this->step($result, self::STEP_NUMBER, 'skipped', 'adopted document');
                $result->outcome = 'adopted';
                $result->ok = true;
                $result->message = 'existing document adopted';
                $adopted = true;
                break;

            case IfirmaGuardResult::OUTCOME_SUSPECTED:
                $result->receipt = $g->receipt;
                $message = $g->receipt instanceof IfirmaReceipt ? (string) $g->receipt->last_error : 'suspected duplicate';
                $this->stop($result, 'suspected', self::STEP_GUARD, $message);

                return;

            case IfirmaGuardResult::OUTCOME_INCONCLUSIVE:
                if ($g->rateLimited) {
                    $this->stop($result, 'rate_limited', self::STEP_GUARD, (string) $g->error);

                    return;
                }
                $row = $this->ensureRow($order, $isNew);
                $row->last_error = (string) $g->error;
                if ($isNew) {
                    $row->status = IfirmaReceipt::STATUS_ERROR;
                }
                $row->save();
                $result->receipt = $row;
                $this->stop($result, 'inconclusive', self::STEP_GUARD, (string) $g->error);

                return;

            case IfirmaGuardResult::OUTCOME_DISABLED:
                $this->step($result, self::STEP_GUARD, 'skipped', 'duplicate check disabled');
                break;

            default:
                $this->step($result, self::STEP_GUARD, 'ok', $g->outcome === IfirmaGuardResult::OUTCOME_BYPASSED ? 'bypassed' : '');
                break;
        }

        if (!$adopted) {
            // 3. CREATE
            try {
                $receipt = $this->service->createReceipt($order, array(
                    'override' => $this->requests->getOverrideForOrder($idOrder),
                ));
            } catch (Exception $e) {
                $row = IfirmaReceipt::getByOrder($idOrder);
                $result->receipt = $row;
                $unknown = $row && $row->status === IfirmaReceipt::STATUS_UNKNOWN;
                $this->stop($result, $unknown ? 'unknown' : 'error', self::STEP_CREATE, $e->getMessage());

                return;
            }
            $result->receipt = $receipt;
            $this->step($result, self::STEP_CREATE, 'ok', '');

            if ($g->outcome === IfirmaGuardResult::OUTCOME_BYPASSED) {
                $this->guard->recordResolution($receipt, 'issue_anyway', $idEmployee);
            }

            // 4. NUMBER (createReceipt already looked it up; no further iFirma call)
            $this->step($result, self::STEP_NUMBER, 'ok', $receipt->ifirma_number ? '' : 'number not yet available');

            $result->outcome = 'issued';
            $result->ok = true;
            $result->message = 'document issued';
        }

        // 5. KSEF (a failure never stops the run)
        $ksefError = $this->submitToKsef($order, $options, $result);

        // 6. DELIVER
        if (!empty($options['skip_delivery'])) {
            $this->appendKsefError($result, $ksefError);
            $this->step($result, self::STEP_DELIVER, 'skipped', 'delivery skipped by caller');

            return;
        }
        try {
            $d = $this->delivery->deliver($result->receipt, $order);
        } catch (Exception $e) {
            $d = array('ok' => false, 'status' => 'failed', 'channel' => '', 'message' => $e->getMessage());
        }
        $result->delivery = $d;
        $message = isset($d['message']) ? (string) $d['message'] : '';
        if (!empty($d['ok'])) {
            $this->step($result, self::STEP_DELIVER, 'ok', $message !== '' ? $message : (isset($d['status']) ? (string) $d['status'] : ''));
        } else {
            $this->step($result, self::STEP_DELIVER, 'stopped', $message);
            $result->stoppedAt = self::STEP_DELIVER;
        }
        $this->appendKsefError($result, $ksefError);
    }

    /**
     * KSeF step: skipped with a reason, ok with iFirma's message, or failed (non-stopping).
     *
     * @param Order                $order
     * @param array                $options
     * @param IfirmaPipelineResult $result
     * @return string error text when the submission failed, '' otherwise
     */
    private function submitToKsef(Order $order, array $options, IfirmaPipelineResult $result)
    {
        if (!empty($options['skip_ksef'])) {
            $this->step($result, self::STEP_KSEF, 'skipped', 'KSeF skipped by caller');

            return '';
        }
        $receipt = $result->receipt;
        if (!$receipt instanceof IfirmaReceipt) {
            $this->step($result, self::STEP_KSEF, 'skipped', 'no document');

            return '';
        }
        try {
            $reason = $this->ksef->skipReason($receipt, $order);
            if ($reason !== '') {
                $this->step($result, self::STEP_KSEF, 'skipped', $reason);

                return '';
            }
            $k = $this->ksef->submit($receipt, $order);
        } catch (Exception $e) {
            $k = array('ok' => false, 'message' => $e->getMessage());
        } catch (Throwable $e) {
            $k = array('ok' => false, 'message' => $e->getMessage());
        }
        $message = isset($k['message']) ? (string) $k['message'] : '';
        if (!empty($k['ok'])) {
            $this->step($result, self::STEP_KSEF, 'ok', $message);

            return '';
        }
        $this->step($result, self::STEP_KSEF, 'failed', $message);

        return $message !== '' ? $message : 'KSeF submission failed';
    }

    /**
     * @param IfirmaPipelineResult $result
     * @param string               $error
     */
    private function appendKsefError(IfirmaPipelineResult $result, $error)
    {
        if ($error !== '') {
            $result->message = rtrim((string) $result->message) . ' KSeF: ' . $error;
        }
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
        $result->outcome = 'error';
        $result->message = (string) $message;
        if ($result->stoppedAt === null) {
            $result->stoppedAt = $this->runningStep($result);
        }

        try {
            $row = $this->ensureRow($order, $isNew);
            $row->last_error = (string) $message;
            if ($isNew) {
                $row->status = IfirmaReceipt::STATUS_ERROR;
            }
            $row->save();
            $result->receipt = $row;
        } catch (Exception $e) {
            // recording is best effort
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
        $row->order_reference = IfirmaReceiptService::buildOrderReference($order);

        return $row;
    }

    /**
     * @param IfirmaPipelineResult $r
     * @param string               $outcome
     * @param string               $step
     * @param string               $message
     */
    private function stop(IfirmaPipelineResult $r, $outcome, $step, $message)
    {
        $this->step($r, $step, 'stopped', $message);
        $r->ok = false;
        $r->outcome = $outcome;
        $r->stoppedAt = $step;
        $r->message = (string) $message;
    }

    /**
     * @param IfirmaPipelineResult $r
     * @param string               $step
     * @param string               $status ok|skipped|stopped|failed
     * @param string               $message
     */
    private function step(IfirmaPipelineResult $r, $step, $status, $message)
    {
        $r->steps[] = array('step' => $step, 'status' => $status, 'message' => (string) $message);
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
