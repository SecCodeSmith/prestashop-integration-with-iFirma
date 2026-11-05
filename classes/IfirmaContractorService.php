<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaContractorMap.php';
require_once dirname(__FILE__) . '/IfirmaContractorResolution.php';
require_once dirname(__FILE__) . '/IfirmaContractorSearchResult.php';

/**
 * Keeps one iFirma contractor (Identyfikator PS-C<id_customer>) in step with the shop customer.
 *
 * iFirma treats a supplied Kontrahent.Identyfikator as a reference to an EXISTING contractor and
 * answers Kod 201 when it does not exist; the contractor is created automatically only when the
 * identifier is null. ensure() therefore registers the contractor first and, when that is not
 * possible, falls back to a null identifier.
 */
class IfirmaContractorService
{
    const ACTION_KEPT = 'kept';
    const ACTION_CREATED = 'created';
    const ACTION_UPDATED = 'updated';
    const ACTION_FALLBACK = 'fallback';
    const ACTION_DISABLED = 'disabled';

    /** Seconds to wait once before the second read-back pass after a confirmed create. */
    const READBACK_WAIT_SECONDS = 2;

    /** Fields the shop is authoritative for (overwritten on update). */
    const FIELDS_OWNED = array('Nazwa', 'NIP', 'Ulica', 'KodPocztowy', 'Miejscowosc', 'Kraj', 'KodKraju', 'Email', 'OsobaFizyczna');

    /** Every documented add-contractor field (whitelist for the full-record PUT). */
    const FIELDS_ALLOWED = array(
        'Nazwa', 'Nazwa2', 'Identyfikator', 'PrefiksUE', 'NIP', 'Ulica', 'KodPocztowy', 'Kraj', 'KodKraju',
        'Miejscowosc', 'Email', 'EmailDlaFaktury', 'Telefon', 'DrugiTelefon', 'Faks', 'Skype', 'Www',
        'NazwaBanku', 'NumerKonta', 'OsobaFizyczna', 'PodmiotPowiazany', 'ZgodaNaEfaktury',
        'DataUdzieleniaZgody', 'JestOdbiorca', 'JestDostawca', 'Uwagi',
        'AdresKorespondencyjnyUlica', 'AdresKorespondencyjnyKodPocztowy', 'AdresKorespondencyjnyKraj',
        'AdresKorespondencyjnyMiejscowosc',
    );

    /** @var IfirmaApiClient */
    private $client;

    /** @var callable|null */
    private $sleeper;

    /** @var string answer text of the last fetch() ("Kod N: Informacja", "empty result", transport error) */
    private $lastFetchAnswer = '';

    /** @var int number of exact-NIP rows the last findByNip() saw */
    private $lastNipRows = 0;

    /**
     * @param IfirmaApiClient $client
     * @param callable|null   $sleeper receives the seconds to wait (default sleep(); tests inject a recorder)
     */
    public function __construct(IfirmaApiClient $client, $sleeper = null)
    {
        $this->client = $client;
        $this->sleeper = $sleeper;
    }

    /**
     * @param Module $module
     * @return IfirmaContractorService
     */
    public static function build(Module $module)
    {
        $client = IfirmaApiClient::fromConfiguration();

        return new self($client);
    }

    /**
     * @return bool enabled unless the switch is explicitly off (default 1)
     */
    public function isEnabled()
    {
        $value = Configuration::get('IFIRMA_CONTRACTOR_SYNC');

        return ($value === false || $value === null) ? true : (bool) $value;
    }

