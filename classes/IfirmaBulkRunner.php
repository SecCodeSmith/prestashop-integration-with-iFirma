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
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaPipelineResult.php';
require_once dirname(__FILE__) . '/IfirmaIssuancePipeline.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryService.php';
require_once dirname(__FILE__) . '/IfirmaStatusSyncService.php';
require_once dirname(__FILE__) . '/IfirmaReceiptActions.php';

/**
 * Runs one batch of a back-office bulk action (issue, retry, resend, refresh) over the module services.
 *
 * The browser sends the selected ids in batches (batchSize() per kind) and the controller wraps run() into the
 * JSON envelope. run() never issues, sends or refreshes more than one batch, ends the batch early when the time
 * budget is used up (the rest comes back in "remaining"), and stops the whole run when the API rate guard
 * refuses a request or iFirma rejected the credentials (the untouched ids come back as "not_attempted").
 *
 * Outcome codes: issued, adopted, suspected, held, failed, sent, refreshed, skipped, not_attempted. The runner
 * carries no words of its own besides a few messages worded through the module's l().
 */
class IfirmaBulkRunner
{
    const KIND_ISSUE = 'issue';
    const KIND_RETRY = 'retry';
    const KIND_RESEND = 'resend';
    const KIND_REFRESH = 'refresh';

    const OUTCOME_ISSUED = 'issued';
    const OUTCOME_ADOPTED = 'adopted';
    const OUTCOME_SUSPECTED = 'suspected';
    const OUTCOME_HELD = 'held';
    const OUTCOME_FAILED = 'failed';
    const OUTCOME_SENT = 'sent';
    const OUTCOME_REFRESHED = 'refreshed';
    const OUTCOME_SKIPPED = 'skipped';
    const OUTCOME_NOT_ATTEMPTED = 'not_attempted';

    const STOP_RATE_LIMITED = 'rate_limited';
    const STOP_AUTH_BLOCKED = 'auth_blocked';

    const DEFAULT_BUDGET = 20;

    /** @var Module */
    private $module;

    /** @var callable|null returns a unix timestamp */
    private $clock;

    /** @var object|null IfirmaIssuancePipeline or a stand-in with run() and describe() */
    private $pipeline;

    /** @var object|null IfirmaDeliveryService or a stand-in with resend() */
    private $delivery;

    /** @var object|null IfirmaStatusSyncService or a stand-in with lookupDate() and syncByIssueDate() */
    private $sync;

    /** @var callable|null returns true when iFirma rejected the credentials recently */
    private $authProbe;

    /**
     * @param Module        $module
     * @param callable|null $clock returns a unix timestamp; time() when null
     */
    public function __construct(Module $module, $clock = null)
    {
        $this->module = $module;
        $this->clock = $clock;
    }

    /**
     * @param string $kind
     * @return int ids one batch of the kind may hold
     * @throws InvalidArgumentException for an unknown kind
     */
    public static function batchSize($kind)
    {
        switch ((string) $kind) {
            case self::KIND_ISSUE:
            case self::KIND_RETRY:
                return 3;
            case self::KIND_RESEND:
                return 5;
            case self::KIND_REFRESH:
                return 25;
        }

        throw new InvalidArgumentException('Unknown bulk kind: ' . $kind);
    }

    /**
     * @return string[]
     */
    public static function kinds()
    {
        return array(self::KIND_ISSUE, self::KIND_RETRY, self::KIND_RESEND, self::KIND_REFRESH);
    }

    /**
     * @return string[] every outcome code a result can carry
     */
    public static function outcomes()
    {
        return array(
            self::OUTCOME_ISSUED, self::OUTCOME_ADOPTED, self::OUTCOME_SUSPECTED, self::OUTCOME_HELD,
            self::OUTCOME_FAILED, self::OUTCOME_SENT, self::OUTCOME_REFRESHED, self::OUTCOME_SKIPPED,
            self::OUTCOME_NOT_ATTEMPTED,
        );
    }

