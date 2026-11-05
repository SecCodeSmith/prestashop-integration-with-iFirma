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
require_once dirname(__FILE__) . '/IfirmaDuplicateMatcher.php';
require_once dirname(__FILE__) . '/IfirmaGuardResult.php';
require_once dirname(__FILE__) . '/IfirmaGuardSettings.php';
require_once dirname(__FILE__) . '/IfirmaContractorMap.php';

/**
 * Duplicate guard: before a document is created, looks in iFirma for a document that
 * already belongs to the order (created by the module earlier, by iFirma's own
 * integrator, or manually).
 *
 * check() order: local issued record -> LOCAL_ISSUED (no request); guard disabled
 * (IFIRMA_DUPLICATE_CHECK=0) -> DISABLED; one-time 'bypass' option -> BYPASSED.
 * Otherwise lists faktury.json over [order date - 1 day, today] (page size 100, paged
 * until a short page): by contractor identifier (PS-C{id_customer}), then by NIP when
 * empty, then unfiltered when the window is within IFIRMA_DUPLICATE_WINDOW_DAYS.
 * Candidates are the rows of the order's buyer (IfirmaDuplicateMatcher::filterByBuyer():
 * contractor identifier, NIP or buyer name) that pass selectCandidates() (type, draft,
 * currency, gross). Each candidate's detail (newest first, max_detail_calls) is read: the
 * order reference in it -> ADOPT (adopt(), source external); otherwise verifyDetail()
 * compares DataSprzedazy and Pozycje with the order's sale date and product lines: a
 * mismatch drops the candidate, a match or an unreadable/incomplete detail keeps it.
 * Survivors -> SUSPECTED (markSuspected(), status duplicate_suspected, nothing is
 * created); none -> PROCEED.
 * A failed list call -> INCONCLUSIVE (error + rateLimited). Admin resolution:
 * linkCandidate() or recordResolution($receipt, 'issue_anyway').
 * Options: bypass, enabled, tolerance, window_days, timeout, max_detail_calls. Each falls back to its
 * configuration key (IFIRMA_DUPLICATE_CHECK, IFIRMA_DUPLICATE_AMOUNT_TOLERANCE,
 * IFIRMA_DUPLICATE_WINDOW_DAYS) and then to a default: see IfirmaGuardSettings.
 *
 * After the early answers check() is three steps: resolveSettings() resolves the options and the
 * configuration once into an IfirmaGuardSettings; scanRemote() makes every call to iFirma (the list
 * queries, the narrowing of their rows to candidates, the details of the candidates); classify()
 * decides on what was scanned and is pure (no Db, iFirma call, configuration or clock). adopt() and
 * markSuspected() are the only writes.
 */
class IfirmaDuplicateGuard
{
    const PAGE_SIZE = 100;
    const MAX_PAGES = 50;

    /** @var IfirmaApiClient */
    private $client;

    /** @var callable */
    private $clock;

    /**
     * @param IfirmaApiClient $client
     * @param callable|null   $clock returns a unix timestamp
     */
    public function __construct(IfirmaApiClient $client, $clock = null)
    {
        $this->client = $client;
        $this->clock = $clock ?: 'time';
    }

    /**
     * @param Module $module
     * @return IfirmaDuplicateGuard
     */
    public static function build(Module $module)
    {
        $client = IfirmaApiClient::fromConfiguration();

        return new self($client);
    }

    /**
     * @param Order $order
     * @param array $options
     * @return IfirmaGuardResult
     */
    public function check(Order $order, array $options = array())
    {
        $local = IfirmaReceipt::getByOrder((int) $order->id);
        if ($local && $local->isIssued()) {
            $r = new IfirmaGuardResult(IfirmaGuardResult::OUTCOME_LOCAL_ISSUED);
            $r->receipt = $local;

            return $r;
        }

        $settings = $this->resolveSettings($options);
        if (!$settings->isEnabled()) {
            return new IfirmaGuardResult(IfirmaGuardResult::OUTCOME_DISABLED);
        }
        if ($settings->isBypassed()) {
            return new IfirmaGuardResult(IfirmaGuardResult::OUTCOME_BYPASSED);
        }

        $result = new IfirmaGuardResult();
        $scan = $this->scanRemote($order, $settings, $result);
        if ($scan === null) {
            return $result;
        }

        $decision = self::classify($settings, $scan);
        $result->outcome = $decision['outcome'];
        $result->candidate = $decision['candidate'];
        $result->candidates = $decision['candidates'];
        $result->error = $decision['error'];
        if ($result->outcome === IfirmaGuardResult::OUTCOME_ADOPT) {
            $result->receipt = $this->adopt($order, $result->candidate);
        } elseif ($result->outcome === IfirmaGuardResult::OUTCOME_SUSPECTED) {
            $result->receipt = $this->markSuspected($order, $result->candidates);
        }

        return $result;
    }