    /**
     * @param string $identifier
     * @return array|null|false contractor array, null when iFirma does not know it, false when unknown outcome
     */
    public function fetch($identifier)
    {
        $this->lastFetchAnswer = '';
        $response = $this->client->get('kontrahenci/id/' . rawurlencode((string) $identifier) . '.json', 'faktura');
        if ($response === null) {
            $this->lastFetchAnswer = (string) $this->client->getLastError();

            return false;
        }

        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;
        if (!isset($node['Kod']) || (int) $node['Kod'] !== 0) {
            $kod = isset($node['Kod']) ? (int) $node['Kod'] : 0;
            $info = isset($node['Informacja']) ? trim((string) $node['Informacja']) : '';
            $this->lastFetchAnswer = $kod !== 0 ? 'Kod ' . $kod . ($info !== '' ? ': ' . $info : '') : 'empty result';

            return null;
        }
        if (!isset($node['Wynik']) || !is_array($node['Wynik']) || !$node['Wynik']) {
            $this->lastFetchAnswer = 'empty result';

            return null;
        }
        if (isset($node['Wynik']['Identyfikator'])) {
            return $node['Wynik'];
        }

        $first = reset($node['Wynik']);
        if (!is_array($first)) {
            $this->lastFetchAnswer = 'empty result';

            return null;
        }

        return $first;
    }

    /**
     * @param int|float $seconds
     */
    private function wait($seconds)
    {
        if (is_callable($this->sleeper)) {
            call_user_func($this->sleeper, $seconds);

            return;
        }
        sleep((int) $seconds);
    }

    /**
     * @param array $contractor
     * @return bool
     */
    public function create(array $contractor)
    {
        return $this->createAndResolve($contractor)->getIdentifier() !== null;
    }

    /**
     * Creates the contractor and resolves it to the identifier under which iFirma stored it: found
     * by the sent identifier or by the one reported in the answer (immediately and once more after
     * a short wait), otherwise through the contractor search with the pre-create matching rules.
     * Answers a created resolution (linked to the customer when there is one, carrying the
     * read-back), or a fallback one when the create was rejected (create_rejected note, no
     * read-back) or the contractor cannot be found (readback_failed note, the read-back attached):
     * the caller then falls back to a document without identifier.
     *
     * @param array    $contractor
     * @param int|null $idCustomer customer to link the created contractor to; 0 for none
     * @param array    $hints      completed hints (nip, email, postcode, name) for the search; empty skips it
     * @return IfirmaContractorResolution
     */
    public function createAndResolve(array $contractor, $idCustomer = 0, array $hints = array())
    {
        $response = $this->client->post('kontrahenci.json', 'faktura', $this->whitelist($contractor));
        if (!$this->isOk($response)) {
            $error = (string) $this->client->getLastError();

            return new IfirmaContractorResolution(null, self::ACTION_FALLBACK, $error, '', $this->note('create_rejected', array('error' => $error)));
        }
        $sent = isset($contractor['Identyfikator']) ? trim((string) $contractor['Identyfikator']) : '';
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;

        $candidates = array($sent);
        if (isset($node['Wynik'])) {
            if (is_scalar($node['Wynik'])) {
                $candidates[] = trim((string) $node['Wynik']);
            } elseif (is_array($node['Wynik']) && isset($node['Wynik']['Identyfikator']) && is_scalar($node['Wynik']['Identyfikator'])) {
                $candidates[] = trim((string) $node['Wynik']['Identyfikator']);
            }
        }
        if (isset($node['Identyfikator']) && is_scalar($node['Identyfikator'])) {
            $candidates[] = trim((string) $node['Identyfikator']);
        }
        $candidates = array_values(array_unique(array_filter($candidates, 'strlen')));

        $readback = array('tried' => array(), 'search' => 'none', 'search_error' => '');
        $found = null;
        for ($pass = 1; $pass <= 2 && $found === null; $pass++) {
            if ($pass === 2) {
                $this->wait(self::READBACK_WAIT_SECONDS);
            }
            foreach ($candidates as $candidate) {
                $row = $this->fetch($candidate);
                if (is_array($row)) {
                    $readback['tried'][] = array('identifier' => $candidate, 'answer' => 'found');
                    $found = $candidate;
                    break;
                }
                $readback['tried'][] = array('identifier' => $candidate, 'answer' => $this->lastFetchAnswer);
            }
        }

        if ($found === null && $hints) {
            $found = $this->searchCreated($hints, $readback);
        }
        if ($found !== null) {
            $this->safeLink($idCustomer, $found, IfirmaContractorMap::BY_MODULE, isset($contractor['Nazwa']) ? (string) $contractor['Nazwa'] : '');
            $assigned = $found !== $sent ? $found : null;
            $note = $assigned !== null ? $this->note('assigned', array('identifier' => $found)) : null;

            return new IfirmaContractorResolution($found, self::ACTION_CREATED, '', $idCustomer > 0 ? IfirmaContractorMap::BY_MODULE : '', $note, $assigned, $readback);
        }

        $tried = array();
        foreach ($readback['tried'] as $entry) {
            $tried[$entry['identifier'] . ': ' . $entry['answer']] = true;
        }
        $message = 'iFirma created the contractor but it cannot be read back (' . implode('; ', array_keys($tried))
            . '); search: ' . $readback['search'] . ($readback['search_error'] !== '' ? ' (' . $readback['search_error'] . ')' : '') . '.';
        $note = $this->note('readback_failed', array(
            'tried' => $readback['tried'],
            'search' => $readback['search'],
            'search_error' => $readback['search_error'],
        ));

        return new IfirmaContractorResolution(null, self::ACTION_FALLBACK, $message, '', $note, null, $readback);
    }