    /**
     * @param object $pipeline IfirmaIssuancePipeline (test seam)
     * @return $this
     */
    public function setPipeline($pipeline)
    {
        $this->pipeline = $pipeline;

        return $this;
    }

    /**
     * @param object $delivery IfirmaDeliveryService (test seam)
     * @return $this
     */
    public function setDeliveryService($delivery)
    {
        $this->delivery = $delivery;

        return $this;
    }

    /**
     * @param object $sync IfirmaStatusSyncService (test seam)
     * @return $this
     */
    public function setSyncService($sync)
    {
        $this->sync = $sync;

        return $this;
    }

    /**
     * @param callable $probe returns true when the credentials were rejected (test seam)
     * @return $this
     */
    public function setAuthProbe($probe)
    {
        $this->authProbe = $probe;

        return $this;
    }

    /**
     * Runs one batch.
     *
     * @param string $kind       issue | retry | resend | refresh
     * @param array  $ids        order ids for issue, receipt ids (id_ifirma_receipt) for the other kinds
     * @param int    $idEmployee
     * @param array  $options    budget (seconds, default 20), final (bool), previous_totals (outcome => count)
     * @return array kind, results (id, id_order, outcome, message, receipt), remaining (ids), stopped, processed
     * @throws InvalidArgumentException for an unknown kind
     */
    public function run($kind, array $ids, $idEmployee, array $options = array())
    {
        $size = self::batchSize($kind);
        $budget = isset($options['budget']) ? (int) $options['budget'] : self::DEFAULT_BUDGET;
        $started = $this->now();

        $clean = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        $batch = array_slice($clean, 0, $size);
        $overflow = array_slice($clean, $size);

        $state = array(
            'results' => array(),   // id => result, filled in the order the ids are handled
            'stopped' => '',
            'out_of_time' => false,
        );

        if ($kind === self::KIND_REFRESH) {
            $this->runRefresh($batch, $budget, $started, $state);
        } else {
            $this->runEach($kind, $batch, (int) $idEmployee, $budget, $started, $state);
        }

        $results = array();
        $remaining = array();
        foreach ($batch as $id) {
            if (isset($state['results'][$id])) {
                $results[] = $state['results'][$id];
                continue;
            }
            if ($state['stopped'] !== '') {
                $results[] = $this->notAttempted($id, $state['stopped']);
            } else {
                $remaining[] = $id;
            }
        }
        if ($state['stopped'] !== '') {
            $overflow = array();
            $remaining = array();
        }
        $remaining = array_merge($remaining, $overflow);

        $processed = 0;
        $counts = array();
        foreach ($results as $result) {
            if ($result['outcome'] !== self::OUTCOME_NOT_ATTEMPTED) {
                $processed++;
            }
            $counts[$result['outcome']] = (isset($counts[$result['outcome']]) ? $counts[$result['outcome']] : 0) + 1;
        }

        // one log entry per run: when the last batch is done, or when the run stops
        if ((!empty($options['final']) && !$remaining) || $state['stopped'] !== '') {
            $this->log($kind, (int) $idEmployee, isset($options['previous_totals']) ? $options['previous_totals'] : array(), $counts, $state['stopped']);
        }

        return array(
            'kind' => $kind,
            'results' => $results,
            'remaining' => $remaining,
            'stopped' => $state['stopped'],
            'processed' => $processed,
        );
    }

    /**
     * issue, retry and resend: one id after the other.
     *
     * @param string $kind
     * @param int[]  $batch
     * @param int    $idEmployee
     * @param int    $budget
     * @param int    $started
     * @param array  $state
     */
    private function runEach($kind, array $batch, $idEmployee, $budget, $started, array &$state)
    {
        foreach ($batch as $id) {
            if ($this->now() - $started >= $budget) {
                return;
            }

            if ($kind === self::KIND_RESEND) {
                $stop = $this->resendOne($id, $state);
            } else {
                $stop = $this->issueOne($kind, $id, $idEmployee, $state);
            }

            if ($stop !== '') {
                $state['stopped'] = $stop;

                return;
            }
            if ($state['results'][$id]['outcome'] === self::OUTCOME_FAILED && $this->authBlocked()) {
                $state['stopped'] = self::STOP_AUTH_BLOCKED;

                return;
            }
        }
    }

