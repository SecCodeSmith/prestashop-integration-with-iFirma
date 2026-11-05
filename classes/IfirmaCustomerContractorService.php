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
require_once dirname(__FILE__) . '/IfirmaContractorMap.php';
require_once dirname(__FILE__) . '/IfirmaContractorService.php';
require_once dirname(__FILE__) . '/IfirmaReceipt.php';

/**
 * Customer-level view of the iFirma contractor: link state, local and remote document counts,
 * contractor search with e-mail/phone ranking, manual link and unlink.
 *
 * The service never translates: it returns codes and raw messages, the admin-actions layer
 * turns them into text. It never changes the stored link except in link() and unlink().
 */
class IfirmaCustomerContractorService
{
    /** Search phrases sent per search at most. */
    const MAX_PHRASES = 4;
    /** List calls one remote count may make. */
    const REMOTE_CALL_BUDGET = 20;
    /** iloscNaStronie of every list call. */
    const REMOTE_PAGE_SIZE = 100;
    /** How far back the remote count looks at most. */
    const REMOTE_MAX_YEARS = 10;
    /** Longest window of one list call, in days. */
    const REMOTE_WINDOW_DAYS = 365;
    /** Longest contractor identifier the map stores. */
    const IDENTIFIER_MAX = 64;
    /** Identifier the module gives a contractor it registers (PS-C<id_customer>). */
    const IDENTIFIER_PREFIX = 'PS-C';

    /** Receipt statuses counted as issued (the paid ones are a subset). */
    const STATES_ISSUED = array(IfirmaReceipt::STATUS_CREATED, IfirmaReceipt::STATUS_PAID);
    const STATES_PAID = array(IfirmaReceipt::STATUS_PAID);
    const STATES_HELD = array(IfirmaReceipt::STATUS_HELD);
    const STATES_ATTENTION = array(
        IfirmaReceipt::STATUS_ERROR, IfirmaReceipt::STATUS_UNKNOWN, IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED,
    );

    const TYPE_RECEIPT = 'receipt';
    const TYPE_INVOICE = 'invoice';
    const TYPE_OTHER = 'other';
    /** document_type prefixes that count as a receipt. */
    const RECEIPT_TYPE_PREFIXES = array('paragon', 'rachunek');
    /** document_type of a domestic invoice. */
    const INVOICE_TYPE = 'fakturakraj';

    const CHECK_REGISTERED = 'registered';
    const CHECK_MISSING = 'missing';
    const CHECK_ERROR = 'error';

    const LINK_LINKED = 'linked';
    const LINK_NOT_FOUND = 'not_found';
    const LINK_ERROR = 'error';
    const LINK_INVALID = 'invalid';

    /** @var IfirmaApiClient */
    private $client;

    /** @var IfirmaContractorService */
    private $contractors;

    /** @var callable returns the current unix timestamp */
    private $clock;

    /**
     * @param IfirmaApiClient         $client
     * @param IfirmaContractorService $contractors
     * @param callable|null           $clock unix timestamp provider (default time())
     */
    public function __construct(IfirmaApiClient $client, IfirmaContractorService $contractors, $clock = null)
    {
        $this->client = $client;
        $this->contractors = $contractors;
        $this->clock = $clock;
    }

    /**
     * @param Module $module
     * @return IfirmaCustomerContractorService
     */
    public static function build(Module $module)
    {
        $client = IfirmaApiClient::fromConfiguration();

        return new self($client, new IfirmaContractorService($client));
    }

    // ---- local state ----------------------------------------------------------------------

    /**
     * @param int $idCustomer
     * @return array identifier, linked, name, matched_by
     */
    public function linkState($idCustomer)
    {
        $map = $this->mapping($idCustomer);
        if ($map && (string) $map->ifirma_identifier !== '') {
            return array(
                'identifier' => (string) $map->ifirma_identifier,
                'linked' => true,
                'name' => (string) $map->ifirma_name,
                'matched_by' => (string) $map->matched_by,
            );
        }

        return array(
            'identifier' => self::IDENTIFIER_PREFIX . (int) $idCustomer,
            'linked' => false,
            'name' => '',
            'matched_by' => '',
        );
    }

