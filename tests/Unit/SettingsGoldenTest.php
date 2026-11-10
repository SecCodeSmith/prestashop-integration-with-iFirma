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
 * Golden comparison of the settings form save (refactor-srp-splits 7.1).
 *
 * tests/fixtures/settings/golden.json holds, for the fully valid request of the settings form and for
 * requests that reach every branch of Ifirma::postProcessConfiguration(), what the save does: every
 * Configuration::updateValue() call (key and value, in order, type for type) and the notice the page
 * shows above the form. The requests go through Ifirma::getContent(), as ModuleHooksTest drives it,
 * and the calls are read from Configuration::$writes, so a changed order, count, cast or value fails
 * here, and so does a write that a rejected request must not make. The file's own description says how
 * a scenario is written; refactor-srp-splits 7.2 must leave it unchanged.
 */
class SettingsGoldenTest extends TestCase
{
    use IfirmaWiringFixture;

    /** @var array|null the decoded fixture, read once */
    private static $golden;

    /** @var string what getContent() renders when nothing is submitted: the limit and usage boxes, the form and the contractors panel */
    private $pageWithoutNotice;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        foreach (self::golden()['employees'] as $row) {
            $employee = new Employee();
            $employee->id = $row['id'];
            $employee->firstname = $row['firstname'];
            $employee->lastname = $row['lastname'];
            $employee->active = $row['active'];
            Employee::register($employee);
        }
        $this->pageWithoutNotice = $this->module->getContent();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    /**
     * @return array
     */
    private static function golden()
    {
        if (self::$golden === null) {
            $path = dirname(__DIR__) . '/fixtures/settings/golden.json';
            $decoded = json_decode(file_get_contents($path), true);
            if (!is_array($decoded)) {
                throw new RuntimeException($path . ': ' . json_last_error_msg());
            }
            self::$golden = $decoded;
        }

        return self::$golden;
    }

    /**
     * @return array scenario name => array(scenario)
     */
    public static function scenarios()
    {
        $cases = array();
        foreach (self::golden()['scenarios'] as $scenario) {
            if (isset($cases[$scenario['name']])) {
                throw new LogicException('Two scenarios are named "' . $scenario['name'] . '"');
            }
            $cases[$scenario['name']] = array($scenario);
        }

        return $cases;
    }

    /**
     * Turns {"repeat": s, "times": n} into s repeated n times, wherever it stands in $value.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function expand($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) === array('repeat', 'times')) {
            return str_repeat($value['repeat'], $value['times']);
        }

        return array_map(function ($part) {
            return self::expand($part);
        }, $value);
    }

    /**
     * @param array $scenario
     * @return array the posted fields: the scenario's own request, or the valid one with its changes
     */
    private static function requestOf(array $scenario)
    {
        if (isset($scenario['post'])) {
            return self::expand($scenario['post']);
        }
        $request = self::golden()['valid']['post'];
        foreach (self::expand($scenario['change']) as $field => $value) {
            if ($value === null) {
                unset($request[$field]);
            } else {
                $request[$field] = $value;
            }
        }

        return $request;
    }

    /**
     * @param array $scenario
     * @return array[] the [key, value] calls the scenario must cause, in order
     */
    private function writesOf(array $scenario)
    {
        if (array_key_exists('writes', $scenario)) {
            return self::expand($scenario['writes']);
        }
        $differences = self::expand($scenario['writes_as_valid']);
        $writes = self::expand(self::golden()['valid']['writes']);
        foreach ($writes as $position => $write) {
            if (array_key_exists($write[0], $differences)) {
                $writes[$position][1] = $differences[$write[0]];
                unset($differences[$write[0]]);
            }
        }
        $this->assertSame(
            array(),
            $differences,
            $scenario['name'] . ': writes_as_valid names a key that the valid request does not write'
        );

        return $writes;
    }

