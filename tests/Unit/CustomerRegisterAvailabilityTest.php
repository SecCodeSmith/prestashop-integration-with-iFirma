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
 * IfirmaCustomerContractorService::registerAvailability(): whether the customer page may offer the
 * contractor registration. It answers with a flag and a reason code; the module class turns the code into
 * text, so the service never translates.
 */
class CustomerRegisterAvailabilityTest extends TestCase
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaCustomerContractorService */
    private $svc;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
        $this->svc = new IfirmaCustomerContractorService($client, new IfirmaContractorService($client));

        // customer 7 has ordered, customer 8 has not
        $order = new Order();
        $order->id = 15;
        $order->id_customer = 7;
        Order::register($order);
    }

    protected function tearDown(): void
    {
        Order::$customerOrdersHook = null;
    }

    public function testTheReasonCodesAreTheWordsTheModuleClassMapsToText()
    {
        $this->assertSame('disabled', IfirmaCustomerContractorService::REGISTER_DISABLED);
        $this->assertSame('no_orders', IfirmaCustomerContractorService::REGISTER_NO_ORDERS);
    }

    public function testACustomerWithAnOrderCanBeRegistered()
    {
        $this->assertSame(array('available' => true, 'reason' => ''), $this->svc->registerAvailability(7));
    }

    public function testACustomerWithoutAnOrderCannotBeRegistered()
    {
        $this->assertSame(array('available' => false, 'reason' => 'no_orders'), $this->svc->registerAvailability(8));
    }

    /**
     * @return array[] label => array(contractor sync setting (null = not set), customer, answer)
     */
    public function settingCases()
    {
        $yes = array('available' => true, 'reason' => '');
        $off = array('available' => false, 'reason' => 'disabled');
        $none = array('available' => false, 'reason' => 'no_orders');

        return array(
            'on when the setting is not set' => array(null, 7, $yes),
            'switched on' => array(1, 7, $yes),
            'switched on, saved as text' => array('1', 7, $yes),
            'switched off' => array(0, 7, $off),
            'switched off, saved as text' => array('0', 7, $off),
            'switched on, no order' => array(1, 8, $none),
            'switching off is told before the missing order' => array(0, 8, $off),
        );
    }

    /**
     * @dataProvider settingCases
     */
    public function testTheContractorSyncSettingDecidesBeforeTheOrders($sync, $idCustomer, $answer)
    {
        if ($sync !== null) {
            Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', $sync);
        }

        $this->assertSame($answer, $this->svc->registerAvailability($idCustomer));
        $this->assertSame(array(), $this->t->calls, 'the decision never asks iFirma');
    }

    public function testTheOrdersAreLookedUpOnlyForAnEnabledRegistrationAndWithTheCustomerAsANumber()
    {
        $asked = array();
        Order::$customerOrdersHook = function ($idCustomer, $rows) use (&$asked) {
            $asked[] = $idCustomer;

            return $rows;
        };

        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->svc->registerAvailability(7);
        $this->assertSame(array(), $asked);

        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 1);
        $this->svc->registerAvailability('7');
        $this->assertSame(array(7), $asked);
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

        $this->assertSame(array('available' => false, 'reason' => 'no_orders'), $this->svc->registerAvailability(7));
    }

    public function testAFailingOrderQuerySurfacesForAnEnabledRegistration()
    {
        Order::$customerOrdersHook = function () {
            throw new RuntimeException('orders query failed');
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('orders query failed');
        $this->svc->registerAvailability(7);
    }

    public function testASwitchedOffRegistrationNeverRunsTheOrderQuery()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        Order::$customerOrdersHook = function () {
            throw new RuntimeException('orders query failed');
        };

        $this->assertSame(array('available' => false, 'reason' => 'disabled'), $this->svc->registerAvailability(7));
    }
}
