<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaHttpTransport.php';
require_once dirname(__FILE__) . '/IfirmaRateGuard.php';
require_once dirname(__FILE__) . '/IfirmaRequestKind.php';
require_once dirname(__FILE__) . '/IfirmaRetryAfter.php';
require_once dirname(__FILE__) . '/IfirmaApiUsage.php';

class IfirmaApiClient
{
    const BASE_URL = 'https://www.ifirma.pl/iapi/';
    const DEFAULT_RATE_CAP = IfirmaRateGuard::DEFAULT_MINUTE_CAP;

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

    /** @var string IfirmaRateDecision::WINDOW_* of the last refusal, '' when the last call was not refused */
    private $rateLimitWindow = IfirmaRateDecision::WINDOW_NONE;

    /** @var IfirmaRateGuard */
    private $rateGuard;

    /** @var IfirmaApiUsage counts every request that is sent, per kind */
    private $usage;

    /** @var callable|null runs once, right before the next request that passes the guards is sent */
    private $beforeSend = null;

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
     * @param IfirmaRateGuard|null $rateGuard admission control; by default one on this client's clock
     * @param IfirmaApiUsage|null  $usage     statistics of the sent requests; by default one on this client's clock
     */
    public function __construct($user, array $keys, $baseUrl = self::BASE_URL, $timeout = 30, $transport = null, $rateGuard = null, $usage = null)
    {
        $this->user = trim((string) $user);
        $this->keys = $keys;
        $baseUrl = trim((string) $baseUrl);
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : self::BASE_URL, '/') . '/';
        $this->timeout = (int) $timeout;
        $this->transport = $transport !== null ? $transport : self::$defaultTransport;
        $this->rateGuard = $rateGuard !== null ? $rateGuard : $this->guardOnOwnClock();
        $this->usage = $usage !== null ? $usage : $this->usageOnOwnClock();
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

        return array('used' => self::limitFigure($node['LimitWykorzystany']), 'granted' => self::limitFigure($node['LimitPrzyznany']));
    }

    /**
     * A plain digit string becomes an integer; anything else (a float such as 1e30, a sign, text) is handed on as it
     * came, because a cast would turn it into some other number. The reader of the answer decides what is usable.
     *
     * @param mixed $figure
     * @return mixed
     */
    private static function limitFigure($figure)
    {
        return is_string($figure) && ctype_digit($figure) ? (int) $figure : $figure;
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
     * Registers what runs right before the next request is sent: after every local check passed (credentials, pause
     * after a rejected key, rate guard), so it does not run for a request that is refused locally. It runs once,
     * for that request only. An exception it throws stops the request from being sent. Pass null to withdraw it.
     *
     * @param callable|null $callback
     * @return $this
     */
    public function beforeNextRequest($callback)
    {
        $this->beforeSend = $callback;

        return $this;
    }

    /**
     * @return bool true when the last call was refused by the local rate guard
     */
    public function isRateLimited()
    {
        return $this->rateLimited;
    }

    /**
     * @return string which window refused the last call: IfirmaRateDecision::WINDOW_MINUTE, WINDOW_DAY, WINDOW_BACKOFF
     *                or WINDOW_SERVER; '' when it was not refused
     */
    public function rateLimitWindow()
    {
        return $this->rateLimitWindow;
    }

    /**
     * @return bool true when the last call was refused before it was sent, by the minute cap, the day cap or a
     *              running back-off; false for iFirma's own limit answer (that request was sent) and for the
     *              credentials cool-down (not a rate limit)
     */
    public function refusedBeforeSendingByRateLimit()
    {
        return $this->rateLimited && $this->rateLimitWindow !== IfirmaRateDecision::WINDOW_SERVER;
    }

    /**
     * @return int seconds until the window that refused the last call lets requests pass again, 0 when the last
     *             call was not refused before it was sent
     */
    public function retryAfterSeconds()
    {
        if (!$this->refusedBeforeSendingByRateLimit()) {
            return 0;
        }

        $minuteFreeAt = $this->rateLimitWindow === IfirmaRateDecision::WINDOW_MINUTE ? $this->rateGuard->minuteFreeAt() : 0;

        return IfirmaRetryAfter::seconds($this->rateLimitWindow, $this->now(), $this->rateGuard->backoffUntil(), $minuteFreeAt);
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
        $this->rateLimitWindow = IfirmaRateDecision::WINDOW_NONE;
        $this->authBlocked = false;
        $this->requestSent = false;
    }

    /**
     * @return IfirmaRateGuard a guard that reads this client's clock at every request, so setClock() reaches it
     */
    private function guardOnOwnClock()
    {
        $client = $this;

        return new IfirmaRateGuard(function () use ($client) {
            return $client->now();
        });
    }

    /**
     * @return IfirmaApiUsage usage statistics that read this client's clock at every request, so setClock() reaches them
     */
    private function usageOnOwnClock()
    {
        $client = $this;

        return new IfirmaApiUsage(function () use ($client) {
            return $client->now();
        });
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
     * iFirma's own refusal for exceeding the request limit: HTTP 429, or an answer whose message says the limit of
     * requests was exceeded ("limit zapytań"), whatever its Kod. Any other message, including one that merely
     * mentions a limit of something else, is not.
     *
     * @param int    $status
     * @param string $body
     * @return bool
     */
    private function isServerLimitAnswer($status, $body)
    {
        if ($status === 429) {
            return true;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return false;
        }
        $node = $this->node($decoded);

        return isset($node['Informacja']) && preg_match('/limit\s+zapyta/iu', (string) $node['Informacja']) === 1;
    }

    /**
     * Records that iFirma refused the request for exceeding its limit and stops all requests until the time it asked for.
     * The request was sent and refused before processing, so the document does not exist; it is neither an
     * authentication failure nor an unknown outcome.
     *
     * @param array $responseHeaders lower-cased name => value
     */
    private function beginBackoff(array $responseHeaders)
    {
        $until = $this->rateGuard->startBackoff(isset($responseHeaders['retry-after']) ? $responseHeaders['retry-after'] : '');
        $this->rateLimited = true;
        $this->rateLimitWindow = IfirmaRateDecision::WINDOW_SERVER;
        $this->lastError = sprintf(
            'iFirma refused the request: the request limit was exceeded (HTTP %d). No requests until %s.',
            $this->lastHttpCode,
            date('Y-m-d H:i:s', $until)
        );
    }

    /**
     * Asks the rate guard whether the request may start; a refusal is remembered for isRateLimited().
     *
     * @return bool false when the request must not be sent
     */
    private function passRateGuard()
    {
        $decision = $this->rateGuard->admit();
        if ($decision->isAllowed()) {
            return true;
        }

        $this->rateLimited = true;
        $this->rateLimitWindow = $decision->getWindow();
        $this->lastError = $decision->getMessage();

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

        if ($this->beforeSend !== null) {
            $before = $this->beforeSend;
            $this->beforeSend = null;
            call_user_func($before);
        }

        $this->requestCount++;
        $this->usage->record(IfirmaRequestKind::of($method, ltrim((string) $endpoint, '/')));
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

        if ($error === '' && $status !== 0 && $this->isServerLimitAnswer($status, isset($result['body']) ? (string) $result['body'] : '')) {
            $this->beginBackoff(isset($result['headers']) && is_array($result['headers']) ? $result['headers'] : array());

            return false;
        }

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
