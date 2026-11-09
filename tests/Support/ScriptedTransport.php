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

/**
 * Test double for the injectable HTTP transport of IfirmaApiClient (task 4.1).
 *
 * TRANSPORT CONTRACT (the production class IfirmaHttpTransport must match):
 *
 *     public function request($method, $url, array $headers, $body, $timeout)
 *     // semantically: request(string $method, string $url, array $headers, string $body, int $timeout)
 *
 *   - $method  upper-case HTTP verb (GET, POST, ...)
 *   - $url     absolute URL including query string
 *   - $headers list of "Name: value" strings (e.g. "Authentication: IAPIS user=..., hmac-sha1=...")
 *   - $body    raw request body ('' for GET)
 *   - $timeout total timeout in seconds
 *   returns array('status' => int, 'body' => string, 'error' => string) and optionally
 *   'headers' => array(lower-cased name => value), left out by a transport that has none
 *   - status: HTTP status code, 0 on transport failure
 *   - error:  '' on success, non-empty on transport failure (e.g. "cURL error: ...")
 *   - headers: scripted with ['headers' => ['Retry-After' => '20']]; the names are lower-cased
 *
 * (Scalar type declarations are omitted so the double stays PHP 7.2/7.0 friendly; the
 * documented types are what callers must pass.)
 */
class ScriptedTransport
{
    /** @var array[] each: ['method','url','headers','body','timeout'] */
    public $calls = [];

    /** @var array[] rules: ['method','regex','queue'=>array,'callable'=>callable|null] */
    protected $rules = [];

    /**
     * Queue a response (FIFO per rule) or a callable producing it.
     *
     * @param string         $method     HTTP verb or '*' for any
     * @param string         $pathRegex  PCRE matched against the URL path, then against the full URL
     * @param array|callable $response   ['status'=>200,'body'=>'','error'=>''] (missing keys default)
     *                                   or callable($method,$url,$headers,$body,$timeout): array.
     *                                   Callable rules are permanent (never consumed).
     * @return $this
     */
    public function queue($method, $pathRegex, $response)
    {
        $method = strtoupper($method);
        if (!is_array($response) && is_callable($response)) {
            $this->rules[] = ['method' => $method, 'regex' => $pathRegex, 'queue' => [], 'callable' => $response];

            return $this;
        }
        foreach ($this->rules as $i => $r) {
            if ($r['method'] === $method && $r['regex'] === $pathRegex && $r['callable'] === null) {
                $this->rules[$i]['queue'][] = $response;

                return $this;
            }
        }
        $this->rules[] = ['method' => $method, 'regex' => $pathRegex, 'queue' => [$response], 'callable' => null];

        return $this;
    }

    public function request($method, $url, array $headers, $body, $timeout)
    {
        $method = strtoupper($method);
        $this->calls[] = [
            'method' => $method, 'url' => $url, 'headers' => $headers,
            'body' => $body, 'timeout' => $timeout,
        ];

        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach ($this->rules as $i => $r) {
            if ($r['method'] !== '*' && $r['method'] !== $method) {
                continue;
            }
            if (!preg_match($r['regex'], $path) && !preg_match($r['regex'], $url)) {
                continue;
            }
            if ($r['callable'] !== null) {
                return $this->normalize(call_user_func($r['callable'], $method, $url, $headers, $body, $timeout));
            }
            if ($r['queue']) {
                return $this->normalize(array_shift($this->rules[$i]['queue']));
            }
        }

        return ['status' => 0, 'body' => '', 'error' => 'ScriptedTransport: no scripted response for ' . $method . ' ' . $url];
    }

    public function lastCall()
    {
        return $this->calls ? $this->calls[count($this->calls) - 1] : null;
    }

    /** Value of the "Authentication:" header of the last call, or null. */
    public function lastAuthHeader()
    {
        $call = $this->lastCall();
        if ($call === null) {
            return null;
        }
        foreach ($call['headers'] as $k => $h) {
            if (is_string($k)) {
                if (strcasecmp($k, 'Authentication') === 0) {
                    return trim($h);
                }
                continue;
            }
            if (preg_match('/^\s*Authentication\s*:\s*(.*)$/i', $h, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    protected function normalize($r)
    {
        $r = is_array($r) ? $r : [];

        $answer = [
            'status' => isset($r['status']) ? (int) $r['status'] : 200,
            'body' => isset($r['body']) ? (string) $r['body'] : '',
            'error' => isset($r['error']) ? (string) $r['error'] : '',
        ];
        if (isset($r['headers']) && is_array($r['headers'])) {
            $answer['headers'] = array_change_key_case(array_map('strval', $r['headers']), CASE_LOWER);
        }

        return $answer;
    }
}
