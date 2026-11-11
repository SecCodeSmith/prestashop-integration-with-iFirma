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

/**
 * Status synchronisation of issued iFirma documents (spec: receipt-status-sync).
 *
 * Refreshes paid amount, sent/draft flags, KSeF state, linked correction and
 * remote disappearance from ONE list call per issue date (faktury.json); the
 * per-document detail endpoint is never used. Used by the admin Refresh button,
 * the opportunistic customer-view sync and the cron endpoint.
 *
 * Public contract: PAGE_SIZE, OPPORTUNISTIC_TIMEOUT, STALE_HOURS, __construct,
 * build, tolerance, lookupDate, fetchRowsForDate, applyRow, syncByIssueDate,
 * syncReceipt, shouldSyncOpportunistically, opportunisticSync, selectForCron,
 * countHeldOrders, runCron, isValidCronToken.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaDataRequest.php';
require_once dirname(__FILE__) . '/IfirmaCustomerSyncBudget.php';

class IfirmaStatusSyncService
{
    const PAGE_SIZE = 100;
    const OPPORTUNISTIC_TIMEOUT = 5;
    const STALE_HOURS = 24;
    const DEFAULT_INTERVAL_MIN = 15;
    const SECONDS_PER_MINUTE = 60;

    /** @var IfirmaApiClient */
    private $client;

    /** @var callable|null returns a unix timestamp */
    private $clock;

    /** @var IfirmaRequestBudget the budget of the syncs that customers' order pages start */
    private $customerSyncBudget;

    /**
     * @param IfirmaApiClient          $client
     * @param callable|null            $clock
     * @param IfirmaRequestBudget|null $customerSyncBudget
     */
    public function __construct(IfirmaApiClient $client, $clock = null, IfirmaRequestBudget $customerSyncBudget = null)
    {
        $this->client = $client;
        $this->clock = $clock;
        $this->customerSyncBudget = $customerSyncBudget !== null ? $customerSyncBudget : IfirmaCustomerSyncBudget::create($clock);
    }

    /**
     * @param Module $module
     * @return IfirmaStatusSyncService
     */
    public static function build(Module $module)
    {
        $client = IfirmaApiClient::fromConfiguration();

        return new self($client);
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock ? (int) call_user_func($this->clock) : time();
    }

    /**
     * @return float
     */
    public function tolerance()
    {
        $value = Configuration::get('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE');

        return ($value !== false && $value !== null && $value !== '') ? (float) $value : 0.01;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @return string Y-m-d
     */
    public function lookupDate(IfirmaReceipt $receipt)
    {
        if (!empty($receipt->issue_date) && $receipt->issue_date !== '0000-00-00') {
            return substr((string) $receipt->issue_date, 0, 10);
        }

        return date('Y-m-d', strtotime((string) $receipt->date_add));
    }

    /**
     * @param string   $date    Y-m-d
     * @param int|null $timeout
     * @return array rows (array|null), error, rate_limited, rate_limit_window (only when rows is null), calls
     */
    public function fetchRowsForDate($date, $timeout = null)
    {
        $rows = array();
        $calls = 0;
        $page = 1;

        while (true) {
            $calls++;
            $batch = $this->requestPage($date, $page, $timeout);

            if ($batch === null) {
                return $this->failedFetch($calls);
            }

            $rows = array_merge($rows, $batch);
            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
            $page++;
        }

        return array('rows' => $rows, 'error' => '', 'rate_limited' => false, 'calls' => $calls);
    }

    /**
     * @param string   $date    Y-m-d
     * @param int      $page    1-based
     * @param int|null $timeout
     * @return array|null the rows of the page, null when the call failed
     */
    private function requestPage($date, $page, $timeout)
    {
        return $this->client->listInvoices(array(
            'dataOd' => $date,
            'dataDo' => $date,
            'iloscNaStronie' => self::PAGE_SIZE,
            'strona' => $page,
        ), 'faktura', $timeout);
    }

    /**
     * @param int $calls
     * @return array the fetchRowsForDate() result of a failed call
     */
    private function failedFetch($calls)
    {
        return array(
            'rows' => null,
            'error' => (string) $this->client->getLastError(),
            'rate_limited' => (bool) $this->client->isRateLimited(),
            'rate_limit_window' => (string) $this->client->rateLimitWindow(),
            'calls' => $calls,
        );
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param array|null    $row list row, or null when absent from the list
     * @return string updated|paid|missing|reappeared
     */
    public function applyRow(IfirmaReceipt $receipt, ?array $row = null)
    {
        $now = date('Y-m-d H:i:s', $this->now());

        if ($row === null) {
            $receipt->remote_missing = 1;
            $receipt->sync_error = '';
            $receipt->last_sync_at = $now;
            $receipt->save();

            return 'missing';
        }

        $outcome = 'updated';
        if ($receipt->remote_missing) {
            $outcome = 'reappeared';
        }
        $receipt->remote_missing = 0;

        $paid = isset($row['Zaplacono']) ? (float) $row['Zaplacono'] : 0.0;
        $gross = isset($row['Brutto']) ? (float) $row['Brutto'] : 0.0;

        $receipt->remote_paid_amount = $paid;
        $receipt->remote_sent = !empty($row['CzyWyslano']);
        $receipt->remote_draft = !empty($row['CzySzkic']);
        // The module's own "sent" marker survives until iFirma reports a KSeF status.
        if (isset($row['KsefStatus']) && trim((string) $row['KsefStatus']) !== '') {
            $receipt->ksef_status = (string) $row['KsefStatus'];
        }
        if (isset($row['KsefNumer']) && trim((string) $row['KsefNumer']) !== '') {
            $receipt->ksef_number = (string) $row['KsefNumer'];
        }
        $receipt->linked_document = isset($row['NumerPowiazanegoPrzychodu'])
            ? (string) $row['NumerPowiazanegoPrzychodu'] : '';
        if (empty($receipt->ifirma_number) && !empty($row['PelnyNumer'])) {
            $receipt->ifirma_number = (string) $row['PelnyNumer'];
        }

        if ($receipt->status !== IfirmaReceipt::STATUS_PAID && $paid >= $gross - $this->tolerance()) {
            $receipt->markPaid();
            $outcome = 'paid';
        }

        $receipt->sync_error = '';
        $receipt->last_sync_at = $now;
        $receipt->save();

        return $outcome;
    }

    /**
     * Summary keys: checked, updated (rows refreshed, incl. paid/reappeared),
     * paid (newly paid), missing, errors, calls, rate_limited, error; a refused call adds rate_limit_window.
     *
     * @param string          $date
     * @param IfirmaReceipt[] $receipts
     * @param int|null        $timeout
     * @return array
     */
    public function syncByIssueDate($date, array $receipts, $timeout = null)
    {
        $summary = array(
            'checked' => 0, 'updated' => 0, 'paid' => 0, 'missing' => 0,
            'errors' => 0, 'calls' => 0, 'rate_limited' => 0, 'error' => '',
        );

        $result = $this->fetchRowsForDate($date, $timeout);
        $summary['calls'] = $result['calls'];

        if ($result['rows'] === null) {
            $summary['error'] = $result['error'];
            if ($result['rate_limited']) {
                $summary['rate_limited'] = 1;
                $summary['rate_limit_window'] = $result['rate_limit_window'];

                return $summary;
            }
            $this->recordSyncError($receipts, $result['error']);
            $summary['errors'] = count($receipts);

            return $summary;
        }

        $byId = $this->rowsByDocumentId($result['rows']);

        foreach ($receipts as $receipt) {
            $key = (string) $receipt->ifirma_id;
            $outcome = $this->applyRow($receipt, isset($byId[$key]) ? $byId[$key] : null);
            $summary['checked']++;
            if ($outcome === 'missing') {
                $summary['missing']++;
                continue;
            }
            $summary['updated']++;
            if ($outcome === 'paid') {
                $summary['paid']++;
            }
        }

        return $summary;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param int|null      $timeout
     * @return array syncByIssueDate() summary
     */
    public function syncReceipt(IfirmaReceipt $receipt, $timeout = null)
    {
        return $this->syncByIssueDate($this->lookupDate($receipt), array($receipt), $timeout);
    }

    /**
     * @param array $rows list rows
     * @return array FakturaId => row
     */
    private function rowsByDocumentId(array $rows)
    {
        $byId = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['FakturaId'])) {
                $byId[(string) $row['FakturaId']] = $row;
            }
        }

        return $byId;
    }

    /**
     * @param IfirmaReceipt[] $receipts
     * @param string          $error
     */
    private function recordSyncError(array $receipts, $error)
    {
        $now = date('Y-m-d H:i:s', $this->now());
        foreach ($receipts as $receipt) {
            $receipt->sync_error = $error;
            $receipt->last_sync_at = $now;
            $receipt->save();
        }
    }

    /**
     * @return int seconds a record counts as freshly synced and as freshly attempted
     */
    private function syncIntervalSeconds()
    {
        $interval = (int) Configuration::get('IFIRMA_SYNC_INTERVAL_MIN');
        if ($interval <= 0) {
            $interval = self::DEFAULT_INTERVAL_MIN;
        }

        return $interval * self::SECONDS_PER_MINUTE;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @return bool
     */
    public function shouldSyncOpportunistically(IfirmaReceipt $receipt)
    {
        if (!$receipt->isIssued()) {
            return false;
        }

        return !$this->isRecent($receipt->last_sync_at, $this->now() - $this->syncIntervalSeconds());
    }

    /**
     * @param string|null $time        a stored date time, empty or zero when never
     * @param int         $staleBefore unix time
     * @return bool
     */
    private function isRecent($time, $staleBefore)
    {
        if (empty($time) || $time === '0000-00-00 00:00:00') {
            return false;
        }
        $at = strtotime((string) $time);

        // A time in the future (a clock that ran ahead) would block the sync until it has passed.
        return $at !== false && $at >= $staleBefore && $at <= $this->now();
    }

    /**
     * The sync an order page of a customer starts: one list call, at most one per customer and interval, that reads
     * the first page of the viewed document's issue date and refreshes the viewed document and the customer's other
     * documents of that date. A refusal by the customer page budget, by another request's claim or by the request
     * limit sends nothing. Never throws.
     *
     * @param IfirmaReceipt $receipt
     * @return bool true when a call was made and the limit did not refuse it; a refused sync writes nothing, so
     *              the stored record is still the last known state
     */
    public function opportunisticSync(IfirmaReceipt $receipt)
    {
        try {
            if (!$this->shouldSyncOpportunistically($receipt)
                || (int) $receipt->id_customer <= 0
                || $this->client->isAuthBlocked()
                || !$this->customerSyncBudget->take()
            ) {
                return false;
            }

            return $this->syncCustomerPage($receipt);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Runs after the budget unit was taken. The unit and the claim stay spent only when a request was sent.
     *
     * @param IfirmaReceipt $receipt
     * @return bool
     */
    private function syncCustomerPage(IfirmaReceipt $receipt)
    {
        $stamp = date('Y-m-d H:i:s', $this->now());
        $anchor = 0;
        $claimed = false;
        $callMade = false;
        try {
            $anchor = IfirmaReceipt::findCustomerSyncAnchor((int) $receipt->id_customer);
            // The viewed record is issued, so an anchor exists unless the lookup failed; the viewed record then
            // carries the claim, which still allows one call per interval for it and no more.
            if ($anchor <= 0) {
                $anchor = (int) $receipt->id;
            }
            $staleBefore = date('Y-m-d H:i:s', $this->now() - $this->syncIntervalSeconds());
            $claimed = IfirmaReceipt::claimSyncAttempt($anchor, $stamp, $staleBefore);
            if (!$claimed) {
                $this->customerSyncBudget->release();

                return false;
            }
            $callMade = true;
            $page = $this->fetchFirstPage($this->lookupDate($receipt));

            return $this->recordCustomerPage($receipt, $page, $anchor, $stamp, $staleBefore);
        } catch (Throwable $e) {
            if (!$callMade || !$this->client->lastRequestWasSent()) {
                $this->giveBackCustomerSync($anchor, $stamp, $claimed);
            }

            return false;
        }
    }

    /**
     * @param int    $anchor
     * @param string $stamp
     * @param bool   $claimed whether this request holds the claim
     */
    private function giveBackCustomerSync($anchor, $stamp, $claimed)
    {
        $this->customerSyncBudget->release();
        if ($claimed) {
            IfirmaReceipt::releaseSyncAttempt($anchor, $stamp);
        }
    }

    /**
     * @param string $date Y-m-d
     * @return array the fetchRowsForDate() result of one call: the first page only
     */
    private function fetchFirstPage($date)
    {
        $batch = $this->requestPage($date, 1, self::OPPORTUNISTIC_TIMEOUT);
        if ($batch === null) {
            return $this->failedFetch(1);
        }

        return array('rows' => $batch, 'error' => '', 'rate_limited' => false, 'calls' => 1);
    }

    /**
     * @param IfirmaReceipt $receipt      the viewed record
     * @param array         $page         fetchFirstPage() result
     * @param int           $anchor       the record that holds the customer's claim
     * @param string        $stamp        the attempt time the claim was made with
     * @param string        $staleBefore  date time
     * @return bool true when the request was sent and not refused by the limit
     */
    private function recordCustomerPage(IfirmaReceipt $receipt, array $page, $anchor, $stamp, $staleBefore)
    {
        if ($page['rows'] === null) {
            if (!$this->client->lastRequestWasSent()) {
                $this->giveBackCustomerSync($anchor, $stamp, true);

                return false;
            }
            if ($page['rate_limited']) {
                return false;
            }
            $this->recordSyncError(array($receipt), $page['error']);

            return true;
        }

        $byId = $this->rowsByDocumentId($page['rows']);
        $viewedKey = (string) $receipt->ifirma_id;
        if (isset($byId[$viewedKey])) {
            $this->applyRow($receipt, $byId[$viewedKey]);
        } elseif (count($page['rows']) < self::PAGE_SIZE) {
            // A full page may hide the document on a later page; only a short page proves it is gone from iFirma.
            $this->applyRow($receipt, null);
        }
        $others = IfirmaReceipt::findStaleIssuedOfCustomerOnDate((int) $receipt->id_customer, (int) $receipt->id, $this->lookupDate($receipt), $staleBefore);
        foreach ($others as $other) {
            // The rows are one page: another document that is not among them is left to the cron.
            if (isset($byId[(string) $other->ifirma_id])) {
                $this->applyRow($other, $byId[(string) $other->ifirma_id]);
            }
        }

        return true;
    }

    /**
     * @param int $limit
     * @return IfirmaReceipt[]
     */
    public function selectForCron($limit = 500)
    {
        $staleBefore = date('Y-m-d H:i:s', $this->now() - self::STALE_HOURS * 3600);

        return IfirmaReceipt::findDueForSync($staleBefore, $limit);
    }

    /**
     * @return int orders held by pending data requests
     */
    public function countHeldOrders()
    {
        return IfirmaDataRequest::countOrdersOnHold();
    }

    /**
     * The cron summary of a run that did nothing.
     *
     * @param int $now unix time
     * @return array
     */
    public static function emptySummary($now)
    {
        return array(
            'receipts_checked' => 0, 'updated' => 0, 'marked_paid' => 0, 'marked_missing' => 0,
            'errors' => 0, 'rate_limited' => 0, 'calls' => 0,
            'dates_total' => 0, 'dates_processed' => 0, 'held_orders' => 0,
            'started_at' => date('c', (int) $now), 'finished_at' => '',
            'auth_blocked' => false, 'message' => '',
        );
    }

    /**
     * @param int|null $maxCalls
     * @return array
     */
    public function runCron($maxCalls = null)
    {
        if ($maxCalls === null) {
            $maxCalls = (int) Configuration::get('IFIRMA_CRON_MAX_CALLS');
            if ($maxCalls <= 0) {
                $maxCalls = 40;
            }
        }

        $summary = self::emptySummary($this->now());

        if ($this->client->isAuthBlocked()) {
            // iFirma rejected the credentials a moment ago: touch neither the receipts nor the API.
            $summary['auth_blocked'] = true;
            $summary['message'] = $this->client->getAuthBlockMessage();
            $summary['finished_at'] = date('c', $this->now());

            return $summary;
        }

        $groups = array();
        foreach ($this->selectForCron() as $receipt) {
            $groups[$this->lookupDate($receipt)][] = $receipt;
        }
        ksort($groups);
        $summary['dates_total'] = count($groups);

        foreach ($groups as $date => $receipts) {
            if ($summary['calls'] >= $maxCalls) {
                break;
            }
            $result = $this->syncByIssueDate((string) $date, $receipts);
            $summary['dates_processed']++;
            $summary['calls'] += $result['calls'];
            $summary['receipts_checked'] += $result['checked'];
            $summary['updated'] += $result['updated'];
            $summary['marked_paid'] += $result['paid'];
            $summary['marked_missing'] += $result['missing'];
            $summary['errors'] += $result['errors'];
            $summary['rate_limited'] += $result['rate_limited'];
            if ($result['rate_limited']) {
                break;
            }
        }

        $summary['held_orders'] = $this->countHeldOrders();
        $summary['finished_at'] = date('c', $this->now());

        return $summary;
    }

    /**
     * @param mixed $given
     * @return bool
     */
    public function isValidCronToken($given)
    {
        $token = (string) Configuration::get('IFIRMA_CRON_TOKEN');

        return $token !== '' && hash_equals($token, (string) $given);
    }
}