    /**
     * @param string $kind  issue (the id is an order id) or retry (the id is a receipt id)
     * @param int    $id
     * @param int    $idEmployee
     * @param array  $state
     * @return string '' or the stop reason
     */
    private function issueOne($kind, $id, $idEmployee, array &$state)
    {
        if ($kind === self::KIND_RETRY) {
            $record = $this->loadReceipt($id);
            if ($record === null) {
                $state['results'][$id] = $this->result($id, 0, self::OUTCOME_FAILED, $this->module->l('The document was not found.', 'ifirmabulkrunner'), null);

                return '';
            }
            $idOrder = (int) $record->id_order;
        } else {
            $idOrder = $id;
            $record = IfirmaReceipt::getByOrder($idOrder);
        }

        $order = new Order($idOrder);
        if (!Validate::isLoadedObject($order)) {
            $state['results'][$id] = $this->result($id, $idOrder, self::OUTCOME_FAILED, $this->module->l('Invalid order.', 'ifirmabulkrunner'), $record);

            return '';
        }

        if ($record instanceof IfirmaReceipt && $record->isIssued()) {
            $state['results'][$id] = $this->result($id, $idOrder, self::OUTCOME_SKIPPED, $this->module->l('The document has already been issued.', 'ifirmabulkrunner'), $record);

            return '';
        }

        $pipeline = $this->pipeline();
        // never bypass_guard: a bulk run must not override the duplicate guard
        $r = $pipeline->run($order, array(
            'trigger' => IfirmaIssuancePipeline::TRIGGER_MANUAL,
            'id_employee' => (int) $idEmployee,
        ));

        if ($r->outcome === IfirmaPipelineResult::OUTCOME_RATE_LIMITED) {
            $state['results'][$id] = $this->notAttempted($id, self::STOP_RATE_LIMITED, $idOrder);

            return self::STOP_RATE_LIMITED;
        }

        if ($r->outcome === IfirmaPipelineResult::OUTCOME_ALREADY_ISSUED) {
            // the order got its document meanwhile (another tab, the automatic issuance): nothing was done
            $outcome = self::OUTCOME_SKIPPED;
        } elseif ($r->ok) {
            $outcome = $r->outcome === IfirmaPipelineResult::OUTCOME_ADOPTED ? self::OUTCOME_ADOPTED : self::OUTCOME_ISSUED;
        } elseif ($r->outcome === IfirmaPipelineResult::OUTCOME_SUSPECTED) {
            $outcome = self::OUTCOME_SUSPECTED;
        } elseif ($r->outcome === IfirmaPipelineResult::OUTCOME_HELD) {
            $outcome = self::OUTCOME_HELD;
        } else {
            $outcome = self::OUTCOME_FAILED;
        }

        $state['results'][$id] = $this->result($id, $idOrder, $outcome, $pipeline->describe($r), IfirmaReceipt::getByOrder($idOrder));

        return '';
    }