    /**
     * The options of a check() call and the configuration they fall back to, resolved once: an
     * option wins, then the configuration (a key that is not set, or empty, counts as unset), then
     * the default of IfirmaGuardSettings. The settings take the values as they are and cast them.
     *
     * @param array $options bypass, enabled, tolerance, window_days, timeout, max_detail_calls
     * @return IfirmaGuardSettings
     */
    public function resolveSettings(array $options)
    {
        if (array_key_exists('enabled', $options)) {
            $enabled = $options['enabled'];
        } else {
            $cfg = Configuration::get('IFIRMA_DUPLICATE_CHECK');
            $enabled = ($cfg === false || $cfg === null || $cfg === '') ? IfirmaGuardSettings::DEFAULT_ENABLED : (int) $cfg;
        }
        $tolerance = isset($options['tolerance']) ? $options['tolerance'] : $this->configFloat('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', IfirmaGuardSettings::DEFAULT_AMOUNT_TOLERANCE);
        $windowDays = isset($options['window_days']) ? $options['window_days'] : $this->configFloat('IFIRMA_DUPLICATE_WINDOW_DAYS', IfirmaGuardSettings::DEFAULT_WINDOW_DAYS);
        $timeout = isset($options['timeout']) ? $options['timeout'] : null;
        $maxDetail = isset($options['max_detail_calls']) ? $options['max_detail_calls'] : IfirmaGuardSettings::DEFAULT_MAX_DETAIL_CALLS;

        return new IfirmaGuardSettings($enabled, !empty($options['bypass']), $tolerance, $windowDays, $timeout, $maxDetail);
    }

    /**
     * The candidates among the rows a list query returned: the rows of the order's buyer that are
     * a document the module can produce, not a draft, in the currency of the order and within the
     * tolerance of its gross (IfirmaDuplicateMatcher::filterByBuyer() and selectCandidates()),
     * newest issue date first and the higher document id first among the same day. Pure.
     *
     * @param IfirmaGuardSettings $settings only the tolerance matters
     * @param array               $rows list rows
     * @param array               $buyer identifier (string), nip (string), names (string[])
     * @param float               $orderGross gross of the order, rounded to groszy
     * @param string              $currencyIso ISO code of the currency of the order
     * @return array list rows, each with `_matched_by`
     */
    public static function candidatesFrom(IfirmaGuardSettings $settings, array $rows, array $buyer, $orderGross, $currencyIso)
    {
        $rows = IfirmaDuplicateMatcher::filterByBuyer($rows, $buyer);
        $candidates = IfirmaDuplicateMatcher::selectCandidates($rows, $orderGross, $currencyIso, $settings->getTolerance());
        usort($candidates, function ($a, $b) {
            $da = isset($a['DataWystawienia']) ? (string) $a['DataWystawienia'] : '';
            $db = isset($b['DataWystawienia']) ? (string) $b['DataWystawienia'] : '';
            if ($da !== $db) {
                return strcmp($db, $da);
            }

            return (int) (isset($b['FakturaId']) ? $b['FakturaId'] : 0) - (int) (isset($a['FakturaId']) ? $a['FakturaId'] : 0);
        });

        return $candidates;
    }

