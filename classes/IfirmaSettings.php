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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaApiClient.php';
require_once dirname(__FILE__) . '/IfirmaDeliveryService.php';
require_once dirname(__FILE__) . '/IfirmaDocumentSettings.php';
require_once dirname(__FILE__) . '/IfirmaKsefService.php';
require_once dirname(__FILE__) . '/IfirmaPaymentMethodResolver.php';

/**
 * The settings form as a table: every Configuration key the form stores, in the order they are stored, with the
 * kind of value it holds and what the form makes of what was posted.
 *
 * fromInput() applies the table to a request and returns the normalised values and the errors.
 * Ifirma::postProcessConfiguration() reads the request, reports the errors and stores the values, one
 * Configuration write per key in table order. The class is pure: it reads no request, no Configuration, no Db and
 * no Context, and it has no words of its own. An error is only a key. The module owns the messages: the
 * translation scanner finds l() literals in ifirma.php only, so it keeps one for each key there.
 *
 * A row is an array with a `type` (one of the TYPE_* constants) and the cells that type reads:
 *  - min, max  bounds of a TYPE_RANGE (both included); the lowest value of a TYPE_DECIMAL; for a TYPE_TEXT, max is
 *              the number of characters it is cut to
 *  - default   what an empty text, or a choice that is none of the options, becomes; without a default it is an error
 *  - options   the values a TYPE_CHOICE accepts
 *  - trim      false to compare a TYPE_CHOICE as posted, with the spaces round it
 *  - with      the callable that makes the value of a TYPE_NORMALISED
 */
class IfirmaSettings
{
    /**
     * Testability seam for the shop's developer mode: null reads _PS_MODE_DEV_, a boolean forces the answer.
     *
     * @var bool|null
     */
    public static $devMode = null;

    /** Trimmed text. An empty text becomes the default; with no default it is an error (a required field). */
    const TYPE_TEXT = 'text';

    /** Like TYPE_TEXT, and a text that is not empty must be hexadecimal (an API key). */
    const TYPE_HEX = 'hex';

    /** Like TYPE_TEXT, and a text that is not empty must be an e-mail address. */
    const TYPE_EMAIL = 'email';

    /**
     * Like TYPE_TEXT, and a text that is not empty must be an address the module may call (isAcceptableBaseUrl()).
     */
    const TYPE_URL = 'url';

    /** Whatever was posted, not trimmed and not cast (false when nothing was posted). */
    const TYPE_RAW = 'raw';

    /** 1 for anything PHP reads as true, 0 for the rest. */
    const TYPE_SWITCH = 'switch';

    /** The integer PHP casts the value to; it is never an error. */
    const TYPE_INT = 'int';

    /** A whole number from min to max, written with digits only. Anything else is an error. */
    const TYPE_RANGE = 'range';

    /**
     * A number from min up. A comma counts as the decimal point. It is stored as the shortest text with at most
     * four decimals. Anything else is an error.
     */
    const TYPE_DECIMAL = 'decimal';

    /** One of the options, trimmed unless the row says otherwise. Anything else becomes the default, or is an error. */
    const TYPE_CHOICE = 'choice';

    /** Whatever the callable `with` makes of the posted value; it is never an error. */
    const TYPE_NORMALISED = 'normalised';

    /**
     * Trimmed text of one line: more than max characters, or a control character, is an error (the text is refused,
     * not cut). An empty text becomes the default.
     */
    const TYPE_LINE = 'line';

    /**
     * The first segment of the order stamp: spaces and a trailing `-` are stripped, an empty text becomes the
     * default, and what is left must be 1 to 32 characters of A-Z a-z 0-9 . _ -
     */
    const TYPE_STAMP_PREFIX = 'stamp_prefix';

    /** The address of the shop's webpage: empty, or an absolute http(s) URL (max characters) with a host and no user information. */
    const TYPE_WEB_URL = 'web_url';

    /** Longest order stamp prefix. */
    const STAMP_PREFIX_MAX = 32;

    /** An iFirma payment method code (SposobZaplaty): trimmed, upper case, one of IfirmaPaymentMethodResolver::codes(). */
    const TYPE_PAYMENT_CODE = 'payment_code';

