<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaHttpTransport.php';

class IfirmaApiClient
{
    const BASE_URL = 'https://www.ifirma.pl/iapi/';
    const DEFAULT_RATE_CAP = 80;

    /** Seconds all calls are refused after iFirma rejected the credentials. */
    const AUTH_COOLDOWN = 900;

    /** @var string */
    private $user;

    /** @var array<string,string> */
    private $keys;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout;

    /** @var object|null */
    private $transport;

    /** @var object|null */
    private static $defaultTransport = null;

    /** @var callable|null */
    private $clock;

    /** @var string */
    private $lastError = '';

    /** @var int */
    private $lastHttpCode = 0;

    /** @var int|null */
    private $lastKod = null;

    /** @var bool */
    private $rateLimited = false;

    /** @var bool */
    private $authBlocked = false;

    /** @var int */
    private $requestCount = 0;

    /** @var bool */
    private $requestSent = false;

    /**
     * @param string      $user
     * @param array       $keys
     * @param string      $baseUrl
     * @param int         $timeout
     * @param object|null $transport object with request($method, $url, array $headers, $body, $timeout)
     */
    public function __construct($user, array $keys, $baseUrl = self::BASE_URL, $timeout = 30, $transport = null)
    {
        $this->user = trim((string) $user);
        $this->keys = $keys;
        $baseUrl = trim((string) $baseUrl);
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : self::BASE_URL, '/') . '/';
        $this->timeout = (int) $timeout;
        $this->transport = $transport !== null ? $transport : self::$defaultTransport;
    }

    /**
     * Named constructor used by the services' build() methods: a client configured from the module
     * Configuration (IFIRMA_USER and the IFIRMA_KEY_FAKTURA / _ABONENT / _RACHUNEK keys).
     *
     * @param string|null $baseUrl explicit base URL; when empty, IFIRMA_BASE_URL, then BASE_URL
     * @return IfirmaApiClient
     */
    public static function fromConfiguration($baseUrl = null)
    {
        if (!$baseUrl) {
            $baseUrl = self::storedBaseUrl();
        }

        return new IfirmaApiClient(
            Configuration::get('IFIRMA_USER'),
            array(
                'faktura' => Configuration::get('IFIRMA_KEY_FAKTURA'),
                'abonent' => Configuration::get('IFIRMA_KEY_ABONENT'),
                'rachunek' => Configuration::get('IFIRMA_KEY_RACHUNEK'),
            ),
            $baseUrl ?: self::BASE_URL
        );
    }

    /** @var bool whether this request already logged a stored base URL that failed the check */
    private static $badBaseUrlLogged = false;

    /**
     * The stored base URL, checked again at run time: a value that no longer passes
     * IfirmaSettings::isAcceptableBaseUrl() (edited in the database, or dev mode switched off since the save)
     * is not used. The client talks to iFirma instead and the shop log says so, once per request.
     *
     * @return string the stored URL, '' for none or for one that is refused
     */
    private static function storedBaseUrl()
    {
        // loaded when needed: IfirmaSettings requires this file, so a require at the top would be circular
        require_once dirname(__FILE__) . '/IfirmaSettings.php';
        $stored = trim((string) Configuration::get('IFIRMA_BASE_URL'));
        if ($stored === '' || IfirmaSettings::isAcceptableBaseUrl($stored)) {
            return $stored;
        }
        if (!self::$badBaseUrlLogged) {
            self::$badBaseUrlLogged = true;
            PrestaShopLogger::addLog('iFirma: the stored API base URL is not acceptable, the production address is used: ' . $stored, 2);
        }

        return '';
    }

    /**
     * Test seam: forgets that the bad stored base URL was logged, as a new request would.
     */
    public static function resetRequestState()
    {
        self::$badBaseUrlLogged = false;
    }

    /**
     * Testability seam: transport used by every client built without an explicit one.
     *
     * @param object|null $transport
     */
    public static function setDefaultTransport($transport)
    {
        self::$defaultTransport = $transport;
    }

    /**
     * @return object|null
     */
    public static function getDefaultTransport()
    {
        return self::$defaultTransport;
    }

    /**
     * @param object $transport
     * @return $this
     */
    public function setTransport($transport)
    {
        $this->transport = $transport;

        return $this;
    }

    /**
     * @return object
     */
    public function getTransport()
    {
        if ($this->transport === null) {
            $this->transport = new IfirmaHttpTransport();
        }

        return $this->transport;
    }

    /**
     * @param int $seconds
     * @return $this
     */
    public function setTimeout($seconds)
    {
        $this->timeout = (int) $seconds;

        return $this;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @param callable $fn returns a unix timestamp
     * @return $this
     */
    public function setClock($fn)
    {
        $this->clock = $fn;

        return $this;
    }

    /**
     * @param string   $endpoint
     * @param string   $keyName
     * @param array    $data
     * @param int|null $timeout
     * @return array|null
     */
    public function post($endpoint, $keyName, array $data, $timeout = null)
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = $this->execute('POST', $endpoint, $keyName, $content, false, $timeout);

        return $this->decodeJson($res);
    }

    /**
     * @param string   $endpoint
     * @param string   $keyName
     * @param array    $data
     * @param int|null $timeout
     * @return array|null
     */
    public function put($endpoint, $keyName, array $data, $timeout = null)
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res = $this->execute('PUT', $endpoint, $keyName, $content, false, $timeout);

        return $this->decodeJson($res);
    }

    /**
     * @param string   $endpoint
     * @param string   $keyName
     * @param int|null $timeout
     * @return array|null
     */
    public function get($endpoint, $keyName, $timeout = null)
    {
        $res = $this->execute('GET', $endpoint, $keyName, '', false, $timeout);

        return $this->decodeJson($res);
    }

    /**
     * @param string   $endpoint
     * @param string   $keyName
     * @param int|null $timeout
     * @return string|false
     */
    public function getBinary($endpoint, $keyName, $timeout = null)
    {
        $res = $this->execute('GET', $endpoint, $keyName, '', true, $timeout);

        if ($res === false) {
            return false;
        }

        if ($this->isPdf($res['body'])) {
            return $res['body'];
        }

        $decoded = json_decode($res['body'], true);
        if (is_array($decoded)) {
            $node = $this->node($decoded);
            if (isset($node['Kod']) && (int) $node['Kod'] !== 0) {
                $this->applyKod($node);

                return false;
            }
            if (isset($node['Informacja']) && (string) $node['Informacja'] !== '') {
                $this->lastError = (string) $node['Informacja'];

                return false;
            }
        }

        $this->lastError = $res['status'] >= 400
            ? 'HTTP ' . $res['status'] . ' returned by iFirma.'
            : 'Unexpected non-PDF response from iFirma.';

        return false;
    }

    /**
     * @param string $body
     * @return bool
     */
    public function isPdf($body)
    {
        return strncmp((string) $body, '%PDF', 4) === 0;
    }

    /**
     * List documents; dataOd, dataDo and iloscNaStronie are mandatory.
     *
     * @param array    $params
     * @param string   $keyName
     * @param int|null $timeout
     * @return array|null rows (possibly empty) or null on failure
     * @throws InvalidArgumentException
     */
    public function listInvoices(array $params, $keyName = 'faktura', $timeout = null)
    {
        foreach (array('dataOd', 'dataDo', 'iloscNaStronie') as $required) {
            if (!isset($params[$required]) || $params[$required] === '') {
                throw new InvalidArgumentException('listInvoices() requires the "' . $required . '" parameter.');
            }
        }

        $decoded = $this->get('faktury.json?' . http_build_query($params), $keyName, $timeout);
        if ($decoded === null || $this->lastKod !== null) {
            return null;
        }

        $node = $this->node($decoded);

        return (isset($node['Wynik']) && is_array($node['Wynik'])) ? $node['Wynik'] : array();
    }

    /**
     * @param string   $type
     * @param string   $id
     * @param int|null $timeout
     * @return array|null array('raw' => string, 'data' => array|null)
     */
    public function getDetail($type, $id, $timeout = null)
    {
        $res = $this->execute('GET', $type . '/' . rawurlencode((string) $id) . '.json', 'faktura', '', false, $timeout);
        if ($res === false) {
            return null;
        }

        $data = json_decode($res['body'], true);
        $data = is_array($data) ? $data : null;
        if ($data !== null) {
            $this->applyKod($this->node($data));
        }
        if ($res['status'] >= 400) {
            if ($this->lastKod === null) {
                $this->lastError = 'HTTP ' . $res['status'] . ' returned by iFirma.';
            }

            return null;
        }

        return array('raw' => $res['body'], 'data' => $data);
    }

    /**
     * @param string   $type
     * @param string   $id
     * @param array    $body
     * @param int|null $timeout
     * @return array|null
     */
    public function send($type, $id, array $body, $timeout = null)
    {
        return $this->post($type . '/send/' . rawurlencode((string) $id) . '.json', 'faktura', $body, $timeout);
    }

    /**
     * Submits an issued document to KSeF; a null date means "send now", a YYYY-MM-DD date schedules it.
     *
     * @param string      $type
     * @param string      $id
     * @param string|null $date
     * @param int|null    $timeout
     * @return array|null
     */
    public function sendToKsef($type, $id, $date = null, $timeout = null)
    {
        return $this->post($type . '/ksef/send/' . rawurlencode((string) $id) . '.json', 'faktura', array('DataWysylki' => $date), $timeout);
    }

    /**
     * Daily API limit; needs the optional "abonent" key.
     *
     * @return array|null array('used' => int, 'granted' => int)
     */
    public function getLimit()
    {
        $this->resetState();
        if (!isset($this->keys['abonent']) || $this->keys['abonent'] === '' || $this->keys['abonent'] === false) {
            return null;
        }

        $decoded = $this->get('abonent/limit.json', 'abonent');
        if ($decoded === null || $this->lastKod !== null) {
            return null;
        }

        $node = $this->node($decoded);
        if (!isset($node['LimitWykorzystany']) || !isset($node['LimitPrzyznany'])) {
            $this->lastError = 'Unexpected limit response from iFirma.';

            return null;
        }

        return array('used' => (int) $node['LimitWykorzystany'], 'granted' => (int) $node['LimitPrzyznany']);
    }

    /**
     * @return string
     */
    public function getLastError()
    {
        return $this->lastError;
    }

    /**
     * @return int
     */
    public function getLastHttpCode()
    {
        return $this->lastHttpCode;
    }

    /**
     * @return int|null
     */
    public function getLastKod()
    {
        return $this->lastKod;
    }

    /**
     * @return bool true when the last call was refused by the local rate guard
     */
    public function isRateLimited()
    {
        return $this->rateLimited;
    }

    /**
     * @return bool true while the stored credentials-rejected mark is younger than AUTH_COOLDOWN seconds by this
     *              client's clock, whether or not this instance has made a call
     */
    public function isAuthBlocked()
    {
        return $this->authBlockMessage() !== null;
    }

    /**
     * @return bool true when the last call was refused locally because of that cool-down
     */
    public function lastCallWasAuthBlocked()
    {
        return $this->authBlocked;
    }

    /**
     * @return string the dated "calls are paused" message while the cool-down runs, else ''
     */
    public function getAuthBlockMessage()
    {
        $message = $this->authBlockMessage();

        return $message !== null ? $message : '';
    }

    /**
     * @return bool true when the last call reached the transport (even if it failed or timed
     *              out); false when it was refused locally (missing/invalid key, rate guard)
     *              or no call was made
     */
    public function lastRequestWasSent()
    {
        return $this->requestSent;
    }

    /**
     * @return int requests actually sent by this instance
     */
    public function getRequestCount()
    {
        return $this->requestCount;
    }

    /**
     * @return array Kod => cause
     */
    public static function getKodMessages()
    {
        return array(
            100 => 'Technical problem on the iFirma side',
            101 => 'Wrong encoding (UTF-8 expected)',
            102 => 'Missing or invalid content type',
            200 => 'Invalid request structure',
            201 => 'Validation error in document data',
            202 => 'Wrong service or account settings',
            400 => 'Invalid authorization header',
            401 => 'Invalid user login',
            402 => 'No API key generated for this account',
            403 => 'Invalid signature - check the API key',
            499 => 'iFirma terms of service not accepted',
            500 => 'Insufficient permissions (super-account required)',
        );
    }

    private function resetState()
    {
        $this->lastError = '';
        $this->lastHttpCode = 0;
        $this->lastKod = null;
        $this->rateLimited = false;
        $this->authBlocked = false;
        $this->requestSent = false;
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }

    /**
     * Remembers that iFirma rejected the credentials; execute() then refuses calls for AUTH_COOLDOWN seconds.
     *
     * @param string $message
     */
    private function recordAuthFailure($message)
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now() . '|' . $message);
    }

    /**
     * @return string|null the refusal message while the cool-down runs, else null
     */
    private function authBlockMessage()
    {
        $stored = (string) Configuration::get('IFIRMA_AUTH_FAILURE');
        if ($stored === '' || strpos($stored, '|') === false) {
            return null;
        }

        $parts = explode('|', $stored, 2);
        $at = (int) $parts[0];
        $now = $this->now();
        // a mark dated in the future (the clock was stepped back) would block calls for good: it counts as expired
        if ($at <= 0 || $at > $now || $now - $at >= self::AUTH_COOLDOWN) {
            return null;
        }

        return sprintf(
            'iFirma rejected the credentials at %s: %s; calls are paused until %s',
            date('Y-m-d H:i:s', $at),
            $parts[1],
            date('Y-m-d H:i:s', $at + self::AUTH_COOLDOWN)
        );
    }

    /**
     * @param array $decoded
     * @return array
     */
    private function node(array $decoded)
    {
        return (isset($decoded['response']) && is_array($decoded['response'])) ? $decoded['response'] : $decoded;
    }

    /**
     * Sets lastKod/lastError when the node carries a non-zero Kod.
     *
     * @param array $node
     */
    private function applyKod(array $node)
    {
        if (!isset($node['Kod']) || (int) $node['Kod'] === 0) {
            return;
        }

        $kod = (int) $node['Kod'];
        $map = self::getKodMessages();
        $message = isset($map[$kod]) ? $map[$kod] . ' (Kod ' . $kod . ')' : 'iFirma error (Kod ' . $kod . ')';
        if (isset($node['Informacja']) && (string) $node['Informacja'] !== '') {
            $message .= ': ' . $node['Informacja'];
        }

        $this->lastKod = $kod;
        $this->lastError = $message;

        // Kod 500 is endpoint-specific (the abonent limit call), so it does not count.
        if (in_array($kod, array(400, 401, 402, 403, 499), true)) {
            $this->recordAuthFailure($message);
        }
    }

    /**
     * @param array|false $res
     * @return array|null
     */
    private function decodeJson($res)
    {
        if ($res === false) {
            return null;
        }

        $decoded = json_decode($res['body'], true);
        if (is_array($decoded)) {
            $this->applyKod($this->node($decoded));
        }

        if ($res['status'] >= 400 && $this->lastKod === null) {
            $this->lastError = 'HTTP ' . $res['status'] . ' returned by iFirma.';

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Local per-minute request guard. Returns false when the request must not be sent.
     *
     * @return bool
     */
    private function passRateGuard()
    {
        $minute = date('YmdHi', $this->now());
        $cap = (int) Configuration::get('IFIRMA_RATE_MAX_PER_MIN');
        if ($cap <= 0) {
            $cap = self::DEFAULT_RATE_CAP;
        }

        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'configuration';
        $m = pSQL($minute);
        // One atomic statement: reset or increment the counter, but only while the cap is not reached.
        $db->execute(
            "UPDATE `" . $table . "` SET `value` = IF(SUBSTRING_INDEX(`value`, ':', 1) = '" . $m . "', "
            . "CONCAT('" . $m . ":', SUBSTRING_INDEX(`value`, ':', -1) + 1), '" . $m . ":1') "
            . "WHERE `name` = 'IFIRMA_RATE_WINDOW' AND (SUBSTRING_INDEX(`value`, ':', 1) <> '" . $m . "' "
            . "OR SUBSTRING_INDEX(`value`, ':', -1) < " . (int) $cap . ")"
        );
        if ((int) $db->Affected_Rows() >= 1) {
            return true;
        }

        $stored = $db->getValue("SELECT `value` FROM `" . $table . "` WHERE `name` = 'IFIRMA_RATE_WINDOW'", false);
        if ($stored === false || $stored === null) {
            // No row yet (the module was never installed with the default): start the window.
            Configuration::updateValue('IFIRMA_RATE_WINDOW', $minute . ':1');

            return true;
        }

        $parts = explode(':', (string) $stored, 2);
        $count = (count($parts) === 2 && $parts[0] === $minute) ? (int) $parts[1] : $cap;
        $this->rateLimited = true;
        $this->lastError = sprintf(
            'iFirma rate limit guard: %d requests in the current minute (cap %d). Retry shortly.',
            $count,
            $cap
        );

        return false;
    }

    /**
     * @return array|false array('status' => int, 'body' => string)
     */
    private function execute($method, $endpoint, $keyName, $content, $binary = false, $timeout = null)
    {
        $this->resetState();

        if (!isset($this->keys[$keyName]) || $this->keys[$keyName] === '' || $this->keys[$keyName] === false) {
            $this->lastError = sprintf('Missing iFirma API key "%s".', $keyName);

            return false;
        }

        $url = $this->baseUrl . ltrim((string) $endpoint, '/');
        $authHeader = $this->buildAuthHeader($url, $keyName, $content);

        if ($authHeader === false) {
            return false;
        }

        $blocked = $this->authBlockMessage();
        if ($blocked !== null) {
            $this->authBlocked = true;
            $this->lastError = $blocked;

            return false;
        }

        if (!$this->passRateGuard()) {
            return false;
        }

        $headers = array(
            'Accept: ' . ($binary ? 'application/pdf' : 'application/json'),
            'Content-Type: application/json; charset=UTF-8',
            'Authentication: ' . $authHeader,
        );

        $this->requestCount++;
        $this->requestSent = true;
        $result = $this->getTransport()->request(
            $method,
            $url,
            $headers,
            (string) $content,
            $timeout !== null ? (int) $timeout : $this->timeout
        );

        $status = isset($result['status']) ? (int) $result['status'] : 0;
        $error = isset($result['error']) ? (string) $result['error'] : '';
        $this->lastHttpCode = $status;

        if ($status === 401 || $status === 403) {
            // Any body: a later Kod in it overwrites the mark with the richer message.
            $this->recordAuthFailure('HTTP ' . $status . ' returned by iFirma.');
        }

        if ($status === 0 || $error !== '') {
            $this->lastError = 'cURL error: ' . ($error !== '' ? $error : 'no response');

            return false;
        }

        return array('status' => $status, 'body' => isset($result['body']) ? (string) $result['body'] : '');
    }

    /**
     * @return string|false
     */
    private function buildAuthHeader($url, $keyName, $content)
    {
        $hexKey = (string) $this->keys[$keyName];

        if ($hexKey === '' || !ctype_xdigit($hexKey) || (strlen($hexKey) % 2) !== 0) {
            $this->lastError = sprintf('iFirma API key "%s" is not a valid hexadecimal string.', $keyName);

            return false;
        }

        $signedUrl = strtok($url, '?');
        $message = $signedUrl . $this->user . $keyName . $content;
        $hmac = hash_hmac('sha1', $message, hex2bin($hexKey));

        return 'IAPIS user=' . $this->user . ', hmac-sha1=' . $hmac;
    }
}
