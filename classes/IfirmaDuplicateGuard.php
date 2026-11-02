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

/**
 * Duplicate guard: before a document is created, looks in iFirma for a document that
 * already belongs to the order (created by the module earlier, by iFirma's own
 * integrator, or manually).
 *
 * check() order: local issued record -> LOCAL_ISSUED (no request); guard disabled
 * (IFIRMA_DUPLICATE_CHECK=0) -> DISABLED; one-time 'bypass' option -> BYPASSED.
 * Otherwise lists faktury.json over [order date - 1 day, today] (page size 100, paged
 * until a short page): by contractor identifier (PS-C{id_customer}), then by NIP when
 * empty, then unfiltered when the window is within IFIRMA_DUPLICATE_WINDOW_DAYS (60).
 * Candidates (IfirmaDuplicateMatcher) are confirmed by finding the order reference in
 * the detail JSON: ADOPT (adopt(), source external), else SUSPECTED (markSuspected(),
 * status duplicate_suspected, nothing is created); no candidates -> PROCEED.
 * A failed list call -> INCONCLUSIVE (error + rateLimited). Admin resolution:
 * linkCandidate() or recordResolution($receipt, 'issue_anyway').
 * Options: bypass, enabled, tolerance (IFIRMA_DUPLICATE_AMOUNT_TOLERANCE, 0.01),
 * window_days, timeout, max_detail_calls (10).
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

        if (array_key_exists('enabled', $options)) {
            $enabled = (bool) $options['enabled'];
        } else {
            $cfg = Configuration::get('IFIRMA_DUPLICATE_CHECK');
            $enabled = !($cfg !== false && $cfg !== null && $cfg !== '' && !(int) $cfg);
        }
        if (!$enabled) {
            return new IfirmaGuardResult(IfirmaGuardResult::OUTCOME_DISABLED);
        }
        if (!empty($options['bypass'])) {
            return new IfirmaGuardResult(IfirmaGuardResult::OUTCOME_BYPASSED);
        }

        $tolerance = isset($options['tolerance']) ? (float) $options['tolerance'] : $this->configFloat('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', 0.01);
        $windowDays = isset($options['window_days']) ? (int) $options['window_days'] : (int) $this->configFloat('IFIRMA_DUPLICATE_WINDOW_DAYS', 60);
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : null;
        $maxDetail = isset($options['max_detail_calls']) ? (int) $options['max_detail_calls'] : 10;

        $now = (int) call_user_func($this->clock);
        $orderTs = strtotime((string) $order->date_add);
        if (!$orderTs) {
            $orderTs = $now;
        }
        $dataOd = date('Y-m-d', $orderTs - 86400);
        $dataDo = date('Y-m-d', $now);
        $days = (int) floor((strtotime($dataDo) - strtotime($dataOd)) / 86400);

        $result = new IfirmaGuardResult();
        $base = array('dataOd' => $dataOd, 'dataDo' => $dataDo, 'iloscNaStronie' => self::PAGE_SIZE);

        $queries = array();
        $queries[] = array('kontrahent' => IfirmaReceiptService::truncate(
            IfirmaReceiptService::CONTRACTOR_ID_PREFIX . (int) $order->id_customer,
            $this->contractorLimit()
        ));
        $nip = $this->orderNip($order);
        if ($nip !== '') {
            $queries[] = array('nipKontrahenta' => $nip);
        }
        if ($days <= $windowDays) {
            $queries[] = array();
        }

        $rows = array();
        foreach ($queries as $filter) {
            $rows = $this->listAll(array_merge($base, $filter), $timeout, $result);
            if ($rows === null) {
                $result->outcome = IfirmaGuardResult::OUTCOME_INCONCLUSIVE;
                $result->error = (string) $this->client->getLastError();
                $result->rateLimited = (bool) $this->client->isRateLimited();

                return $result;
            }
            if (!empty($rows)) {
                break;
            }
        }

        $currency = new Currency((int) $order->id_currency);
        $iso = !empty($currency->iso_code) ? $currency->iso_code : 'PLN';
        $candidates = IfirmaDuplicateMatcher::selectCandidates($rows, round((float) $order->total_paid_tax_incl, 2), $iso, $tolerance);
        if (empty($candidates)) {
            $result->outcome = IfirmaGuardResult::OUTCOME_PROCEED;

            return $result;
        }

        usort($candidates, function ($a, $b) {
            $da = isset($a['DataWystawienia']) ? (string) $a['DataWystawienia'] : '';
            $db = isset($b['DataWystawienia']) ? (string) $b['DataWystawienia'] : '';
            if ($da !== $db) {
                return strcmp($db, $da);
            }

            return (int) (isset($b['FakturaId']) ? $b['FakturaId'] : 0) - (int) (isset($a['FakturaId']) ? $a['FakturaId'] : 0);
        });

        $reference = IfirmaReceiptService::buildOrderReference($order);
        foreach (array_slice($candidates, 0, max(0, $maxDetail)) as $row) {
            $type = IfirmaDuplicateMatcher::rodzajToDocumentType(isset($row['Rodzaj']) ? $row['Rodzaj'] : '');
            $detail = $type ? $this->client->getDetail($type, (string) $row['FakturaId'], $timeout) : null;
            if ($detail === null) {
                $result->error = (string) $this->client->getLastError();
                continue;
            }
            if (IfirmaDuplicateMatcher::confirm($detail['raw'], $reference)) {
                $result->outcome = IfirmaGuardResult::OUTCOME_ADOPT;
                $result->candidate = $row;
                $result->error = '';
                $result->receipt = $this->adopt($order, $row);

                return $result;
            }
        }

        $result->outcome = IfirmaGuardResult::OUTCOME_SUSPECTED;
        $result->candidates = $candidates;
        $result->receipt = $this->markSuspected($order, $candidates);

        return $result;
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
        $tolerance = $this->configFloat('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', 0.01);
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
        $r->status = ($paid >= $gross - $tolerance) ? IfirmaReceipt::STATUS_PAID : IfirmaReceipt::STATUS_CREATED;
        $r->delivery_status = IfirmaReceipt::DELIVERY_SKIPPED;
        $r->delivery_channel = '';
        $r->delivery_error = 'external document - not auto-delivered';
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
        $r->response_payload = json_encode($row);
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
        $r->status = IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED;
        $r->ifirma_id = '';
        $r->source = IfirmaReceipt::SOURCE_MODULE;
        $r->order_reference = IfirmaReceiptService::buildOrderReference($order);
        $r->last_error = 'Suspected duplicate: ' . count($summaries) . ' matching document(s) found in iFirma';
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