    /** The payment method map: parsed, and written again the way IfirmaPaymentMethodResolver::formatMap() writes it. */
    const TYPE_PAYMENT_MAP = 'payment_map';

    /**
     * @return array[] Configuration key => row, in the order the keys are stored
     */
    public static function fields()
    {
        $documents = IfirmaDocumentSettings::class;
        $channels = array(IfirmaDeliveryService::CHANNEL_SHOP_MAIL, IfirmaDeliveryService::CHANNEL_IFIRMA_MAIL, IfirmaDeliveryService::CHANNEL_NONE);
        $paymentModes = array(IfirmaPaymentMethodResolver::MODE_ORDER, IfirmaPaymentMethodResolver::MODE_FIXED);

        return array(
            'IFIRMA_BASE_URL' => array('type' => self::TYPE_URL, 'default' => IfirmaApiClient::BASE_URL),
            'IFIRMA_USER' => array('type' => self::TYPE_TEXT),
            'IFIRMA_KEY_FAKTURA' => array('type' => self::TYPE_HEX, 'default' => ''),
            'IFIRMA_KEY_ABONENT' => array('type' => self::TYPE_HEX, 'default' => ''),
            'IFIRMA_KEY_RACHUNEK' => array('type' => self::TYPE_HEX, 'default' => ''),
            'IFIRMA_DOC_TYPE' => array('type' => self::TYPE_NORMALISED, 'with' => array($documents, 'normalizeDocumentType')),
            'IFIRMA_SIGNATURE_TYPE' => array('type' => self::TYPE_NORMALISED, 'with' => array($documents, 'normalizeSignatureType')),
            'IFIRMA_PAYMENT_METHOD' => array('type' => self::TYPE_PAYMENT_CODE),
            'IFIRMA_PAYMENT_METHOD_MODE' => array('type' => self::TYPE_CHOICE, 'options' => $paymentModes),
            'IFIRMA_PAYMENT_METHOD_MAP' => array('type' => self::TYPE_PAYMENT_MAP),
            'IFIRMA_CONTRACTOR_SYNC' => array('type' => self::TYPE_SWITCH),
            'IFIRMA_PAYMENT_TYPE_TOKEN' => array('type' => self::TYPE_TEXT, 'default' => ''),
            'IFIRMA_DEFAULT_UNIT' => array('type' => self::TYPE_RAW),
            'IFIRMA_AUTO_CREATE_STATE' => array('type' => self::TYPE_INT),
            'IFIRMA_MARK_PAID_ON_CREATE' => array('type' => self::TYPE_INT),
            'IFIRMA_SEND_CHANNEL' => array('type' => self::TYPE_CHOICE, 'options' => $channels, 'default' => IfirmaDeliveryService::CHANNEL_SHOP_MAIL),
            'IFIRMA_SEND_TEXT' => array('type' => self::TYPE_TEXT, 'max' => IfirmaDeliveryService::TEXT_MAX, 'default' => ''),
            'IFIRMA_SEND_COPY_MODE' => array('type' => self::TYPE_CHOICE, 'options' => IfirmaDeliveryService::copyModes(), 'default' => IfirmaDeliveryService::COPY_NONE, 'trim' => false),
            'IFIRMA_SEND_COPY_EMPLOYEE' => array('type' => self::TYPE_INT),
            'IFIRMA_KSEF_MODE' => array('type' => self::TYPE_NORMALISED, 'with' => array(IfirmaKsefService::class, 'normalizeMode')),
            'IFIRMA_DUPLICATE_CHECK' => array('type' => self::TYPE_SWITCH),
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => array('type' => self::TYPE_RANGE, 'min' => 1, 'max' => 365),
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => array('type' => self::TYPE_DECIMAL, 'min' => 0),
            'IFIRMA_DOC_TEMPLATE' => array('type' => self::TYPE_LINE, 'max' => 100, 'default' => ''),
            'IFIRMA_ORDER_STAMP_PREFIX' => array('type' => self::TYPE_STAMP_PREFIX, 'default' => 'PS-ORDER'),
            'IFIRMA_SHOP_URL' => array('type' => self::TYPE_WEB_URL, 'max' => 255, 'default' => ''),
            'IFIRMA_ALLOW_DATA_REQUEST' => array('type' => self::TYPE_SWITCH),
            'IFIRMA_DATA_REQUEST_AUTO_ACCEPT' => array('type' => self::TYPE_CHOICE, 'options' => array('none', 'safe', 'all'), 'default' => 'none'),
            'IFIRMA_NOTIFY_EMAIL' => array('type' => self::TYPE_EMAIL, 'default' => ''),
            'IFIRMA_SYNC_INTERVAL_MIN' => array('type' => self::TYPE_RANGE, 'min' => 1, 'max' => 1440),
            'IFIRMA_CRON_MAX_CALLS' => array('type' => self::TYPE_RANGE, 'min' => 1, 'max' => 90),
            'IFIRMA_CRON_MAX_ITEMS' => array('type' => self::TYPE_RANGE, 'min' => 1, 'max' => 50),
            'IFIRMA_RATE_MAX_PER_MIN' => array('type' => self::TYPE_RANGE, 'min' => 1, 'max' => 100),
            'IFIRMA_RATE_MAX_PER_DAY' => array('type' => self::TYPE_RANGE, 'min' => 1, 'max' => 15000),
        );
    }

