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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/front/guestrequest.php';

/** A code service that records what the page asks and answers from the test. */
class IfirmaTestFakeGuestCodes extends IfirmaGuestCodeService
{
    /** @var array[] array(id_order, client address) per issue() call */
    public $issued = array();

    /** @var array[] array(id_order, code) per verify() call */
    public $verified = array();

    /** @var string|null the code issue() hands out, null refuses */
    public $code = '123456';

    /** @var Throwable|null raised by issue() and verify() */
    public $failWith;

    public function __construct()
    {
    }

    public function issue(Order $order, $clientAddress)
    {
        if ($this->failWith) {
            throw $this->failWith;
        }
        $this->issued[] = array((int) $order->id, $clientAddress);

        return $this->code;
    }

    public function verify($idOrder, $code)
    {
        if ($this->failWith) {
            throw $this->failWith;
        }
        $this->verified[] = array((int) $idOrder, $code);

        return $this->code !== null && $code === $this->code;
    }
}

/** The guest page with the code service, the clock and the issuance resume under the test's control. */
class IfirmaTestGuestController extends IfirmaGuestrequestModuleFrontController
{
    /** @var IfirmaTestFakeGuestCodes */
    public static $codes;

    /** @var int */
    public static $clock = 1800000000;

    /** @var IfirmaDataRequest[] */
    public $resumed = array();

    /** @var float[] readings of the clock, handed out in order; the real clock once they are used up */
    public static $ticks = array();

    /** @var int[] the waits the page asked for, in microseconds (nothing really sleeps) */
    public static $slept = array();

    protected function clock()
    {
        return self::$ticks ? array_shift(self::$ticks) : parent::clock();
    }

    protected function pause($microseconds)
    {
        self::$slept[] = $microseconds;
    }

    protected function codeService()
    {
        return self::$codes;
    }

    protected function now()
    {
        return self::$clock;
    }

    protected function resumeIssuance(IfirmaDataRequest $request)
    {
        $this->resumed[] = $request;
    }
}

/**
 * The guest page: identify, verify, form, confirmation, and what it refuses.
 */
class GuestRequestControllerTest extends TestCase
{
    use IfirmaWiringFixture;