    /**
     * @param int   $id receipt id
     * @param array $state
     * @return string '' or the stop reason
     */
    private function resendOne($id, array &$state)
    {
        $receipt = $this->loadReceipt($id);
        if ($receipt === null) {
            $state['results'][$id] = $this->result($id, 0, self::OUTCOME_FAILED, $this->module->l('The document was not found.', 'ifirmabulkrunner'), null);

            return '';
        }
        $idOrder = (int) $receipt->id_order;

        if (!$receipt->isIssued()) {
            $state['results'][$id] = $this->result($id, $idOrder, self::OUTCOME_SKIPPED, $this->module->l('The document has not been issued yet.', 'ifirmabulkrunner'), $receipt);

            return '';
        }

        $order = new Order($idOrder);
        $res = $this->delivery()->resend($receipt, Validate::isLoadedObject($order) ? $order : null);
        $message = (string) $res->getMessage();

        if ($res->getStatus() === 'sent') {
            $state['results'][$id] = $this->result(
                $id,
                $idOrder,
                self::OUTCOME_SENT,
                $message !== '' ? $message : $this->module->l('The document was sent.', 'ifirmabulkrunner'),
                $receipt
            );

            return '';
        }
        if ($res->getStatus() === 'disabled' || $res->getStatus() === 'skipped') {
            $state['results'][$id] = $this->result($id, $idOrder, self::OUTCOME_SKIPPED, $message, $receipt);

            return '';
        }
        if ($res->refusedLocally()) {
            // the client refused the call itself and the record was left untouched: stop the run
            $reason = $res->getRefusal() === 'auth_blocked' ? self::STOP_AUTH_BLOCKED : self::STOP_RATE_LIMITED;
            $state['results'][$id] = $this->notAttempted($id, $reason, $idOrder);

            return $reason;
        }

        $state['results'][$id] = $this->result($id, $idOrder, self::OUTCOME_FAILED, $message, $receipt);

        return '';
    }

    /**
     * refresh: the issued records are grouped by issue date and one list call per date refreshes the group.
     *
     * @param int[] $batch
     * @param int   $budget
     * @param int   $started
     * @param array $state
     */
    private function runRefresh(array $batch, $budget, $started, array &$state)
    {
        $groups = array();   // date => IfirmaReceipt[] keyed by id, dates in order of first appearance
        $sync = null;
        foreach ($batch as $id) {
            $receipt = $this->loadReceipt($id);
            if ($receipt === null) {
                $state['results'][$id] = $this->result($id, 0, self::OUTCOME_FAILED, $this->module->l('The document was not found.', 'ifirmabulkrunner'), null);
                continue;
            }
            if (!$receipt->isIssued()) {
                $state['results'][$id] = $this->result($id, (int) $receipt->id_order, self::OUTCOME_SKIPPED, $this->module->l('The document has not been issued yet.', 'ifirmabulkrunner'), $receipt);
                continue;
            }
            if ($sync === null) {
                $sync = $this->sync();
            }
            $groups[$sync->lookupDate($receipt)][$id] = $receipt;
        }

        foreach ($groups as $date => $receipts) {
            if ($this->now() - $started >= $budget) {
                return;
            }

            $summary = $sync->syncByIssueDate((string) $date, array_values($receipts));
            if (!empty($summary['rate_limited'])) {
                $state['stopped'] = self::STOP_RATE_LIMITED;
                foreach (array_keys($receipts) as $id) {
                    $state['results'][$id] = $this->notAttempted($id, self::STOP_RATE_LIMITED, (int) $receipts[$id]->id_order);
                }

                return;
            }

            $failed = false;
            foreach ($receipts as $id => $receipt) {
                $error = (string) $receipt->sync_error;
                if ($receipt->remote_missing) {
                    $state['results'][$id] = $this->result($id, (int) $receipt->id_order, self::OUTCOME_FAILED, $this->module->l('The document is missing in iFirma.', 'ifirmabulkrunner'), $receipt);
                } elseif ($error === '') {
                    $state['results'][$id] = $this->result($id, (int) $receipt->id_order, self::OUTCOME_REFRESHED, $this->module->l('Refreshed from iFirma.', 'ifirmabulkrunner'), $receipt);
                } else {
                    $failed = true;
                    $state['results'][$id] = $this->result($id, (int) $receipt->id_order, self::OUTCOME_FAILED, $error, $receipt);
                }
            }
            if ($failed && $this->authBlocked()) {
                $state['stopped'] = self::STOP_AUTH_BLOCKED;

                return;
            }
        }
    }

    /**
     * @param int $id
     * @return IfirmaReceipt|null
     */
    private function loadReceipt($id)
    {
        $receipt = new IfirmaReceipt((int) $id);

        return Validate::isLoadedObject($receipt) ? $receipt : null;
    }