    /**
     * Applies the table to a request.
     *
     * A field the request does not carry reads as false, which is what Tools::getValue() returns for it.
     * A copy of the delivery to an employee needs an employee who is active. Whether it is one depends on the
     * shop, so the caller supplies the ids, and the callable runs only when the copy goes to an employee,
     * however the employee field is filled in.
     *
     * @param array    $input             Configuration key => the value posted for it
     * @param callable $activeEmployeeIds called without arguments; returns the ids (integers) of the active employees
     * @return array array('values' => key => normalised value, in table order, null for a field that is an error;
     *               'errors' => key => detail for every field that is an error, in table order; the detail is ''
     *               except for the payment method map, where it lists the bad lines)
     */
    public static function fromInput(array $input, callable $activeEmployeeIds)
    {
        $values = array();
        $errors = array();
        foreach (self::fields() as $key => $row) {
            try {
                $values[$key] = self::normalise($row, isset($input[$key]) ? $input[$key] : false);
            } catch (InvalidArgumentException $e) {
                $values[$key] = null;
                $errors[$key] = $e->getMessage();
            }
        }
        if (self::lacksAnActiveEmployee($values, $activeEmployeeIds)) {
            $values['IFIRMA_SEND_COPY_EMPLOYEE'] = null;
            $errors['IFIRMA_SEND_COPY_EMPLOYEE'] = '';
        }

        return array('values' => $values, 'errors' => $errors);
    }

    /**
     * @param array    $values            the normalised values
     * @param callable $activeEmployeeIds see fromInput()
     * @return bool true when the copy goes to an employee and the one chosen is not an active employee
     */
    private static function lacksAnActiveEmployee(array $values, callable $activeEmployeeIds)
    {
        if ($values['IFIRMA_SEND_COPY_MODE'] !== IfirmaDeliveryService::COPY_EMPLOYEE) {
            return false;
        }
        $active = call_user_func($activeEmployeeIds);
        $chosen = $values['IFIRMA_SEND_COPY_EMPLOYEE'];

        return $chosen <= 0 || !in_array($chosen, $active, true);
    }

