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
            'IFIRMA_RATE_MAX_PER_MIN' => '60',
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
            'IFIRMA_RATE_MAX_PER_MIN' => 'The API rate limit must be a whole number between 1 and 100 calls per minute.',
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
        $page = $this->save(array($field => '0'));

        $this->assertSaved($page);
        $this->assertSame('0', $this->stored($field));
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
}
