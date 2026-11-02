<?php
/**
 * @author    SCM Jakub Berechowskir
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
        $this->requestSent = false;
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
        $now = $this->clock !== null ? (int) call_user_func($this->clock) : time();
        $minute = date('YmdHi', $now);
        $cap = (int) Configuration::get('IFIRMA_RATE_MAX_PER_MIN');
        if ($cap <= 0) {
            $cap = self::DEFAULT_RATE_CAP;
        }

        $count = 0;
        $stored = (string) Configuration::get('IFIRMA_RATE_WINDOW');
        if (strpos($stored, ':') !== false) {
            $parts = explode(':', $stored, 2);
            if ($parts[0] === $minute) {
                $count = (int) $parts[1];
            }
        }

        if ($count >= $cap) {
            $this->rateLimited = true;
            $this->lastError = sprintf(
                'iFirma rate limit guard: %d requests in the current minute (cap %d). Retry shortly.',
                $count,
                $cap
            );

            return false;
        }

        Configuration::updateValue('IFIRMA_RATE_WINDOW', $minute . ':' . ($count + 1));

        return true;
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
