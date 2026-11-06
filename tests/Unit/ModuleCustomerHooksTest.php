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
 * What the two customer-page hooks of the module class hand to their templates, and the decisions behind it:
 *
 * - hookDisplayAdminCustomers(): whether the contractor can be registered from the customer page, and the
 *   reason shown when it cannot;
 * - hookDisplayOrderDetail(): the options of the order block, that is the switches, the name of the trigger
 *   state and the payment tolerance, all read from the configuration.
 *
 * Every case goes through the real hook, so the tests keep holding when a decision moves out of the module
 * class.
 */
class ModuleCustomerHooksTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    const REASON_DISABLED = 'Contractor registration is disabled in the module settings.';
    const REASON_NO_ORDERS = 'Registration needs at least one order of this customer.';

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();

        // a customer who has not ordered anything (customer 5 has order 15)
        $c = new Customer();
        $c->id = 8;
        $c->firstname = 'Anna';
        $c->lastname = 'Nowak';
        Customer::register($c);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        Order::$customerOrdersHook = null;
    }

    private function vars()
    {
        return Context::getContext()->smarty->vars;
    }

    /**
     * @param string     $key
     * @param mixed|null $value null leaves the key out of the configuration
     */
    private function setting($key, $value)
    {
        if ($value === null) {
            Configuration::deleteByName($key);
        } else {
            Configuration::updateValue($key, $value);
        }
    }

    private function registerState($id, $name)
    {
        $state = new OrderState();
        $state->id = $id;
        $state->name = $name;
        OrderState::register($state);
    }

    private function orderView()
    {
        $this->module->hookDisplayOrderDetail(array('order' => Order::$instances[15]));

        return $this->vars()['ifirma_view'];
    }

    // ---- the customer page: can the contractor be registered, and if not why -------------------------

    /**
     * @return array[] label => array(contractor sync setting (null = not set), customer, can register, reason)
     */
    public function registerCases()
    {
        return array(
            'on when the setting is not set' => array(null, 5, true, ''),
            'switched on' => array(1, 5, true, ''),
            'switched on, saved as text' => array('1', 5, true, ''),
            'switched off' => array(0, 5, false, self::REASON_DISABLED),
            'switched off, saved as text' => array('0', 5, false, self::REASON_DISABLED),
            'no order' => array(null, 8, false, self::REASON_NO_ORDERS),
            'no order, switched on' => array(1, 8, false, self::REASON_NO_ORDERS),
            'switching off is told before the missing order' => array(0, 8, false, self::REASON_DISABLED),
        );
    }

    /**
     * @dataProvider registerCases
     */
    public function testThePanelSaysWhetherTheContractorCanBeRegisteredAndWhyNot($sync, $idCustomer, $can, $reason)
    {
        $this->setting('IFIRMA_CONTRACTOR_SYNC', $sync);

        $this->module->hookDisplayAdminCustomers(array('id_customer' => $idCustomer));
        $v = $this->vars();

        $this->assertSame($can, $v['ifirma_customer_can_register']);
        $this->assertSame($reason, $v['ifirma_customer_register_reason']);
        $this->assertSame(array(), $this->t->calls, 'the decision never asks iFirma');
    }

    public function testThePanelAssignsAllItsValuesInTheSameOrder()
    {
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));

        $this->assertSame(array(
            'ifirma_customer_id',
            'ifirma_customer_configured',
            'ifirma_customer_link',
            'ifirma_customer_match_label',
            'ifirma_customer_match_labels_json',
            'ifirma_customer_counts',
            'ifirma_customer_criteria',
            'ifirma_customer_can_register',
            'ifirma_customer_register_reason',
            'ifirma_customer_ajax_url',
            'ifirma_customer_css_url',
        ), array_keys($this->vars()));
    }

    public function testThePanelValuesBesideTheRegistrationDecision()
    {
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertSame(5, $v['ifirma_customer_id']);
        $this->assertTrue($v['ifirma_customer_configured']);
        $this->assertSame(array('identifier' => 'PS-C5', 'linked' => false, 'name' => '', 'matched_by' => ''), $v['ifirma_customer_link']);
        $this->assertSame('', $v['ifirma_customer_match_label']);
        $this->assertCount(9, json_decode($v['ifirma_customer_match_labels_json'], true));
        $this->assertSame(
            array('issued' => 0, 'paid' => 0, 'held' => 0, 'attention' => 0, 'by_type' => array('receipt' => 0, 'invoice' => 0, 'other' => 0)),
            $v['ifirma_customer_counts']
        );
        $this->assertSame(
            array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'company' => '', 'nip' => '', 'email' => 'jan@example.test', 'phone' => ''),
            $v['ifirma_customer_criteria']
        );
        $this->assertSame('https://shop.test/admin/index.php?controller=AdminIfirma&token=admin-token-AdminIfirma', $v['ifirma_customer_ajax_url']);
        $this->assertSame('/modules/ifirma/views/css/ifirma.css?v=' . rawurlencode($this->module->version), $v['ifirma_customer_css_url']);
    }

    /**
     * @return Closure that records the customer id of every orders query and lets the stub answer
     */
    private function recordOrderLookups(array &$ids)
    {
        return function ($idCustomer, $rows) use (&$ids) {
            $ids[] = $idCustomer;

            return $rows;
        };
    }

    public function testOnlyAnEnabledRegistrationLooksForTheOrdersOfTheCustomer()
    {
        $off = array();
        Order::$customerOrdersHook = $this->recordOrderLookups($off);
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));

        $on = array();
        Order::$customerOrdersHook = $this->recordOrderLookups($on);
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 1);
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));

        // the search form reads the orders for the invoice address either way (one lookup); the decision is
        // the second one, for this customer as a number, and only when the registration is on
        $this->assertSame(array(5), $off);
        $this->assertSame(array(5, 5), $on);
    }

    public function testASwitchedOffRegistrationIsToldWithoutTheOrderQuery()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        Order::$customerOrdersHook = function () {
            throw new RuntimeException('orders query failed');
        };

        // the search form reads the orders too, and swallows a failing query
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertFalse($v['ifirma_customer_can_register']);
        $this->assertSame(self::REASON_DISABLED, $v['ifirma_customer_register_reason']);
    }

    public function testAFailingOrderQueryOfTheDecisionSurfaces()
    {
        Order::$customerOrdersHook = function () {
            throw new RuntimeException('orders query failed');
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('orders query failed');
        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
    }

    /**
     * @return array[] label => array(what the orders query answers)
     */
    public function noOrdersAnswers()
    {
        return array(
            'an empty list' => array(array()),
            'false' => array(false),
            'null' => array(null),
        );
    }

    /**
     * @dataProvider noOrdersAnswers
     */
    public function testAnyEmptyAnswerOfTheOrderQueryMeansNoOrder($answer)
    {
        Order::$customerOrdersHook = function () use ($answer) {
            return $answer;
        };

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $v = $this->vars();

        $this->assertFalse($v['ifirma_customer_can_register']);
        $this->assertSame(self::REASON_NO_ORDERS, $v['ifirma_customer_register_reason']);
    }

    public function testTheReasonIsTheModulesTranslationOfItsLiteral()
    {
        $asked = array();
        $module = $this->getMockBuilder(get_class($this->module))->onlyMethods(array('l'))->getMock();
        $module->method('l')->willReturnCallback(function ($string, $specific = null) use (&$asked) {
            $asked[] = array($string, $specific);

            return 'T:' . $string;
        });
        $reasons = array(self::REASON_DISABLED, self::REASON_NO_ORDERS);
        $translated = function () use (&$asked, $reasons) {
            $found = array();
            foreach ($asked as $args) {
                if (in_array($args[0], $reasons, true)) {
                    $found[] = $args;
                }
            }

            return $found;
        };

        // available: neither reason is looked up
        $module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $this->assertSame('', $this->vars()['ifirma_customer_register_reason']);
        $this->assertSame(array(), $translated());

        // no order: only its reason is looked up, without a translation domain of its own
        $module->hookDisplayAdminCustomers(array('id_customer' => 8));
        $this->assertSame('T:' . self::REASON_NO_ORDERS, $this->vars()['ifirma_customer_register_reason']);
        $this->assertSame(array(array(self::REASON_NO_ORDERS, null)), $translated());

        // switched off: only its reason is looked up, without a translation domain of its own
        $asked = array();
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $module->hookDisplayAdminCustomers(array('id_customer' => 8));
        $this->assertSame('T:' . self::REASON_DISABLED, $this->vars()['ifirma_customer_register_reason']);
        $this->assertSame(array(array(self::REASON_DISABLED, null)), $translated());
    }

    // ---- the order page: the options of the block -------------------------------------------------

    public function testTheViewOfAnOrderWithoutADocumentCarriesTheOptionsOfTheShop()
    {
        $this->registerState(5, 'Payment accepted');

        $view = $this->orderView();

        $this->assertSame(array(
            'state' => 'not_issued',
            'state_label' => 'Not issued yet',
            'can_request_change' => true,
            'request_url' => 'https://shop.test/module/ifirma/datarequest?id_order=15',
            'cancel_url' => 'https://shop.test/module/ifirma/datarequest?id_order=15&cancel=1',
            'pending_request' => null,
            'auto_issue_enabled' => true,
            'trigger_state_name' => 'Payment accepted',
            'contact_shop' => false,
        ), $view);
    }

    /**
     * @return array[] label => array(allow data request setting (null = not set), the customer can ask for a change)
     */
    public function dataRequestSettings()
    {
        return array(
            'on' => array(1, true),
            'on, saved as text' => array('1', true),
            'off' => array(0, false),
            'off, saved as text' => array('0', false),
            'not set' => array(null, false),
        );
    }

    /**
     * @dataProvider dataRequestSettings
     */
    public function testTheDataRequestSwitchDecidesWhetherTheCustomerCanAskForAChange($setting, $canRequest)
    {
        $this->setting('IFIRMA_ALLOW_DATA_REQUEST', $setting);

        $this->assertSame($canRequest, $this->orderView()['can_request_change']);
    }

    /**
     * @return array[] label => array(trigger state setting (null = not set), auto issue, name shown, ids loaded)
     */
    public function triggerStateSettings()
    {
        return array(
            'not set' => array(null, false, '', array()),
            'zero' => array(0, false, '', array()),
            'zero, saved as text' => array('0', false, '', array()),
            'negative' => array(-1, false, '', array()),
            'the lowest state id' => array(1, true, '', array(1)),
            'a state' => array(5, true, 'Payment accepted', array(5)),
            'a state, saved as text' => array('5', true, 'Payment accepted', array(5)),
        );
    }

    /**
     * @dataProvider triggerStateSettings
     */
    public function testTheTriggerStateSwitchesAutoIssueOnAndNamesTheState($setting, $autoIssue, $name, $loaded)
    {
        $this->registerState(5, 'Payment accepted');
        $this->setting('IFIRMA_AUTO_CREATE_STATE', $setting);
        OrderState::$loads = array();

        $view = $this->orderView();

        $this->assertSame($autoIssue, $view['auto_issue_enabled']);
        $this->assertSame($name, $view['trigger_state_name']);
        $this->assertSame($loaded, OrderState::$loads, 'only a state that is set is looked up, as a number');
    }

    /**
     * @return array[] label => array(name of the state, id of the language of the shop, name shown)
     */
    public function triggerStateNames()
    {
        $names = array(1 => 'Paid', 2 => 'Zaplacono');

        return array(
            'a plain name' => array('Payment accepted', 1, 'Payment accepted'),
            'a plain name, another language' => array('Payment accepted', 2, 'Payment accepted'),
            'the name in the language of the shop' => array($names, 1, 'Paid'),
            'the name in the other language of the shop' => array($names, 2, 'Zaplacono'),
            'the language id is text' => array($names, '2', 'Zaplacono'),
            'the first name when the language has none' => array(array(1 => 'Paid', 3 => 'Bezahlt'), 2, 'Paid'),
            'the first of the list, not the lowest id' => array(array(3 => 'Bezahlt', 1 => 'Paid'), 2, 'Bezahlt'),
            'a language without text counts as none' => array(array(3 => 'Bezahlt', 1 => null), 1, 'Bezahlt'),
            'a language with an empty text keeps it' => array(array(3 => 'Bezahlt', 1 => ''), 1, ''),
            'no names at all' => array(array(), 1, ''),
            'a state without a name' => array(null, 1, ''),
        );
    }

    /**
     * @dataProvider triggerStateNames
     */
    public function testTheTriggerStateIsNamedInTheLanguageOfTheShop($name, $idLang, $shown)
    {
        $this->registerState(5, $name);
        Context::getContext()->language->id = $idLang;

        $view = $this->orderView();

        $this->assertSame($shown, $view['trigger_state_name']);
        $this->assertSame(array(5), OrderState::$loads);
        $this->assertSame(array((int) $idLang), OrderState::$langs, 'the state is read in the language of the context, as a number');
    }

    public function testAStateThatIsNotRegisteredHasNoName()
    {
        $view = $this->orderView();

        $this->assertTrue($view['auto_issue_enabled']);
        $this->assertSame('', $view['trigger_state_name']);
        $this->assertSame(array(5), OrderState::$loads);
    }

    /**
     * @return array[] label => array(tolerance setting (null = not set), amount iFirma reports paid, document counts as paid)
     */
    public function toleranceCases()
    {
        // the order is 123.00 in total
        return array(
            'no tolerance is read as none, not as a cent' => array(null, 122.995, false),
            'a cent covers half a cent' => array('0.01', 122.995, true),
            'a tenth of a cent does not' => array('0.001', 122.995, false),
            'fifty groszy cover 40 groszy' => array('0.5', 122.6, true),
            'a cent does not' => array('0.01', 122.6, false),
            'a tolerance saved as a number' => array(0.5, 122.6, true),
        );
    }

    /**
     * @dataProvider toleranceCases
     */
    public function testTheDocumentIsPaidWithinTheConfiguredAmountTolerance($setting, $remotePaid, $paid)
    {
        $this->makeReceipt(array('remote_paid_amount' => $remotePaid));
        $this->setting('IFIRMA_DUPLICATE_AMOUNT_TOLERANCE', $setting);

        $view = $this->orderView();

        $this->assertSame('issued', $view['state']);
        $this->assertSame($paid, $view['document']['paid']);
        $this->assertSame(array(), $this->t->calls, 'the receipt is fresh, so nothing is synced');
    }
}
