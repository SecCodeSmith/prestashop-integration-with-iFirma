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

use PHPUnit\Framework\TestCase;

/**
 * IfirmaSettings (refactor-srp-splits 7.2): the table of the settings form and what fromInput() makes of a
 * request: each type, each bound, each default, the shape of the answer, the one rule that needs the shop (the
 * employee of the delivery copy) and that the class asks nothing of the shop otherwise. SettingsGoldenTest and
 * SettingsSaveTest drive the same code through Ifirma::getContent(), with the module's own words.
 */
class IfirmaSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        // the class is loaded the way production loads it: through the module's require list
        IfirmaTestModuleLoader::loadModule();
    }

    /**
     * @param array $input
     * @param int[] $employees the ids of the active employees
     * @return array array('values' => ..., 'errors' => ...)
     */
    private function fromRequest(array $input, array $employees = array())
    {
        return IfirmaSettings::fromInput($input, function () use ($employees) {
            return $employees;
        });
    }

    /**
     * @param array $changes
     * @return array the smallest request that no check refuses, with the changes
     */
    private function validRequest(array $changes = array())
    {
        return $changes + array(
            'IFIRMA_USER' => 'firma@example.test',
            'IFIRMA_PAYMENT_METHOD' => 'PRZ',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'order',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '30',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0',
            'IFIRMA_SYNC_INTERVAL_MIN' => '15',
            'IFIRMA_CRON_MAX_CALLS' => '10',
            'IFIRMA_CRON_MAX_ITEMS' => '10',
            'IFIRMA_RATE_MAX_PER_MIN' => '60',
            'IFIRMA_RATE_MAX_PER_DAY' => '12000',
        );
    }

    /**
     * @param string $key
     * @param mixed  $posted
     * @return mixed what the table makes of the value posted for the key
     */
    private function valueOf($key, $posted)
    {
        $result = $this->fromRequest(array($key => $posted));

        return $result['values'][$key];
    }

    /**
     * @param string $key
     * @param mixed  $posted
     * @return string|null the detail of the error of the key, null when it is no error
     */
    private function errorOf($key, $posted)
    {
        $result = $this->fromRequest(array($key => $posted));

        return array_key_exists($key, $result['errors']) ? $result['errors'][$key] : null;
    }

    // ---- the table ----------------------------------------------------------------------------

    public function testTheTableHoldsEveryKeyTheFormStoresInTheOrderItStoresThem()
    {
        $this->assertSame(array(
            'IFIRMA_BASE_URL', 'IFIRMA_USER', 'IFIRMA_KEY_FAKTURA', 'IFIRMA_KEY_ABONENT', 'IFIRMA_KEY_RACHUNEK',
            'IFIRMA_DOC_TYPE', 'IFIRMA_SIGNATURE_TYPE', 'IFIRMA_PAYMENT_METHOD', 'IFIRMA_PAYMENT_METHOD_MODE',
            'IFIRMA_PAYMENT_METHOD_MAP', 'IFIRMA_CONTRACTOR_SYNC', 'IFIRMA_PAYMENT_TYPE_TOKEN', 'IFIRMA_DEFAULT_UNIT',
            'IFIRMA_AUTO_CREATE_STATE', 'IFIRMA_MARK_PAID_ON_CREATE', 'IFIRMA_SEND_CHANNEL', 'IFIRMA_SEND_TEXT',
            'IFIRMA_SEND_COPY_MODE', 'IFIRMA_SEND_COPY_EMPLOYEE', 'IFIRMA_KSEF_MODE', 'IFIRMA_DUPLICATE_CHECK',
            'IFIRMA_DUPLICATE_WINDOW_DAYS', 'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', 'IFIRMA_DOC_TEMPLATE',
            'IFIRMA_ORDER_STAMP_PREFIX', 'IFIRMA_SHOP_URL', 'IFIRMA_ALLOW_DATA_REQUEST',
            'IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'IFIRMA_NOTIFY_EMAIL', 'IFIRMA_SYNC_INTERVAL_MIN', 'IFIRMA_CRON_MAX_CALLS', 'IFIRMA_CRON_MAX_ITEMS', 'IFIRMA_RATE_MAX_PER_MIN',
            'IFIRMA_RATE_MAX_PER_DAY',
        ), array_keys(IfirmaSettings::fields()));
    }

    public function testEveryKeyOfTheTableHasAnInstallDefault()
    {
        $this->assertSame(
            array(),
            array_values(array_diff(array_keys(IfirmaSettings::fields()), array_keys(IfirmaSchema::configurationDefaults()))),
            'a key the form stores that the module does not know at install'
        );
    }

    public function testEveryRowHasATypeTheClassKnows()
    {
        $types = array();
        foreach ((new ReflectionClass('IfirmaSettings'))->getConstants() as $name => $value) {
            if (strpos($name, 'TYPE_') === 0) {
                $types[] = $value;
            }
        }

        $used = array_values(array_unique(array_column(IfirmaSettings::fields(), 'type')));

        $this->assertNotEmpty($types, 'the scan found the types');
        $this->assertSame(array(), array_values(array_diff($used, $types)), 'a row with a type the class does not have');
        $this->assertSame(array(), array_values(array_diff($types, $used)), 'a type that no row uses');
    }

    // ---- the answer ---------------------------------------------------------------------------

    public function testARequestWithNothingFilledInGetsTheDefaultsAndTheErrorsOfTheRequiredFields()
    {
        $result = $this->fromRequest(array());

        $this->assertSame(array(
            'IFIRMA_BASE_URL' => 'https://www.ifirma.pl/iapi/',
            'IFIRMA_USER' => null,
            'IFIRMA_KEY_FAKTURA' => '',
            'IFIRMA_KEY_ABONENT' => '',
            'IFIRMA_KEY_RACHUNEK' => '',
            'IFIRMA_DOC_TYPE' => 'fakturakraj',
            'IFIRMA_SIGNATURE_TYPE' => 'BPO',
            'IFIRMA_PAYMENT_METHOD' => null,
            'IFIRMA_PAYMENT_METHOD_MODE' => null,
            'IFIRMA_PAYMENT_METHOD_MAP' => '',
            'IFIRMA_CONTRACTOR_SYNC' => 0,
            'IFIRMA_PAYMENT_TYPE_TOKEN' => '',
            'IFIRMA_DEFAULT_UNIT' => false,
            'IFIRMA_AUTO_CREATE_STATE' => 0,
            'IFIRMA_MARK_PAID_ON_CREATE' => 0,
            'IFIRMA_SEND_CHANNEL' => 'shop_mail',
            'IFIRMA_SEND_TEXT' => '',
            'IFIRMA_SEND_COPY_MODE' => 'none',
            'IFIRMA_SEND_COPY_EMPLOYEE' => 0,
            'IFIRMA_KSEF_MODE' => 'b2b',
            'IFIRMA_DUPLICATE_CHECK' => 0,
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => null,
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => null,
            'IFIRMA_DOC_TEMPLATE' => '',
            'IFIRMA_ORDER_STAMP_PREFIX' => 'PS-ORDER',
            'IFIRMA_SHOP_URL' => '',
            'IFIRMA_ALLOW_DATA_REQUEST' => 0,
            'IFIRMA_DATA_REQUEST_AUTO_ACCEPT' => 'none',
            'IFIRMA_NOTIFY_EMAIL' => '',
            'IFIRMA_SYNC_INTERVAL_MIN' => null,
            'IFIRMA_CRON_MAX_CALLS' => null,
            'IFIRMA_CRON_MAX_ITEMS' => null,
            'IFIRMA_RATE_MAX_PER_MIN' => null,
            'IFIRMA_RATE_MAX_PER_DAY' => null,
        ), $result['values']);
        $this->assertSame(array(
            'IFIRMA_USER' => '',
            'IFIRMA_PAYMENT_METHOD' => '',
            'IFIRMA_PAYMENT_METHOD_MODE' => '',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '',
            'IFIRMA_SYNC_INTERVAL_MIN' => '',
            'IFIRMA_CRON_MAX_CALLS' => '',
            'IFIRMA_CRON_MAX_ITEMS' => '',
            'IFIRMA_RATE_MAX_PER_MIN' => '',
            'IFIRMA_RATE_MAX_PER_DAY' => '',
        ), $result['errors']);
    }

    public function testAValidRequestHasNoErrorsAndEveryValueIsNormalised()
    {
        $result = $this->fromRequest(array(
            'submitIfirma' => '1',
            'IFIRMA_BASE_URL' => ' https://sandbox.example/iapi/ ',
            'IFIRMA_USER' => ' firma@example.test ',
            'IFIRMA_KEY_FAKTURA' => '0123456789abcdef',
            'IFIRMA_PAYMENT_METHOD' => 'kar',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'fixed',
            'IFIRMA_PAYMENT_METHOD_MAP' => "ps_wirepayment = prz\r\n# note\r\nmygateway* = ele",
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '45',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0,050',
            'IFIRMA_SYNC_INTERVAL_MIN' => '30',
            'IFIRMA_CRON_MAX_CALLS' => '20',
            'IFIRMA_CRON_MAX_ITEMS' => '15',
            'IFIRMA_RATE_MAX_PER_MIN' => '60',
            'IFIRMA_RATE_MAX_PER_DAY' => '12000',
        ));

        $this->assertSame(array(), $result['errors']);
        $this->assertSame(array_keys(IfirmaSettings::fields()), array_keys($result['values']), 'a value for every key, in table order');
        $this->assertSame('https://sandbox.example/iapi/', $result['values']['IFIRMA_BASE_URL']);
        $this->assertSame('firma@example.test', $result['values']['IFIRMA_USER']);
        $this->assertSame('KAR', $result['values']['IFIRMA_PAYMENT_METHOD']);
        $this->assertSame("ps_wirepayment=PRZ\nmygateway*=ELE", $result['values']['IFIRMA_PAYMENT_METHOD_MAP']);
        $this->assertSame('0.05', $result['values']['IFIRMA_DUPLICATE_AMOUNT_TOLERANCE']);
        $this->assertSame(array(45, 30, 20, 15, 60, 12000), array(
            $result['values']['IFIRMA_DUPLICATE_WINDOW_DAYS'],
            $result['values']['IFIRMA_SYNC_INTERVAL_MIN'],
            $result['values']['IFIRMA_CRON_MAX_CALLS'],
            $result['values']['IFIRMA_CRON_MAX_ITEMS'],
            $result['values']['IFIRMA_RATE_MAX_PER_MIN'],
            $result['values']['IFIRMA_RATE_MAX_PER_DAY'],
        ));
    }

    public function testAFieldThatIsAnErrorHasNoValueAndTheOthersAreNotAffected()
    {
        $result = $this->fromRequest(array(
            'IFIRMA_USER' => 'firma@example.test',
            'IFIRMA_KEY_FAKTURA' => 'not hex',
            'IFIRMA_PAYMENT_METHOD' => 'PRZ',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'order',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '30',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '-1',
            'IFIRMA_SYNC_INTERVAL_MIN' => '15',
            'IFIRMA_CRON_MAX_CALLS' => '10',
            'IFIRMA_CRON_MAX_ITEMS' => '10',
            'IFIRMA_RATE_MAX_PER_MIN' => '60',
            'IFIRMA_RATE_MAX_PER_DAY' => '12000',
        ));

        $this->assertSame(array('IFIRMA_KEY_FAKTURA' => '', 'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => ''), $result['errors'], 'the errors are keyed by setting, in table order');
        $this->assertNull($result['values']['IFIRMA_KEY_FAKTURA']);
        $this->assertNull($result['values']['IFIRMA_DUPLICATE_AMOUNT_TOLERANCE']);
        $this->assertSame('firma@example.test', $result['values']['IFIRMA_USER']);
        $this->assertSame(30, $result['values']['IFIRMA_DUPLICATE_WINDOW_DAYS']);
    }

    public function testKeysThatAreNotInTheTableAreIgnored()
    {
        $result = $this->fromRequest(array('submitIfirma' => '1', 'IFIRMA_CRON_TOKEN' => 'secret', 'junk' => 'x'));

        $this->assertSame(array_keys(IfirmaSettings::fields()), array_keys($result['values']));
        $this->assertArrayNotHasKey('IFIRMA_CRON_TOKEN', $result['errors']);
    }

    public function testANullIsReadLikeAFieldThatWasNotPosted()
    {
        $this->assertFalse($this->valueOf('IFIRMA_DEFAULT_UNIT', null));
        $this->assertSame('', $this->valueOf('IFIRMA_KEY_ABONENT', null));
    }

    // ---- text ---------------------------------------------------------------------------------

    /**
     * @return array[] name => array(key, posted, value)
     */
    public function texts()
    {
        $a = "\u{0105}";

        return array(
            'a login is trimmed' => array('IFIRMA_USER', "  firma@example.test\t", 'firma@example.test'),
            'a login of zero is a login' => array('IFIRMA_USER', '0', '0'),
            'a key is trimmed' => array('IFIRMA_KEY_ABONENT', ' abcd ', 'abcd'),
            'a key is empty' => array('IFIRMA_KEY_ABONENT', '', ''),
            'a key of spaces is empty' => array('IFIRMA_KEY_RACHUNEK', '   ', ''),
            'a token is trimmed' => array('IFIRMA_PAYMENT_TYPE_TOKEN', ' prz_faktura_kraj ', 'prz_faktura_kraj'),
            'a token is not posted' => array('IFIRMA_PAYMENT_TYPE_TOKEN', false, ''),
            'a base url is trimmed' => array('IFIRMA_BASE_URL', ' http://mock/iapi/ ', 'http://mock/iapi/'),
            'an empty base url is the production one' => array('IFIRMA_BASE_URL', '', 'https://www.ifirma.pl/iapi/'),
            'a base url of spaces is the production one' => array('IFIRMA_BASE_URL', '   ', 'https://www.ifirma.pl/iapi/'),
            'a text is trimmed' => array('IFIRMA_SEND_TEXT', "  Thank you\r\n", 'Thank you'),
            'a text keeps its line breaks and markup' => array('IFIRMA_SEND_TEXT', "<b>a</b>\r\nb & \"c\"", "<b>a</b>\r\nb & \"c\""),
            'a text as long as the limit' => array('IFIRMA_SEND_TEXT', str_repeat($a, 1000), str_repeat($a, 1000)),
            'a text one over the limit is cut' => array('IFIRMA_SEND_TEXT', str_repeat($a, 1001), str_repeat($a, 1000)),
            'a text is trimmed and then cut' => array('IFIRMA_SEND_TEXT', '  ' . str_repeat($a, 1001), str_repeat($a, 1000)),
            'a text is cut in characters, not in bytes' => array('IFIRMA_SEND_TEXT', str_repeat($a, 600), str_repeat($a, 600)),
            'no text' => array('IFIRMA_SEND_TEXT', false, ''),
            'a login is not cut at the limit of the text' => array('IFIRMA_USER', str_repeat($a, 1500), str_repeat($a, 1500)),
            'a base url is not cut at the limit of the text' => array('IFIRMA_BASE_URL', 'https://sandbox.example/' . str_repeat('a', 1500) . '/', 'https://sandbox.example/' . str_repeat('a', 1500) . '/'),
            'a token is not cut at the limit of the text' => array('IFIRMA_PAYMENT_TYPE_TOKEN', str_repeat('a', 1500), str_repeat('a', 1500)),
        );
    }

    /**
     * @dataProvider texts
     */
    public function testATextIsTrimmedAndAnEmptyOneBecomesItsDefault($key, $posted, $value)
    {
        $result = $this->fromRequest(array($key => $posted));

        $this->assertSame($value, $result['values'][$key]);
        $this->assertArrayNotHasKey($key, $result['errors']);
    }

    /**
     * @return array[] name => array(posted)
     */
    public function emptyLogins()
    {
        return array('empty' => array(''), 'spaces' => array("  \t "), 'not posted' => array(false), 'null' => array(null));
    }

    /**
     * @dataProvider emptyLogins
     */
    public function testARequiredTextThatIsEmptyIsAnError($posted)
    {
        $result = $this->fromRequest(array('IFIRMA_USER' => $posted));

        $this->assertArrayHasKey('IFIRMA_USER', $result['errors']);
        $this->assertNull($result['values']['IFIRMA_USER']);
    }

    // ---- hexadecimal and e-mail text ----------------------------------------------------------

    /**
     * @return array[] name => array(posted, value)
     */
    public function hexKeys()
    {
        return array(
            'digits' => array('0123456789', '0123456789'),
            'letters in both cases' => array('abcdefABCDEF', 'abcdefABCDEF'),
            'a pair of zeros' => array('00', '00'),
            'padded' => array(' a1b2 ', 'a1b2'),
            'empty' => array('', ''),
            'spaces' => array('   ', ''),
        );
    }

    /**
     * @dataProvider hexKeys
     */
    public function testAHexTextIsAcceptedAndAnEmptyOneIsNoError($posted, $value)
    {
        $result = $this->fromRequest(array('IFIRMA_KEY_FAKTURA' => $posted));

        $this->assertSame($value, $result['values']['IFIRMA_KEY_FAKTURA']);
        $this->assertArrayNotHasKey('IFIRMA_KEY_FAKTURA', $result['errors']);
    }

    /**
     * @return array[] name => array(posted)
     */
    public function keysThatAreNotHex()
    {
        return array(
            'a letter past f' => array('abcg'),
            'an odd length' => array('abc'),
            'a single digit' => array('0'),
            'an odd length of 31 digits' => array('fedcba987654321fedcba987654321a'),
            'a prefix' => array('0x1f'),
            'a space inside' => array('12 34'),
            'a minus' => array('-1'),
            'a dash' => array('abcd-ef'),
            'words' => array('not-a-hex-key'),
            'a letter with an accent' => array("\u{0105}b"),
        );
    }

    /**
     * @dataProvider keysThatAreNotHex
     */
    public function testATextThatIsNotHexadecimalIsAnError($posted)
    {
        $this->assertSame('', $this->errorOf('IFIRMA_KEY_FAKTURA', $posted));
        $this->assertNull($this->valueOf('IFIRMA_KEY_FAKTURA', $posted));
    }

    public function testANotificationAddressIsTrimmedAndAnEmptyOneIsNoError()
    {
        $this->assertSame('biuro@example.test', $this->valueOf('IFIRMA_NOTIFY_EMAIL', '  biuro@example.test '));
        $this->assertSame('', $this->valueOf('IFIRMA_NOTIFY_EMAIL', ''));
        $this->assertSame('', $this->valueOf('IFIRMA_NOTIFY_EMAIL', '   '));
        $this->assertSame('', $this->valueOf('IFIRMA_NOTIFY_EMAIL', false));
        $this->assertNull($this->errorOf('IFIRMA_NOTIFY_EMAIL', ''));
    }

    /**
     * @return array[] name => array(posted)
     */
    public function addressesThatAreNotEmail()
    {
        return array('words' => array('not-an-email'), 'zero' => array('0'), 'no domain' => array('a@'), 'no name' => array('@example.test'), 'two at signs' => array('a@b@example.test'));
    }

    /**
     * @dataProvider addressesThatAreNotEmail
     */
    public function testAnAddressThatIsNotAnEmailAddressIsAnError($posted)
    {
        $this->assertSame('', $this->errorOf('IFIRMA_NOTIFY_EMAIL', $posted));
        $this->assertNull($this->valueOf('IFIRMA_NOTIFY_EMAIL', $posted));
    }

    // ---- raw, switches and integers -----------------------------------------------------------

    /**
     * @return array[] name => array(posted)
     */
    public function units()
    {
        return array('a unit' => array('szt.'), 'a padded unit' => array('  szt.  '), 'zero' => array('0'), 'empty' => array(''), 'not posted' => array(false));
    }

    /**
     * @dataProvider units
     */
    public function testARawValueIsKeptExactlyAsPosted($posted)
    {
        $this->assertSame($posted, $this->valueOf('IFIRMA_DEFAULT_UNIT', $posted));
        $this->assertNull($this->errorOf('IFIRMA_DEFAULT_UNIT', $posted));
    }

    /**
     * @return array[] name => array(posted, value)
     */
    public function switches()
    {
        return array(
            'one' => array('1', 1),
            'zero' => array('0', 0),
            'empty' => array('', 0),
            'not posted' => array(false, 0),
            'two' => array('2', 1),
            'yes' => array('yes', 1),
            'two zeros' => array('00', 1),
            'a padded zero' => array(' 0 ', 1),
            'a decimal zero' => array('0.0', 1),
            'a boolean true' => array(true, 1),
        );
    }

    /**
     * @dataProvider switches
     */
    public function testASwitchIsOneForAnythingPhpReadsAsTrue($posted, $value)
    {
        foreach (array('IFIRMA_CONTRACTOR_SYNC', 'IFIRMA_DUPLICATE_CHECK', 'IFIRMA_ALLOW_DATA_REQUEST') as $key) {
            $this->assertSame($value, $this->valueOf($key, $posted), $key);
            $this->assertNull($this->errorOf($key, $posted), $key);
        }
    }

    /**
     * @return array[] name => array(posted, value)
     */
    public function integers()
    {
        return array(
            'a number' => array('7', 7),
            'zero' => array('0', 0),
            'a negative number' => array('-3', -3),
            'text' => array('abc', 0),
            'a number with text behind' => array('7abc', 7),
            'a decimal number' => array('2.9', 2),
            'an exponent' => array('1e2', 100),
            'a padded number' => array(' 7 ', 7),
            'empty' => array('', 0),
            'not posted' => array(false, 0),
        );
    }

    /**
     * @dataProvider integers
     */
    public function testAnIntegerIsCastTheWayPhpCastsIt($posted, $value)
    {
        foreach (array('IFIRMA_AUTO_CREATE_STATE', 'IFIRMA_MARK_PAID_ON_CREATE') as $key) {
            $this->assertSame($value, $this->valueOf($key, $posted), $key);
            $this->assertNull($this->errorOf($key, $posted), $key);
        }
    }

    // ---- whole numbers in a range -------------------------------------------------------------

    /**
     * @return array[] key => array(key, lowest, highest)
     */
    public function ranges()
    {
        return array(
            'the duplicate window' => array('IFIRMA_DUPLICATE_WINDOW_DAYS', 1, 365),
            'the sync interval' => array('IFIRMA_SYNC_INTERVAL_MIN', 1, 1440),
            'the cron call budget' => array('IFIRMA_CRON_MAX_CALLS', 1, 90),
            'the cron item limit' => array('IFIRMA_CRON_MAX_ITEMS', 1, 50),
            'the API rate limit' => array('IFIRMA_RATE_MAX_PER_MIN', 1, 100),
            'the API day limit' => array('IFIRMA_RATE_MAX_PER_DAY', 1, 15000),
        );
    }

    /**
     * @dataProvider ranges
     */
    public function testARangeAcceptsEveryNumberFromItsLowestToItsHighest($key, $lowest, $highest)
    {
        foreach (array($lowest, $lowest + 1, (int) (($lowest + $highest) / 2), $highest - 1, $highest) as $number) {
            $this->assertSame($number, $this->valueOf($key, (string) $number), $key . ' = ' . $number);
            $this->assertNull($this->errorOf($key, (string) $number), $key . ' = ' . $number);
        }
        $this->assertSame($highest, $this->valueOf($key, " \t" . $highest . "\n"), 'padding is trimmed');
        $this->assertSame($lowest, $this->valueOf($key, '000' . $lowest), 'leading zeros are digits');
        $this->assertSame($lowest, $this->valueOf($key, $lowest), 'an integer is read like its text');
    }

    /**
     * @dataProvider ranges
     */
    public function testARangeRefusesTheNumbersBesideIt($key, $lowest, $highest)
    {
        foreach (array($lowest - 1, $highest + 1, $highest * 10, '99999999999999999999') as $posted) {
            $this->assertSame('', $this->errorOf($key, (string) $posted), $key . ' = ' . $posted);
            $this->assertNull($this->valueOf($key, (string) $posted), $key . ' = ' . $posted);
        }
    }

    /**
     * @dataProvider ranges
     */
    public function testARangeRefusesWhatIsNotWrittenWithDigitsOnly($key, $lowest, $highest)
    {
        foreach (array('', '   ', 'abc', '1.5', '1,5', '-1', '+1', '1e1', '0x1', '1 0', '1a', "\u{0661}", false) as $posted) {
            $this->assertSame('', $this->errorOf($key, $posted), $key . ' = ' . var_export($posted, true));
            $this->assertNull($this->valueOf($key, $posted), $key . ' = ' . var_export($posted, true));
        }
    }

    // ---- the amount tolerance -----------------------------------------------------------------

    /**
     * @return array[] posted => array(posted, value)
     */
    public function tolerances()
    {
        $cases = array();
        foreach (array(
            '0' => '0',
            '0.0' => '0',
            '0,00' => '0',
            '-0' => '0',
            '5' => '5',
            '10' => '10',
            '100' => '100',
            '1.50' => '1.5',
            '1,5' => '1.5',
            '.5' => '0.5',
            '5.' => '5',
            '+3' => '3',
            '00.50' => '0.5',
            '1e2' => '100',
            '1e-2' => '0.01',
            '0.0001' => '0.0001',
            '0.00004' => '0',
            '1234.56789' => '1234.5679',
            ' 0,05 ' => '0.05',
            "0.05\0" => '0.05',
        ) as $posted => $value) {
            $cases[$posted] = array((string) $posted, $value);
        }

        return $cases;
    }

    /**
     * @dataProvider tolerances
     */
    public function testAToleranceIsStoredAsTheShortestNumberOfUpToFourDecimals($posted, $value)
    {
        $this->assertSame($value, $this->valueOf('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', $posted));
        $this->assertNull($this->errorOf('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', $posted));
    }

    /**
     * @return array[] name => array(posted)
     */
    public function tolerancesBelowZeroOrNoNumber()
    {
        $cases = array();
        foreach (array('-1', '-0.0001', '-0.00001', 'abc', '', '   ', '1.2.3', '1,2,3', 'INF', 'NAN', '0x1A', '1_000', '1 2', '5%', '1e', ',') as $posted) {
            $cases[var_export($posted, true)] = array($posted);
        }
        $cases['not posted'] = array(false);

        return $cases;
    }

    /**
     * @dataProvider tolerancesBelowZeroOrNoNumber
     */
    public function testAToleranceBelowZeroOrThatIsNoNumberIsAnError($posted)
    {
        $this->assertSame('', $this->errorOf('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', $posted));
        $this->assertNull($this->valueOf('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', $posted));
    }

    // ---- choices ------------------------------------------------------------------------------

    public function testTheSendChannelIsOneOfThreeAndTrimmed()
    {
        foreach (array('shop_mail', 'ifirma_mail', 'none') as $channel) {
            $this->assertSame($channel, $this->valueOf('IFIRMA_SEND_CHANNEL', $channel), $channel);
            $this->assertSame($channel, $this->valueOf('IFIRMA_SEND_CHANNEL', "  $channel\t"), 'padded ' . $channel);
        }
        $this->assertNull($this->errorOf('IFIRMA_SEND_CHANNEL', 'carrier_pigeon'));
    }

    /**
     * @return array[] name => array(posted)
     */
    public function channelsNobodyKnows()
    {
        return array('another word' => array('carrier_pigeon'), 'other case' => array('SHOP_MAIL'), 'empty' => array(''), 'spaces' => array('  '), 'not posted' => array(false), 'zero' => array('0'));
    }

    /**
     * @dataProvider channelsNobodyKnows
     */
    public function testAnUnknownSendChannelBecomesTheShopMail($posted)
    {
        $this->assertSame('shop_mail', $this->valueOf('IFIRMA_SEND_CHANNEL', $posted));
    }

    public function testTheCopyModeIsOneOfThreeAndIsNotTrimmed()
    {
        foreach (array('none', 'shop', 'employee') as $mode) {
            $this->assertSame($mode, $this->valueOf('IFIRMA_SEND_COPY_MODE', $mode), $mode);
            $this->assertSame('none', $this->valueOf('IFIRMA_SEND_COPY_MODE', " $mode "), 'padded ' . $mode . ' is not a mode');
            $this->assertNull($this->errorOf('IFIRMA_SEND_COPY_MODE', " $mode "), 'and it is no error');
        }
    }

    /**
     * @return array[] name => array(posted)
     */
    public function copyModesNobodyKnows()
    {
        return array('another word' => array('everyone'), 'other case' => array('Shop'), 'empty' => array(''), 'not posted' => array(false), 'zero' => array('0'));
    }

    /**
     * @dataProvider copyModesNobodyKnows
     */
    public function testAnUnknownCopyModeBecomesNone($posted)
    {
        $this->assertSame('none', $this->valueOf('IFIRMA_SEND_COPY_MODE', $posted));
    }

    public function testThePaymentMethodModeIsOrderOrFixedAndTrimmed()
    {
        $this->assertSame('order', $this->valueOf('IFIRMA_PAYMENT_METHOD_MODE', 'order'));
        $this->assertSame('fixed', $this->valueOf('IFIRMA_PAYMENT_METHOD_MODE', 'fixed'));
        $this->assertSame('fixed', $this->valueOf('IFIRMA_PAYMENT_METHOD_MODE', "  fixed\n"));
        $this->assertNull($this->errorOf('IFIRMA_PAYMENT_METHOD_MODE', 'order'));
    }

    /**
     * @return array[] name => array(posted)
     */
    public function modesNobodyKnows()
    {
        return array('another word' => array('sometimes'), 'other case' => array('Order'), 'empty' => array(''), 'spaces' => array('  '), 'not posted' => array(false), 'zero' => array('0'));
    }

    /**
     * @dataProvider modesNobodyKnows
     */
    public function testAnUnknownPaymentMethodModeIsAnError($posted)
    {
        $this->assertSame('', $this->errorOf('IFIRMA_PAYMENT_METHOD_MODE', $posted));
        $this->assertNull($this->valueOf('IFIRMA_PAYMENT_METHOD_MODE', $posted));
    }

    // ---- normalised by the module's own classes -----------------------------------------------

    /**
     * @return array[] name => array(posted)
     */
    public function postedTexts()
    {
        return array(
            'the value' => array('fakturakraj'),
            'padded' => array('  fakturakraj  '),
            'another document type' => array('fakturaparagon'),
            'upper case' => array('UPO'),
            'lower case' => array('upo'),
            'padded lower case' => array('  upo  '),
            'unknown' => array('xyz'),
            'all' => array('all'),
            'off' => array(' OFF '),
            'b2b' => array('B2B'),
            'empty' => array(''),
            'not posted' => array(false),
            'zero' => array('0'),
        );
    }

    /**
     * @dataProvider postedTexts
     */
    public function testTheDocumentTheSignatureAndTheKsefModeAreWhatTheirOwnClassesMakeOfThem($posted)
    {
        $this->assertSame(IfirmaDocumentSettings::normalizeDocumentType($posted), $this->valueOf('IFIRMA_DOC_TYPE', $posted));
        $this->assertSame(IfirmaDocumentSettings::normalizeSignatureType($posted), $this->valueOf('IFIRMA_SIGNATURE_TYPE', $posted));
        $this->assertSame(IfirmaKsefService::normalizeMode($posted), $this->valueOf('IFIRMA_KSEF_MODE', $posted));
        foreach (array('IFIRMA_DOC_TYPE', 'IFIRMA_SIGNATURE_TYPE', 'IFIRMA_KSEF_MODE') as $key) {
            $this->assertNull($this->errorOf($key, $posted), $key);
        }
    }

    public function testTheseThreeAreTheNormalisersOfTheirOwnClassesAndNotACopy()
    {
        $this->assertSame('fakturakraj', $this->valueOf('IFIRMA_DOC_TYPE', 'fakturaparagon'));
        $this->assertSame('UPO', $this->valueOf('IFIRMA_SIGNATURE_TYPE', ' upo '));
        $this->assertSame('BPO', $this->valueOf('IFIRMA_SIGNATURE_TYPE', 'xyz'));
        $this->assertSame('all', $this->valueOf('IFIRMA_KSEF_MODE', ' ALL '));
        $this->assertSame('off', $this->valueOf('IFIRMA_KSEF_MODE', 'off'));
        $this->assertSame('b2b', $this->valueOf('IFIRMA_KSEF_MODE', 'whenever'));
    }

    // ---- the payment method code and map ------------------------------------------------------

    public function testEveryPaymentCodeIsAcceptedInEveryCaseAndTrimmed()
    {
        $this->assertNotEmpty(IfirmaPaymentMethodResolver::codes());
        foreach (array_keys(IfirmaPaymentMethodResolver::codes()) as $code) {
            $this->assertSame($code, $this->valueOf('IFIRMA_PAYMENT_METHOD', $code), $code);
            $this->assertSame($code, $this->valueOf('IFIRMA_PAYMENT_METHOD', strtolower($code)), 'lower case ' . $code);
            $this->assertSame($code, $this->valueOf('IFIRMA_PAYMENT_METHOD', "  $code\t"), 'padded ' . $code);
            $this->assertNull($this->errorOf('IFIRMA_PAYMENT_METHOD', $code), $code);
        }
    }

    /**
     * @return array[] name => array(posted)
     */
    public function codesNobodyKnows()
    {
        return array('a made up code' => array('XYZ'), 'a longer code' => array('PRZZ'), 'empty' => array(''), 'spaces' => array('   '), 'not posted' => array(false), 'zero' => array('0'), 'a label' => array('Card'));
    }

    /**
     * @dataProvider codesNobodyKnows
     */
    public function testAPaymentCodeThatIsNotListedIsAnError($posted)
    {
        $this->assertSame('', $this->errorOf('IFIRMA_PAYMENT_METHOD', $posted));
        $this->assertNull($this->valueOf('IFIRMA_PAYMENT_METHOD', $posted));
    }

    /**
     * @return array[] name => array(posted, value)
     */
    public function maps()
    {
        return array(
            'empty' => array('', ''),
            'not posted' => array(false, ''),
            'blank lines and comments' => array("  \r\n# only a comment\r\n\t\n", ''),
            'one line' => array('ps_wirepayment=PRZ', 'ps_wirepayment=PRZ'),
            'spaces, case and a wildcard' => array('  PS_CheckPayment = prz ', 'ps_checkpayment=PRZ'),
            'every kind of line break' => array("a=PRZ\r\nb=KAR\rc=ELE\nd=GTK", "a=PRZ\nb=KAR\nc=ELE\nd=GTK"),
            'a later line wins' => array("ps_checkpayment=PRZ\nps_checkpayment=GTK", 'ps_checkpayment=GTK'),
            'comments between lines' => array("a=PRZ\n# note\n\nmygateway* = ele", "a=PRZ\nmygateway*=ELE"),
        );
    }

    /**
     * @dataProvider maps
     */
    public function testThePaymentMapIsWrittenOutAgainInItsOwnForm($posted, $value)
    {
        $this->assertSame($value, $this->valueOf('IFIRMA_PAYMENT_METHOD_MAP', $posted));
        $this->assertNull($this->errorOf('IFIRMA_PAYMENT_METHOD_MAP', $posted));
    }

    /**
     * @return array[] name => array(posted, detail)
     */
    public function mapsWithBadLines()
    {
        return array(
            'an unknown code' => array('ps_checkpayment=XYZ', 'line 1: ps_checkpayment=XYZ'),
            'no equals sign' => array("\n  \n# note\n\nps_checkpayment\nps_wirepayment=PRZ\nbad module=PRZ", 'line 5: ps_checkpayment; line 7: bad module=PRZ'),
            'a module with a capital letter is lowered, one with a space is not' => array('Bad Module=PRZ', 'line 1: Bad Module=PRZ'),
        );
    }

    /**
     * @dataProvider mapsWithBadLines
     */
    public function testAPaymentMapWithBadLinesIsAnErrorThatListsThem($posted, $detail)
    {
        $result = $this->fromRequest(array('IFIRMA_PAYMENT_METHOD_MAP' => $posted));

        $this->assertSame($detail, $result['errors']['IFIRMA_PAYMENT_METHOD_MAP']);
        $this->assertNull($result['values']['IFIRMA_PAYMENT_METHOD_MAP']);
    }

    public function testTheOnlyErrorWithADetailIsThePaymentMap()
    {
        $result = $this->fromRequest(array(
            'IFIRMA_USER' => '', 'IFIRMA_KEY_FAKTURA' => 'zz', 'IFIRMA_PAYMENT_METHOD' => 'XYZ', 'IFIRMA_PAYMENT_METHOD_MODE' => 'never',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '0', 'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '-1', 'IFIRMA_NOTIFY_EMAIL' => 'no',
            'IFIRMA_SYNC_INTERVAL_MIN' => '0', 'IFIRMA_CRON_MAX_CALLS' => '0', 'IFIRMA_CRON_MAX_ITEMS' => '0', 'IFIRMA_RATE_MAX_PER_MIN' => '0',
            'IFIRMA_RATE_MAX_PER_DAY' => '0',
            'IFIRMA_PAYMENT_METHOD_MAP' => 'ps_checkpayment=XYZ',
        ));

        $this->assertSame(array(
            'IFIRMA_USER' => '',
            'IFIRMA_KEY_FAKTURA' => '',
            'IFIRMA_PAYMENT_METHOD' => '',
            'IFIRMA_PAYMENT_METHOD_MODE' => '',
            'IFIRMA_PAYMENT_METHOD_MAP' => 'line 1: ps_checkpayment=XYZ',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '',
            'IFIRMA_NOTIFY_EMAIL' => '',
            'IFIRMA_SYNC_INTERVAL_MIN' => '',
            'IFIRMA_CRON_MAX_CALLS' => '',
            'IFIRMA_CRON_MAX_ITEMS' => '',
            'IFIRMA_RATE_MAX_PER_MIN' => '',
            'IFIRMA_RATE_MAX_PER_DAY' => '',
        ), $result['errors'], 'every rule that can fail, in table order');
    }

    // ---- the employee of the delivery copy ----------------------------------------------------

    /**
     * @return array[] name => array(posted mode)
     */
    public function modesThatAreNotTheEmployeeMode()
    {
        return array('none' => array('none'), 'shop' => array('shop'), 'unknown' => array('everyone'), 'padded' => array(' employee '), 'other case' => array('Employee'), 'not posted' => array(false));
    }

    /**
     * @dataProvider modesThatAreNotTheEmployeeMode
     */
    public function testTheEmployeesAreNotReadWhenTheCopyDoesNotGoToAnEmployee($mode)
    {
        $calls = 0;

        $result = IfirmaSettings::fromInput(
            $this->validRequest(array('IFIRMA_SEND_COPY_MODE' => $mode, 'IFIRMA_SEND_COPY_EMPLOYEE' => '99')),
            function () use (&$calls) {
                $calls++;

                return array();
            }
        );

        $this->assertSame(0, $calls);
        $this->assertSame(array(), $result['errors'], 'whoever is chosen, it is no error');
        $this->assertSame(99, $result['values']['IFIRMA_SEND_COPY_EMPLOYEE'], 'and the number is stored as it is');
    }

    /**
     * @return array[] name => array(posted employee)
     */
    public function employeeFieldsOfACopyToAnEmployee()
    {
        return array('an id' => array('3'), 'zero' => array('0'), 'not posted' => array(false), 'text' => array('abc'), 'an id nobody has' => array('99'));
    }

    /**
     * @dataProvider employeeFieldsOfACopyToAnEmployee
     */
    public function testTheEmployeesAreReadOnceWhenTheCopyGoesToAnEmployeeWhateverTheEmployeeField($posted)
    {
        $calls = 0;

        IfirmaSettings::fromInput(
            $this->validRequest(array('IFIRMA_SEND_COPY_MODE' => 'employee', 'IFIRMA_SEND_COPY_EMPLOYEE' => $posted)),
            function () use (&$calls) {
                $calls++;

                return array(3, 4);
            }
        );

        $this->assertSame(1, $calls);
    }

    /**
     * @return array[] name => array(posted employee, ids of the active employees, stored)
     */
    public function activeEmployees()
    {
        return array(
            'an id' => array(' 4 ', array(3, 4), 4),
            'the first id, the one every shop starts with' => array('1', array(1, 3), 1),
            'the only employee' => array('3', array(3), 3),
            'the last of a long list' => array('9', array(1, 2, 3, 9), 9),
            'the first of a long list' => array('2', array(2, 3, 9), 2),
        );
    }

    /**
     * @dataProvider activeEmployees
     */
    public function testAnActiveEmployeeIsAccepted($posted, array $active, $stored)
    {
        $result = $this->fromRequest($this->validRequest(array('IFIRMA_SEND_COPY_MODE' => 'employee', 'IFIRMA_SEND_COPY_EMPLOYEE' => $posted)), $active);

        $this->assertSame(array(), $result['errors']);
        $this->assertSame($stored, $result['values']['IFIRMA_SEND_COPY_EMPLOYEE']);
        $this->assertSame('employee', $result['values']['IFIRMA_SEND_COPY_MODE']);
    }

    /**
     * @return array[] name => array(posted employee, ids of the active employees)
     */
    public function employeesThatAreNotActive()
    {
        return array(
            'an id nobody has' => array('99', array(3, 4)),
            'nobody is active' => array('3', array()),
            'zero' => array('0', array(0, 3)),
            'not posted' => array(false, array(0, 3)),
            'a negative number' => array('-3', array(-3, 3)),
            'text' => array('abc', array(0, 3)),
            'an id that the list holds as text' => array('3', array('3')),
        );
    }

    /**
     * @dataProvider employeesThatAreNotActive
     */
    public function testAnEmployeeWhoIsNotActiveIsAnError($posted, array $active)
    {
        $result = $this->fromRequest($this->validRequest(array('IFIRMA_SEND_COPY_MODE' => 'employee', 'IFIRMA_SEND_COPY_EMPLOYEE' => $posted)), $active);

        $this->assertSame(array('IFIRMA_SEND_COPY_EMPLOYEE' => ''), $result['errors']);
        $this->assertNull($result['values']['IFIRMA_SEND_COPY_EMPLOYEE']);
        $this->assertSame('employee', $result['values']['IFIRMA_SEND_COPY_MODE'], 'the mode itself is no error');
    }

    // ---- purity -------------------------------------------------------------------------------

    public function testTheClassNamesNeitherTheConfigurationNorTheRequestNorTheDatabaseNorTheContext()
    {
        $names = array();
        foreach (token_get_all(file_get_contents(dirname(__DIR__, 2) . '/classes/IfirmaSettings.php')) as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                $names[$token[1]] = true;
            }
        }

        $this->assertSame(array(), array_values(array_intersect(array('Configuration', 'Tools', 'Db', 'Context', 'Employee', 'Module'), array_keys($names))));
        $this->assertArrayHasKey('IfirmaSettings', $names, 'the scan reads the class file');
    }

    public function testItDependsOnTheRequestPassedInAndNothingElse()
    {
        Tools::$values = array('IFIRMA_USER' => 'from the request', 'IFIRMA_DUPLICATE_WINDOW_DAYS' => '45');
        Configuration::updateValue('IFIRMA_USER', 'from the configuration');
        Configuration::$writes = array();

        $result = $this->fromRequest(array('IFIRMA_USER' => 'passed in'));

        $this->assertSame('passed in', $result['values']['IFIRMA_USER']);
        $this->assertNull($result['values']['IFIRMA_DUPLICATE_WINDOW_DAYS']);
        $this->assertSame(array(), Configuration::$writes);
        $this->assertSame('from the configuration', Configuration::get('IFIRMA_USER'));
    }

    /**
     * @return array[] name => array(url, devMode, accepted)
     */
    public function baseUrls()
    {
        return array(
            'production' => array('https://www.ifirma.pl/iapi/', false, true),
            'another https host' => array('https://sandbox.example/iapi/', false, true),
            'http to the public internet without dev mode' => array('http://www.ifirma.pl/iapi/', false, false),
            'http to the public internet in dev mode' => array('http://www.ifirma.pl/iapi/', true, true),
            'the mock service (single label host)' => array('http://ifirma-mock:8001/iapi/', false, true),
            'localhost' => array('http://localhost:8001/', false, true),
            'loopback' => array('http://127.0.0.1:8001/', false, true),
            'ipv6 loopback' => array('http://[::1]:8001/', false, true),
            'class A private range' => array('http://10.1.2.3/', false, true),
            'class B private range, first' => array('http://172.16.0.9/', false, true),
            'class B private range, last' => array('http://172.31.255.1/', false, true),
            'just outside the class B private range' => array('http://172.32.0.1/', false, false),
            'class C private range' => array('http://192.168.1.5/', false, true),
            'a public name that starts like a private range' => array('http://10.example.com/', false, false),
            'ipv6 unique local over http' => array('http://[fd12:3456::1]:8001/', false, true),
            'ipv6 link local over http' => array('http://[fe80::1]/', false, true),
            'public ipv6 over http' => array('http://[2606:4700::1111]/iapi/', false, false),
            'public ipv6 over http in dev mode' => array('http://[2606:4700::1111]/iapi/', true, true),
            'public ipv6 over https' => array('https://[2606:4700::1111]/iapi/', false, true),
            'a query string' => array('https://www.ifirma.pl/iapi/?a=b', false, false),
            'a query string on a local host' => array('http://localhost:8001/iapi/?a=b', false, false),
            'a fragment' => array('https://www.ifirma.pl/iapi/#top', false, false),
            'a fragment on a local host' => array('http://ifirma-mock:8001/iapi/#top', false, false),
            'file' => array('file:///etc/passwd', true, false),
            'gopher' => array('gopher://host/', true, false),
            'ftp' => array('ftp://host/', true, false),
            'credentials in https' => array('https://user:pw@host.example/', false, false),
            'a login only' => array('https://user@host.example/', false, false),
            'credentials in a local http url' => array('http://user:pw@localhost/', false, false),
            'no scheme' => array('www.ifirma.pl/iapi/', true, false),
            'a relative path' => array('/iapi/', true, false),
            'zero' => array('0', true, false),
            'a word' => array('abc', true, false),
        );
    }

    /**
     * @dataProvider baseUrls
     */
    public function testTheBaseUrlMustBeAnAddressTheModuleMayCall($url, $devMode, $accepted)
    {
        IfirmaSettings::$devMode = $devMode;
        try {
            $result = $this->fromRequest(array('IFIRMA_BASE_URL' => $url));
        } finally {
            IfirmaSettings::$devMode = null;
        }

        if ($accepted) {
            $this->assertSame($url, $result['values']['IFIRMA_BASE_URL']);
            $this->assertArrayNotHasKey('IFIRMA_BASE_URL', $result['errors']);

            return;
        }
        $this->assertArrayHasKey('IFIRMA_BASE_URL', $result['errors']);
        $this->assertNull($result['values']['IFIRMA_BASE_URL']);
    }

    public function testAUrlThePrestaShopAbsoluteUrlCheckRejectsIsNotAcceptable()
    {
        IfirmaSettings::$devMode = true;
        try {
            $this->assertFalse(IfirmaSettings::isAcceptableBaseUrl('http://exa<mple.test/iapi/'));
        } finally {
            IfirmaSettings::$devMode = null;
        }
    }

    // ---- document template, order stamp prefix, shop webpage, auto-accept (1.6.0) --------------

    /**
     * @return array[] name => array(key, posted, value)
     */
    public function acceptedNewSettings()
    {
        return array(
            'a template is trimmed' => array('IFIRMA_DOC_TEMPLATE', ' logo ', 'logo'),
            'a template of 100 characters' => array('IFIRMA_DOC_TEMPLATE', str_repeat('a', 100), str_repeat('a', 100)),
            'an empty template' => array('IFIRMA_DOC_TEMPLATE', '', ''),
            'a prefix loses its trailing hyphen' => array('IFIRMA_ORDER_STAMP_PREFIX', 'scmedical-', 'scmedical'),
            'a prefix is trimmed' => array('IFIRMA_ORDER_STAMP_PREFIX', '  scm.med_1  ', 'scm.med_1'),
            'an empty prefix is PS-ORDER' => array('IFIRMA_ORDER_STAMP_PREFIX', '', 'PS-ORDER'),
            'a prefix of hyphens is PS-ORDER' => array('IFIRMA_ORDER_STAMP_PREFIX', '--', 'PS-ORDER'),
            'a prefix of 32 characters' => array('IFIRMA_ORDER_STAMP_PREFIX', str_repeat('a', 32), str_repeat('a', 32)),
            'a prefix that is not posted' => array('IFIRMA_ORDER_STAMP_PREFIX', false, 'PS-ORDER'),
            'an https webpage' => array('IFIRMA_SHOP_URL', 'https://scmedical.pl', 'https://scmedical.pl'),
            'an http webpage with a path, trimmed' => array('IFIRMA_SHOP_URL', ' http://shop.example.test/pl/ ', 'http://shop.example.test/pl/'),
            'an empty webpage' => array('IFIRMA_SHOP_URL', '', ''),
            'the safe mode' => array('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe', 'safe'),
            'the all mode, trimmed' => array('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', ' all ', 'all'),
            'an unknown mode is none' => array('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'sometimes', 'none'),
            'a mode that is not posted is none' => array('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', false, 'none'),
        );
    }

    /**
     * @dataProvider acceptedNewSettings
     */
    public function testTheNewSettingsAreNormalised($key, $posted, $value)
    {
        $result = $this->fromRequest(array($key => $posted));

        $this->assertSame($value, $result['values'][$key]);
        $this->assertArrayNotHasKey($key, $result['errors']);
    }

    /**
     * @return array[] name => array(key, posted)
     */
    public function refusedNewSettings()
    {
        return array(
            'a template of 101 characters' => array('IFIRMA_DOC_TEMPLATE', str_repeat('a', 101)),
            'a template with a control character' => array('IFIRMA_DOC_TEMPLATE', "lo\x07go"),
            'a template with a line break' => array('IFIRMA_DOC_TEMPLATE', "lo\ngo"),
            'a template that is an array' => array('IFIRMA_DOC_TEMPLATE', array('a')),
            'a prefix with a space inside' => array('IFIRMA_ORDER_STAMP_PREFIX', 'sc medical'),
            'a prefix of 33 characters' => array('IFIRMA_ORDER_STAMP_PREFIX', str_repeat('a', 33)),
            'a prefix with a slash' => array('IFIRMA_ORDER_STAMP_PREFIX', 'sc/medical'),
            'a prefix with a letter outside ASCII' => array('IFIRMA_ORDER_STAMP_PREFIX', "sc\u{0105}"),
            'a webpage without a scheme' => array('IFIRMA_SHOP_URL', 'scmedical.pl'),
            'a webpage with user information' => array('IFIRMA_SHOP_URL', 'https://user:pw@host.example'),
            'a webpage over ftp' => array('IFIRMA_SHOP_URL', 'ftp://scmedical.pl'),
            'a webpage without a host' => array('IFIRMA_SHOP_URL', 'https://'),
            'a webpage with a space' => array('IFIRMA_SHOP_URL', 'https://scmedical.pl/a b'),
            'a webpage longer than 255 characters' => array('IFIRMA_SHOP_URL', 'https://scmedical.pl/' . str_repeat('a', 240)),
        );
    }

    /**
     * @dataProvider refusedNewSettings
     */
    public function testTheNewSettingsRefuseWhatTheirRulesForbid($key, $posted)
    {
        $result = $this->fromRequest(array($key => $posted));

        $this->assertNull($result['values'][$key]);
        $this->assertSame('', $result['errors'][$key]);
    }

    public function testTheShopWebpageRuleIgnoresDeveloperModeAndLocalHosts()
    {
        IfirmaSettings::$devMode = false;
        try {
            $result = $this->fromRequest(array('IFIRMA_SHOP_URL' => 'http://shop.example.test'));
        } finally {
            IfirmaSettings::$devMode = null;
        }

        $this->assertSame('http://shop.example.test', $result['values']['IFIRMA_SHOP_URL']);
    }

    public function testTheDevModeSeamDefaultsToThePrestaShopConstant()
    {
        $this->assertNull(IfirmaSettings::$devMode);
        $expected = defined('_PS_MODE_DEV_') && _PS_MODE_DEV_;
        $this->assertSame($expected, IfirmaSettings::isAcceptableBaseUrl('http://www.ifirma.pl/iapi/'));
    }

    public function testAnEmptyBaseUrlStaysTheProductionOne()
    {
        IfirmaSettings::$devMode = false;
        try {
            $result = $this->fromRequest(array('IFIRMA_BASE_URL' => '  '));
        } finally {
            IfirmaSettings::$devMode = null;
        }

        $this->assertSame(IfirmaApiClient::BASE_URL, $result['values']['IFIRMA_BASE_URL']);
        $this->assertArrayNotHasKey('IFIRMA_BASE_URL', $result['errors']);
    }

    // ---- an array or an object posted for a scalar (admin-receipt-ops-and-hardening 8.6) ---------

    /**
     * @return array[] name => array(key, posted, is an error)
     */
    public function nonScalars()
    {
        return array(
            'a login' => array('IFIRMA_USER', array('x'), true),
            'a base url' => array('IFIRMA_BASE_URL', array('https://a.example/'), true),
            'a key' => array('IFIRMA_KEY_FAKTURA', array('abc'), true),
            'an abonent key' => array('IFIRMA_KEY_ABONENT', array('abc'), true),
            'an e-mail address' => array('IFIRMA_NOTIFY_EMAIL', array('a@example.test'), true),
            'a whole number' => array('IFIRMA_SYNC_INTERVAL_MIN', array('15'), true),
            'a tolerance' => array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', array('1'), true),
            'a payment code' => array('IFIRMA_PAYMENT_METHOD', array('PRZ'), true),
            'a payment map' => array('IFIRMA_PAYMENT_METHOD_MAP', array('a=PRZ'), true),
            'an object as a login' => array('IFIRMA_USER', new stdClass(), true),
            'a choice' => array('IFIRMA_SEND_CHANNEL', array('none'), false),
            'a copy mode' => array('IFIRMA_SEND_COPY_MODE', array('shop'), false),
            'a raw value' => array('IFIRMA_DEFAULT_UNIT', array('szt.'), false),
        );
    }

    /**
     * @dataProvider nonScalars
     */
    public function testArrayLoginOrAnyOtherScalarGivenAnArrayIsRefusedOrIgnoredWithoutAWarning($key, $posted, $isError)
    {
        set_error_handler(function ($no, $message) {
            throw new ErrorException($message);
        });
        try {
            $result = $this->fromRequest(array($key => $posted));
        } finally {
            restore_error_handler();
        }

        $this->assertSame($isError, array_key_exists($key, $result['errors']), $key);
        if ($isError) {
            $this->assertNull($result['values'][$key]);
        } else {
            $this->assertTrue(is_scalar($result['values'][$key]) || $result['values'][$key] === false, $key . ' stays a scalar');
        }
    }

    public function testTheAbonentAndReceiptKeysMustBeHexadecimalWhenTheyAreNotEmpty()
    {
        foreach (array('IFIRMA_KEY_ABONENT', 'IFIRMA_KEY_RACHUNEK') as $key) {
            $this->assertArrayHasKey($key, $this->fromRequest(array($key => 'xyz'))['errors']);
            $this->assertArrayNotHasKey($key, $this->fromRequest(array($key => ''))['errors']);
            $this->assertArrayNotHasKey($key, $this->fromRequest(array($key => 'abcdef0123'))['errors']);
        }
    }
}