    /**
     * @param string $name
     * @return array
     */
    private static function scenarioNamed($name)
    {
        $cases = self::scenarios();
        if (!isset($cases[$name])) {
            throw new OutOfBoundsException('No scenario named "' . $name . '"');
        }

        return $cases[$name][0];
    }

    /**
     * Every message the checks of postProcessConfiguration() can report, in the order they run.
     *
     * @return string[]
     */
    private static function validationMessages()
    {
        return array(
            'The iFirma login is required.',
            'The invoice API key must be a hexadecimal string.',
            'Choose an active employee for the delivery copy.',
            'The duplicate-check window must be a whole number of days between 1 and 365.',
            'The amount tolerance must be a number greater than or equal to 0.',
            'The notification e-mail address is not valid.',
            'The sync interval must be a whole number of minutes between 1 and 1440.',
            'The cron call budget must be a whole number between 1 and 90.',
            'The cron item limit must be a whole number between 1 and 50.',
            'The API rate limit must be a whole number between 1 and 100 calls per minute.',
            'The API daily limit must be a whole number between 1 and 15000 calls per day.',
            'The payment method mode must be "order" or "fixed".',
            'The fallback payment method must be one of the listed codes.',
            'Payment method map: line 1: ps_checkpayment=XYZ',
        );
    }

    /**
     * @dataProvider scenarios
     */
    public function testTheSaveWritesAndReportsWhatWasCaptured(array $scenario)
    {
        Tools::$values = self::requestOf($scenario);
        $writes = $this->writesOf($scenario);
        $storedBefore = Configuration::$values;
        Configuration::$writes = array();

        $page = $this->module->getContent();

        $this->assertSame(
            array_merge($writes, array_key_exists('page_writes', $scenario) ? $scenario['page_writes'] : array()),
            Configuration::$writes,
            'Configuration writes'
        );
        // the limit box follows the stored abonent key, so a save that changes the key changes the page below the notice
        Tools::$values = array();
        $this->assertSame($scenario['notice'] . ($writes === array() ? $this->pageWithoutNotice : $this->module->getContent()), $page, 'page');
        if ($writes === array()) {
            $this->assertSame($storedBefore, Configuration::$values, 'a rejected request stores nothing');
        }
    }

    public function testTheValidRequestPostsEveryFieldItStoresAndStoresEachOnce()
    {
        $keys = array_column(self::golden()['valid']['writes'], 0);
        $fields = array_keys(self::golden()['valid']['post']);

        $this->assertSame(array('submitIfirma'), array_values(array_diff($fields, $keys)), 'the submit button is the only field not stored');
        $this->assertSame(array(), array_diff($keys, $fields), 'every stored key is posted');
        $this->assertSame($keys, array_unique($keys), 'no key is stored twice');
    }

    public function testEveryValidationMessageHasARequestOfItsOwn()
    {
        $alone = array();
        foreach (self::scenarios() as $case) {
            if (array_key_exists('writes', $case[0]) && $case[0]['writes'] === array()) {
                $alone[$case[0]['notice']] = true;
            }
        }

        foreach (self::validationMessages() as $message) {
            $this->assertArrayHasKey('<error>' . $message . '</error>', $alone, $message);
        }
    }

    public function testOneRequestReachesEveryValidationMessageInTheOrderOfTheChecks()
    {
        $all = self::scenarioNamed('every validation error at once');

        $this->assertSame('<error>' . implode('<br>', self::validationMessages()) . '</error>', $all['notice']);
        $this->assertSame(array(), $all['writes']);
    }

    public function testTheConfigurationStubRecordsEveryWriteInOrder()
    {
        Configuration::$writes = array();

        Configuration::updateValue('IFIRMA_A', 1);
        Configuration::updateValue('IFIRMA_B', false);
        Configuration::updateValue('IFIRMA_A', '2');

        $this->assertSame(array(array('IFIRMA_A', 1), array('IFIRMA_B', false), array('IFIRMA_A', '2')), Configuration::$writes);
        Configuration::reset();
        $this->assertSame(array(), Configuration::$writes);
    }
}
