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
 * non-empty error means a transport failure. An answer may also carry 'headers' =>
 * array(lower-cased name => value), the response headers of the last HTTP response
 * (the last value wins for a repeated header); a transport that has none leaves the key out.
 */
class IfirmaHttpTransport
{
    /** Largest answer accepted, in bytes; a bigger one is aborted while it arrives. */
    const MAX_RESPONSE_BYTES = 20971520;

    /** @var string the answer received so far by the request in progress */
    private $buffer = '';

    /** @var bool true when the request in progress was aborted for exceeding MAX_RESPONSE_BYTES */
    private $overflow = false;

    /** @var array response headers of the request in progress, lower-cased name => value */
    private $responseHeaders = array();

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
        // Real network: options(), writeChunk() and outcome() carry the tested logic.
        // @codeCoverageIgnoreStart
        $this->buffer = '';
        $this->overflow = false;
        $this->responseHeaders = array();

        $ch = curl_init();
        curl_setopt_array($ch, $this->options($method, $url, $headers, $body, $timeout));

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $response === false ? curl_error($ch) : '';
        curl_close($ch);

        return $this->outcome($response, $status, $error);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Turns the finished cURL transfer into the transport answer and resets the buffer.
     *
     * @param string|bool $response curl_exec() result; false on failure
     * @param int         $status   HTTP status code
     * @param string      $error    curl_error() text of a failed transfer
     * @return array status, body, error and, for a finished transfer, headers
     */
    public function outcome($response, $status, $error)
    {
        $answer = $this->buffer;
        $this->buffer = '';
        $headers = $this->responseHeaders;
        $this->responseHeaders = array();

        if ($response === false) {
            if ($this->overflow) {
                $error = 'iFirma answer larger than 20 MB';
            }

            return array('status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'unknown transport error');
        }

        return array('status' => (int) $status, 'body' => $answer, 'error' => '', 'headers' => $headers);
    }

    /**
     * The cURL options of one request. Only http and https are spoken, redirects are never followed (so a
     * redirect cannot move the signed request to another host or protocol) and the answer is capped.
     *
     * @param string $method
     * @param string $url
     * @param array  $headers
     * @param string $body
     * @param int    $timeout seconds
     * @return array CURLOPT_* => value
     */
    public function options($method, $url, array $headers, $body, $timeout)
    {
        $timeout = max(1, (int) $timeout);
        $protocols = (defined('CURLPROTO_HTTP') ? CURLPROTO_HTTP : 1) | (defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2);

        $options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'PrestaShop-iFirma/1.1',
            CURLOPT_PROTOCOLS => $protocols,
            CURLOPT_REDIR_PROTOCOLS => $protocols,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => array($this, 'writeChunk'),
            CURLOPT_HEADERFUNCTION => array($this, 'collectHeader'),
        );

        $method = strtoupper((string) $method);
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method !== 'GET') {
            // PUT (contractor update) and any other verb: cURL defaults to GET otherwise.
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ((string) $body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        return $options;
    }

    /**
     * CURLOPT_WRITEFUNCTION callback: keeps the chunk and aborts the transfer (-1) once more than
     * MAX_RESPONSE_BYTES have arrived.
     *
     * @param resource|null $ch
     * @param string        $chunk
     * @return int the number of bytes taken, or -1 to abort
     */
    public function writeChunk($ch, $chunk)
    {
        $chunk = (string) $chunk;
        if (strlen($this->buffer) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
            $this->overflow = true;
            $this->buffer = '';

            return -1;
        }
        $this->buffer .= $chunk;

        return strlen($chunk);
    }

    /**
     * CURLOPT_HEADERFUNCTION callback: keeps one response header per line. A status line ("HTTP/1.1 200 OK")
     * starts a new header block, so after a redirect or a 100 Continue only the last response counts.
     *
     * @param resource|null $ch
     * @param string        $line
     * @return int the length of the line, as cURL requires
     */
    public function collectHeader($ch, $line)
    {
        $line = (string) $line;
        if (stripos($line, 'HTTP/') === 0) {
            $this->responseHeaders = array();

            return strlen($line);
        }
        $colon = strpos($line, ':');
        if ($colon !== false && $colon > 0) {
            $this->responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
        }

        return strlen($line);
    }

    /**
     * @return string the answer received so far
     */
    public function receivedBody()
    {
        return $this->buffer;
    }

    /**
     * @return bool true when the transfer in progress was aborted by the size cap
     */
    public function exceededTheCap()
    {
        return $this->overflow;
    }
}