    /**
     * Post-create search: NIP, then name (same rules as before the create).
     *
     * @param array $hints
     * @param array $readback updated in place (search, search_error)
     * @return string|null identifier of the single confirmed contractor
     */
    private function searchCreated(array $hints, array &$readback)
    {
        $error = '';
        $ambiguous = false;

        $this->lastNipRows = 0;
        $byNip = $this->findByNip($hints);
        $row = $byNip['row'];
        if ($byNip['error'] !== '') {
            $error = $byNip['error'];
        } elseif ($row === null && $this->lastNipRows > 1) {
            $ambiguous = true;
        }
        if ($row === null || !$this->hasIdentifier($row)) {
            $byName = $this->findByName($hints);
            $row = $byName['row'];
            if ($error === '' && $byName['error'] !== '') {
                $error = $byName['error'];
            }
        }

        if ($row !== null && $this->hasIdentifier($row)) {
            $readback['search'] = 'found';

            return trim((string) $row['Identyfikator']);
        }
        if ($error !== '') {
            $readback['search'] = 'error';
            $readback['search_error'] = $error;
        } elseif ($ambiguous) {
            $readback['search'] = 'ambiguous';
        }

        return null;
    }

    /**
     * @param array $row
     * @return bool
     */
    private function hasIdentifier(array $row)
    {
        return isset($row['Identyfikator']) && trim((string) $row['Identyfikator']) !== '';
    }

    /**
     * Full-record update: whitelisted existing record overwritten by the owned fields.
     *
     * @param string $identifier
     * @param array  $existing
     * @param array  $owned
     * @return bool
     */
    public function update($identifier, array $existing, array $owned)
    {
        $record = $this->whitelist($existing);
        foreach (self::FIELDS_OWNED as $field) {
            if (array_key_exists($field, $owned)) {
                $record[$field] = $owned[$field];
            }
        }

        $response = $this->client->put('kontrahenci/' . rawurlencode((string) $identifier) . '.json', 'faktura', $record);

        return $this->isOk($response);
    }