    /**
     * @param int                $id
     * @param int                $idOrder
     * @param string             $outcome
     * @param string             $message
     * @param IfirmaReceipt|null $receipt
     * @return array
     */
    private function result($id, $idOrder, $outcome, $message, $receipt)
    {
        $actions = new IfirmaReceiptActions($this->module);

        return array(
            'id' => $id,
            'id_order' => (int) $idOrder,
            'outcome' => $outcome,
            'message' => (string) $message,
            'receipt' => $receipt instanceof IfirmaReceipt ? $actions->receiptToArray($receipt) : null,
        );
    }

    /**
     * @param int    $id
     * @param string $reason rate_limited | auth_blocked
     * @param int    $idOrder
     * @return array
     */
    private function notAttempted($id, $reason, $idOrder = 0)
    {
        $message = $reason === self::STOP_AUTH_BLOCKED
            ? $this->module->l('not attempted (credentials rejected)', 'ifirmabulkrunner')
            : $this->module->l('not attempted (rate limited)', 'ifirmabulkrunner') . '. ' . $this->whenToRunAgain();

        return $this->result($id, $idOrder, self::OUTCOME_NOT_ATTEMPTED, $message, null);
    }

    /**
     * @return string the hint that names the window that refused: tomorrow for the day cap, else in a minute
     */
    private function whenToRunAgain()
    {
        $runner = $this;
        $guard = new IfirmaRateGuard(function () use ($runner) {
            return $runner->now();
        });

        return $guard->blockedBy() === IfirmaRateDecision::WINDOW_DAY
            ? $this->module->l('Run it again tomorrow.', 'ifirmabulkrunner')
            : $this->module->l('Run it again in a minute.', 'ifirmabulkrunner');
    }

    /**
     * One log entry per run: the totals of the earlier batches plus this one.
     *
     * @param string $kind
     * @param int    $idEmployee
     * @param array  $previous outcome => count
     * @param array  $counts   outcome => count of this batch
     * @param string $stopped
     */
    private function log($kind, $idEmployee, $previous, array $counts, $stopped)
    {
        $totals = array();
        foreach (array($previous, $counts) as $part) {
            if (!is_array($part)) {
                continue;
            }
            foreach ($part as $outcome => $count) {
                // the browser posts the earlier totals back: keep only the known outcome words and no negative count
                if (!in_array((string) $outcome, self::outcomes(), true)) {
                    continue;
                }
                $totals[(string) $outcome] = (isset($totals[(string) $outcome]) ? $totals[(string) $outcome] : 0) + max(0, (int) $count);
            }
        }
        $pairs = array();
        foreach ($totals as $outcome => $count) {
            $pairs[] = $outcome . '=' . $count;
        }

        PrestaShopLogger::addLog(
            'iFirma bulk ' . $kind . ' by employee ' . $idEmployee . ': ' . implode(', ', $pairs)
            . ($stopped !== '' ? ', stopped: ' . $stopped : ''),
            1
        );
    }

    /**
     * @return bool true when iFirma rejected the credentials less than the cool-down ago
     */
    private function authBlocked()
    {
        if ($this->authProbe !== null) {
            return (bool) call_user_func($this->authProbe);
        }

        try {
            $client = IfirmaApiClient::fromConfiguration();

            return method_exists($client, 'isAuthBlocked') && $client->isAuthBlocked();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return object
     */
    private function pipeline()
    {
        if ($this->pipeline === null) {
            $this->pipeline = IfirmaIssuancePipeline::build($this->module);
        }

        return $this->pipeline;
    }

    /**
     * @return object
     */
    private function delivery()
    {
        if ($this->delivery === null) {
            $this->delivery = IfirmaDeliveryService::build($this->module);
        }

        return $this->delivery;
    }

    /**
     * @return object
     */
    private function sync()
    {
        if ($this->sync === null) {
            $this->sync = IfirmaStatusSyncService::build($this->module);
        }

        return $this->sync;
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock ? (int) call_user_func($this->clock) : time();
    }
}