    /**
     * @param int $idCustomer
     * @return array issued, paid, held, attention, by_type (issued documents only)
     */
    public function localCounts($idCustomer)
    {
        $out = array(
            'issued' => 0,
            'paid' => 0,
            'held' => 0,
            'attention' => 0,
            'by_type' => array(self::TYPE_RECEIPT => 0, self::TYPE_INVOICE => 0, self::TYPE_OTHER => 0),
        );
        try {
            $rows = IfirmaReceipt::countByCustomer((int) $idCustomer);
        } catch (Exception $e) {
            $rows = array();
        }
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $n = (int) $row['total'];
            if (in_array($status, self::STATES_ISSUED, true)) {
                $out['issued'] += $n;
                $out['by_type'][self::localType((string) $row['document_type'])] += $n;
            }
            if (in_array($status, self::STATES_PAID, true)) {
                $out['paid'] += $n;
            }
            if (in_array($status, self::STATES_HELD, true)) {
                $out['held'] += $n;
            }
            if (in_array($status, self::STATES_ATTENTION, true)) {
                $out['attention'] += $n;
            }
        }

        return $out;
    }

    /**
     * @param string $documentType
     * @return string receipt|invoice|other
     */
    public static function localType($documentType)
    {
        foreach (self::RECEIPT_TYPE_PREFIXES as $prefix) {
            if (strncmp($documentType, $prefix, strlen($prefix)) === 0) {
                return self::TYPE_RECEIPT;
            }
        }

        return $documentType === self::INVOICE_TYPE ? self::TYPE_INVOICE : self::TYPE_OTHER;
    }

    /**
     * @param string $rodzaj Rodzaj of an iFirma list row
     * @return string receipt|invoice|other
     */
    public static function remoteType($rodzaj)
    {
        $rodzaj = strtolower((string) $rodzaj);
        if (strpos($rodzaj, 'paragon') !== false || strpos($rodzaj, 'rachunek') !== false) {
            return self::TYPE_RECEIPT;
        }
        if (strpos($rodzaj, 'faktura_kraj') !== false || strpos($rodzaj, 'fakturakraj') !== false) {
            return self::TYPE_INVOICE;
        }

        return self::TYPE_OTHER;
    }

    /**
     * Search form defaults from the customer and the latest invoice address.
     *
     * @param int $idCustomer
     * @return array firstname, lastname, company, nip, email, phone
     */
    public function defaultCriteria($idCustomer)
    {
        $customer = new Customer((int) $idCustomer);
        $addresses = $this->invoiceAddresses($idCustomer);
        $address = $addresses ? $addresses[0] : null;

        $company = trim((string) ($address ? $address->company : ''));
        if ($company === '') {
            $company = trim((string) $customer->company);
        }
        $phone = '';
        $nip = '';
        if ($address) {
            $phone = trim((string) $address->phone);
            if ($phone === '') {
                $phone = trim((string) $address->phone_mobile);
            }
            $nip = preg_replace('/\D+/', '', (string) $address->vat_number);
        }
        if ($nip === '') {
            $nip = $this->customerNip($idCustomer);
        }

        return array(
            'firstname' => trim((string) $customer->firstname),
            'lastname' => trim((string) $customer->lastname),
            'company' => $company,
            'nip' => (string) $nip,
            'email' => trim((string) $customer->email),
            'phone' => $phone,
        );
    }

    /**
     * NIP of the latest invoice address that carries one.
     *
     * @param int $idCustomer
     * @return string 10 digits or ''
     */
    public function customerNip($idCustomer)
    {
        foreach ($this->invoiceAddresses($idCustomer) as $address) {
            $digits = preg_replace('/\D+/', '', (string) $address->vat_number);
            if (strlen($digits) === 10) {
                return $digits;
            }
        }

        return '';
    }

    // ---- live registration check ----------------------------------------------------------

    /**
     * One lookup of the shown identifier. Never changes the mapping.
     *
     * @param int $idCustomer
     * @return array state (registered|missing|error), identifier, name, message
     */
    public function check($idCustomer)
    {
        $state = $this->linkState($idCustomer);
        $out = array('state' => self::CHECK_ERROR, 'identifier' => $state['identifier'], 'name' => '', 'message' => '');

        $row = $this->contractors->fetch($state['identifier']);
        if (is_array($row)) {
            $out['state'] = self::CHECK_REGISTERED;
            $out['name'] = isset($row['Nazwa']) ? (string) $row['Nazwa'] : '';
        } elseif ($row === null) {
            $out['state'] = self::CHECK_MISSING;
        } else {
            $out['message'] = (string) $this->client->getLastError();
        }

        return $out;
    }

    // ---- search ---------------------------------------------------------------------------

    /**
     * @param array $criteria firstname, lastname, company, nip, email, phone
     * @return array refused, rows, errors (phrase => message), phrases
     */
    public function searchCandidates(array $criteria)
    {
        $c = $this->normalizeCriteria($criteria);
        $phrases = self::buildPhrases($c);
        $out = array('refused' => false, 'reason' => '', 'rows' => array(), 'errors' => array(), 'phrases' => $phrases);
        if (!$phrases) {
            $out['refused'] = true;
            $out['reason'] = 'no_search_key';

            return $out;
        }

        $merged = array();
        foreach ($phrases as $phrase) {
            $found = $this->contractors->search($phrase);
            $error = $found->getError();
            if ($error !== '') {
                $out['errors'][$phrase] = $error;
            }
            foreach ($found->getRows() as $row) {
                $identifier = isset($row['Identyfikator']) ? trim((string) $row['Identyfikator']) : '';
                if ($identifier === '' || isset($merged[$identifier])) {
                    continue;
                }
                $merged[$identifier] = $this->candidateRow($identifier, $row, $c);
            }
        }

        $rows = array_values($merged);
        usort($rows, array(__CLASS__, 'compareCandidates'));
        foreach ($rows as $i => $row) {
            unset($rows[$i]['sort_name']);
        }
        $out['rows'] = $rows;

        return $out;
    }

    /**
     * @param array $c normalised criteria
     * @return string[] up to MAX_PHRASES phrases, most selective first
     */
    public static function buildPhrases(array $c)
    {
        $phrases = array();
        $add = function ($phrase) use (&$phrases) {
            $phrase = trim((string) $phrase);
            if ($phrase === '' || count($phrases) >= IfirmaCustomerContractorService::MAX_PHRASES) {
                return;
            }
            foreach ($phrases as $existing) {
                if (mb_strtolower($existing, 'UTF-8') === mb_strtolower($phrase, 'UTF-8')) {
                    return;
                }
            }
            $phrases[] = $phrase;
        };

        $nip = preg_replace('/\D+/', '', $c['nip']);
        if (strlen($nip) >= 10) {
            $add($nip);
        }
        $add($c['company']);
        $add(trim($c['firstname'] . ' ' . $c['lastname']));
        if (count($phrases) < 3) {
            $add($c['lastname']);
        }

        return $phrases;
    }

    /**
     * @param array $criteria
     * @return array
     */
    private function normalizeCriteria(array $criteria)
    {
        $out = array();
        foreach (array('firstname', 'lastname', 'company', 'nip', 'email', 'phone') as $key) {
            $out[$key] = isset($criteria[$key]) && is_scalar($criteria[$key])
                ? trim(preg_replace('/\s+/u', ' ', (string) $criteria[$key]))
                : '';
        }

        return $out;
    }

    /**
     * @param string $identifier
     * @param array  $row        iFirma contractor row
     * @param array  $c          normalised criteria
     * @return array
     */
    private function candidateRow($identifier, array $row, array $c)
    {
        $get = function ($key) use ($row) {
            return isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';
        };
        $name = $get('Nazwa');
        $email = $get('Email');
        $emailInvoice = $get('EmailDlaFaktury');
        $phone = $get('Telefon');
        $phone2 = $get('DrugiTelefon');

        $nipDigits = preg_replace('/\D+/', '', $c['nip']);
        $rowNip = preg_replace('/\D+/', '', $get('NIP'));
        $flags = array('nip' => false, 'email' => false, 'phone' => false, 'name' => false);

        $flags['nip'] = $nipDigits !== '' && $rowNip !== '' && $nipDigits === $rowNip;
        if ($c['email'] !== '') {
            $want = mb_strtolower($c['email'], 'UTF-8');
            foreach (array($email, $emailInvoice) as $candidate) {
                if ($candidate !== '' && mb_strtolower($candidate, 'UTF-8') === $want) {
                    $flags['email'] = true;
                }
            }
        }
        $wantPhone = self::phoneDigits($c['phone']);
        if ($wantPhone !== '') {
            foreach (array($phone, $phone2) as $candidate) {
                if ($candidate !== '' && self::phoneDigits($candidate) === $wantPhone) {
                    $flags['phone'] = true;
                }
            }
        }
        $normalized = IfirmaContractorService::normalizeName($name);
        if ($normalized !== '') {
            $targets = array(
                IfirmaContractorService::normalizeName($c['firstname'] . ' ' . $c['lastname']),
                IfirmaContractorService::normalizeName($c['lastname'] . ' ' . $c['firstname']),
                IfirmaContractorService::normalizeName($c['company']),
            );
            $flags['name'] = in_array($normalized, array_filter($targets), true);
        }

        return array(
            'identifier' => $identifier,
            'name' => $name,
            'nip' => $get('NIP'),
            'email' => $email !== '' ? $email : $emailInvoice,
            'phone' => $phone !== '' ? $phone : $phone2,
            'postcode' => $get('KodPocztowy'),
            'city' => $get('Miejscowosc'),
            'flags' => $flags,
            'sort_name' => $normalized,
        );
    }

    /**
     * Digits only; a leading 0048 or, on an 11-digit number, 48 is the country code.
     *
     * @param string $phone
     * @return string
     */
    public static function phoneDigits($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strncmp($digits, '0048', 4) === 0) {
            $digits = substr($digits, 4);
        } elseif (strlen($digits) === 11 && strncmp($digits, '48', 2) === 0) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * NIP match first, then the number of matches, then the normalised name.
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    public static function compareCandidates(array $a, array $b)
    {
        if ($a['flags']['nip'] !== $b['flags']['nip']) {
            return $a['flags']['nip'] ? -1 : 1;
        }
        $ca = count(array_filter($a['flags']));
        $cb = count(array_filter($b['flags']));
        if ($ca !== $cb) {
            return $cb - $ca;
        }
        $byName = strcmp($a['sort_name'], $b['sort_name']);

        return $byName !== 0 ? $byName : strcmp($a['identifier'], $b['identifier']);
    }

    // ---- remote count ---------------------------------------------------------------------

    /**
     * Counts the customer's documents in iFirma (yearly windows, newest first, call budget).
     *
     * @param int $idCustomer
     * @return array ok, error, total, by_type, paid, period_from, period_to, calls, complete, identifier, nip
     */
    public function remoteCount($idCustomer)
    {
        $state = $this->linkState($idCustomer);
        $nip = $this->customerNip($idCustomer);
        $result = array(
            'ok' => true,
            'error' => '',
            'total' => 0,
            'by_type' => array(self::TYPE_RECEIPT => 0, self::TYPE_INVOICE => 0, self::TYPE_OTHER => 0),
            'paid' => 0,
            'period_from' => '',
            'period_to' => '',
            'calls' => 0,
            'complete' => true,
            'identifier' => $state['identifier'],
            'nip' => $nip,
        );

        $today = new DateTime('@' . $this->now());
        $today->setTime(0, 0, 0);
        $earliest = clone $today;
        $earliest->modify('-' . self::REMOTE_MAX_YEARS . ' years');
        $customer = new Customer((int) $idCustomer);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $customer->date_add, $m)) {
            $registered = DateTime::createFromFormat('Y-m-d H:i:s', $m[1] . ' 00:00:00', new DateTimeZone('UTC'));
            if ($registered && $registered > $earliest) {
                $earliest = $registered;
            }
        }
        if ($earliest > $today) {
            $earliest = clone $today;
        }

        $docs = array();
        $kontrahentKnown = true;
        $coveredFrom = '';
        $to = clone $today;
        $result['period_to'] = $today->format('Y-m-d');
        $exhausted = false;

        while ($to >= $earliest && !$exhausted) {
            $from = clone $to;
            $from->modify('-' . (self::REMOTE_WINDOW_DAYS - 1) . ' days');
            if ($from < $earliest) {
                $from = clone $earliest;
            }
            $base = array(
                'dataOd' => $from->format('Y-m-d'),
                'dataDo' => $to->format('Y-m-d'),
                'iloscNaStronie' => self::REMOTE_PAGE_SIZE,
            );

            $queries = array();
            if ($kontrahentKnown) {
                $queries[] = array('kontrahent' => $state['identifier']);
            }
            if ($nip !== '') {
                $queries[] = array('nipKontrahenta' => $nip);
            }

            foreach ($queries as $filter) {
                $isKontrahent = isset($filter['kontrahent']);
                for ($page = 1;; ++$page) {
                    if ($result['calls'] >= self::REMOTE_CALL_BUDGET) {
                        $exhausted = true;
                        break 2;
                    }
                    $params = array_merge($base, $filter);
                    if ($page > 1) {
                        $params['strona'] = $page;
                    }
                    ++$result['calls'];
                    $rows = $this->client->listInvoices($params, 'faktura');
                    if ($rows === null) {
                        if ($isKontrahent && $page === 1 && $this->contractorFilterUnknown()) {
                            $kontrahentKnown = false;
                            break;
                        }
                        $result['ok'] = false;
                        $result['error'] = (string) $this->client->getLastError();

                        return $this->failed($result);
                    }
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $docs[self::docKey($row)] = $row;
                        }
                    }
                    if (count($rows) < self::REMOTE_PAGE_SIZE) {
                        break;
                    }
                }
            }

            if ($exhausted) {
                break;
            }
            $coveredFrom = $from->format('Y-m-d');
            $to = clone $from;
            $to->modify('-1 day');
        }

        if ($exhausted) {
            $result['complete'] = false;
        }
        $result['period_from'] = $coveredFrom;
        if ($coveredFrom === '') {
            $result['period_to'] = '';
        }

        foreach ($docs as $row) {
            ++$result['total'];
            $result['by_type'][self::remoteType(isset($row['Rodzaj']) ? $row['Rodzaj'] : '')]++;
            $paid = self::amount(isset($row['Zaplacono']) ? $row['Zaplacono'] : 0);
            $gross = self::amount(isset($row['Brutto']) ? $row['Brutto'] : 0);
            if ($paid >= $gross - 0.01) {
                ++$result['paid'];
            }
        }

        return $result;
    }

    /**
     * @param array $result
     * @return array the result without any partial count
     */
    private function failed(array $result)
    {
        $result['total'] = 0;
        $result['paid'] = 0;
        $result['by_type'] = array(self::TYPE_RECEIPT => 0, self::TYPE_INVOICE => 0, self::TYPE_OTHER => 0);
        $result['complete'] = false;
        $result['period_from'] = '';
        $result['period_to'] = '';

        return $result;
    }

    /**
     * @return bool true when iFirma answered Kod 201 about an unknown contractor
     */
    private function contractorFilterUnknown()
    {
        return (int) $this->client->getLastKod() === 201
            && mb_stripos((string) $this->client->getLastError(), 'kontrahent') !== false;
    }

    /**
     * @param array $row
     * @return string
     */
    private static function docKey(array $row)
    {
        if (isset($row['FakturaId']) && (string) $row['FakturaId'] !== '') {
            return 'id:' . $row['FakturaId'];
        }

        return 'row:' . md5(json_encode($row));
    }

    /**
     * @param mixed $value
     * @return float
     */
    private static function amount($value)
    {
        return (float) str_replace(',', '.', (string) $value);
    }

    // ---- link / unlink --------------------------------------------------------------------

    /**
     * Links the customer to a contractor the employee chose, after reading it from iFirma again.
     *
     * @param int    $idCustomer
     * @param string $identifier
     * @return array success, code (linked|not_found|error|invalid), identifier, name, message
     */
    public function link($idCustomer, $identifier)
    {
        $identifier = trim((string) $identifier);
        $out = array('success' => false, 'code' => self::LINK_INVALID, 'identifier' => $identifier, 'name' => '', 'message' => '');
        if ((int) $idCustomer <= 0 || $identifier === '' || mb_strlen($identifier, 'UTF-8') > self::IDENTIFIER_MAX) {
            return $out;
        }

        $row = $this->contractors->fetch($identifier);
        if ($row === null) {
            $out['code'] = self::LINK_NOT_FOUND;

            return $out;
        }
        if ($row === false) {
            $out['code'] = self::LINK_ERROR;
            $out['message'] = (string) $this->client->getLastError();

            return $out;
        }

        $name = isset($row['Nazwa']) ? (string) $row['Nazwa'] : '';
        try {
            IfirmaContractorMap::link((int) $idCustomer, $identifier, IfirmaContractorMap::BY_MANUAL, $name);
        } catch (Exception $e) {
            $out['code'] = self::LINK_ERROR;
            $out['message'] = $e->getMessage();

            return $out;
        }

        $out['success'] = true;
        $out['code'] = self::LINK_LINKED;
        $out['name'] = $name;

        return $out;
    }

    /**
     * Removes the stored link. No iFirma call.
     *
     * @param int $idCustomer
     * @return bool true when a link was removed
     */
    public function unlink($idCustomer)
    {
        try {
            return (bool) IfirmaContractorMap::unlinkCustomer((int) $idCustomer);
        } catch (Exception $e) {
            return false;
        }
    }

    // ---- helpers --------------------------------------------------------------------------

    /**
     * @param int $idCustomer
     * @return IfirmaContractorMap|null
     */
    private function mapping($idCustomer)
    {
        try {
            return IfirmaContractorMap::getByCustomer((int) $idCustomer);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock ? (int) call_user_func($this->clock) : time();
    }

    /**
     * Invoice addresses, newest first: the invoice addresses of the orders (newest order first),
     * then the customer's own addresses (newest first). Each address once.
     *
     * @param int $idCustomer
     * @return Address[]
     */
    private function invoiceAddresses($idCustomer)
    {
        $ids = array();
        try {
            $orders = Order::getCustomerOrders((int) $idCustomer);
        } catch (Exception $e) {
            $orders = array();
        }
        if (is_array($orders)) {
            foreach ($orders as $order) {
                if (!empty($order['id_address_invoice'])) {
                    $ids[] = (int) $order['id_address_invoice'];
                }
            }
        }
        $customer = new Customer((int) $idCustomer);
        $idLang = (int) Context::getContext()->language->id;
        $own = array();
        foreach ((array) $customer->getAddresses($idLang) as $row) {
            if (isset($row['id_address'])) {
                $own[] = (int) $row['id_address'];
            }
        }
        rsort($own);
        $ids = array_merge($ids, $own);

        $out = array();
        foreach (array_unique($ids) as $id) {
            $address = new Address($id);
            if (Validate::isLoadedObject($address)) {
                $out[] = $address;
            }
        }

        return $out;
    }
}