    /**
     * @param array $existing
     * @param array $owned
     * @return bool true when any owned field differs
     */
    public function needsUpdate(array $existing, array $owned)
    {
        foreach (self::FIELDS_OWNED as $field) {
            if (!array_key_exists($field, $owned)) {
                continue;
            }
            $have = array_key_exists($field, $existing) ? $existing[$field] : null;
            if ($field === 'OsobaFizyczna') {
                if ((bool) $have !== (bool) $owned[$field]) {
                    return true;
                }
                continue;
            }
            if (trim((string) $have) !== trim((string) $owned[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the iFirma contractor for a document: stored mapping, PS-C<id>, exact NIP, exact
     * normalised name confirmed by e-mail or postcode, and finally a newly created PS-C<id>.
     * Steps that need the customer (mapping, NIP and name search, storing the link) only run when
     * $idCustomer is given. No search failure ever blocks the call.
     *
     * @param array    $contractor buildContractor() result (must carry Identyfikator)
     * @param int|null $idCustomer
     * @param array    $hints      nip (digits), email, postcode, name; missing keys come from $contractor
     * @return IfirmaContractorResolution
     */
    public function ensure(array $contractor, $idCustomer = null, array $hints = array())
    {
        $identifier = isset($contractor['Identyfikator']) ? trim((string) $contractor['Identyfikator']) : '';
        $idCustomer = (int) $idCustomer;

        if (!$this->isEnabled() || $identifier === '') {
            return new IfirmaContractorResolution(null, self::ACTION_DISABLED);
        }

        $hints = $this->completeHints($contractor, $hints);

        // a. stored mapping
        if ($idCustomer > 0) {
            $mapping = $this->safeMapping($idCustomer);
            if ($mapping) {
                $mapped = trim((string) $mapping->ifirma_identifier);
                $existing = $this->fetch($mapped);
                if (is_array($existing)) {
                    return $this->useExisting($mapped, $existing, $contractor, (string) $mapping->matched_by, 0);
                }
                if ($existing === false) {
                    return new IfirmaContractorResolution(null, self::ACTION_FALLBACK, $this->client->getLastError(), '', $this->note('lookup_failed', array('identifier' => $mapped, 'error' => (string) $this->client->getLastError())));
                }
                $this->safeUnlink($idCustomer);
            }
        }

        // b. the module identifier
        $existing = $this->fetch($identifier);
        if ($existing === false) {
            return new IfirmaContractorResolution(null, self::ACTION_FALLBACK, $this->client->getLastError(), '', $this->note('lookup_failed', array('identifier' => $identifier, 'error' => (string) $this->client->getLastError())));
        }
        if (is_array($existing)) {
            return $this->useExisting($identifier, $existing, $contractor, IfirmaContractorMap::BY_MODULE, $idCustomer);
        }

        // c. + d. existing contractors found through the search endpoint
        if ($idCustomer > 0) {
            $found = $this->findByNip($hints)['row'];
            $matchedBy = IfirmaContractorMap::BY_NIP;
            if ($found === null) {
                $found = $this->findByName($hints)['row'];
                $matchedBy = IfirmaContractorMap::BY_NAME;
            }
            if ($found !== null && isset($found['Identyfikator']) && trim((string) $found['Identyfikator']) !== '') {
                return $this->useExisting(trim((string) $found['Identyfikator']), $found, $contractor, $matchedBy, $idCustomer);
            }
        }

        // e. create the module contractor
        return $this->createAndResolve($contractor, $idCustomer, $hints);
    }

    /**
     * Kept / updated outcome for an existing contractor; stores the link when $linkCustomer > 0.
     *
     * @param string $identifier
     * @param array  $existing     the iFirma record
     * @param array  $contractor   shop-owned data
     * @param string $matchedBy
     * @param int    $linkCustomer customer to link (0 = the mapping is already stored)
     * @return IfirmaContractorResolution
     */
    private function useExisting($identifier, array $existing, array $contractor, $matchedBy, $linkCustomer)
    {
        if ($this->needsUpdate($existing, $contractor)) {
            if (!$this->update($identifier, $existing, $contractor)) {
                $error = (string) $this->client->getLastError();

                return new IfirmaContractorResolution(null, self::ACTION_FALLBACK, $error, '', $this->note('update_failed', array('identifier' => (string) $identifier, 'error' => $error)));
            }
            $action = self::ACTION_UPDATED;
        } else {
            $action = self::ACTION_KEPT;
        }
        if ($linkCustomer > 0) {
            $this->safeLink($linkCustomer, $identifier, $matchedBy, isset($existing['Nazwa']) ? (string) $existing['Nazwa'] : '');
        }

        return new IfirmaContractorResolution($identifier, $action, '', $matchedBy);
    }

    /**
     * @param array $contractor
     * @param array $hints
     * @return array
     */
    private function completeHints(array $contractor, array $hints)
    {
        $pick = function ($hint, $field) use ($contractor, $hints) {
            if (isset($hints[$hint])) {
                return (string) $hints[$hint];
            }

            return isset($contractor[$field]) ? (string) $contractor[$field] : '';
        };

        return array(
            'nip' => preg_replace('/\D/', '', $pick('nip', 'NIP')),
            'email' => trim($pick('email', 'Email')),
            'postcode' => trim($pick('postcode', 'KodPocztowy')),
            'name' => trim($pick('name', 'Nazwa')),
        );
    }

    /**
     * @param array $hints
     * @return array row (contractor row with exactly this NIP, null when there is none), error (message of the NIP search, empty when it worked)
     */
    private function findByNip(array $hints)
    {
        if (strlen($hints['nip']) < 10) {
            return array('row' => null, 'error' => '');
        }
        $search = $this->search($hints['nip']);
        $rows = array();
        foreach ($search->getRows() as $row) {
            if (isset($row['NIP']) && preg_replace('/\D/', '', (string) $row['NIP']) === $hints['nip']) {
                $rows[] = $row;
            }
        }
        $this->lastNipRows = count($rows);

        return array('row' => $this->pickByNip($rows, $hints), 'error' => $search->getError());
    }

    /**
     * @param array $rows  the contractor rows with exactly the buyer NIP
     * @param array $hints
     * @return array|null the buyer's row: the only one, else the one with the buyer name, else the only one with the postcode
     */
    private function pickByNip(array $rows, array $hints)
    {
        if (count($rows) === 1) {
            return $rows[0];
        }
        if (!$rows) {
            return null;
        }

        $name = self::normalizeName($hints['name']);
        $byName = array();
        foreach ($rows as $row) {
            if ($name !== '' && self::normalizeName(isset($row['Nazwa']) ? $row['Nazwa'] : '') === $name) {
                $byName[] = $row;
            }
        }
        if (count($byName) === 1) {
            return $byName[0];
        }

        return $this->onlyByPostcode($byName ? $byName : $rows, $hints['postcode']);
    }

    /**
     * @param array $hints
     * @return array row (contractor row with the buyer name, confirmed by e-mail or postcode; null when there is none), error (message of the name search, empty when it worked)
     */
    private function findByName(array $hints)
    {
        $name = self::normalizeName($hints['name']);
        if ($name === '') {
            return array('row' => null, 'error' => '');
        }
        $search = $this->search($hints['name']);
        $candidates = array();
        foreach ($search->getRows() as $row) {
            if (self::normalizeName(isset($row['Nazwa']) ? $row['Nazwa'] : '') !== $name) {
                continue;
            }
            $rowNip = isset($row['NIP']) ? preg_replace('/\D/', '', (string) $row['NIP']) : '';
            if ($hints['nip'] !== '' && $rowNip !== '' && $rowNip !== $hints['nip']) {
                continue;
            }
            $candidates[] = $row;
        }

        return array('row' => $this->pickByName($candidates, $hints), 'error' => $search->getError());
    }

    /**
     * @param array $candidates the contractor rows with the buyer name (and not another NIP)
     * @param array $hints
     * @return array|null the buyer's row: the only one confirmed by e-mail, else the only one with the postcode
     */
    private function pickByName(array $candidates, array $hints)
    {
        $email = mb_strtolower($hints['email'], 'UTF-8');
        $byEmail = array();
        if ($email !== '') {
            foreach ($candidates as $row) {
                if (isset($row['Email']) && mb_strtolower(trim((string) $row['Email']), 'UTF-8') === $email) {
                    $byEmail[] = $row;
                }
            }
        }
        if (count($byEmail) === 1) {
            return $byEmail[0];
        }
        if (count($byEmail) > 1) {
            return $this->onlyByPostcode($byEmail, $hints['postcode']);
        }

        return $this->onlyByPostcode($candidates, $hints['postcode']);
    }

    /**
     * @param array  $rows
     * @param string $postcode
     * @return array|null the single row with that postcode (digits compared)
     */
    private function onlyByPostcode(array $rows, $postcode)
    {
        $digits = preg_replace('/\D/', '', (string) $postcode);
        if ($digits === '') {
            return null;
        }
        $hit = array();
        foreach ($rows as $row) {
            if (isset($row['KodPocztowy']) && preg_replace('/\D/', '', (string) $row['KodPocztowy']) === $digits) {
                $hit[] = $row;
            }
        }

        return count($hit) === 1 ? $hit[0] : null;
    }

    /**
     * Contractor search (identifier, name or NIP fragment; iFirma returns at most 20 rows).
     * Any failure counts as "no match": no rows, and the message of the failure in the answer.
     *
     * @param string $phrase
     * @return IfirmaContractorSearchResult the contractor rows and the failure message
     */
    public function search($phrase)
    {
        $phrase = trim((string) $phrase);
        if ($phrase === '') {
            return new IfirmaContractorSearchResult();
        }

        $response = $this->client->get('kontrahenci/' . rawurlencode($phrase) . '.json', 'faktura');
        if ($response === null) {
            return new IfirmaContractorSearchResult(array(), (string) $this->client->getLastError());
        }
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;
        if (!isset($node['Kod']) || (int) $node['Kod'] !== 0) {
            $error = '';
            if (isset($node['Kod']) && (int) $node['Kod'] !== 208) {
                $error = isset($node['Informacja']) && (string) $node['Informacja'] !== ''
                    ? (string) $node['Informacja']
                    : 'Kod ' . (int) $node['Kod'];
            }

            return new IfirmaContractorSearchResult(array(), $error);
        }
        if (!isset($node['Wynik']) || !is_array($node['Wynik'])) {
            return new IfirmaContractorSearchResult();
        }

        $rows = array();
        foreach ($node['Wynik'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return new IfirmaContractorSearchResult($rows);
    }

    /**
     * Case, whitespace, punctuation and Polish diacritics folded.
     *
     * @param string $name
     * @return string
     */
    public static function normalizeName($name)
    {
        $name = mb_strtolower((string) $name, 'UTF-8');
        $name = strtr($name, array(
            "\xC4\x85" => 'a', "\xC4\x87" => 'c', "\xC4\x99" => 'e', "\xC5\x82" => 'l', "\xC5\x84" => 'n',
            "\xC3\xB3" => 'o', "\xC5\x9B" => 's', "\xC5\xBA" => 'z', "\xC5\xBC" => 'z',
        ));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);

        return trim((string) $name);
    }

    /**
     * @param int $idCustomer
     * @return IfirmaContractorMap|null
     */
    private function safeMapping($idCustomer)
    {
        try {
            return IfirmaContractorMap::getByCustomer($idCustomer);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param int $idCustomer
     */
    private function safeUnlink($idCustomer)
    {
        try {
            IfirmaContractorMap::unlinkCustomer($idCustomer);
        } catch (Exception $e) {
            // the mapping is only an optimisation
        }
    }

    /**
     * @param int    $idCustomer
     * @param string $identifier
     * @param string $matchedBy
     * @param string $name
     */
    private function safeLink($idCustomer, $identifier, $matchedBy, $name)
    {
        if ($idCustomer <= 0) {
            return;
        }
        try {
            IfirmaContractorMap::link($idCustomer, $identifier, $matchedBy, $name);
        } catch (Exception $e) {
            // never block issuance on the link bookkeeping
        }
    }

    /**
     * @param array $record
     * @return array
     */
    private function whitelist(array $record)
    {
        return array_intersect_key($record, array_flip(self::FIELDS_ALLOWED));
    }

    /**
     * @param array|null $response
     * @return bool
     */
    private function isOk($response)
    {
        if ($response === null) {
            return false;
        }
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;

        return isset($node['Kod']) && (int) $node['Kod'] === 0;
    }

    /**
     * @param string $code
     * @param array  $params
     * @return array structured note rendered in the back-office language by the module
     */
    private function note($code, array $params)
    {
        return array('code' => $code, 'params' => $params);
    }
}