    const NEUTRAL = 'If the data matches an order, we have sent a code to its e-mail address.';

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Context::getContext()->customer->logged = false;
        IfirmaTestGuestController::$codes = new IfirmaTestFakeGuestCodes();
        IfirmaTestGuestController::$clock = 1800000000;
        IfirmaTestGuestController::$ticks = array();
        IfirmaTestGuestController::$slept = array();
        Tools::$values = array();
        $country = new Country();
        $country->id = 14;
        $country->iso_code = 'PL';
        Country::register($country);
    }

    protected function tearDown(): void
    {
        Tools::reset();
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function codes()
    {
        return IfirmaTestGuestController::$codes;
    }

    private function vars()
    {
        return Context::getContext()->smarty->vars;
    }

    private function cookie()
    {
        $c = Context::getContext()->cookie;

        return isset($c->ifirma_guest) ? $c->ifirma_guest : null;
    }

    /** @return IfirmaTestGuestController the controller after it handled the request */
    private function request(array $values)
    {
        Tools::$values = $values;
        Context::getContext()->smarty->vars = array();
        $controller = new IfirmaTestGuestController();
        $controller->postProcess();
        $controller->initContent();

        return $controller;
    }

    private function identify(array $over = array())
    {
        return $this->request($over + array(
            'identifyIfirmaGuest' => '1',
            'token' => IfirmaTestState::formToken(),
            'reference' => 'ABCDEFGHI',
            'email' => 'jan@example.test',
        ));
    }

    private function verifyCode($code = '123456')
    {
        return $this->request(array(
            'verifyIfirmaGuest' => '1',
            'token' => IfirmaTestState::formToken(),
            'reference' => 'ABCDEFGHI',
            'email' => 'jan@example.test',
            'code' => $code,
        ));
    }

    /** Opens the verified session for order 15 through the verify step. */
    private function openSession()
    {
        $this->verifyCode();
        $this->assertNotNull($this->cookie());
    }

    private function formValues(array $over = array())
    {
        return $over + array(
            'id_order' => '15',
            'token' => IfirmaTestState::formToken(),
            'submitIfirmaDataRequest' => '1',
            'firstname' => 'Jan',
            'lastname' => 'K',
            'address1' => 'Nowa 2',
            'postcode' => '00-002',
            'city' => 'Warszawa',
            'id_country' => '14',
        );
    }

    // ---- the page ------------------------------------------------------------------------------------

    public function testAGuestSeesTheIdentificationFormAndNoOrderData()
    {
        $this->request(array());

        $v = $this->vars();
        $this->assertSame('identify', $v['ifirma_step']);
        $this->assertNull($v['ifirma_order']);
        $this->assertSame('', $v['ifirma_notice']);
        $this->assertSame(array(), $v['ifirma_errors']);
    }

    public function testTheReferenceOfTheQueryFillsTheForm()
    {
        $this->request(array('reference' => 'ABCDEFGHI'));

        $this->assertSame('ABCDEFGHI', $this->vars()['ifirma_reference']);
    }

    public function testALoggedInCustomerIsSentToTheOrderHistoryAndLosesAGuestSession()
    {
        Context::getContext()->customer->logged = true;
        Context::getContext()->cookie->ifirma_guest = '15:1:abc';
        Tools::$values = array();

        try {
            (new IfirmaTestGuestController())->postProcess();
            $this->fail('expected a redirect');
        } catch (IfirmaTestRedirect $e) {
            $this->assertStringContainsString('history', $e->url);
        }
        $this->assertNull($this->cookie());
    }

    public function testWithTheFeatureSwitchedOffThePageSaysSoAndIssuesNoCode()
    {
        Configuration::updateValue('IFIRMA_ALLOW_DATA_REQUEST', 0);

        $this->identify();

        $this->assertSame('disabled', $this->vars()['ifirma_step']);
        $this->assertSame('Changing the invoice data is not available.', $this->vars()['ifirma_reason_message']);
        $this->assertSame(array(), $this->codes()->issued);
        $this->assertSame(array(), Mail::$sent);

        $this->request(array());
        $this->assertSame('disabled', $this->vars()['ifirma_step']);
    }

    // ---- identification ------------------------------------------------------------------------------

    /** @return array[] label => array(posted values, whether the code service is asked) */
    public function answersWithoutAMail()
    {
        return array(
            'unknown reference' => array(array('reference' => 'ZZZZZZZZZ'), false),
            'e-mail of another customer' => array(array('email' => 'other@example.test'), false),
            'empty e-mail' => array(array('email' => ''), false),
            'an array instead of the reference' => array(array('reference' => array('ABCDEFGHI')), false),
        );
    }

    /**
     * @dataProvider answersWithoutAMail
     */
    public function testAnIdentificationThatMatchesNoOrderSendsNothingAndAnswersAsAlways(array $over, $asked)
    {
        $this->identify($over);

        $v = $this->vars();
        $this->assertSame(array(), Mail::$sent);
        $this->assertSame(array(), $this->codes()->issued);
        $this->assertSame(self::NEUTRAL, $v['ifirma_notice']);
        $this->assertSame('verify', $v['ifirma_step']);
        $this->assertSame(array(), $v['ifirma_errors']);
        $this->assertNull($v['ifirma_order']);
    }

    public function testAnOrderThatAlreadyHasADocumentGetsNoCodeAndTheSameAnswer()
    {
        $this->makeReceipt();

        $this->identify();

        $v = $this->vars();
        $this->assertSame(array(), $this->codes()->issued);
        $this->assertSame(array(), Mail::$sent);
        $this->assertSame(self::NEUTRAL, $v['ifirma_notice']);
        $this->assertSame('verify', $v['ifirma_step']);
    }

    public function testARefusedIssuanceSendsNothingAndAnswersAsAlways()
    {
        $this->codes()->code = null;

        $this->identify();

        $v = $this->vars();
        $this->assertCount(1, $this->codes()->issued);
        $this->assertSame(array(), Mail::$sent);
        $this->assertSame(self::NEUTRAL, $v['ifirma_notice']);
        $this->assertSame('verify', $v['ifirma_step']);
    }

    public function testAMatchingOrderGetsTheCodeByMailAndTheNeutralAnswer()
    {
        $this->identify(array('reference' => ' ABCDEFGHI ', 'email' => ' JAN@Example.Test '));

        $this->assertSame(array(array(15, '127.0.0.1')), $this->codes()->issued);
        $this->assertCount(1, Mail::$sent);
        $mail = Mail::$sent[0];
        $this->assertSame('data_request_code', $mail['template']);
        $this->assertSame('jan@example.test', $mail['to']);
        $this->assertSame('Your code to change the invoice data of order ABCDEFGHI', $mail['subject']);
        $this->assertSame('123456', $mail['templateVars']['{code}']);
        $this->assertSame('ABCDEFGHI', $mail['templateVars']['{order_reference}']);
        $this->assertSame('Jan', $mail['templateVars']['{firstname}']);
        $this->assertSame('15', $mail['templateVars']['{minutes}']);
        $v = $this->vars();
        $this->assertSame(self::NEUTRAL, $v['ifirma_notice']);
        $this->assertSame('verify', $v['ifirma_step']);
        $this->assertSame('ABCDEFGHI', $v['ifirma_reference']);
        $this->assertSame('JAN@Example.Test', $v['ifirma_email']);
        $this->assertNull($v['ifirma_order']);
        $this->assertNull($this->cookie());
    }

    public function testTheAnswerIsTheSameForAMatchAndForAMiss()
    {
        $this->identify();
        $match = $this->vars();
        $this->identify(array('reference' => 'ZZZZZZZZZ'));
        $miss = $this->vars();

        foreach (array('ifirma_notice', 'ifirma_step', 'ifirma_errors', 'ifirma_order', 'ifirma_pending', 'ifirma_eligibility') as $key) {
            $this->assertSame($match[$key], $miss[$key], $key);
        }
    }

    public function testAFailingMailChangesNothingForTheVisitor()
    {
        Mail::$result = false;

        $this->identify();

        $this->assertSame(self::NEUTRAL, $this->vars()['ifirma_notice']);
        $this->assertSame('verify', $this->vars()['ifirma_step']);
    }

    // ---- the token -----------------------------------------------------------------------------------

    /** @return array[] label => array(posted values) */
    public function steps()
    {
        return array(
            'identify' => array(array('identifyIfirmaGuest' => '1', 'reference' => 'ABCDEFGHI', 'email' => 'jan@example.test')),
            'verify' => array(array('verifyIfirmaGuest' => '1', 'reference' => 'ABCDEFGHI', 'email' => 'jan@example.test', 'code' => '123456')),
            'submit' => array(array('submitIfirmaDataRequest' => '1', 'id_order' => '15', 'firstname' => 'X', 'city' => 'Y')),
            'cancel' => array(array('cancelIfirmaDataRequest' => '1', 'id_order' => '15')),
        );
    }

    /**
     * @dataProvider steps
     */
    public function testAStepWithoutTheTokenOrWithAWrongOneDoesNothing(array $values)
    {
        foreach (array(array(), array('token' => ''), array('token' => 'wrong'), array('token' => array('x'))) as $token) {
            Mail::$sent = array();
            $this->request($values + $token);

            $v = $this->vars();
            $this->assertSame(array('Invalid form token.'), $v['ifirma_errors']);
            $this->assertSame(array(), $this->codes()->issued);
            $this->assertSame(array(), $this->codes()->verified);
            $this->assertSame(array(), Mail::$sent);
            $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
            $this->assertNull($this->cookie());
        }
    }

    /**
     * Red team 1.6.0 F2: the token of one visitor is useless for another.
     *
     * @dataProvider steps
     */
    public function testAStepWithTheTokenOfAnotherVisitorDoesNothing(array $values)
    {
        $foreign = IfirmaFormToken::forVisitor(new stdClass(), IfirmaFormToken::cookieKey());
        $this->request(array());

        $this->request($values + array('token' => $foreign));

        $this->assertSame(array('Invalid form token.'), $this->vars()['ifirma_errors']);
        $this->assertSame(array(), $this->codes()->issued);
        $this->assertSame(array(), $this->codes()->verified);
        $this->assertSame(array(), Mail::$sent);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testThePageHandsEveryVisitorHisOwnToken()
    {
        $this->request(array());
        $first = $this->vars()['ifirma_token'];
        $this->assertSame($first, IfirmaTestState::formToken());

        Context::getContext()->cookie = new stdClass();
        $this->request(array());

        $this->assertNotSame($first, $this->vars()['ifirma_token']);
        $this->assertNotSame('', $this->vars()['ifirma_token']);
    }

    /**
     * Red team 1.6.0 F2: nothing may change over GET, a valid token and every field notwithstanding.
     *
     * @dataProvider steps
     */
    public function testAGetRequestCarryingAValidTokenAndEveryFieldChangesNothing(array $values)
    {
        $this->openSession();
        $this->codes()->verified = array();
        Mail::$sent = array();
        $session = $this->cookie();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller = $this->request($values + $this->formValues() + array('code' => '123456', 'token' => IfirmaTestState::formToken()));

        $this->assertSame(array(), $this->codes()->issued);
        $this->assertSame(array(), $this->codes()->verified);
        $this->assertSame(array(), Mail::$sent);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
        $this->assertSame(array(), $controller->resumed);
        $this->assertSame(array(), $controller->errors);
        $this->assertSame($session, $this->cookie());
        $this->assertSame('form', $this->vars()['ifirma_step']);
    }

    // ---- the answer time (red team 1.6.0 F7) -------------------------------------------------------------

    /** @return array[] label => array(posted values over the identify defaults, codes the service hands out) */
    public function identifyBranches()
    {
        return array(
            'match, code mailed' => array(array(), '123456'),
            'unknown reference' => array(array('reference' => 'ZZZZZZZZZ'), '123456'),
            'e-mail of another customer' => array(array('email' => 'other@example.test'), '123456'),
            'issuance refused' => array(array(), null),
        );
    }

    /**
     * @dataProvider identifyBranches
     */
    public function testEveryIdentifyBranchWaitsForTheRestOfTheMinimumTime(array $over, $code)
    {
        $this->codes()->code = $code;
        // the work took 200 ms of the 1500 ms
        IfirmaTestGuestController::$ticks = array(1000.000, 1000.200);

        $this->identify($over);

        $this->assertSame(array(1300000), IfirmaTestGuestController::$slept);
    }

    public function testASlowIdentifyIsNotDelayedFurther()
    {
        IfirmaTestGuestController::$ticks = array(1000.000, 1001.600);

        $this->identify();

        $this->assertSame(array(), IfirmaTestGuestController::$slept);
    }

    public function testTheOtherStepsAreNotPadded()
    {
        $this->verifyCode();
        $this->request(array());

        $this->assertSame(array(), IfirmaTestGuestController::$slept);
    }

    public function testTheMinimumIsOneAndAHalfSeconds()
    {
        $this->assertSame(1500, IfirmaGuestrequestModuleFrontController::IDENTIFY_MIN_MS);
    }
    // ---- verification --------------------------------------------------------------------------------

    public function testTheRightCodeOpensTheFormOfTheOrder()
    {
        $this->verifyCode();

        $v = $this->vars();
        $this->assertSame(array(array(15, '123456')), $this->codes()->verified);
        $this->assertSame('form', $v['ifirma_step']);
        $this->assertSame(15, (int) $v['ifirma_order']->id);
        $this->assertTrue($v['ifirma_eligibility']['allowed']);
        $this->assertSame('Prosta 1', $v['ifirma_values']['address1']);
        $this->assertMatchesRegularExpression('/^15:' . (IfirmaTestGuestController::$clock + 1800) . ':[0-9a-f]{64}$/', $this->cookie());
    }

    public function testTheSessionReachesTheFormWithoutANewCode()
    {
        $this->openSession();
        IfirmaTestGuestController::$clock += 29 * 60;

        $this->request(array());

        $this->assertSame('form', $this->vars()['ifirma_step']);
        $this->assertCount(1, $this->codes()->verified);
        $this->assertSame(array(), $this->codes()->issued);
    }

    public function testAWrongCodeIsAnsweredGenericallyAndTheAttemptIsAskedOfTheService()
    {
        $this->verifyCode('000000');

        $v = $this->vars();
        $this->assertSame(array(array(15, '000000')), $this->codes()->verified);
        $this->assertSame(array('Invalid or expired code. You can ask for a new one below.'), $v['ifirma_errors']);
        $this->assertSame('verify', $v['ifirma_step']);
        $this->assertSame('ABCDEFGHI', $v['ifirma_reference']);
        $this->assertNull($v['ifirma_order']);
        $this->assertNull($this->cookie());
    }

    public function testAnUnknownOrderGetsTheSameAnswerAsAWrongCode()
    {
        $this->request(array(
            'verifyIfirmaGuest' => '1', 'token' => IfirmaTestState::formToken(), 'reference' => 'ZZZZZZZZZ',
            'email' => 'jan@example.test', 'code' => '123456',
        ));

        $this->assertSame(array('Invalid or expired code. You can ask for a new one below.'), $this->vars()['ifirma_errors']);
        $this->assertSame('verify', $this->vars()['ifirma_step']);
        $this->assertNull($this->cookie());
    }

    public function testAfterTheAttemptsAreExhaustedTheRightCodeIsRefusedAndANewOneCanBeAsked()
    {
        $this->codes()->code = null; // what the service answers once the code is invalidated

        $this->verifyCode('123456');

        $v = $this->vars();
        $this->assertSame('verify', $v['ifirma_step']);
        $this->assertCount(1, $v['ifirma_errors']);
        $this->assertNull($this->cookie());

        // the page offers a new code: the identification posted again from the verify step
        $this->codes()->code = '654321';
        $this->identify();
        $this->assertSame('654321', Mail::$sent[0]['templateVars']['{code}']);
    }

    public function testAnExpiredSessionShowsTheIdentificationStep()
    {
        $this->openSession();
        IfirmaTestGuestController::$clock += 31 * 60;

        $this->request(array());

        $this->assertSame('identify', $this->vars()['ifirma_step']);
        $this->assertNull($this->vars()['ifirma_order']);
        $this->assertNull($this->cookie());
    }

    public function testATamperedSessionIsRefused()
    {
        $this->openSession();
        $parts = explode(':', $this->cookie());
        Context::getContext()->cookie->ifirma_guest = '16:' . $parts[1] . ':' . $parts[2];

        $this->request(array());

        $this->assertSame('identify', $this->vars()['ifirma_step']);
        $this->assertNull($this->cookie());
    }

    public function testASessionOfAnotherOrderIsRefusedAndTheIdentificationStepIsShown()
    {
        $this->openSession();

        $this->request($this->formValues(array('id_order' => '16')));

        $v = $this->vars();
        $this->assertSame('identify', $v['ifirma_step']);
        $this->assertSame(
            array('Your verification has expired or does not match this order. Please identify the order again.'),
            $v['ifirma_errors']
        );
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
        $this->assertNull($this->cookie());
    }

    public function testSubmittingWithoutASessionIsRefused()
    {
        $this->request($this->formValues());

        $this->assertSame('identify', $this->vars()['ifirma_step']);
        $this->assertCount(1, $this->vars()['ifirma_errors']);
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    // ---- the request ---------------------------------------------------------------------------------

    public function testASubmittedRequestIsConfirmedAndEndsTheSession()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'none');
        $this->openSession();

        $controller = $this->request($this->formValues());

        $v = $this->vars();
        $this->assertSame('done', $v['ifirma_step']);
        $this->assertSame('submitted', $v['ifirma_outcome']);
        $this->assertSame('ABCDEFGHI', $v['ifirma_done_reference']);
        $this->assertNull($v['ifirma_order']);
        $this->assertNull($this->cookie());
        $this->assertSame(array(), $controller->resumed);
        $rows = IfirmaTestState::objects('ifirma_data_request');
        $this->assertCount(1, $rows);
        $this->assertSame(15, (int) reset($rows)['id_order']);
        $this->assertSame('pending', reset($rows)['status']);

        // the session is gone: the form is not reachable without a new code
        $this->request(array());
        $this->assertSame('identify', $this->vars()['ifirma_step']);
    }

    public function testARequestApprovedOnSubmissionResumesTheIssuance()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'safe');
        $this->openSession();

        $controller = $this->request($this->formValues());

        $this->assertSame('done', $this->vars()['ifirma_step']);
        $this->assertCount(1, $controller->resumed);
        $this->assertSame(15, (int) $controller->resumed[0]->id_order);
    }

    public function testAnInvalidRequestKeepsTheFormWithItsErrorsAndTheSession()
    {
        $this->openSession();

        $this->request($this->formValues(array('city' => '', 'postcode' => 'x')));

        $v = $this->vars();
        $this->assertSame('form', $v['ifirma_step']);
        $this->assertNotEmpty($v['ifirma_errors']);
        $this->assertNotEmpty($v['ifirma_field_errors']);
        $this->assertSame('x', $v['ifirma_values']['postcode']);
        $this->assertNotNull($this->cookie());
        $this->assertSame(array(), IfirmaTestState::objects('ifirma_data_request'));
    }

    public function testAPendingRequestIsCancelledAndConfirmed()
    {
        Configuration::updateValue('IFIRMA_DATA_REQUEST_AUTO_ACCEPT', 'none');
        $this->openSession();
        $this->request($this->formValues());
        $this->openSession();
        $this->request(array());
        $this->assertNotNull($this->vars()['ifirma_pending']);

        $this->request(array('cancelIfirmaDataRequest' => '1', 'token' => IfirmaTestState::formToken(), 'id_order' => '15'));

        $v = $this->vars();
        $this->assertSame('done', $v['ifirma_step']);
        $this->assertSame('cancelled', $v['ifirma_outcome']);
        $this->assertSame('ABCDEFGHI', $v['ifirma_done_reference']);
        $this->assertNull($this->cookie());
        $rows = IfirmaTestState::objects('ifirma_data_request');
        $this->assertSame('cancelled', reset($rows)['status']);
    }

    public function testCancellingWithNothingPendingShowsTheFormAgain()
    {
        $this->openSession();

        $this->request(array('cancelIfirmaDataRequest' => '1', 'token' => IfirmaTestState::formToken(), 'id_order' => '15'));

        $this->assertSame('form', $this->vars()['ifirma_step']);
        $this->assertSame(array('This request is no longer pending.'), $this->vars()['ifirma_errors']);
    }

    // ---- failures and defaults -----------------------------------------------------------------------

    public function testACodeServiceThatFailsLeavesTheVisitorWithTheNeutralAnswerAndNoMail()
    {
        $this->codes()->failWith = new RuntimeException('the secure random generator is unavailable');

        $this->identify();

        $this->assertSame(self::NEUTRAL, $this->vars()['ifirma_notice']);
        $this->assertSame(array(), Mail::$sent);

        $this->verifyCode();
        $this->assertSame('verify', $this->vars()['ifirma_step']);
        $this->assertCount(1, $this->vars()['ifirma_errors']);
        $this->assertNull($this->cookie());
    }

    public function testAnOrderWhoseCustomerIsGoneIsRefused()
    {
        $this->openSession();
        Order::$instances[15]->id_customer = 99;

        $this->request($this->formValues());

        $this->assertSame('identify', $this->vars()['ifirma_step']);
        $this->assertCount(1, $this->vars()['ifirma_errors']);
        $this->assertNull($this->cookie());
    }

    public function testTheDefaultSeamsAreTheRealServices()
    {
        $controller = new IfirmaGuestrequestModuleFrontController();
        $call = function ($method) use ($controller) {
            $m = new ReflectionMethod($controller, $method);
            $m->setAccessible(true);

            return $m->invoke($controller);
        };

        $this->assertInstanceOf('IfirmaGuestCodeService', $call('codeService'));
        $this->assertInstanceOf('IfirmaDataRequestNotifier', $call('notifier'));
        $this->assertEqualsWithDelta(time(), $call('now'), 5);
        $this->assertEqualsWithDelta(microtime(true), $call('clock'), 5);

        $pause = new ReflectionMethod($controller, 'pause');
        $pause->setAccessible(true);
        $before = microtime(true);
        $pause->invoke($controller, 20000);
        $this->assertGreaterThanOrEqual(0.015, microtime(true) - $before);
    }

    public function testTheDefaultResumeNeverThrows()
    {
        $controller = new IfirmaGuestrequestModuleFrontController();
        $resume = new ReflectionMethod($controller, 'resumeIssuance');
        $resume->setAccessible(true);
        $request = $this->makeRequest('approved');

        $resume->invoke($controller, $request);
        // whatever the pipeline cannot build or run stays unseen by the visitor
        $controller->module = null;
        $resume->invoke($controller, $request);

        $this->addToAssertionCount(1);
    }

    public function testAnOrderWithADocumentOffersNoForm()
    {
        $this->openSession();
        $this->makeReceipt();

        $this->request(array());

        $v = $this->vars();
        $this->assertSame('form', $v['ifirma_step']);
        $this->assertFalse($v['ifirma_eligibility']['allowed']);
        $this->assertNotSame('', $v['ifirma_reason_message']);
    }
}