    /**
     * The decision on a scan: which candidate is the order's document (ADOPT), which stay suspected
     * (SUSPECTED), or that none does (PROCEED). Pure: it needs no database, no call to iFirma, no
     * configuration and no clock, only the scan and the settings.
     *
     * The candidates are taken in order. One the settings do not read the detail of stays suspected,
     * unverified; so does one whose detail could not be read, and the error is kept. The first detail
     * that carries the order reference decides: ADOPT. Any other detail is verified against the sale
     * date and the product lines of the order (IfirmaDuplicateMatcher::verifyDetail()): a mismatch
     * drops the candidate, a match or an incomplete detail keeps it with the sale date it records.
     *
     * @param IfirmaGuardSettings $settings only the cap on the details read matters
     * @param array               $scan candidates (list rows, newest first), reads (readDetails(): an
     *                                  entry per candidate within the cap, up to the first confirmed
     *                                  one), reference of the order, its sale_date (Y-m-d) and its
     *                                  lines (name, quantity)
     * @return array outcome (OUTCOME_ADOPT, OUTCOME_SUSPECTED or OUTCOME_PROCEED), candidate (the adopted
     *               row as listed, else null), candidates (the rows that stay suspected, each with its
     *               `_sale_date`, '' when unverified; else empty), error (what the client said about the
     *               last detail that could not be read; empty after an adoption and for PROCEED)
     */
    public static function classify(IfirmaGuardSettings $settings, array $scan)
    {
        $kept = array();
        $error = '';
        foreach ($scan['candidates'] as $index => $row) {
            if (!$settings->readsDetailOf($index)) {
                // Beyond the per-run detail cap: cannot be verified, so it stays suspected.
                $row['_sale_date'] = '';
                $kept[] = $row;
                continue;
            }
            $read = $scan['reads'][$index];
            if ($read['detail'] === null) {
                $error = $read['error'];
                $row['_sale_date'] = '';
                $kept[] = $row;
                continue;
            }
            if (IfirmaDuplicateMatcher::confirm($read['detail']['raw'], $scan['reference'])) {
                return self::decision(IfirmaGuardResult::OUTCOME_ADOPT, $row, array(), '');
            }
            $verdict = IfirmaDuplicateMatcher::verifyDetail(self::detailNode($read['detail']), $scan['sale_date'], $scan['lines']);
            if ($verdict['matched'] === false) {
                // Same buyer and amount, but another sale date or other products: not this order's document.
                continue;
            }
            $row['_sale_date'] = $verdict['sale_date'];
            $kept[] = $row;
        }

        if (empty($kept)) {
            return self::decision(IfirmaGuardResult::OUTCOME_PROCEED, null, array(), '');
        }

        return self::decision(IfirmaGuardResult::OUTCOME_SUSPECTED, null, $kept, $error);
    }

    /**
     * Adopt an existing iFirma document as the order receipt.
     *
     * @param Order $order
     * @param array $row list row
     * @param array $resolution merged into the stored resolution node
     * @return IfirmaReceipt
     */
    public function adopt(Order $order, array $row, $resolution = array())
    {
        $r = $this->reuseOrCreate($order);
        $gross = isset($row['Brutto']) ? (float) $row['Brutto'] : 0.0;
        $paid = isset($row['Zaplacono']) ? (float) $row['Zaplacono'] : 0.0;
        $tolerance = $this->configFloat('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', IfirmaGuardSettings::DEFAULT_AMOUNT_TOLERANCE);
        $type = IfirmaDuplicateMatcher::rodzajToDocumentType(isset($row['Rodzaj']) ? $row['Rodzaj'] : '');

        $r->ifirma_id = (string) $row['FakturaId'];
        $r->ifirma_number = isset($row['PelnyNumer']) ? (string) $row['PelnyNumer'] : '';
        $r->document_type = $type ?: 'fakturakraj';
        $r->source = IfirmaReceipt::SOURCE_EXTERNAL;
        $r->issue_date = isset($row['DataWystawienia']) ? $row['DataWystawienia'] : null;
        $r->order_reference = IfirmaReceiptService::buildOrderReference($order);
        $r->paid_amount = $paid;
        $r->remote_paid_amount = $paid;
        $r->remote_sent = !empty($row['CzyWyslano']) ? 1 : 0;
        $r->remote_draft = !empty($row['CzySzkic']) ? 1 : 0;
        if ($paid >= $gross - $tolerance) {
            $r->markPaid();
        } else {
            $r->status = IfirmaReceipt::STATUS_CREATED;
        }
        $r->recordDelivery(IfirmaReceipt::DELIVERY_SKIPPED, '', 'external document - not auto-delivered');
        $r->last_error = '';
        if (empty($r->security_token)) {
            $r->security_token = IfirmaReceiptService::generateSecurityToken();
        }

        $res = array_merge(
            array('action' => 'adopt', 'id_employee' => null, 'at' => date('Y-m-d H:i:s', (int) call_user_func($this->clock))),
            is_array($resolution) ? $resolution : array()
        );
        $r->duplicate_candidates = json_encode(array(
            'candidates' => array(IfirmaDuplicateMatcher::summarize($row)),
            'resolution' => $res,
        ));
        $public = array();
        foreach ($row as $key => $value) {
            if (strpos((string) $key, '_') !== 0) {
                $public[$key] = $value;
            }
        }
        $r->response_payload = json_encode($public);
        $r->save();

        return $r;
    }

