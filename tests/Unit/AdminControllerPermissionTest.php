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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/admin/AdminIfirmaController.php';

/** Thrown by the recording controller instead of ending the request with a JSON envelope. */
class IfirmaTestPermissionJson extends Exception
{
    /** @var array */
    public $payload;

    public function __construct(array $payload)
    {
        parent::__construct('json response');
        $this->payload = $payload;
    }
}

/** Thrown by the recording controller instead of ending the request with a text answer. */
class IfirmaTestPermissionText extends Exception
{
    /** @var int */
    public $status;
    /** @var string */
    public $text;

    public function __construct($status, $text)
    {
        parent::__construct('text response');
        $this->status = $status;
        $this->text = $text;
    }
}

/** AdminIfirmaController that records the status codes and throws its answers instead of ending. */
class IfirmaTestStatusRecordingAdminController extends AdminIfirmaController
{
    /** @var int[] */
    public $statuses = array();

    protected function statusHeader($code)
    {
        $this->statuses[] = $code;
    }

    protected function output(array $payload)
    {
        throw new IfirmaTestPermissionJson($payload);
    }

    protected function textResponse($status, $text)
    {
        throw new IfirmaTestPermissionText($status, $text);
    }
}

/**
 * Spec: receipt-access-control (design D1). Every action needs the level of its table row; the token
 * stays the core's first gate.
 */
class AdminControllerPermissionTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    const PROFILE = 4;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_customer' => 5, 'id_order' => 15);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    // ---- helpers ----------------------------------------------------------------------------

    /** The employee of the context: profile 1 (SuperAdmin) or profile 4 holding exactly these roles. */
    private function employeeHolding(array $roles, $superAdmin = false)
    {
        Access::reset();
        $employee = Context::getContext()->employee;
        $employee->id_profile = $superAdmin ? 1 : self::PROFILE;
        foreach ($roles as $role) {
            Access::grant($role, self::PROFILE);
        }
    }

    /** @return string[] the three permission refusals as the module words them */
    private function refusals()
    {
        return array(
            'view' => 'You do not have permission to see iFirma documents.',
            'issue' => 'You do not have permission to issue iFirma documents.',
            'manage' => 'You do not have permission to manage iFirma documents.',
        );
    }

    /**
     * @return array array(the envelope, the recorded status codes)
     */
    private function callAction($action, array $request = array())
    {
        Tools::$values = array_merge(array('token' => 'admin-token-AdminIfirma', 'id_customer' => 5, 'id_order' => 15), $request);
        $controller = new IfirmaTestStatusRecordingAdminController();
        try {
            $controller->{'ajaxProcess' . $action}();
        } catch (IfirmaTestPermissionJson $e) {
            return array($e->payload, $controller->statuses);
        }
        $this->fail('The action ' . $action . ' produced no response');
    }

    private static function roleOf($level)
    {
        $roles = array(
            'view' => IfirmaPermission::ROLE_READ,
            'issue' => IfirmaPermission::ROLE_CREATE,
            'manage' => IfirmaPermission::ROLE_UPDATE,
        );

        return $roles[$level];
    }

    // ---- the table --------------------------------------------------------------------------

    /** @return array action => level, for every action that has a method today */
    public static function actionTable()
    {
        return array(
            'CreateReceipt' => array('CreateReceipt', 'issue'),
            'IssueAnyway' => array('IssueAnyway', 'issue'),
            'LinkCandidate' => array('LinkCandidate', 'issue'),
            'RegisterContractor' => array('RegisterContractor', 'issue'),
            'UpdateStatus' => array('UpdateStatus', 'manage'),
            'ResendDocument' => array('ResendDocument', 'manage'),
            'MarkSent' => array('MarkSent', 'manage'),
            'SendToKsef' => array('SendToKsef', 'manage'),
            'RefreshStatus' => array('RefreshStatus', 'manage'),
            'ApproveRequest' => array('ApproveRequest', 'manage'),
            'RejectRequest' => array('RejectRequest', 'manage'),
            'CustomerCheck' => array('CustomerCheck', 'view'),
            'CustomerSearch' => array('CustomerSearch', 'view'),
            'CustomerCountRemote' => array('CustomerCountRemote', 'view'),
            'CustomerLink' => array('CustomerLink', 'view'),
            'CustomerUnlink' => array('CustomerUnlink', 'view'),
            'CustomerRegister' => array('CustomerRegister', 'issue'),
            'IssueFromList' => array('IssueFromList', 'issue'),
            'IssueFeed' => array('IssueFeed', 'view'),
            'IssueFeedSeen' => array('IssueFeedSeen', 'view'),
        );
    }

    /** @dataProvider actionTable */
    public function testTheTableHoldsTheLevelOfTheAction($action, $level)
    {
        $this->assertSame($level, AdminIfirmaController::levelFor($action));
        $this->assertTrue(method_exists('AdminIfirmaController', 'ajaxProcess' . $action));
    }

    public function testEveryAjaxMethodOfTheControllerIsInTheTable()
    {
        $table = self::actionTable();
        $methods = array();
        foreach (get_class_methods('AdminIfirmaController') as $method) {
            if (strpos($method, 'ajaxProcess') === 0) {
                $methods[] = substr($method, strlen('ajaxProcess'));
            }
        }
        sort($methods);
        $expected = array_keys($table);
        $expected[] = 'DownloadPdf';
        $expected[] = 'BulkRun'; // its level depends on the posted kind, see testABulkRunIsRefusedByTheLevelOfItsKind
        sort($expected);

        $this->assertSame($expected, $methods);
    }

    public function testTheNextWaveActionsHaveTheirLevelsAlready()
    {
        $this->assertSame('issue', AdminIfirmaController::levelFor('IssueFromList'));
        $this->assertSame('view', AdminIfirmaController::levelFor('IssueFeed'));
        $this->assertSame('view', AdminIfirmaController::levelFor('IssueFeedSeen'));
        $this->assertSame('view', AdminIfirmaController::levelFor('DownloadPdf'));
        $this->assertNull(AdminIfirmaController::levelFor('NoSuchAction'));
    }

    public function testTheLevelOfABulkRunDependsOnItsKind()
    {
        $this->assertSame('manage', AdminIfirmaController::levelFor('BulkRun', 'resend'));
        $this->assertSame('manage', AdminIfirmaController::levelFor('BulkRun', 'refresh'));
        $this->assertSame('manage', AdminIfirmaController::levelFor('BulkRun', 'mark_sent'));
        $this->assertSame('issue', AdminIfirmaController::levelFor('BulkRun', 'issue'));
        $this->assertSame('issue', AdminIfirmaController::levelFor('BulkRun', 'retry'));
        $this->assertSame('manage', AdminIfirmaController::levelFor('BulkRun', 'something else'));
        $this->assertSame('manage', AdminIfirmaController::levelFor('BulkRun'));
    }

    public function testNoViewLevelActionNameStartsWithACorePermissionPrefix()
    {
        $prefixes = array('add', 'new', 'edit', 'update', 'options', 'status', 'delete');
        foreach (self::actionTable() as $case) {
            if ($case[1] !== 'view') {
                continue;
            }
            foreach ($prefixes as $prefix) {
                $this->assertNotSame(0, stripos($case[0], $prefix), $case[0] . ' starts with ' . $prefix);
            }
        }
        foreach (array('DownloadPdf', 'IssueFeed', 'IssueFeedSeen') as $action) {
            foreach ($prefixes as $prefix) {
                $this->assertNotSame(0, stripos($action, $prefix), $action . ' starts with ' . $prefix);
            }
        }
    }

    // ---- the matrix: every action x {no level, view, issue, manage, SuperAdmin} --------------------

    public static function matrix()
    {
        $holders = array(
            'no level' => array(array(), false),
            'view' => array(array(IfirmaPermission::ROLE_READ), false),
            'issue' => array(array(IfirmaPermission::ROLE_CREATE), false),
            'manage' => array(array(IfirmaPermission::ROLE_UPDATE), false),
            'SuperAdmin' => array(array(), true),
        );
        $cases = array();
        foreach (self::actionTable() as $action => $case) {
            foreach ($holders as $name => $holder) {
                $cases[$action . ' as ' . $name] = array($action, $case[1], $holder[0], $holder[1]);
            }
        }

        return $cases;
    }

    /**
     * Scenario: an action needs its level. The role of the level decides, the other two do not.
     *
     * @dataProvider matrix
     */
    public function testEveryActionNeedsItsLevel($action, $level, array $roles, $superAdmin)
    {
        $this->employeeHolding($roles, $superAdmin);
        $allowed = $superAdmin || in_array(self::roleOf($level), $roles, true);
        $refusals = $this->refusals();

        list($payload, $statuses) = $this->callAction($action);

        if ($allowed) {
            // the customer writes pass the level and are then refused for the missing customers-edit right
            $expected = in_array($action, array('CustomerLink', 'CustomerUnlink', 'CustomerRegister'), true) ? array(403) : array();
            $this->assertSame($expected, $statuses, 'no status header for an allowed call');
            $this->assertNotContains($payload['message'], $refusals, 'the body ran');
            return;
        }
        $this->assertSame(array(403), $statuses);
        $this->assertFalse($payload['success']);
        $this->assertSame($refusals[$level], $payload['message']);
        $this->assertSame(array(), $payload['data']);
    }

    public function testARefusedActionDoesNotTouchIfirmaOrTheDatabase()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ));
        IfirmaTestState::clearWrites();

        foreach (array('CreateReceipt', 'IssueAnyway', 'UpdateStatus', 'ResendDocument', 'SendToKsef', 'RefreshStatus') as $action) {
            $this->callAction($action);
        }

        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), ObjectModel::$writeLog);
    }

    public function testTheCustomerWritesStillNeedTheCustomersEditPermissionOnTopOfView()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ));
        list($payload, $statuses) = $this->callAction('CustomerLink', array('identifier' => 'X-1'));

        $this->assertSame(array(403), $statuses, 'the level passed; the missing customers-edit right answers 403 once');
        $this->assertFalse($payload['success']);
        $this->assertSame('You do not have permission to edit customers.', $payload['message']);
    }

    // ---- bulk run (the method arrives in the next wave) ---------------------------------------------

    public function testABulkRunIsRefusedByTheLevelOfItsKind()
    {
        $controller = new IfirmaTestStatusRecordingAdminController();
        $handle = new ReflectionMethod($controller, 'handle');
        $handle->setAccessible(true);
        $refusals = $this->refusals();
        $kinds = array('issue' => 'issue', 'retry' => 'issue', 'resend' => 'manage', 'refresh' => 'manage', 'mark_sent' => 'manage');

        foreach ($kinds as $kind => $level) {
            // an employee holding only the OTHER write level is refused with the message of the level
            $other = $level === 'issue' ? 'manage' : 'issue';
            $this->employeeHolding(array(IfirmaPermission::ROLE_READ, self::roleOf($other)));
            Tools::$values = array('token' => 'admin-token-AdminIfirma', 'kind' => $kind);
            $ran = false;
            try {
                $handle->invoke($controller, 'BulkRun', function () use (&$ran) {
                    $ran = true;
                });
                $this->fail('bulk ' . $kind . ' was not refused');
            } catch (IfirmaTestPermissionJson $e) {
                $this->assertSame($refusals[$level], $e->payload['message'], $kind);
            }
            $this->assertFalse($ran, $kind);

            // holding the level runs the body
            $this->employeeHolding(array(self::roleOf($level)));
            $handle->invoke($controller, 'BulkRun', function () use (&$ran) {
                $ran = true;
            });
            $this->assertTrue($ran, $kind);
        }
    }

    // ---- the token stays first -----------------------------------------------------------------------

    /** Scenario: Missing token still refused first */
    public function testMissingTokenIsRefusedBeforeAnyPermissionCheck()
    {
        $this->employeeHolding(array());
        Tools::$values = array('id_customer' => 5, 'id_order' => 15);
        $controller = new IfirmaTestStatusRecordingAdminController();

        $this->assertFalse($controller->dispatchAjax('CreateReceipt'));
        $this->assertSame(array(), $controller->statuses, 'the permission check never ran');
        $this->assertSame(array('Invalid token'), $controller->errors);
    }

    /** Scenario: Mark as sent needs the manage level */
    public function testAnIssueLevelEmployeeCannotMarkADocumentAsSent()
    {
        $this->makeReceipt(array('delivery_status' => 'failed', 'delivery_channel' => 'shop_mail'));
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));
        IfirmaTestState::clearWrites();

        list($payload, $statuses) = $this->callAction('MarkSent');

        $this->assertSame(array(403), $statuses);
        $this->assertFalse($payload['success']);
        $this->assertSame('You do not have permission to manage iFirma documents.', $payload['message']);
        $this->assertSame('failed', (new IfirmaReceipt(1))->delivery_status);
        $this->assertSame(array(), ObjectModel::$writeLog);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testAManageLevelEmployeeMarksTheDocumentOfThePostedOrderAsSent()
    {
        $this->makeReceipt(array('delivery_status' => 'failed', 'delivery_channel' => 'shop_mail'));
        $this->employeeHolding(array(IfirmaPermission::ROLE_UPDATE));

        list($payload, $statuses) = $this->callAction('MarkSent');

        $this->assertSame(array(), $statuses);
        $this->assertTrue($payload['success'], $payload['message']);
        $this->assertSame('manual', (new IfirmaReceipt(1))->delivery_channel);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(array(), Mail::$sent);
    }

    public function testAMarkSentWithoutTheModuleTokenIsRefusedBeforeTheDocumentIsTouched()
    {
        $this->makeReceipt(array('delivery_status' => 'failed', 'delivery_channel' => 'shop_mail'));
        $this->employeeHolding(array(IfirmaPermission::ROLE_UPDATE));
        Tools::$values = array('id_order' => 15);
        $controller = new IfirmaTestStatusRecordingAdminController();
        IfirmaTestState::clearWrites();

        $this->assertFalse($controller->dispatchAjax('MarkSent'));

        $this->assertSame(array('Invalid token'), $controller->errors);
        $this->assertSame(array(), $controller->statuses, 'the permission check never ran');
        $this->assertSame('failed', (new IfirmaReceipt(1))->delivery_status);
        $this->assertSame(array(), ObjectModel::$writeLog);
    }

    public function testAValidTokenReachesThePermissionCheck()
    {
        $this->employeeHolding(array());
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_order' => 15);
        $controller = new IfirmaTestStatusRecordingAdminController();

        try {
            $controller->dispatchAjax('CreateReceipt');
            $this->fail('no response');
        } catch (IfirmaTestPermissionJson $e) {
            $this->assertSame(array(403), $controller->statuses);
        }
    }

    // ---- the PDF download ----------------------------------------------------------------------------

    /** Scenario: PDF download without view */
    public function testPdfDownloadWithoutViewAnswersA403TextAndCallsNothing()
    {
        $this->makeReceipt(array('status' => 'created'));
        $this->employeeHolding(array(IfirmaPermission::ROLE_CREATE, IfirmaPermission::ROLE_UPDATE));
        IfirmaTestState::clearWrites();
        $controller = new IfirmaTestStatusRecordingAdminController();

        try {
            $controller->ajaxProcessDownloadPdf();
            $this->fail('no response');
        } catch (IfirmaTestPermissionText $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('You do not have permission to see iFirma documents.', $e->text);
        }
        $this->assertSame(array(), $this->t->calls, 'iFirma is not asked for the document');
        $this->assertSame(array(), ObjectModel::$writeLog);
    }

    public function testPdfDownloadWithViewGetsPastTheLevel()
    {
        $this->employeeHolding(array(IfirmaPermission::ROLE_READ));
        $controller = new IfirmaTestStatusRecordingAdminController();

        try {
            $controller->ajaxProcessDownloadPdf();
            $this->fail('no response');
        } catch (IfirmaTestPermissionText $e) {
            // no receipt for the order: the normal 404, not the permission refusal
            $this->assertSame(404, $e->status);
        }
    }

    public function testPdfDownloadWithoutAnEmployeeIsRefused()
    {
        Context::getContext()->employee = null;
        $controller = new IfirmaTestStatusRecordingAdminController();

        try {
            $controller->ajaxProcessDownloadPdf();
            $this->fail('no response');
        } catch (IfirmaTestPermissionText $e) {
            $this->assertSame(403, $e->status);
        }
    }
}
