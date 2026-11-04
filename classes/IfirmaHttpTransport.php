<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Default cURL transport used by IfirmaApiClient.
 *
 * Contract: request($method, $url, array $headers, $body, $timeout) returns
 * array('status' => int, 'body' => string, 'error' => string); status 0 plus a
 * non-empty error means a transport failure.
 */
class IfirmaHttpTransport
{
    /**
     * @param string $method
     * @param string $url
     * @param array  $headers list of "Name: value" strings
     * @param string $body
     * @param int    $timeout seconds
     * @return array
     */
    public function request($method, $url, array $headers, $body, $timeout)
    {
        $timeout = max(1, (int) $timeout);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(15, $timeout));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PrestaShop-iFirma/1.1');

        $method = strtoupper((string) $method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method !== 'GET') {
            // PUT (contractor update) and any other verb: cURL defaults to GET otherwise.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ((string) $body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return array('status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'unknown transport error');
        }

        curl_close($ch);

        return array('status' => $status, 'body' => (string) $response, 'error' => '');
    }
}