    /**
     * @param Order $order
     * @param array $candidates list rows
     * @return IfirmaReceipt
     */
    public function markSuspected(Order $order, array $candidates)
    {
        $r = $this->reuseOrCreate($order);
        $summaries = array();
        foreach ($candidates as $row) {
            $summaries[] = IfirmaDuplicateMatcher::summarize($row);
        }
        $r->markSuspected('Suspected duplicate: ' . count($summaries) . ' matching document(s) found in iFirma');
        $r->order_reference = IfirmaReceiptService::buildOrderReference($order);
        $r->duplicate_candidates = json_encode(array('candidates' => $summaries, 'resolution' => null));
        $r->save();

        return $r;
    }

    /**
     * @param IfirmaReceipt $receipt
     * @param string        $action 'link' or 'issue_anyway'
     * @param int|null      $idEmployee
     * @return IfirmaReceipt
     */
    public function recordResolution(IfirmaReceipt $receipt, $action, $idEmployee = null)
    {
        $data = json_decode((string) $receipt->duplicate_candidates, true);
        if (!is_array($data)) {
            $data = array('candidates' => array());
        }
        $data['resolution'] = array(
            'action' => (string) $action,
            'id_employee' => $idEmployee === null ? null : (int) $idEmployee,
            'at' => date('Y-m-d H:i:s', (int) call_user_func($this->clock)),
        );
        $receipt->duplicate_candidates = json_encode($data);
        $receipt->save();

        return $receipt;
    }

    /**
     * @param Order         $order
     * @param IfirmaReceipt $receipt record holding the stored candidates
     * @param string|int    $fakturaId
     * @param int|null      $idEmployee
     * @return IfirmaReceipt
     * @throws Exception when the id is not among the stored candidates
     */
    public function linkCandidate(Order $order, IfirmaReceipt $receipt, $fakturaId, $idEmployee)
    {
        $data = json_decode((string) $receipt->duplicate_candidates, true);
        $found = null;
        if (is_array($data) && !empty($data['candidates'])) {
            foreach ($data['candidates'] as $c) {
                if (isset($c['id']) && (string) $c['id'] === (string) $fakturaId) {
                    $found = $c;
                    break;
                }
            }
        }
        if ($found === null) {
            throw new Exception('Candidate ' . $fakturaId . ' not found for this order.');
        }

        $row = array(
            'FakturaId' => $found['id'],
            'PelnyNumer' => isset($found['number']) ? $found['number'] : '',
            'DataWystawienia' => isset($found['date']) ? $found['date'] : null,
            'Brutto' => isset($found['gross']) ? $found['gross'] : 0,
            'Zaplacono' => isset($found['paid']) ? $found['paid'] : 0,
            'KontrahentNazwa' => isset($found['contractor']) ? $found['contractor'] : '',
            'Rodzaj' => isset($found['rodzaj']) ? $found['rodzaj'] : '',
            'Waluta' => isset($found['currency']) ? $found['currency'] : 'PLN',
            'CzyWyslano' => !empty($found['sent']),
        );

        return $this->adopt($order, $row, array(
            'action' => 'link',
            'id_employee' => $idEmployee === null ? null : (int) $idEmployee,
        ));
    }

    /**
     * Everything check() has to ask iFirma, in the order it asks it: the list queries of the search
     * window, then the detail of the candidates among their rows (newest first, as many as the
     * settings allow, none after one that carries the order reference). It decides nothing but whom
     * to ask; classify() judges the answers.
     *
     * @param Order               $order
     * @param IfirmaGuardSettings $settings
     * @param IfirmaGuardResult   $result counts the list calls; takes the INCONCLUSIVE outcome when one fails
     * @return array|null the scan classify() takes: candidates, reads, reference, sale_date and lines
     *                    (reference, sale_date and lines stay empty without candidates); null when a
     *                    list call failed
     */
    private function scanRemote(Order $order, IfirmaGuardSettings $settings, IfirmaGuardResult $result)
    {
        $window = self::searchWindow($order->date_add, (int) call_user_func($this->clock));
        $buyer = array(
            'identifier' => $this->contractorFilter($order),
            'nip' => $this->orderNip($order),
            'names' => IfirmaReceiptService::buyerNames($order),
        );
        $rows = $this->listWindow($window, $buyer, $settings, $result);
        if ($rows === null) {
            return null;
        }

        $currency = new Currency((int) $order->id_currency);
        $iso = !empty($currency->iso_code) ? $currency->iso_code : 'PLN';
        $gross = round((float) $order->total_paid_tax_incl, 2);
        $scan = array(
            'candidates' => self::candidatesFrom($settings, $rows, $buyer, $gross, $iso),
            'reads' => array(),
            'reference' => '',
            'sale_date' => '',
            'lines' => array(),
        );
        if (!empty($scan['candidates'])) {
            $scan['reference'] = IfirmaReceiptService::buildOrderReference($order);
            $scan['sale_date'] = IfirmaReceiptService::orderSaleDate($order);
            $scan['lines'] = IfirmaReceiptService::orderProductLines($order);
            $scan['reads'] = $this->readDetails($scan, $settings);
        }

        return $scan;
    }

