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

/** IfirmaActionSupport with its helpers made public, so that the test can call them. */
class IfirmaTestActionProbe extends IfirmaActionSupport
{
    public function result($success, $message, array $data = array())
    {
        return parent::result($success, $message, $data);
    }

    public function failure($e)
    {
        return parent::failure($e);
    }

    public function guard(callable $action)
    {
        return parent::guard($action);
    }

    public function loadOrder($idOrder)
    {
        return parent::loadOrder($idOrder);
    }

    public function module()
    {
        return $this->module;
    }
}

/**
 * IfirmaActionSupport: what the three admin action classes share. Their own tests (AdminActionsTest,
 * ErrorGuardTest and the others) reach these helpers through the actions; here each one is called
 * directly, with the inputs no action passes today (a number for a message, a string for an order id).
 */
class ActionSupportTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaThrowableProviders;

    /** @var IfirmaTestActionProbe */
    private $probe;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->probe = new IfirmaTestActionProbe($this->module);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    public function testTheBaseClassIsNeverUsedOnItsOwn()
    {
        $this->assertTrue((new ReflectionClass('IfirmaActionSupport'))->isAbstract());
    }

    public function testTheActionClassesExtendIt()
    {
        foreach (array('IfirmaReceiptActions', 'IfirmaCustomerActions', 'IfirmaDataRequestActions') as $class) {
            $this->assertTrue(is_subclass_of($class, 'IfirmaActionSupport'), $class);
            $this->assertInstanceOf($class, new $class($this->module));
        }
    }

    public function testTheModuleIsKeptForTheSubclasses()
    {
        $this->assertSame($this->module, $this->probe->module());
    }

    // ---- result ------------------------------------------------------------------------------------

    public function testResultAnswersTheThreePartsInOrder()
    {
        $this->assertSame(
            array('success' => true, 'message' => 'Done.', 'data' => array('id' => 3)),
            $this->probe->result(true, 'Done.', array('id' => 3))
        );
        $this->assertSame(
            array('success' => false, 'message' => 'No.', 'data' => array()),
            $this->probe->result(false, 'No.')
        );
    }

    public function testResultTurnsTheFlagIntoABooleanAndTheMessageIntoAString()
    {
        $this->assertSame(
            array('success' => true, 'message' => '5', 'data' => array()),
            $this->probe->result(1, 5)
        );
        $this->assertSame(
            array('success' => false, 'message' => '', 'data' => array()),
            $this->probe->result(0, null)
        );
    }

    // ---- failure and guard ---------------------------------------------------------------------------

    /**
     * @dataProvider throwables
     */
    public function testFailureAnswersTheMessageOfAnyThrowableWithoutData(Throwable $failure)
    {
        $this->assertSame(
            array('success' => false, 'message' => $failure->getMessage(), 'data' => array()),
            $this->probe->failure($failure)
        );
    }

    public function testFailureKeepsTheFieldErrorsAndTheCodeOfARefusal()
    {
        $refusal = new IfirmaDataRequestException('Please correct the highlighted fields.');
        $refusal->code_name = 'invalid';
        $refusal->errors = array('postcode' => 'The postcode is required.');

        $this->assertSame(
            array(
                'success' => false,
                'message' => 'Please correct the highlighted fields.',
                'data' => array('errors' => array('postcode' => 'The postcode is required.'), 'code' => 'invalid'),
            ),
            $this->probe->failure($refusal)
        );
    }

    public function testGuardAnswersWhatTheActionReturnsAndRunsItOnce()
    {
        $calls = 0;
        $answer = array('success' => true, 'message' => 'Fine.', 'data' => array('n' => 1));

        $r = $this->probe->guard(function () use (&$calls, $answer) {
            ++$calls;

            return $answer;
        });

        $this->assertSame($answer, $r);
        $this->assertSame(1, $calls);
    }

    /**
     * @dataProvider throwables
     */
    public function testGuardAnswersTheFailureEnvelopeOfAnythingTheActionThrows(Throwable $failure)
    {
        $r = $this->probe->guard(function () use ($failure) {
            throw $failure;
        });

        $this->assertSame(array('success' => false, 'message' => $failure->getMessage(), 'data' => array()), $r);
    }

    public function testGuardKeepsTheRefusalOfADataRequest()
    {
        $refusal = new IfirmaDataRequestException('This request is no longer pending.');
        $refusal->code_name = 'not_pending';
        $refusal->errors = array();

        $r = $this->probe->guard(function () use ($refusal) {
            throw $refusal;
        });

        $this->assertSame(
            array('success' => false, 'message' => 'This request is no longer pending.', 'data' => array('errors' => array(), 'code' => 'not_pending')),
            $r
        );
    }

    // ---- the order lookup ----------------------------------------------------------------------------

    public function testLoadOrderAnswersTheLoadedOrder()
    {
        $order = $this->probe->loadOrder(15);

        $this->assertInstanceOf('Order', $order);
        $this->assertSame(15, (int) $order->id);
        $this->assertSame(15, (int) $this->probe->loadOrder('15')->id);
    }

    public function testLoadOrderAnswersNullForAnOrderThatDoesNotExist()
    {
        $this->assertNull($this->probe->loadOrder(999));
        $this->assertNull($this->probe->loadOrder(0));
        $this->assertNull($this->probe->loadOrder('abc'));
    }
}
