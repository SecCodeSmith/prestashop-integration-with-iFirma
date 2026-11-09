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
 * What the save of the settings form does that SettingsGoldenTest does not pin (refactor-srp-splits 7.2),
 * driven through Ifirma::getContent() like the golden test:
 * - when the save reads the list of active employees (only when the copy goes to an employee, however
 *   the employee field is filled), and that it compares their ids as integers;
 * - the employee field is stored as posted, cast to an integer, whenever the copy does not go to an employee;
 * - the answer to the odd numbers the amount tolerance and the whole-number fields can be given;
 * - the order in which the text of the e-mail is trimmed and cut;
 * - how the switches, the order state fields and the unit are cast.
 * Each test starts from the smallest request the checks accept and changes the fields it is about.
 */
class SettingsSaveTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $employee = new Employee();
        $employee->id = 3;
        $employee->firstname = 'Anna';
        $employee->lastname = 'Nowak';
        Employee::register($employee);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    /**
     * @param array $changes field => value; a null removes the field
     * @return array the posted fields: the smallest request the checks accept, with the changes
     */
    private function request(array $changes = array())
    {
        $request = array(
            'submitIfirma' => '1',
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
        foreach ($changes as $field => $value) {
            if ($value === null) {
                unset($request[$field]);
            } else {
                $request[$field] = $value;
            }
        }

        return $request;
    }

    /**
     * Posts the request and forgets the writes the fixture made.
     *
     * @param array $changes see request()
     * @return string the page
     */
    private function save(array $changes = array())
    {
        Tools::$values = $this->request($changes);
        Configuration::$writes = array();

        return $this->module->getContent();
    }

    /**
     * @param string $key
     * @return mixed the one value the save wrote to the key
     */
    private function stored($key)
    {
        $values = array();
        foreach (Configuration::$writes as $write) {
            if ($write[0] === $key) {
                $values[] = $write[1];
            }
        }
        $this->assertCount(1, $values, $key . ' is written once');

        return $values[0];
    }

    private function assertSaved($page)
    {
        $this->assertStringStartsWith('<confirmation>Settings updated.</confirmation>', $page);
    }

    private function assertRejected($page, $message)
    {
        $this->assertStringStartsWith('<error>' . $message . '</error>', $page);
        $this->assertSame(array(), Configuration::$writes, 'a rejected request stores nothing');
    }

    // ---- the list of active employees ---------------------------------------------------------

    /**
     * @return array[] name => array(changes, lookups the whole request makes)
     */
    public function copyModes()
    {
        $employee = array('IFIRMA_SEND_COPY_MODE' => 'employee');

        return array(
            // the page builds its form from the list once, after the save
            'to an employee' => array($employee + array('IFIRMA_SEND_COPY_EMPLOYEE' => '3'), 2),
            'to an employee, none chosen' => array($employee + array('IFIRMA_SEND_COPY_EMPLOYEE' => '0'), 2),
            'to an employee, none posted' => array($employee, 2),
            'to the shop' => array(array('IFIRMA_SEND_COPY_MODE' => 'shop', 'IFIRMA_SEND_COPY_EMPLOYEE' => '3'), 1),
            'to nobody' => array(array('IFIRMA_SEND_COPY_MODE' => 'none', 'IFIRMA_SEND_COPY_EMPLOYEE' => '3'), 1),
            'a mode nobody knows' => array(array('IFIRMA_SEND_COPY_MODE' => 'everyone', 'IFIRMA_SEND_COPY_EMPLOYEE' => '3'), 1),
            'a padded mode is not the employee mode' => array(array('IFIRMA_SEND_COPY_MODE' => ' employee ', 'IFIRMA_SEND_COPY_EMPLOYEE' => '3'), 1),
            'no mode posted' => array(array('IFIRMA_SEND_COPY_EMPLOYEE' => '3'), 1),
        );
    }

    /**
     * @dataProvider copyModes
     */
    public function testTheActiveEmployeesAreReadOnlyWhenTheCopyGoesToAnEmployee(array $changes, $lookups)
    {
        $asked = array();
        Employee::$employeesHook = function ($activeOnly, array $rows) use (&$asked) {
            $asked[] = $activeOnly;

            return $rows;
        };

        $this->save($changes);

        $this->assertSame(array_fill(0, $lookups, true), $asked, 'every lookup asks for the active employees only');
    }

    public function testAnEmployeeWhoseIdTheDatabaseReturnsAsTextIsFound()
    {
        $employee = new Employee();
        $employee->id = '8';
        $employee->firstname = 'Ewa';
        $employee->lastname = 'Adamska';
        Employee::register($employee);

        $page = $this->save(array('IFIRMA_SEND_COPY_MODE' => 'employee', 'IFIRMA_SEND_COPY_EMPLOYEE' => '8'));

        $this->assertSaved($page);
        $this->assertSame(8, $this->stored('IFIRMA_SEND_COPY_EMPLOYEE'));
    }

    public function testTheEmployeeEveryShopStartsWithIsFound()
    {
        // PrestaShop creates the first employee with id 1 at install; the boundary of "an id above zero" is 1
        $first = new Employee();
        $first->id = 1;
        $first->firstname = 'Admin';
        $first->lastname = 'Shop';
        Employee::register($first);

        $page = $this->save(array('IFIRMA_SEND_COPY_MODE' => 'employee', 'IFIRMA_SEND_COPY_EMPLOYEE' => '1'));

        $this->assertSaved($page);
        $this->assertSame(1, $this->stored('IFIRMA_SEND_COPY_EMPLOYEE'));
    }

    public function testAnEmployeeWhoIsNotActiveIsNotFound()
    {
        $inactive = new Employee();
        $inactive->id = 9;
        $inactive->lastname = 'Zielinski';
        $inactive->active = 0;
        Employee::register($inactive);

        $page = $this->save(array('IFIRMA_SEND_COPY_MODE' => 'employee', 'IFIRMA_SEND_COPY_EMPLOYEE' => '9'));

        $this->assertRejected($page, 'Choose an active employee for the delivery copy.');
    }

    /**
     * @return array[] name => array(posted employee, stored)
     */
    public function employeeFieldsOfACopyThatGoesToNoEmployee()
    {
        return array(
            'an id nobody has' => array('99', 99),
            'a negative number' => array('-5', -5),
            'text' => array('abc', 0),
            'a padded id' => array(' 3 ', 3),
            'empty' => array('', 0),
            'a decimal number' => array('3.9', 3),
        );
    }

    /**
     * @dataProvider employeeFieldsOfACopyThatGoesToNoEmployee
     */
    public function testTheEmployeeFieldIsStoredCastToAnIntegerWhenTheCopyGoesToNoEmployee($posted, $stored)
    {
        $page = $this->save(array('IFIRMA_SEND_COPY_MODE' => 'shop', 'IFIRMA_SEND_COPY_EMPLOYEE' => $posted));

        $this->assertSaved($page);
        $this->assertSame($stored, $this->stored('IFIRMA_SEND_COPY_EMPLOYEE'));
    }

    // ---- the amount tolerance -----------------------------------------------------------------

    /**
     * @return array[] posted => array(posted, stored); a negative zero comes out as 0, as sprintf() writes it
     */
    public function tolerancesThatAreAccepted()
    {
        $cases = array();
        foreach (array(
            '0' => '0',
            '0.0' => '0',
            '0,00' => '0',
            '-0' => '0',
            '-0.0' => '0',
            '00.50' => '0.5',
            '5.' => '5',
            '+3' => '3',
            '1e-2' => '0.01',
            '.5e1' => '5',
            '1,5' => '1.5',
            '1.10' => '1.1',
            '100' => '100',
            '0.1234' => '0.1234',
        ) as $posted => $stored) {
            $cases[$posted] = array((string) $posted, $stored);
        }

        return $cases;
    }

    /**
     * @dataProvider tolerancesThatAreAccepted
     */
    public function testTheToleranceIsStoredAsTheShortestNumberOfUpToFourDecimals($posted, $stored)
    {
        $page = $this->save(array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => $posted));

        $this->assertSaved($page);
        $this->assertSame($stored, $this->stored('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE'));
    }

    /**
     * @return array[]
     */
    public function tolerancesThatAreRefused()
    {
        $cases = array();
        foreach (array('-0.00001', '-1,5', 'INF', 'NAN', '0x1A', '1_000', '1 2', '--1', '1e', 'e1', ',5,', '1,2,3', '5%') as $posted) {
            $cases[$posted] = array($posted);
        }

        return $cases;
    }

    /**
     * @dataProvider tolerancesThatAreRefused
     */
    public function testATextThatIsNoNumberAboveZeroIsNotATolerance($posted)
    {
        $page = $this->save(array('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => $posted));

        $this->assertRejected($page, 'The amount tolerance must be a number greater than or equal to 0.');
    }

    // ---- the whole numbers --------------------------------------------------------------------

    /**
     * @return array[] name => array(field, posted, the message of the field)
     */
    public function wholeNumbersThatAreRefused()
    {
        $cases = array();
        foreach (array(
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => 'The duplicate-check window must be a whole number of days between 1 and 365.',
            'IFIRMA_SYNC_INTERVAL_MIN' => 'The sync interval must be a whole number of minutes between 1 and 1440.',
            'IFIRMA_CRON_MAX_CALLS' => 'The cron call budget must be a whole number between 1 and 90.',
            'IFIRMA_CRON_MAX_ITEMS' => 'The cron item limit must be a whole number between 1 and 50.',
            'IFIRMA_RATE_MAX_PER_MIN' => 'The API rate limit must be a whole number between 1 and 100 calls per minute.',
            'IFIRMA_RATE_MAX_PER_DAY' => 'The API daily limit must be a whole number between 1 and 15000 calls per day.',
        ) as $field => $message) {
            foreach (array('-1', '+5', '1 0', '1.0', '1,0', '99999999999999999999', 'ten', '0x10', ' ') as $posted) {
                $cases[$field . ' = "' . $posted . '"'] = array($field, $posted, $message);
            }
        }

        return $cases;
    }

    /**
     * @dataProvider wholeNumbersThatAreRefused
     */
    public function testOnlyDigitsAreAWholeNumber($field, $posted, $message)
    {
        $page = $this->save(array($field => $posted));

        $this->assertRejected($page, $message);
    }

    public function testAPaddedWholeNumberIsAccepted()
    {
        $page = $this->save(array('IFIRMA_DUPLICATE_WINDOW_DAYS' => "\t 7 \n"));

        $this->assertSaved($page);
        $this->assertSame(7, $this->stored('IFIRMA_DUPLICATE_WINDOW_DAYS'));
    }

    // ---- text fields --------------------------------------------------------------------------

    /**
     * @return array[] field => array(field)
     */
    public function textFields()
    {
        $cases = array();
        foreach (array(
            'IFIRMA_USER', 'IFIRMA_BASE_URL', 'IFIRMA_KEY_FAKTURA', 'IFIRMA_KEY_ABONENT', 'IFIRMA_KEY_RACHUNEK',
            'IFIRMA_PAYMENT_TYPE_TOKEN', 'IFIRMA_SEND_TEXT',
        ) as $field) {
            $cases[$field] = array($field);
        }

        return $cases;
    }

    /**
     * PHP reads "0" as empty; the checks compare with the empty string, so the text is stored.
     *
     * @dataProvider textFields
     */
    public function testAZeroIsATextAndNotAnEmptyField($field)
    {
        // the base URL must be an address (IfirmaSettings::TYPE_URL), so its "zero" is a URL with a path of 0
        $zero = $field === 'IFIRMA_BASE_URL' ? 'https://example.test/0/' : '0';
        if (strpos($field, 'IFIRMA_KEY_') === 0) {
            // a key is whole bytes: the shortest "zero" key is two digits
            $zero = '00';
        }
        $page = $this->save(array($field => $zero));

        $this->assertSaved($page);
        $this->assertSame($zero, $this->stored($field));
    }

    /**
     * @return array[] field => array(field)
     */
    public function textFieldsWithoutALimit()
    {
        $cases = array();
        foreach (array('IFIRMA_USER', 'IFIRMA_BASE_URL', 'IFIRMA_KEY_FAKTURA', 'IFIRMA_KEY_RACHUNEK', 'IFIRMA_PAYMENT_TYPE_TOKEN') as $field) {
            $cases[$field] = array($field);
        }

        return $cases;
    }

    /**
     * Only the e-mail text is cut, at a thousand characters; the other texts are stored whatever their length.
     *
     * @dataProvider textFieldsWithoutALimit
     */
    public function testOnlyTheSendTextIsCut($field)
    {
        // the base URL must be an address (IfirmaSettings::TYPE_URL): a URL with a 1500 character path
        $long = $field === 'IFIRMA_BASE_URL' ? 'https://example.test/' . str_repeat('a', 1500) . '/' : str_repeat('a', 1500);

        $page = $this->save(array($field => $long));

        $this->assertSaved($page);
        $this->assertSame($long, $this->stored($field));
    }

    public function testANotificationAddressOfZeroIsNotValid()
    {
        $page = $this->save(array('IFIRMA_NOTIFY_EMAIL' => '0'));

        $this->assertRejected($page, 'The notification e-mail address is not valid.');
    }

    // ---- the text of the e-mail ---------------------------------------------------------------

    /**
     * @return array[] name => array(posted, stored)
     */
    public function sendTexts()
    {
        $a = "\u{0105}";

        return array(
            'exactly as long as the limit' => array(str_repeat($a, 1000), str_repeat($a, 1000)),
            'one below the limit' => array(str_repeat($a, 999), str_repeat($a, 999)),
            'padding in front is trimmed before the cut' => array('   ' . str_repeat($a, 1001), str_repeat($a, 1000)),
            'padding behind is trimmed before the cut' => array(str_repeat($a, 1000) . '   ', str_repeat($a, 1000)),
            'a text of spaces' => array('     ', ''),
            'a line break inside is kept' => array("one\r\ntwo", "one\r\ntwo"),
        );
    }

    /**
     * @dataProvider sendTexts
     */
    public function testTheSendTextIsTrimmedAndThenCutAtAThousandCharacters($posted, $stored)
    {
        $page = $this->save(array('IFIRMA_SEND_TEXT' => $posted));

        $this->assertSaved($page);
        $this->assertSame($stored, $this->stored('IFIRMA_SEND_TEXT'));
    }

    // ---- switches, order states and the unit --------------------------------------------------

    /**
     * @return array[] name => array(posted, stored)
     */
    public function switchValues()
    {
        return array(
            'zero' => array('0', 0),
            'one' => array('1', 1),
            'empty' => array('', 0),
            'two zeros' => array('00', 1),
            'a decimal zero' => array('0.0', 1),
            'off' => array('off', 1),
            'false' => array('false', 1),
            'a space' => array(' ', 1),
        );
    }

    /**
     * @dataProvider switchValues
     */
    public function testASwitchStoresTrueOrFalseAsOneOrZero($posted, $stored)
    {
        $page = $this->save(array(
            'IFIRMA_CONTRACTOR_SYNC' => $posted,
            'IFIRMA_DUPLICATE_CHECK' => $posted,
            'IFIRMA_ALLOW_DATA_REQUEST' => $posted,
        ));

        $this->assertSaved($page);
        $this->assertSame($stored, $this->stored('IFIRMA_CONTRACTOR_SYNC'));
        $this->assertSame($stored, $this->stored('IFIRMA_DUPLICATE_CHECK'));
        $this->assertSame($stored, $this->stored('IFIRMA_ALLOW_DATA_REQUEST'));
    }

    /**
     * @return array[] name => array(posted, stored)
     */
    public function orderStateValues()
    {
        return array(
            'a state' => array('5', 5),
            'text' => array('abc', 0),
            'a negative number' => array('-3', -3),
            'an exponent' => array('1e2', 100),
            'padding in front' => array(' 7', 7),
            'padding behind' => array('7 ', 7),
            'empty' => array('', 0),
            'a switch word' => array('on', 0),
        );
    }

    /**
     * @dataProvider orderStateValues
     */
    public function testAnOrderStateIsStoredCastToAnInteger($posted, $stored)
    {
        $page = $this->save(array('IFIRMA_AUTO_CREATE_STATE' => $posted, 'IFIRMA_MARK_PAID_ON_CREATE' => $posted));

        $this->assertSaved($page);
        $this->assertSame($stored, $this->stored('IFIRMA_AUTO_CREATE_STATE'));
        $this->assertSame($stored, $this->stored('IFIRMA_MARK_PAID_ON_CREATE'));
    }

    /**
     * @return array[] name => array(posted, stored)
     */
    public function units()
    {
        return array(
            'a unit' => array('kg', 'kg'),
            'a padded unit stays padded' => array(' kg ', ' kg '),
            'zero stays a text' => array('0', '0'),
            'empty' => array('', ''),
            'not posted' => array(null, false),
        );
    }

    /**
     * @dataProvider units
     */
    public function testTheDefaultUnitIsStoredAsThePageSentIt($posted, $stored)
    {
        $page = $this->save(array('IFIRMA_DEFAULT_UNIT' => $posted));

        $this->assertSaved($page);
        $this->assertSame($stored, $this->stored('IFIRMA_DEFAULT_UNIT'));
    }

    // ---- the pause after a rejected credential (admin-receipt-ops-and-hardening 7.2) ------------

    public function testBannerShownWhileThePauseRuns()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', time() . '|Bad hash');
        Tools::$values = array();

        $page = $this->module->getContent();

        $this->assertStringStartsWith('<warning>iFirma rejected the credentials at ', $page);
        $this->assertStringContainsString('Bad hash; calls are paused until ', $page);
    }

    public function testNoBannerWithoutTheMarkOrAfterThePause()
    {
        Tools::$values = array();
        $this->assertStringNotContainsString('<warning>', $this->module->getContent());

        Configuration::updateValue('IFIRMA_AUTH_FAILURE', (time() - 3600) . '|Bad hash');
        $this->assertStringNotContainsString('<warning>', $this->module->getContent());
    }

    public function testSaveClearsTheMarkAndARefusedSaveKeepsIt()
    {
        $mark = time() . '|Bad hash';
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $mark);

        $page = $this->save(array('IFIRMA_USER' => ''));
        $this->assertStringContainsString('<error>', $page);
        $this->assertSame($mark, Configuration::get('IFIRMA_AUTH_FAILURE'), 'a refused save keeps the mark');
        $this->assertStringContainsString('<warning>', $page);

        $page = $this->save();
        $this->assertSaved($page);
        $this->assertFalse(Configuration::get('IFIRMA_AUTH_FAILURE'), 'a valid save removes the mark');
        $this->assertStringNotContainsString('<warning>', $page, 'and the banner is gone with it');
    }

    public function testARefusedBaseUrlShowsItsMessage()
    {
        IfirmaSettings::$devMode = false;
        try {
            $page = $this->save(array('IFIRMA_BASE_URL' => 'http://api.example.com/iapi/'));
        } finally {
            IfirmaSettings::$devMode = null;
        }

        $this->assertRejected($page, 'The API base URL must be an absolute https URL; http is accepted only for a local host or a shop in developer mode.');
    }

    // ---- the API keys never reach the page (D12) ----------------------------------------------

    /**
     * @param string $name
     * @return array the form input of the name
     */
    private function formInput($name)
    {
        foreach (HelperForm::$lastForm[0]['form']['input'] as $input) {
            if ($input['name'] === $name) {
                return $input;
            }
        }
        $this->fail('The form has no input ' . $name);
    }

    public function testPageSourceCarriesNoStoredKey()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', 'fedcba9876543210fedcba9876543210');
        Tools::$values = array();

        $this->module->getContent();

        $values = HelperForm::$lastHelper->fields_value;
        $this->assertSame('', $values['IFIRMA_KEY_FAKTURA']);
        $this->assertSame('', $values['IFIRMA_KEY_ABONENT']);
        $source = json_encode(array(HelperForm::$lastForm, $values));
        $this->assertStringNotContainsString(str_repeat('1', 64), $source);
        $this->assertStringNotContainsString('fedcba9876543210fedcba9876543210', $source);

        $faktura = $this->formInput('IFIRMA_KEY_FAKTURA');
        $this->assertSame('password', $faktura['type']);
        $this->assertArrayNotHasKey('required', $faktura, 'a stored key needs no new one');
        $this->assertStringContainsString('A key is stored; leave the field empty to keep it.', $faktura['desc']);
        $this->assertSame('password', $this->formInput('IFIRMA_KEY_ABONENT')['type']);
        $this->assertSame('', $values['IFIRMA_KEY_RACHUNEK']);
        $this->assertSame('password', $this->formInput('IFIRMA_KEY_RACHUNEK')['type']);
    }

    public function testTheReceiptKeyHasItsOwnOptionalPasswordInputLikeTheOtherKeys()
    {
        Configuration::updateValue('IFIRMA_KEY_RACHUNEK', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        Tools::$values = array();

        $this->module->getContent();

        $rachunek = $this->formInput('IFIRMA_KEY_RACHUNEK');
        $this->assertSame('API key: rachunek', $rachunek['label']);
        $this->assertSame('password', $rachunek['type']);
        $this->assertArrayNotHasKey('required', $rachunek, 'the receipt key is optional');
        $this->assertStringContainsString('A key is stored; leave the field empty to keep it.', $rachunek['desc']);
        $this->assertStringNotContainsString('a1b2c3d4e5f60718293a4b5c6d7e8f90', json_encode(HelperForm::$lastForm));

        Configuration::deleteByName('IFIRMA_KEY_RACHUNEK');
        $this->module->getContent();
        $rachunek = $this->formInput('IFIRMA_KEY_RACHUNEK');
        $this->assertArrayNotHasKey('required', $rachunek);
        $this->assertStringNotContainsString('A key is stored', $rachunek['desc']);
    }

    public function testTheReceiptKeyIsKeptOnAnEmptyFieldClearedByItsBoxAndReplacedByATypedKey()
    {
        Configuration::updateValue('IFIRMA_KEY_RACHUNEK', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');

        $this->assertSaved($this->save(array('IFIRMA_KEY_RACHUNEK' => '')));
        $this->assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90', $this->stored('IFIRMA_KEY_RACHUNEK'));

        $this->assertSaved($this->save(array('IFIRMA_KEY_RACHUNEK' => 'aabbccdd')));
        $this->assertSame('aabbccdd', $this->stored('IFIRMA_KEY_RACHUNEK'));

        $this->assertSaved($this->save(array('IFIRMA_KEY_RACHUNEK' => '', 'clear_IFIRMA_KEY_RACHUNEK_on' => '1')));
        $this->assertSame('', $this->stored('IFIRMA_KEY_RACHUNEK'));

        $this->assertRejected($this->save(array('IFIRMA_KEY_RACHUNEK' => 'abc')), 'The receipt API key must be a hexadecimal string.');
    }

    public function testWithoutAStoredKeyTheFakturaInputIsRequiredAndSaysNothingAboutAStoredKey()
    {
        Configuration::deleteByName('IFIRMA_KEY_FAKTURA');
        Tools::$values = array();

        $this->module->getContent();

        $faktura = $this->formInput('IFIRMA_KEY_FAKTURA');
        $this->assertTrue($faktura['required']);
        $this->assertStringNotContainsString('A key is stored', $faktura['desc']);
        $this->assertStringNotContainsString('A key is stored', $this->formInput('IFIRMA_KEY_ABONENT')['desc']);
    }

    public function testEveryKeyInputHasARemoveBox()
    {
        Tools::$values = array();
        $this->module->getContent();

        foreach (array('IFIRMA_KEY_FAKTURA', 'IFIRMA_KEY_ABONENT', 'IFIRMA_KEY_RACHUNEK') as $key) {
            $box = $this->formInput('clear_' . $key);
            $this->assertSame('checkbox', $box['type']);
            // HelperForm names the posted field <name>_<value id>
            $this->assertSame('on', $box['values']['query'][0]['id']);
            $this->assertSame('Remove the stored key', $box['values']['query'][0]['name']);
        }
    }

    public function testAnEmptyFieldKeepsTheStoredKeys()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', 'fedcba9876543210fedcba9876543210');
        Configuration::updateValue('IFIRMA_KEY_RACHUNEK', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');

        $page = $this->save(array('IFIRMA_KEY_FAKTURA' => '', 'IFIRMA_KEY_ABONENT' => '   '));

        $this->assertSaved($page);
        $this->assertSame(str_repeat('1', 64), $this->stored('IFIRMA_KEY_FAKTURA'));
        $this->assertSame('fedcba9876543210fedcba9876543210', $this->stored('IFIRMA_KEY_ABONENT'));
        $this->assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90', $this->stored('IFIRMA_KEY_RACHUNEK'), 'an empty receipt key field keeps the stored key');
    }

    public function testANewKeyReplacesTheStoredOne()
    {
        $page = $this->save(array('IFIRMA_KEY_FAKTURA' => ' 0123456789abcdef0123456789abcdef '));

        $this->assertSaved($page);
        $this->assertSame('0123456789abcdef0123456789abcdef', $this->stored('IFIRMA_KEY_FAKTURA'));
    }

    public function testTheRemoveBoxClearsTheStoredKeyOnlyWhenTheFieldIsEmpty()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', 'fedcba9876543210fedcba9876543210');

        $page = $this->save(array('clear_IFIRMA_KEY_FAKTURA_on' => '1', 'clear_IFIRMA_KEY_ABONENT_on' => '1', 'IFIRMA_KEY_ABONENT' => 'aabbcc'));

        $this->assertSaved($page);
        $this->assertSame('', $this->stored('IFIRMA_KEY_FAKTURA'));
        $this->assertSame('aabbcc', $this->stored('IFIRMA_KEY_ABONENT'), 'a typed key wins over the box');
    }

    public function testAMalformedAbonentKeyIsRefused()
    {
        $page = $this->save(array('IFIRMA_KEY_ABONENT' => 'not-hex!'));

        $this->assertRejected($page, 'The abonent API key must be a hexadecimal string.');
    }

    public function testAnEmptyAbonentKeyIsSaved()
    {
        Configuration::deleteByName('IFIRMA_KEY_ABONENT');

        $page = $this->save(array('IFIRMA_KEY_ABONENT' => ''));

        $this->assertSaved($page);
        $this->assertSame('', $this->stored('IFIRMA_KEY_ABONENT'));
    }

    public function testAnArrayLoginIsRefusedWithTheLoginMessage()
    {
        $page = $this->save(array('IFIRMA_USER' => array('x')));

        $this->assertRejected($page, 'The iFirma login is required.');
    }

    public function testAnArrayInAnyScalarFieldIsRefusedOrIgnoredWithoutAWarning()
    {
        set_error_handler(function ($no, $message) {
            throw new ErrorException($message);
        });
        try {
            foreach (array('IFIRMA_DUPLICATE_WINDOW_DAYS', 'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', 'IFIRMA_PAYMENT_METHOD', 'IFIRMA_PAYMENT_METHOD_MAP', 'IFIRMA_SEND_CHANNEL') as $field) {
                $page = $this->save(array($field => array('x')));
                $this->assertNotSame('', $page, $field);
            }
            $page = $this->save(array('IFIRMA_DEFAULT_UNIT' => array('x'), 'IFIRMA_DOC_TYPE' => array('x'), 'IFIRMA_KEY_FAKTURA' => array('x')));
            $this->assertStringStartsWith('<error>The invoice API key must be a hexadecimal string.', $page);
        } finally {
            restore_error_handler();
        }
    }

    // ---- the cron token comes from the secure generator only (8.3) ------------------------------

    public function testRegenerationShowsAnErrorAndKeepsTheOldTokenWhenTheGeneratorIsUnavailable()
    {
        Configuration::updateValue('IFIRMA_CRON_TOKEN', 'cron-token-1');
        IfirmaSchema::$randomBytes = function () {
            throw new Exception('no entropy');
        };
        try {
            Tools::$values = array('submitIfirmaRegenerateCron' => '1');
            Configuration::$writes = array();
            $page = $this->module->getContent();
        } finally {
            IfirmaSchema::$randomBytes = null;
        }

        $this->assertStringStartsWith('<error>The cron token could not be regenerated: the secure random generator is unavailable.</error>', $page);
        $this->assertSame('cron-token-1', Configuration::get('IFIRMA_CRON_TOKEN'));
        $this->assertSame(array(), Configuration::$writes);
    }

    public function testInstallFailsWhenTheCronTokenCannotBeGenerated()
    {
        Module::reset();
        Configuration::deleteByName('IFIRMA_CRON_TOKEN');
        IfirmaSchema::$randomBytes = function () {
            return '';
        };
        try {
            $installed = $this->module->install();
        } finally {
            IfirmaSchema::$randomBytes = null;
        }

        $this->assertFalse($installed);
        $this->assertFalse(Configuration::get('IFIRMA_CRON_TOKEN'));
        $this->assertStringContainsString('The secure random generator is unavailable', implode('|', array_column(PrestaShopLogger::$logs, 'message')));
    }
}
