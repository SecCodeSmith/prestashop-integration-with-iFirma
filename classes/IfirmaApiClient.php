<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class IfirmaApiClient
{
    const BASE_URL = 'https://www.ifirma.pl/iapi/';

    /** @var string */
    private $user;

    /** @var array<string,string> */
    private $keys;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout;

    /** @var string */
    private $lastError = '';

    /** @var int */
    private $lastHttpCode = 0;

    /**
     * @param string $user
     * @param array  $keys
     * @param string $baseUrl
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
     * @param string $endpoint
     * @param string $keyName
     * @param array  $data
     * @return array|null
     */
    public function post($endpoint, $keyName, array $data)
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->request('POST', $endpoint, $keyName, $content);

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
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
     * @param string $endpoint
     * @param string $keyName
     * @return string|false
     */
    public function getBinary($endpoint, $keyName)
    {
        $raw = $this->request('GET', $endpoint, $keyName, '', true);

        if ($raw === false) {
            return false;
        }

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
     * @return string|false
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
        }

        return $response;
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