    /**
     * @param array $row the row of the key
     * @param mixed $raw what was posted for it
     * @return mixed the value to store
     * @throws InvalidArgumentException when the value is an error; the message is its detail
     */
    private static function normalise(array $row, $raw)
    {
        switch ($row['type']) {
            case self::TYPE_TEXT:
                return self::text($row, $raw, null);
            case self::TYPE_HEX:
                return self::text($row, $raw, array('IfirmaSettings', 'isHexKey'));
            case self::TYPE_EMAIL:
                return self::text($row, $raw, array('Validate', 'isEmail'));
            case self::TYPE_URL:
                return self::text($row, $raw, array('IfirmaSettings', 'isAcceptableBaseUrl'));
            case self::TYPE_RAW:
                return is_scalar($raw) ? $raw : false;
            case self::TYPE_SWITCH:
                return (int) (bool) $raw;
            case self::TYPE_INT:
                return (int) $raw;
            case self::TYPE_RANGE:
                return self::range($row, $raw);
            case self::TYPE_DECIMAL:
                return self::decimal($row, $raw);
            case self::TYPE_CHOICE:
                return self::choice($row, $raw);
            case self::TYPE_NORMALISED:
                return call_user_func($row['with'], is_scalar($raw) ? $raw : false);
            case self::TYPE_LINE:
                return self::line($row, $raw);
            case self::TYPE_STAMP_PREFIX:
                return self::stampPrefix($row, $raw);
            case self::TYPE_WEB_URL:
                return self::webUrl($row, $raw);
            case self::TYPE_PAYMENT_CODE:
                return self::paymentCode($raw);
            case self::TYPE_PAYMENT_MAP:
                // parseMap() throws an InvalidArgumentException that lists every bad line: that list is the detail
                return IfirmaPaymentMethodResolver::formatMap(IfirmaPaymentMethodResolver::parseMap(self::scalarText($raw)));
        }
    }

    /**
     * @param string $text
     * @return bool a hexadecimal string of even length: an API key is a sequence of whole bytes
     */
    public static function isHexKey($text)
    {
        $text = (string) $text;

        return ctype_xdigit($text) && strlen($text) % 2 === 0;
    }

