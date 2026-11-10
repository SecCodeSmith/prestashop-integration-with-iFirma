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

/** Thrown by the recording controller instead of ending the request. */
class IfirmaTestPostOnlyJson extends Exception
{
    /** @var array */
    public $payload;

    public function __construct(array $payload)
    {
        parent::__construct('json response');
        $this->payload = $payload;
    }
}

/** AdminIfirmaController that records the status codes and throws its envelope instead of ending. */
class IfirmaTestPostOnlyAdminController extends AdminIfirmaController
{
    /** @var int[] */
    public $statuses = array();

    /** @var int PDFs streamed */
    public $streamed = 0;

    protected function statusHeader($code)
    {
        $this->statuses[] = $code;
    }

    protected function output(array $payload)
    {
        throw new IfirmaTestPostOnlyJson($payload);
    }

    protected function streamPdf($pdf, IfirmaReceipt $receipt)
    {
        $this->streamed++;
    }
}

/**
 * Spec receipt-access-control, "State-changing back-office actions need POST": an action that changes state or sends
 * a request to iFirma runs only for a POST; a GET (or any other method) is answered with the failure envelope and
 * HTTP 405, whatever the shop's token setting.
 */
class AdminPostOnlyActionsTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    const PROFILE = 4;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        Access::reset();
        // every level and the customers edit right, so only the method can refuse
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id_profile = self::PROFILE;
        foreach (array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE, IfirmaPermission::ROLE_UPDATE, 'ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE') as $role) {
            Access::grant($role, self::PROFILE);
        }
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        Tools::reset();
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /** @return array[] one case per action that changes state or sends a request to iFirma: action, request */
    public static function stateChangingActions()
    {
        return array(
            'CreateReceipt' => array('CreateReceipt', array()),
            'IssueAnyway' => array('IssueAnyway', array()),
            'LinkCandidate' => array('LinkCandidate', array('id_candidate' => 1)),
            'RegisterContractor' => array('RegisterContractor', array()),
            'IssueFromList' => array('IssueFromList', array()),
            'UpdateStatus' => array('UpdateStatus', array()),
            'ResendDocument' => array('ResendDocument', array()),
            'SendToKsef' => array('SendToKsef', array()),
            'RefreshStatus' => array('RefreshStatus', array()),
            'ApproveRequest' => array('ApproveRequest', array('id_request' => 1)),
            'RejectRequest' => array('RejectRequest', array('id_request' => 1)),
            'CustomerLink' => array('CustomerLink', array('identifier' => 'KOW-01')),
            'CustomerUnlink' => array('CustomerUnlink', array()),
            'CustomerRegister' => array('CustomerRegister', array()),
            'IssueFeedSeen' => array('IssueFeedSeen', array()),
            'BulkRun issue' => array('BulkRun', array('kind' => 'issue', 'ids' => array(15))),
            'BulkRun retry' => array('BulkRun', array('kind' => 'retry', 'ids' => array(1))),
            'BulkRun resend' => array('BulkRun', array('kind' => 'resend', 'ids' => array(1))),
            'BulkRun refresh' => array('BulkRun', array('kind' => 'refresh', 'ids' => array(1))),
        );
    }

    /** @return string[][] the actions that only read */
    public static function readOnlyActions()
    {
        return array(
            'IssueFeed' => array('IssueFeed'),
            'CustomerCheck' => array('CustomerCheck'),
            'CustomerSearch' => array('CustomerSearch'),
            'CustomerCountRemote' => array('CustomerCountRemote'),
        );
    }

    /** @return array array(the envelope, the status codes), the request sent with the method */
    private function call($method, $action, array $request = array())
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        Tools::$values = array_merge(array('token' => 'admin-token-AdminIfirma', 'id_customer' => 5, 'id_order' => 15), $request);
        $controller = new IfirmaTestPostOnlyAdminController();
        try {
            $controller->{'ajaxProcess' . $action}();
        } catch (IfirmaTestPostOnlyJson $e) {
            return array($e->payload, $controller->statuses);
        }
        $this->fail('The action ' . $action . ' produced no response');
    }

    /** @dataProvider stateChangingActions */
    public function testAGetWithAValidTokenAndEveryLevelIsRefusedWith405AndChangesNothing($action, array $request)
    {
        $this->makeReceipt();
        IfirmaTestState::clearWrites();

        list($payload, $statuses) = $this->call('GET', $action, $request);

        $this->assertSame(array(405), $statuses);
        $this->assertFalse($payload['success']);
        $this->assertSame('This action must be sent with POST.', $payload['message']);
        $this->assertSame(array(), $payload['data']);
        $this->assertSame(array(), $this->t->calls, 'nothing is sent to iFirma');
        $this->assertSame(array(), ObjectModel::$writeLog, 'no record is written');
    }

    /** @return string[][] */
    public static function otherMethods()
    {
        return array('HEAD' => array('HEAD'), 'OPTIONS' => array('OPTIONS'), 'PUT' => array('PUT'), 'DELETE' => array('DELETE'), 'lower case get' => array('get'));
    }

    /** @dataProvider otherMethods */
    public function testAnyMethodButPostIsRefused($method)
    {
        list($payload, $statuses) = $this->call($method, 'CreateReceipt');

        $this->assertSame(array(405), $statuses);
        $this->assertFalse($payload['success']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testAMethodOverrideInTheRequestDoesNotMakeAGetAPost()
    {
        list($payload, $statuses) = $this->call('GET', 'CreateReceipt', array('_method' => 'POST', 'method' => 'POST', 'REQUEST_METHOD' => 'POST'));

        $this->assertSame(array(405), $statuses);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testAMissingRequestMethodIsRefused()
    {
        unset($_SERVER['REQUEST_METHOD']);
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_order' => 15);
        $controller = new IfirmaTestPostOnlyAdminController();

        try {
            $controller->ajaxProcessCreateReceipt();
            $this->fail('no response');
        } catch (IfirmaTestPostOnlyJson $e) {
            $this->assertSame(array(405), $controller->statuses);
        }
    }

    public function testAnEmployeeWithoutTheLevelStillGetsTheAccessDeniedAnswerForAGet()
    {
        Access::reset();
        Access::grant(IfirmaPermission::ROLE_READ, self::PROFILE);

        list($payload, $statuses) = $this->call('GET', 'CreateReceipt');

        $this->assertSame(array(403), $statuses);
        $this->assertSame('You do not have permission to issue iFirma documents.', $payload['message']);
    }

    public function testAPostStillRunsAStateChangingAction()
    {
        $this->makeReceipt(array('delivery_status' => 'none'));

        list($payload, $statuses) = $this->call('POST', 'IssueFeedSeen');

        $this->assertSame(array(), $statuses);
        $this->assertTrue($payload['success'], $payload['message']);
    }

    /** @dataProvider readOnlyActions */
    public function testTheReadOnlyActionsStillWorkWithAGet($action)
    {
        $this->t->queue('GET', '#kontrahenci#', array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":[]}}'));
        $this->t->queue('GET', '#faktury\.json#', $this->listBody(array()));

        list($payload, $statuses) = $this->call('GET', $action, array('criteria' => array('lastname' => 'Nowak')));

        $this->assertNotContains(405, $statuses);
        $this->assertNotSame('This action must be sent with POST.', $payload['message']);
    }

    public function testTheBackOfficePdfDownloadStillWorksWithAGet()
    {
        $this->makeReceipt();
        $this->t->queue('GET', '#\.pdf#', array('body' => "%PDF-1.4\nfake body\n%%EOF"));
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_order' => 15);
        $controller = new IfirmaTestPostOnlyAdminController();

        $controller->ajaxProcessDownloadPdf();

        $this->assertSame(1, $controller->streamed);
    }

    public function testEveryAjaxActionOfTheControllerIsClassifiedAsStateChangingOrReadOnly()
    {
        $classified = array_merge(
            array_keys(self::stateChangingActions()),
            array_keys(self::readOnlyActions()),
            array('DownloadPdf', 'BulkRun')
        );
        $methods = array();
        foreach (get_class_methods('AdminIfirmaController') as $method) {
            if (strpos($method, 'ajaxProcess') === 0) {
                $methods[] = substr($method, strlen('ajaxProcess'));
            }
        }

        foreach ($methods as $action) {
            $known = in_array($action, $classified, true);
            $this->assertTrue($known, $action . ' is neither listed as state-changing nor as read-only');
        }
        foreach ($methods as $action) {
            $this->assertSame(
                !in_array($action, array('IssueFeed', 'CustomerCheck', 'CustomerSearch', 'CustomerCountRemote', 'DownloadPdf'), true),
                AdminIfirmaController::requiresPost($action),
                $action
            );
        }
    }

    public function testAnUnknownActionIsNotRunByAnyMethod()
    {
        $this->assertTrue(AdminIfirmaController::requiresPost('NoSuchAction'), 'deny by default');
    }
}
