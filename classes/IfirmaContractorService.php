<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaContractorMap.php';

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

    /** @var array */
    private $lastAction = array('identifier' => null, 'action' => self::ACTION_DISABLED, 'message' => '', 'matched_by' => '', 'linked_identifier' => null);

    /** @var string */
    private $lastSearchError = '';

    public function __construct(IfirmaApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * @param Module $module
     * @return IfirmaContractorService
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
        $response = $this->client->get('kontrahenci/id/' . rawurlencode((string) $identifier) . '.json', 'faktura');
        if ($response === null) {
            return false;
        }

        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;
        if (!isset($node['Kod']) || (int) $node['Kod'] !== 0) {
            return null;
        }
        if (!isset($node['Wynik']) || !is_array($node['Wynik']) || !$node['Wynik']) {
            return null;
        }

        $first = reset($node['Wynik']);

        return is_array($first) ? $first : null;
    }

    /**
     * @param array $contractor
     * @return bool
     */
    public function create(array $contractor)
    {
        return $this->createAndGetIdentifier($contractor) !== false;
    }

    /**
     * Creates the contractor and returns the identifier iFirma reports for it: the one sent when
     * iFirma confirms it exists, otherwise the `Wynik` value when that one exists, otherwise false
     * (the caller then falls back to a document without identifier).
     *
     * @param array $contractor
     * @return string|false
     */
    public function createAndGetIdentifier(array $contractor)
    {
        $response = $this->client->post('kontrahenci.json', 'faktura', $this->whitelist($contractor));
        if (!$this->isOk($response)) {
            return false;
        }
        $sent = isset($contractor['Identyfikator']) ? trim((string) $contractor['Identyfikator']) : '';
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;
        $reported = isset($node['Wynik']) && is_scalar($node['Wynik']) ? trim((string) $node['Wynik']) : '';

        foreach (array_unique(array_filter(array($sent, $reported))) as $candidate) {
            if (is_array($this->fetch($candidate))) {
                return $candidate;
            }
        }
        if ($sent !== '' || $reported !== '') {
            $this->lastSearchError = 'iFirma created the contractor but neither identifier (' . $sent . ' / ' . $reported . ') can be read back.';
        }

        return false;
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
     * @return array identifier (string|null), action, message, matched_by, linked_identifier
     */
    public function ensure(array $contractor, $idCustomer = null, array $hints = array())
    {
        $identifier = isset($contractor['Identyfikator']) ? trim((string) $contractor['Identyfikator']) : '';
        $idCustomer = (int) $idCustomer;
        $this->lastSearchError = '';

        if (!$this->isEnabled() || $identifier === '') {
            return $this->remember(null, self::ACTION_DISABLED, '');
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
                    return $this->remember(null, self::ACTION_FALLBACK, $this->client->getLastError());
                }
                $this->safeUnlink($idCustomer);
            }
        }

        // b. the module identifier
        $existing = $this->fetch($identifier);
        if ($existing === false) {
            return $this->remember(null, self::ACTION_FALLBACK, $this->client->getLastError());
        }
        if (is_array($existing)) {
            return $this->useExisting($identifier, $existing, $contractor, IfirmaContractorMap::BY_MODULE, $idCustomer);
        }

        // c. + d. existing contractors found through the search endpoint
        if ($idCustomer > 0) {
            $found = $this->findByNip($hints);
            $matchedBy = IfirmaContractorMap::BY_NIP;
            if ($found === null) {
                $found = $this->findByName($hints);
                $matchedBy = IfirmaContractorMap::BY_NAME;
            }
            if ($found !== null && isset($found['Identyfikator']) && trim((string) $found['Identyfikator']) !== '') {
                return $this->useExisting(trim((string) $found['Identyfikator']), $found, $contractor, $matchedBy, $idCustomer);
            }
        }

        // e. create the module contractor
        $assigned = $this->createAndGetIdentifier($contractor);
        if ($assigned !== false) {
            $this->safeLink($idCustomer, $assigned, IfirmaContractorMap::BY_MODULE, isset($contractor['Nazwa']) ? (string) $contractor['Nazwa'] : '');

            return $this->remember($assigned, self::ACTION_CREATED, '', $idCustomer > 0 ? IfirmaContractorMap::BY_MODULE : '');
        }

        $reason = $this->lastSearchError !== '' ? $this->lastSearchError : $this->client->getLastError();

        return $this->remember(null, self::ACTION_FALLBACK, $reason);
    }

    /**
     * Kept / updated outcome for an existing contractor; stores the link when $linkCustomer > 0.
     *
     * @param string $identifier
     * @param array  $existing     the iFirma record
     * @param array  $contractor   shop-owned data
     * @param string $matchedBy
     * @param int    $linkCustomer customer to link (0 = the mapping is already stored)
     * @return array
     */
    private function useExisting($identifier, array $existing, array $contractor, $matchedBy, $linkCustomer)
    {
        if ($this->needsUpdate($existing, $contractor)) {
            if (!$this->update($identifier, $existing, $contractor)) {
                return $this->remember(null, self::ACTION_FALLBACK, $this->client->getLastError());
            }
            $action = self::ACTION_UPDATED;
        } else {
            $action = self::ACTION_KEPT;
        }
        if ($linkCustomer > 0) {
            $this->safeLink($linkCustomer, $identifier, $matchedBy, isset($existing['Nazwa']) ? (string) $existing['Nazwa'] : '');
        }

        return $this->remember($identifier, $action, '', $matchedBy);
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
     * @return array|null contractor row with exactly this NIP
     */
    private function findByNip(array $hints)
    {
        if (strlen($hints['nip']) < 10) {
            return null;
        }
        $rows = array();
        foreach ($this->search($hints['nip']) as $row) {
            if (isset($row['NIP']) && preg_replace('/\D/', '', (string) $row['NIP']) === $hints['nip']) {
                $rows[] = $row;
            }
        }
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
     * @return array|null contractor row with the buyer name, confirmed by e-mail or postcode
     */
    private function findByName(array $hints)
    {
        $name = self::normalizeName($hints['name']);
        if ($name === '') {
            return null;
        }
        $candidates = array();
        foreach ($this->search($hints['name']) as $row) {
            if (self::normalizeName(isset($row['Nazwa']) ? $row['Nazwa'] : '') !== $name) {
                continue;
            }
            $rowNip = isset($row['NIP']) ? preg_replace('/\D/', '', (string) $row['NIP']) : '';
            if ($hints['nip'] !== '' && $rowNip !== '' && $rowNip !== $hints['nip']) {
                continue;
            }
            $candidates[] = $row;
        }

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
     * Any failure counts as "no match" and is recorded in getLastSearchError().
     *
     * @param string $phrase
     * @return array list of contractor rows
     */
    public function search($phrase)
    {
        $this->lastSearchError = '';
        $phrase = trim((string) $phrase);
        if ($phrase === '') {
            return array();
        }

        $response = $this->client->get('kontrahenci/' . rawurlencode($phrase) . '.json', 'faktura');
        if ($response === null) {
            $this->lastSearchError = (string) $this->client->getLastError();

            return array();
        }
        $node = isset($response['response']) && is_array($response['response']) ? $response['response'] : $response;
        if (!isset($node['Kod']) || (int) $node['Kod'] !== 0) {
            if (isset($node['Kod']) && (int) $node['Kod'] !== 208) {
                $this->lastSearchError = isset($node['Informacja']) && (string) $node['Informacja'] !== ''
                    ? (string) $node['Informacja']
                    : 'Kod ' . (int) $node['Kod'];
            }

            return array();
        }
        if (!isset($node['Wynik']) || !is_array($node['Wynik'])) {
            return array();
        }

        $rows = array();
        foreach ($node['Wynik'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return string failure message of the last search that failed in this ensure() call
     */
    public function getLastSearchError()
    {
        return $this->lastSearchError;
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
     * @return array identifier, action, message of the last ensure() call
     */
    public function getLastAction()
    {
        return $this->lastAction;
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
     * @return array
     */
    private function remember($identifier, $action, $message, $matchedBy = '')
    {
        $this->lastAction = array(
            'identifier' => $identifier,
            'action' => $action,
            'message' => (string) $message,
            'matched_by' => $identifier === null ? '' : (string) $matchedBy,
            'linked_identifier' => ($identifier !== null && $matchedBy !== '') ? $identifier : null,
        );

        return $this->lastAction;
    }
}
