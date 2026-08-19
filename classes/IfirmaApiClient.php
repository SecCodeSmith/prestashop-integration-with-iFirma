<?php
/**
 * iFirma module – low level HTTP client with HMAC-SHA1 request signing.
 *
 * Authentication (per https://api.ifirma.pl/naglowek-autoryzacji/):
 *   message = url + user + keyName + requestContent   (query string stripped from url)
 *   hash    = HMAC-SHA1(message, hex2bin(key))
 *   header  = "Authentication: IAPIS user=<user>, hmac-sha1=<hash>"
 *
 * Key names understood by iFirma: abonent, faktura, rachunek, wydatek.
 *
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class IfirmaApiClient
{
    const BASE_URL = 'https://www.ifirma.pl/iapi/';

    /** @var string iFirma account login (usually the e-mail address) */
    private $user;

    /** @var array<string,string> keyName => hexadecimal key */
    private $keys;

    /** @var string API base URL (overridable so a mock server can be used in tests) */
    private $baseUrl;

    /** @var int cURL timeout in seconds */
    private $timeout;

    /** @var string last transport / API error message */
    private $lastError = '';

    /** @var int last HTTP status code */
    private $lastHttpCode = 0;

    /**
     * @param string $user    iFirma login
     * @param array  $keys    keyName => hex key (e.g. ['faktura' => '...', 'abonent' => '...'])
     * @param string $baseUrl API base URL; defaults to production. Trailing slash is enforced.
     * @param int    $timeout
     */
    public function __construct($user, array $keys, $baseUrl = self::BASE_URL, $timeout = 30)
    {
        $this->user = trim((string) $user);
        $this->keys = $keys;
        $baseUrl = trim((string) $baseUrl);
        $this->baseUrl = rtrim($baseUrl !== '' ? $baseUrl : self::BASE_URL, '/') . '/';
        $this->timeout = (int) $timeout;
    }

    /**
     * POST a JSON payload and decode the JSON response.
     *
     * @param string $endpoint e.g. "fakturaparagon.json"
     * @param string $keyName
     * @param array  $data
     * @return array|null decoded response, or null on transport failure
     */
    public function post($endpoint, $keyName, array $data)
    {
        // The exact string that is signed MUST equal the string that is sent.
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->request('POST', $endpoint, $keyName, $content);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * GET and decode a JSON response.
     *
     * @param string $endpoint
     * @param string $keyName
     * @return array|null
     */
    public function get($endpoint, $keyName)
    {
        $raw = $this->request('GET', $endpoint, $keyName, '');

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * GET a binary payload (used for PDF downloads).
     *
     * @param string $endpoint e.g. "fakturaparagon/123.pdf"
     * @param string $keyName
     * @return string|false raw bytes, or false on failure / non-PDF answer
     */
    public function getBinary($endpoint, $keyName)
    {
        $raw = $this->request('GET', $endpoint, $keyName, '', true);

        if ($raw === false) {
            return false;
        }

        // iFirma reports errors as JSON even on a .pdf endpoint. Guard against
        // streaming an error body to the browser as if it were a PDF.
        if (strncmp($raw, '%PDF', 4) !== 0) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['response']['Informacja'])) {
                $this->lastError = (string) $decoded['response']['Informacja'];
            } else {
                $this->lastError = 'Unexpected non-PDF response from iFirma.';
            }

            return false;
        }

        return $raw;
    }

    /**
     * Perform the signed HTTP request.
     *
     * @return string|false raw response body, or false on transport error
     */
    private function request($method, $endpoint, $keyName, $content, $binary = false)
    {
        $this->lastError = '';
        $this->lastHttpCode = 0;

        if (!isset($this->keys[$keyName]) || $this->keys[$keyName] === '') {
            $this->lastError = sprintf('Missing iFirma API key "%s".', $keyName);

            return false;
        }

        $url = $this->baseUrl . ltrim((string) $endpoint, '/');
        $authHeader = $this->buildAuthHeader($url, $keyName, $content);

        if ($authHeader === false) {
            return false;
        }

        $headers = array(
            'Accept: ' . ($binary ? 'application/pdf' : 'application/json'),
            'Content-Type: application/json; charset=UTF-8',
            'Authentication: ' . $authHeader,
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop-iFirma/1.0');

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        }

        $response = curl_exec($ch);
        $this->lastHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $this->lastError = 'cURL error: ' . curl_error($ch);
            curl_close($ch);

            return false;
        }

        curl_close($ch);

        if ($this->lastHttpCode >= 400) {
            $this->lastError = 'HTTP ' . $this->lastHttpCode . ' returned by iFirma.';
            // Fall through: caller may still parse the body for a detailed message.
        }

        return $response;
    }

    /**
     * Build the "IAPIS user=..., hmac-sha1=..." header value.
     *
     * @return string|false
     */
    private function buildAuthHeader($url, $keyName, $content)
    {
        $hexKey = (string) $this->keys[$keyName];

        // iFirma keys are hexadecimal; a bad key would silently break the hash.
        if ($hexKey === '' || !ctype_xdigit($hexKey) || (strlen($hexKey) % 2) !== 0) {
            $this->lastError = sprintf('iFirma API key "%s" is not a valid hexadecimal string.', $keyName);

            return false;
        }

        // Query parameters must NOT be part of the signed message.
        $signedUrl = strtok($url, '?');
        $message = $signedUrl . $this->user . $keyName . $content;
        $hmac = hash_hmac('sha1', $message, hex2bin($hexKey));

        return 'IAPIS user=' . $this->user . ', hmac-sha1=' . $hmac;
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
}
