<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';

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
    private $lastAction = array('identifier' => null, 'action' => self::ACTION_DISABLED, 'message' => '');

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
        $response = $this->client->post('kontrahenci.json', 'faktura', $this->whitelist($contractor));

        return $this->isOk($response);
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
     * @param array $contractor buildContractor() result (must carry Identyfikator)
     * @return array identifier (string|null), action, message
     */
    public function ensure(array $contractor)
    {
        $identifier = isset($contractor['Identyfikator']) ? trim((string) $contractor['Identyfikator']) : '';

        if (!$this->isEnabled() || $identifier === '') {
            return $this->remember(null, self::ACTION_DISABLED, '');
        }

        $existing = $this->fetch($identifier);
        if ($existing === false) {
            return $this->remember(null, self::ACTION_FALLBACK, $this->client->getLastError());
        }

        if (is_array($existing)) {
            if (!$this->needsUpdate($existing, $contractor)) {
                return $this->remember($identifier, self::ACTION_KEPT, '');
            }
            if ($this->update($identifier, $existing, $contractor)) {
                return $this->remember($identifier, self::ACTION_UPDATED, '');
            }

            return $this->remember(null, self::ACTION_FALLBACK, $this->client->getLastError());
        }

        if ($this->create($contractor)) {
            return $this->remember($identifier, self::ACTION_CREATED, '');
        }

        return $this->remember(null, self::ACTION_FALLBACK, $this->client->getLastError());
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
    private function remember($identifier, $action, $message)
    {
        $this->lastAction = array('identifier' => $identifier, 'action' => $action, 'message' => (string) $message);

        return $this->lastAction;
    }
}
