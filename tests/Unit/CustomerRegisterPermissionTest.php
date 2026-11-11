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
class IfirmaTestRegisterJson extends Exception
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
class IfirmaTestRegisterAdminController extends AdminIfirmaController
{
    /** @var int[] */
    public $statuses = array();

    protected function statusHeader($code)
    {
        $this->statuses[] = $code;
    }

    protected function output(array $payload)
    {
        throw new IfirmaTestRegisterJson($payload);
    }
}

/**
 * Spec receipt-access-control, "Registering a customer's contractor needs the issue level": creating a contractor in
 * iFirma from a customer's page needs the same level as registering it from an order, on top of the customers edit
 * permission.
 */
class CustomerRegisterPermissionTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    const PROFILE = 4;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        Access::reset();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id_profile = self::PROFILE;
        $notFound = array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
        $found = array('body' => '{"response":{"Kod":0,"Informacja":"","Wynik":{"Identyfikator":"PS-C5","Nazwa":"Jan K"}}}');
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $notFound);
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $notFound);
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $found);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        Tools::reset();
    }

    private function holding(array $roles)
    {
        Access::reset();
        foreach ($roles as $role) {
            Access::grant($role, self::PROFILE);
        }
    }

    /** @return array array(the envelope, the status codes) */
    private function register()
    {
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_customer' => 5);
        $controller = new IfirmaTestRegisterAdminController();
        try {
            $controller->ajaxProcessCustomerRegister();
        } catch (IfirmaTestRegisterJson $e) {
            return array($e->payload, $controller->statuses);
        }
        $this->fail('no response');
    }

    public function testAnEmployeeWithOnlyTheViewLevelAndTheCustomerEditRightCannotRegisterAContractor()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ, 'ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE'));

        list($payload, $statuses) = $this->register();

        $this->assertSame(array(403), $statuses);
        $this->assertFalse($payload['success']);
        $this->assertSame('You do not have permission to issue iFirma documents.', $payload['message']);
        $this->assertSame(array(), $this->t->calls, 'nothing is sent to iFirma');
    }

    public function testAnEmployeeWithTheIssueLevelAndTheCustomerEditRightCanRegisterAContractor()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE, 'ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE'));

        list($payload, $statuses) = $this->register();

        $this->assertSame(array(), $statuses);
        $this->assertTrue($payload['success'], $payload['message']);
        $this->assertNotSame(array(), $this->t->calls);
    }

    public function testTheIssueLevelAloneStillNeedsTheCustomerEditRight()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE));

        list($payload) = $this->register();

        $this->assertFalse($payload['success']);
        $this->assertSame('You do not have permission to edit customers.', $payload['message']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testLinkingAndUnlinkingStayAtTheViewLevelBecauseTheyOnlyChangeLocalState()
    {
        $this->assertSame('view', AdminIfirmaController::levelFor('CustomerLink'));
        $this->assertSame('view', AdminIfirmaController::levelFor('CustomerUnlink'));
        $this->assertSame('view', AdminIfirmaController::levelFor('CustomerCheck'));
        $this->assertSame('view', AdminIfirmaController::levelFor('CustomerSearch'));
        $this->assertSame('view', AdminIfirmaController::levelFor('CustomerCountRemote'));
    }

    public function testTheOrderPageRegistrationAndTheCustomerPageRegistrationNeedTheSameLevel()
    {
        $this->assertSame(
            AdminIfirmaController::levelFor('RegisterContractor'),
            AdminIfirmaController::levelFor('CustomerRegister')
        );
    }

    public function testTheCustomerPanelOffersNoRegisterButtonToAnEmployeeWithoutTheIssueLevel()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ, 'ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE'));

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $vars = Context::getContext()->smarty->vars;

        $this->assertFalse($vars['ifirma_customer_can_register']);
        $this->assertSame('Registration needs the permission to issue iFirma documents.', $vars['ifirma_customer_register_reason']);
    }

    public function testTheCustomerPanelOffersTheRegisterButtonToAnEmployeeWithTheIssueLevel()
    {
        $this->holding(array(IfirmaPermission::ROLE_READ, IfirmaPermission::ROLE_CREATE, 'ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE'));

        $this->module->hookDisplayAdminCustomers(array('id_customer' => 5));
        $vars = Context::getContext()->smarty->vars;

        $this->assertTrue($vars['ifirma_customer_can_register']);
        $this->assertSame('', $vars['ifirma_customer_register_reason']);
    }
}