    /**
     * @param mixed $orderDate date_add of the order (any strtotime() text); empty or unreadable counts as $now
     * @param int   $now       unix timestamp
     * @return array dataOd (the day before the order) and dataDo (the day of $now) as Y-m-d, and the
     *               days between the two
     */
    private static function searchWindow($orderDate, $now)
    {
        $orderTs = strtotime((string) $orderDate);
        if (!$orderTs) {
            $orderTs = $now;
        }
        $dataOd = date('Y-m-d', $orderTs - 86400);
        $dataDo = date('Y-m-d', $now);

        return array(
            'dataOd' => $dataOd,
            'dataDo' => $dataDo,
            'days' => (int) floor((strtotime($dataDo) - strtotime($dataOd)) / 86400),
        );
    }

    /**
     * Lists the documents of the window by contractor identifier, then by NIP when that finds none,
     * then unfiltered when the window is within the window_days of the settings: the rows of the
     * first query that returns any.
     *
     * @param array               $window dataOd, dataDo and days (searchWindow())
     * @param array               $buyer identifier, nip
     * @param IfirmaGuardSettings $settings
     * @param IfirmaGuardResult   $result counts the list calls; takes the INCONCLUSIVE outcome when one fails
     * @return array|null rows (empty when no query found any); null when a list call failed
     */
    private function listWindow(array $window, array $buyer, IfirmaGuardSettings $settings, IfirmaGuardResult $result)
    {
        $base = array('dataOd' => $window['dataOd'], 'dataDo' => $window['dataDo'], 'iloscNaStronie' => self::PAGE_SIZE);
        $queries = array();
        $queries[] = array('kontrahent' => $buyer['identifier']);
        if ($buyer['nip'] !== '') {
            $queries[] = array('nipKontrahenta' => $buyer['nip']);
        }
        if ($window['days'] <= $settings->getWindowDays()) {
            $queries[] = array();
        }

        $rows = array();
        foreach ($queries as $filter) {
            $rows = $this->listAll(array_merge($base, $filter), $settings->getTimeout(), $result);
            if ($rows === null && $filter && $this->filterValueUnknown()) {
                // iFirma answers Kod 201 "Nie znaleziono kontrahenta..." when the filter names a
                // contractor it does not have. No document of that contractor can exist, so this
                // query is conclusively empty; the next query still runs.
                $rows = array();
                continue;
            }
            if ($rows === null) {
                $result->outcome = IfirmaGuardResult::OUTCOME_INCONCLUSIVE;
                $result->error = (string) $this->client->getLastError();
                $result->rateLimited = (bool) $this->client->isRateLimited();

                return null;
            }
            if (!empty($rows)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Reads, newest first, the detail of each candidate the settings allow, and stops after the first
     * detail that carries the order reference: a confirmed document needs no further proof.
     *
     * @param array               $scan candidates and reference
     * @param IfirmaGuardSettings $settings
     * @return array per candidate read, keyed like the candidates: detail (getDetail() result, null
     *               when it could not be read) and error (what the client said then, else empty)
     */
    private function readDetails(array $scan, IfirmaGuardSettings $settings)
    {
        $reads = array();
        foreach ($scan['candidates'] as $index => $row) {
            if (!$settings->readsDetailOf($index)) {
                break;
            }
            $type = IfirmaDuplicateMatcher::rodzajToDocumentType(isset($row['Rodzaj']) ? $row['Rodzaj'] : '');
            $detail = $type ? $this->client->getDetail($type, (string) $row['FakturaId'], $settings->getTimeout()) : null;
            $reads[$index] = array('detail' => $detail, 'error' => $detail === null ? (string) $this->client->getLastError() : '');
            if ($detail !== null && IfirmaDuplicateMatcher::confirm($detail['raw'], $scan['reference'])) {
                break;
            }
        }

        return $reads;
    }

    /**
     * @param string     $outcome
     * @param array|null $candidate
     * @param array      $candidates
     * @param string     $error
     * @return array the decision classify() returns
     */
    private static function decision($outcome, $candidate, array $candidates, $error)
    {
        return array('outcome' => $outcome, 'candidate' => $candidate, 'candidates' => $candidates, 'error' => $error);
    }

    /**
     * @param array             $params
     * @param int|null          $timeout
     * @param IfirmaGuardResult $result counts the list calls
     * @return array|null all rows or null on failure
     */
    private function listAll(array $params, $timeout, IfirmaGuardResult $result)
    {
        $all = array();
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $p = $params;
            if ($page > 1) {
                $p['strona'] = $page;
            }
            ++$result->queries;
            $rows = $this->client->listInvoices($p, 'faktura', $timeout);
            if ($rows === null) {
                return null;
            }
            $all = array_merge($all, $rows);
            if (count($rows) < self::PAGE_SIZE) {
                break;
            }
        }

        return $all;
    }

    /**
     * @param array $detail getDetail() result (raw + data)
     * @return array the document node of the decoded detail (empty when undecodable)
     */
    private static function detailNode(array $detail)
    {
        $data = (isset($detail['data']) && is_array($detail['data'])) ? $detail['data'] : array();

        return (isset($data['response']) && is_array($data['response'])) ? $data['response'] : $data;
    }

    /**
     * @param Order $order
     * @return IfirmaReceipt
     */
    private function reuseOrCreate(Order $order)
    {
        $r = IfirmaReceipt::getByOrder((int) $order->id);
        if (!$r || $r->isIssued()) {
            $r = new IfirmaReceipt();
        }
        $r->id_order = (int) $order->id;
        $r->id_customer = (int) $order->id_customer;
        $r->id_shop = (int) $order->id_shop;
        $r->order_reference = IfirmaReceiptService::buildOrderReference($order);

        return $r;
    }

    /**
     * @param Order $order
     * @return string digits of the NIP on the invoice address, '' when none
     */
    private function orderNip(Order $order)
    {
        $address = new Address((int) $order->id_address_invoice);
        foreach (array('vat_number', 'dni') as $field) {
            if (!empty($address->{$field})) {
                $digits = preg_replace('/\D+/', '', (string) $address->{$field});
                if ($digits !== '') {
                    return $digits;
                }
            }
        }

        return '';
    }

    /**
     * @return int
     */
    private function contractorLimit()
    {
        $limits = IfirmaReceiptService::fieldLimits();

        return isset($limits['Identyfikator']) ? (int) $limits['Identyfikator'] : 15;
    }

    /**
     * The contractor identifier the module would reference for this customer: the stored mapping
     * when there is one (an existing iFirma contractor), otherwise the module identifier PS-C{id}.
     *
     * @param Order $order
     * @return string
     */
    private function contractorFilter(Order $order)
    {
        $idCustomer = (int) $order->id_customer;
        try {
            $map = IfirmaContractorMap::getByCustomer($idCustomer);
            if ($map && trim((string) $map->ifirma_identifier) !== '') {
                return trim((string) $map->ifirma_identifier);
            }
        } catch (Exception $e) {
            // mapping unavailable: fall back to the module identifier
        }

        return IfirmaReceiptService::truncate(
            IfirmaReceiptService::CONTRACTOR_ID_PREFIX . $idCustomer,
            $this->contractorLimit()
        );
    }

    /**
     * True when the last list call was refused because the filter named a contractor iFirma does
     * not know (Kod 201 whose message mentions the contractor), as opposed to a transport, auth or
     * other validation failure.
     *
     * @return bool
     */
    private function filterValueUnknown()
    {
        return (int) $this->client->getLastKod() === 201
            && mb_stripos((string) $this->client->getLastError(), 'kontrahent') !== false;
    }

    /**
     * @param string $key
     * @param float  $default
     * @return float
     */
    private function configFloat($key, $default)
    {
        $v = Configuration::get($key);

        return ($v === false || $v === null || $v === '') ? $default : (float) $v;
    }
}
