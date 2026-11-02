<?php
/**
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Chooses the iFirma payment method code (SposobZaplaty) for an order.
 *
 * In "order" mode the code follows the order's payment module (merchant map first,
 * then the built-in defaults), then the payment method label; the configured fallback
 * code is used when nothing matches. In "fixed" mode the fallback code is always used.
 */
class IfirmaPaymentMethodResolver
{
    const MODE_ORDER = 'order';
    const MODE_FIXED = 'fixed';

    const DEFAULT_CODE = 'PRZ';

    /** @var string */
    private $mode;

    /** @var string */
    private $fixedCode;

    /** @var array pattern => code */
    private $merchantMap;

    /**
     * @return array code => English label
     */
    public static function codes()
    {
        return array(
            'GTK' => 'Cash',
            'POB' => 'Cash on delivery',
            'PRZ' => 'Bank transfer',
            'KAR' => 'Card',
            'PZA' => 'Direct debit',
            'CZK' => 'Cheque',
            'KOM' => 'Compensation',
            'BAR' => 'Barter',
            'DOT' => 'DotPay',
            'PAL' => 'PayPal',
            'ALG' => 'PayU',
            'P24' => 'Przelewy24',
            'TPA' => 'tpay.com',
            'ELE' => 'Electronic payment',
        );
    }

    /**
     * @return array module pattern => code (patterns lowercase, optional trailing *)
     */
    public static function defaultModuleMap()
    {
        return array(
            'ps_wirepayment' => 'PRZ',
            'ps_checkpayment' => 'CZK',
            'ps_cashondelivery' => 'POB',
            'cashondelivery*' => 'POB',
            'paypal*' => 'PAL',
            'payu*' => 'ALG',
            'przelewy24*' => 'P24',
            'p24*' => 'P24',
            'tpay*' => 'TPA',
            'dotpay*' => 'DOT',
            'stripe*' => 'KAR',
            'adyen*' => 'KAR',
            'braintree*' => 'KAR',
            'sumup*' => 'KAR',
            'authorize*' => 'KAR',
            'blik*' => 'ELE',
            'mollie*' => 'ELE',
            'klarna*' => 'ELE',
            'payplug*' => 'ELE',
            'hipay*' => 'ELE',
            'ingenico*' => 'ELE',
            'worldline*' => 'ELE',
            'imoje*' => 'ELE',
            'autopay*' => 'ELE',
            'paynow*' => 'ELE',
            'revolut*' => 'ELE',
        );
    }

    /**
     * Parses the merchant map text: one "module=CODE" per line, blank lines and lines
     * starting with # are ignored.
     *
     * @param string $text
     * @return array pattern => code (pattern lowercased/trimmed, code uppercased)
     * @throws InvalidArgumentException listing every bad line as "line N: <text>"
     */
    public static function parseMap($text)
    {
        $codes = self::codes();
        $map = array();
        $errors = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);

        foreach ($lines as $index => $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                $errors[] = 'line ' . ($index + 1) . ': ' . $line;
                continue;
            }

            $module = strtolower(trim(substr($line, 0, $pos)));
            $code = strtoupper(trim(substr($line, $pos + 1)));

            if (!preg_match('/^[a-z0-9_.\-]+\*?$/', $module) || !isset($codes[$code])) {
                $errors[] = 'line ' . ($index + 1) . ': ' . $line;
                continue;
            }

            $map[$module] = $code;
        }

        if ($errors) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        return $map;
    }

    /**
     * @param string $mode       MODE_ORDER or MODE_FIXED
     * @param string $fixedCode  fallback code; defaults to PRZ when empty or unknown
     * @param array  $merchantMap pattern => code, checked before the default map
     */
    public function __construct($mode, $fixedCode, array $merchantMap = array())
    {
        $this->mode = $mode;
        $code = strtoupper(trim((string) $fixedCode));
        $this->fixedCode = isset(self::codes()[$code]) ? $code : self::DEFAULT_CODE;
        $this->merchantMap = $merchantMap;
    }

    /**
     * Reads the settings from Configuration. A merchant map that fails to parse is ignored.
     *
     * @return IfirmaPaymentMethodResolver
     */
    public static function build()
    {
        $mode = Configuration::get('IFIRMA_PAYMENT_METHOD_MODE') ?: self::MODE_ORDER;
        $fixed = Configuration::get('IFIRMA_PAYMENT_METHOD') ?: self::DEFAULT_CODE;

        try {
            $map = self::parseMap((string) Configuration::get('IFIRMA_PAYMENT_METHOD_MAP'));
        } catch (InvalidArgumentException $e) {
            $map = array();
        }

        return new self($mode, $fixed, $map);
    }

    /**
     * @param Order $order
     * @return string payment method code
     */
    public function resolve(Order $order)
    {
        if ($this->mode === self::MODE_FIXED) {
            return $this->fixedCode;
        }

        $code = $this->resolveFromModule($order->module);
        if ($code === null) {
            $code = $this->resolveFromLabel($order->payment);
        }

        return $code !== null ? $code : $this->fixedCode;
    }

    /**
     * @param string $module payment module name
     * @return string|null merchant map first (exact, then wildcard), then default map
     */
    public function resolveFromModule($module)
    {
        $name = strtolower(trim((string) $module));
        if ($name === '') {
            return null;
        }

        $code = self::matchPattern($this->merchantMap, $name);
        if ($code === null) {
            $code = self::matchPattern(self::defaultModuleMap(), $name);
        }

        return $code;
    }

    /**
     * @param string $label payment method label shown to the customer
     * @return string|null code of the first keyword group found in the label
     */
    public function resolveFromLabel($label)
    {
        $text = ' ' . mb_strtolower((string) $label) . ' ';

        $groups = array(
            array(array('pobran', 'cash on delivery', 'cod '), 'POB'),
            array(array('paypal'), 'PAL'),
            array(array('payu'), 'ALG'),
            array(array('przelewy24', 'p24'), 'P24'),
            array(array('tpay'), 'TPA'),
            array(array('dotpay'), 'DOT'),
            array(array('blik'), 'ELE'),
            array(array('gotów', 'gotow', 'cash'), 'GTK'),
            array(array('kart', 'card', 'visa', 'mastercard'), 'KAR'),
            array(array('czek', 'check', 'cheque'), 'CZK'),
            array(array('przelew', 'wire', 'transfer', 'bank'), 'PRZ'),
            array(array('online', 'elektron', 'electronic'), 'ELE'),
        );

        foreach ($groups as $group) {
            foreach ($group[0] as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    return $group[1];
                }
            }
        }

        return null;
    }

    /**
     * @return string MODE_ORDER or MODE_FIXED
     */
    public function getMode()
    {
        return $this->mode;
    }

    /**
     * @return string the fallback code (always a valid code)
     */
    public function getFixedCode()
    {
        return $this->fixedCode;
    }

    /**
     * Exact match first, then wildcard patterns ("foo*" matches names starting with "foo").
     *
     * @param array  $map  pattern => code
     * @param string $name lowercased module name
     * @return string|null
     */
    private static function matchPattern(array $map, $name)
    {
        if (isset($map[$name])) {
            return $map[$name];
        }

        foreach ($map as $pattern => $code) {
            if (substr($pattern, -1) === '*' && strpos($name, substr($pattern, 0, -1)) === 0) {
                return $code;
            }
        }

        return null;
    }
}
