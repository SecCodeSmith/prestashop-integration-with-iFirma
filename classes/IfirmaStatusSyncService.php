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

class IfirmaStatusSyncService
{
    const PAGE_SIZE = 100;
    const OPPORTUNISTIC_TIMEOUT = 5;
    const STALE_HOURS = 24;

    /** @var IfirmaApiClient */
    private $client;

    /** @var callable|null returns a unix timestamp */
    private $clock;

    /**
     * @param IfirmaApiClient $client
     * @param callable|null   $clock
     */
    public function __construct(IfirmaApiClient $client, $clock = null)
    {
        $this->client = $client;
        $this->clock = $clock;
    }

    /**
     * @param Module $module
     * @return IfirmaStatusSyncService
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
     * @return array rows (array|null), error, rate_limited, calls
     */
    public function fetchRowsForDate($date, $timeout = null)
    {
        $rows = array();
        $calls = 0;
        $page = 1;

        while (true) {
            $calls++;
            $batch = $this->client->listInvoices(array(
                'dataOd' => $date,
                'dataDo' => $date,
                'iloscNaStronie' => self::PAGE_SIZE,
                'strona' => $page,
            ), 'faktura', $timeout);

            if ($batch === null) {
                return array(
                    'rows' => null,
                    'error' => (string) $this->client->getLastError(),
                    'rate_limited' => (bool) $this->client->isRateLimited(),
                    'calls' => $calls,
                );
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
            $receipt->status = IfirmaReceipt::STATUS_PAID;
            $outcome = 'paid';
        }

        $receipt->sync_error = '';
        $receipt->last_sync_at = $now;
        $receipt->save();

        return $outcome;
    }

    /**
     * Summary keys: checked, updated (rows refreshed, incl. paid/reappeared),
     * paid (newly paid), missing, errors, calls, rate_limited, error.
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

                return $summary;
            }
            $now = date('Y-m-d H:i:s', $this->now());
            foreach ($receipts as $receipt) {
                $receipt->sync_error = $result['error'];
                $receipt->last_sync_at = $now;
                $receipt->save();
                $summary['errors']++;
            }

            return $summary;
        }

        $byId = array();
        foreach ($result['rows'] as $row) {
            if (is_array($row) && isset($row['FakturaId'])) {
                $byId[(string) $row['FakturaId']] = $row;
            }
        }

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
     * @param IfirmaReceipt $receipt
     * @return bool
     */
    public function shouldSyncOpportunistically(IfirmaReceipt $receipt)
    {
        if (!$receipt->isIssued()) {
            return false;
        }
        if (empty($receipt->last_sync_at) || $receipt->last_sync_at === '0000-00-00 00:00:00') {
            return true;
        }
        $interval = (int) Configuration::get('IFIRMA_SYNC_INTERVAL_MIN');
        if ($interval <= 0) {
            $interval = 15;
        }
        $last = strtotime((string) $receipt->last_sync_at);

        return $last === false || $last < $this->now() - $interval * 60;
    }

    /**
     * Never throws.
     *
     * @param IfirmaReceipt $receipt
     * @return bool true when a call was made
     */
    public function opportunisticSync(IfirmaReceipt $receipt)
    {
        try {
            if (!$this->shouldSyncOpportunistically($receipt)) {
                return false;
            }
            $summary = $this->syncReceipt($receipt, self::OPPORTUNISTIC_TIMEOUT);

            return $summary['calls'] > 0;
        } catch (Exception $e) {
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param int $limit
     * @return IfirmaReceipt[]
     */
    public function selectForCron($limit = 500)
    {
        $staleBefore = date('Y-m-d H:i:s', $this->now() - self::STALE_HOURS * 3600);
        $rows = Db::getInstance()->executeS(
            'SELECT id_ifirma_receipt FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE ifirma_id != \'\' AND status != \'error\'
             AND (status != \'paid\' OR remote_sent = 0 OR last_sync_at IS NULL
                  OR last_sync_at < \'' . pSQL($staleBefore) . '\')
             ORDER BY last_sync_at ASC, id_ifirma_receipt ASC
             LIMIT ' . max(1, (int) $limit)
        );

        $receipts = array();
        foreach ((array) $rows as $row) {
            $receipt = new IfirmaReceipt((int) $row['id_ifirma_receipt']);
            if (!empty($receipt->id)) {
                $receipts[] = $receipt;
            }
        }

        return $receipts;
    }

    /**
     * @return int orders held by pending data requests
     */
    public function countHeldOrders()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT id_order) FROM `' . _DB_PREFIX_ . 'ifirma_data_request`
             WHERE status = \'pending\''
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

        $summary = array(
            'receipts_checked' => 0, 'updated' => 0, 'marked_paid' => 0, 'marked_missing' => 0,
            'errors' => 0, 'rate_limited' => 0, 'calls' => 0,
            'dates_total' => 0, 'dates_processed' => 0, 'held_orders' => 0,
            'started_at' => date('c', $this->now()), 'finished_at' => '',
        );

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