    /**
     * Whether the module may send its signed requests to this address: an absolute URL without credentials,
     * over https, or over http when the shop is in developer mode or the host is local (localhost, loopback,
     * private ranges, or a single-label name such as a Docker service). A query string or a fragment is refused:
     * the module appends its own path and query. An IPv6 literal counts as local only when it is ::1 or a
     * private, link-local or reserved address; a public one never gets plain http.
     *
     * @param string $url
     * @return bool
     */
    public static function isAcceptableBaseUrl($url)
    {
        $url = (string) $url;
        if (method_exists('Validate', 'isAbsoluteUrl') && !Validate::isAbsoluteUrl($url)) {
            return false;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && (self::inDevMode() || self::isLocalHost($parts['host']));
    }

    /**
     * @return bool
     */
    private static function inDevMode()
    {
        if (self::$devMode !== null) {
            return (bool) self::$devMode;
        }

        return defined('_PS_MODE_DEV_') && _PS_MODE_DEV_;
    }

    /**
     * @param string $host
     * @return bool
     */
    private static function isLocalHost($host)
    {
        $host = strtolower(trim($host, '[]'));
        if (strpos($host, ':') !== false) {
            return self::isLocalIpv6($host);
        }

        return $host === 'localhost'
            || strpos($host, '.') === false
            || (bool) preg_match('/^(127|10)\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)
            || (bool) preg_match('/^172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}$/', $host)
            || (bool) preg_match('/^192\.168\.\d{1,3}\.\d{1,3}$/', $host);
    }

    /**
     * @param string $host an IPv6 literal without brackets
     * @return bool ::1, or an address in a private, link-local or reserved range
     */
    private static function isLocalIpv6($host)
    {
        if ($host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @param array         $row
     * @param mixed         $raw
     * @param callable|null $format a text that is not empty must pass it
     * @return string
     * @throws InvalidArgumentException
     */
    private static function text(array $row, $raw, $format)
    {
        $text = trim(self::scalarText($raw));
        if ($text === '') {
            return self::defaultOf($row);
        }
        if ($format !== null && !call_user_func($format, $text)) {
            throw new InvalidArgumentException('');
        }

        return isset($row['max']) ? mb_substr($text, 0, $row['max']) : $text;
    }

    /**
     * @param array $row
     * @param mixed $raw
     * @return string
     * @throws InvalidArgumentException for a control character, or for more than max characters
     */
    private static function line(array $row, $raw)
    {
        $text = trim(self::scalarText($raw));
        if ($text === '') {
            return self::defaultOf($row);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $text) || mb_strlen($text) > $row['max']) {
            throw new InvalidArgumentException('');
        }

        return $text;
    }

    /**
     * @param array $row
     * @param mixed $raw
     * @return string
     * @throws InvalidArgumentException when what is left is not 1 to 32 characters of A-Z a-z 0-9 . _ -
     */
    private static function stampPrefix(array $row, $raw)
    {
        $text = rtrim(trim(self::scalarText($raw)), '- ');
        if ($text === '') {
            return self::defaultOf($row);
        }
        if (strlen($text) > self::STAMP_PREFIX_MAX || !preg_match('/^[A-Za-z0-9._-]+$/D', $text)) {
            throw new InvalidArgumentException('');
        }

        return $text;
    }

    /**
     * @param array $row
     * @param mixed $raw
     * @return string
     * @throws InvalidArgumentException when the text is not an absolute http(s) URL with a host and no user information
     */
    private static function webUrl(array $row, $raw)
    {
        $text = trim(self::scalarText($raw));
        if ($text === '') {
            return self::defaultOf($row);
        }
        if (mb_strlen($text) > $row['max'] || !self::isShopWebpage($text)) {
            throw new InvalidArgumentException('');
        }

        return $text;
    }

    /**
     * @param string $url
     * @return bool an absolute http or https URL with a host and without user information (any host: no developer mode rule)
     */
    public static function isShopWebpage($url)
    {
        $url = (string) $url;
        if (preg_match('/[\x00-\x20\x7F]/', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        return $scheme === 'http' || $scheme === 'https';
    }

    /**
     * @param array $row
     * @param mixed $raw
     * @return int
     * @throws InvalidArgumentException
     */
    private static function range(array $row, $raw)
    {
        $text = trim(self::scalarText($raw));
        $number = ($text !== '' && ctype_digit($text)) ? (int) $text : null;
        if ($number === null || $number < $row['min'] || $number > $row['max']) {
            throw new InvalidArgumentException('');
        }

        return $number;
    }

    /**
     * @param array $row
     * @param mixed $raw
     * @return string
     * @throws InvalidArgumentException
     */
    private static function decimal(array $row, $raw)
    {
        $text = str_replace(',', '.', trim(self::scalarText($raw)));
        if ($text === '' || !is_numeric($text) || (float) $text < $row['min']) {
            throw new InvalidArgumentException('');
        }

        // sprintf() always writes a digit in front of the point, so what is left of the number is never empty
        return rtrim(rtrim(sprintf('%.4F', (float) $text), '0'), '.');
    }

    /**
     * @param array $row
     * @param mixed $raw
     * @return string
     * @throws InvalidArgumentException
     */
    private static function choice(array $row, $raw)
    {
        // an array is no option: it reads as an unknown one, so the default (or the error of a row without one)
        $text = is_scalar($raw) ? (string) $raw : '';
        if (!isset($row['trim']) || $row['trim']) {
            $text = trim($text);
        }
        if (in_array($text, $row['options'], true)) {
            return $text;
        }

        return self::defaultOf($row);
    }

    /**
     * @param mixed $raw
     * @return string
     * @throws InvalidArgumentException
     */
    private static function paymentCode($raw)
    {
        $code = strtoupper(trim(self::scalarText($raw)));
        if (!isset(IfirmaPaymentMethodResolver::codes()[$code])) {
            throw new InvalidArgumentException('');
        }

        return $code;
    }

    /**
     * @param mixed $raw what was posted
     * @return string the text of a scalar (false, nothing posted, is the empty text)
     * @throws InvalidArgumentException for an array or an object: a request can post `name[]=x`, and it is no text
     */
    private static function scalarText($raw)
    {
        if (!is_scalar($raw) && $raw !== null) {
            throw new InvalidArgumentException('');
        }

        return (string) $raw;
    }

    /**
     * @param array $row
     * @return mixed the default of the row
     * @throws InvalidArgumentException when the row has none
     */
    private static function defaultOf(array $row)
    {
        if (!isset($row['default'])) {
            throw new InvalidArgumentException('');
        }

        return $row['default'];
    }
}
