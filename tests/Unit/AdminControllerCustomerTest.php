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

/** Thrown by the capturing controller instead of ending the request. */
class IfirmaTestJsonResponse extends Exception
{
    /** @var array */
    public $payload;

    public function __construct(array $payload)
    {
        parent::__construct('json response');
        $this->payload = $payload;
    }
}

/** AdminIfirmaController whose JSON output is thrown instead of echoed and die()d. */
class IfirmaTestCapturingAdminController extends AdminIfirmaController
{
    /** @var int[] status codes the controller asked for */
    public $statuses = array();

    protected function statusHeader($code)
    {
        $this->statuses[] = $code;
    }

    protected function output(array $payload)
    {
        throw new IfirmaTestJsonResponse($payload);
    }
}

/**
 * Spec: customer-contractor-panel (register a customer, permissions, token) at the level of the
 * IfirmaAdminActions customer methods and the AdminIfirma controller actions.
 */
class AdminControllerCustomerTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    /** @var IfirmaAdminActions */
    private $actions;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        $this->actions = new IfirmaAdminActions($this->module);
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_customer' => 5);
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id_profile = 1;
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    // ---- helpers ----------------------------------------------------------------------------

    private function notFound()
    {
        return array('body' => '{"response":{"Kod":208,"Informacja":"Nie znaleziono kontrahenta"}}');
    }

    private function found($identifier, $name = 'Jan Kowalski')
    {
        return array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'Informacja' => '', 'Wynik' => array('Identyfikator' => $identifier, 'Nazwa' => $name),
        ))));
    }

    private function results(array $rows)
    {
        return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows))));
    }

    private function mapIdentifier()
    {
        $rows = $this->mapRows();

        return $rows ? reset($rows)['ifirma_identifier'] : null;
    }

    private function seedLink($identifier = 'OLD-01')
    {
        IfirmaContractorMap::link(5, $identifier, IfirmaContractorMap::BY_MANUAL, 'Old name');
    }

    /** Runs a controller action and returns the JSON envelope it produced. */
    private function call($action, array $request = array(), $viaDispatch = false)
    {
        Tools::$values = array_merge(array('token' => 'admin-token-AdminIfirma', 'id_customer' => 5), $request);
        $controller = new IfirmaTestCapturingAdminController();
        try {
            if ($viaDispatch) {
                $controller->dispatchAjax($action);
            } else {
                $controller->{'ajaxProcess' . $action}();
            }
        } catch (IfirmaTestJsonResponse $e) {
            return $e->payload;
        }
        $this->fail('The action ' . $action . ' produced no response');
    }

    private function setEditAccess($granted)
    {
        Access::reset();
        if ($granted) {
            Access::grant('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', 1);
        }
    }

    // ---- register a customer (IfirmaAdminActions) --------------------------------------------

    /** Scenario: Register a customer */
    public function testRegisterCustomerDelegatesWithTheNewestOrderAndLinksTheContractor()
    {
        $older = $this->wiringOrder();
        $older->id = 14;
        $older->date_add = date('Y-m-d H:i:s', time() - 5 * 86400);
        Order::register($older);
        // order 15 (one day old) is the newest; order 14 must not be used
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFound());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFound());
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->found('PS-C5', 'Jan K'));

        $r = $this->actions->registerCustomer(5);

        $this->assertTrue($r['success'], $r['message']);
        $this->assertSame('Contractor PS-C5 created in iFirma.', $r['message']);
        $this->assertSame('created', $r['data']['contractor']['action']);
        $this->assertSame('PS-C5', $r['data']['contractor']['identifier']);
        $this->assertSame('PS-C5', $r['data']['link_state']['identifier']);
        $this->assertSame(array('GET', 'GET', 'POST', 'GET'), array_column($this->t->calls, 'method'));
    }

    public function testRegisterCustomerWithoutOrders()
    {
        Order::$instances = array();
        $r = $this->actions->registerCustomer(5);

        $this->assertFalse($r['success']);
        $this->assertSame('Registration needs at least one order of this customer.', $r['message']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testRegisterCustomerWithSyncDisabled()
    {
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $r = $this->actions->registerCustomer(5);

        $this->assertFalse($r['success']);
        $this->assertSame('Contractor registration is disabled in the module settings.', $r['message']);
        $this->assertSame(array(), $this->t->calls);
        $this->assertNull($this->mapIdentifier());
    }

    public function testEveryCustomerActionRefusesAnInvalidCustomer()
    {
        foreach (array(0, 999) as $id) {
            $results = array(
                $this->actions->customerCheck($id),
                $this->actions->customerLink($id, 'X-1'),
                $this->actions->customerUnlink($id),
                $this->actions->registerCustomer($id),
                $this->actions->customerCountRemote($id),
            );
            foreach ($results as $r) {
                $this->assertFalse($r['success']);
                $this->assertSame('Invalid customer.', $r['message']);
            }
        }
        $this->assertSame(array(), $this->t->calls);
    }

    // ---- message mapping ----------------------------------------------------------------------

    public function testCheckMessages()
    {
        $this->seedLink('KOW-01');
        $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));
        $r = $this->actions->customerCheck(5);
        $this->assertTrue($r['success']);
        $this->assertSame('Registered in iFirma as KOW-01', $r['message']);
        $this->assertSame('Registered in iFirma as KOW-01', $r['data']['check']['label']);
        $this->assertSame('registered', $r['data']['check']['state']);

        $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->notFound());
        $r = $this->actions->customerCheck(5);
        $this->assertTrue($r['success']);
        $this->assertSame('Not found in iFirma', $r['data']['check']['label']);

        $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', array('status' => 0, 'error' => 'timeout'));
        $r = $this->actions->customerCheck(5);
        $this->assertFalse($r['success']);
        $this->assertStringStartsWith('Could not check: ', $r['message']);
        $this->assertSame($r['message'], $r['data']['check']['label']);
        $this->assertSame('error', $r['data']['check']['state']);
        $this->assertSame('KOW-01', $this->mapIdentifier());
    }

    public function testSearchMessages()
    {
        $row = array('Identyfikator' => 'KOW-01', 'Nazwa' => 'Jan Kowalski');
        $this->t->queue('GET', '#kontrahenci/Jan%20Kowalski\.json#', $this->results(array($row)));
        $this->t->queue('GET', '#kontrahenci/Kowalski\.json#', $this->results(array()));
        $r = $this->actions->customerSearch(array('firstname' => 'Jan', 'lastname' => 'Kowalski'));
        $this->assertTrue($r['success']);
        $this->assertSame('Found 1 contractor(s).', $r['message']);
        $this->assertSame('KOW-01', $r['data']['search']['rows'][0]['identifier']);

        $this->t->queue('GET', '#kontrahenci/Nowak\.json#', $this->results(array()));
        $r = $this->actions->customerSearch(array('lastname' => 'Nowak'));
        $this->assertTrue($r['success']);
        $this->assertSame('No contractor was found in iFirma.', $r['message']);

        $this->t->queue('GET', '#kontrahenci/Zielinski\.json#', array('status' => 0, 'error' => 'timeout'));
        $r = $this->actions->customerSearch(array('lastname' => 'Zielinski'));
        $this->assertStringContainsString('Search failed for: Zielinski: ', $r['message']);
        $this->assertStringStartsWith('No contractor was found in iFirma.', $r['message']);

        $calls = count($this->t->calls);
        $r = $this->actions->customerSearch(array('email' => 'jan@example.test', 'phone' => '123456789'));
        $this->assertFalse($r['success']);
        $this->assertSame(
            'iFirma searches contractors by name, company or NIP. E-mail and phone are only used to confirm the results; fill in a name, company or NIP.',
            $r['message']
        );
        $this->assertTrue($r['data']['search']['refused']);
        $this->assertCount($calls, $this->t->calls);
    }

    public function testLinkMessages()
    {
        $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));
        $r = $this->actions->customerLink(5, 'KOW-01');
        $this->assertTrue($r['success']);
        $this->assertSame('Customer linked to contractor KOW-01.', $r['message']);
        $this->assertSame('KOW-01', $r['data']['link_state']['identifier']);
        $this->assertTrue($r['data']['link_state']['linked']);

        $this->t->queue('GET', '#kontrahenci/id/GONE-01\.json#', $this->notFound());
        $r = $this->actions->customerLink(5, 'GONE-01');
        $this->assertFalse($r['success']);
        $this->assertSame('Contractor GONE-01 was not found in iFirma; nothing was linked.', $r['message']);
        $this->assertSame('KOW-01', $this->mapIdentifier());

        $this->t->queue('GET', '#kontrahenci/id/ERR-01\.json#', array('status' => 0, 'error' => 'timeout'));
        $r = $this->actions->customerLink(5, 'ERR-01');
        $this->assertFalse($r['success']);
        $this->assertStringStartsWith('Contractor ERR-01 could not be checked: ', $r['message']);
        $this->assertSame('KOW-01', $this->mapIdentifier());

        $calls = count($this->t->calls);
        $r = $this->actions->customerLink(5, '   ');
        $this->assertFalse($r['success']);
        $this->assertSame('Invalid contractor identifier.', $r['message']);
        $this->assertCount($calls, $this->t->calls);
    }

    public function testUnlinkMessages()
    {
        $this->seedLink('KOW-01');
        $r = $this->actions->customerUnlink(5);
        $this->assertTrue($r['success']);
        $this->assertSame('The link was removed. Nothing was changed in iFirma.', $r['message']);
        $this->assertFalse($r['data']['link_state']['linked']);
        $this->assertNull($this->mapIdentifier());

        $r = $this->actions->customerUnlink(5);
        $this->assertFalse($r['success']);
        $this->assertSame('The customer was not linked.', $r['message']);
        $this->assertSame(array(), $this->t->calls);
    }

    public function testCountRemoteMessages()
    {
        Customer::$instances[5]->date_add = date('Y-m-d H:i:s', time() - 3 * 86400);
        $rows = array(
            array('FakturaId' => 1, 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 10),
            array('FakturaId' => 2, 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 10, 'Zaplacono' => 0),
        );
        $this->t->queue('GET', '#faktury\.json#', $this->results($rows));
        $r = $this->actions->customerCountRemote(5);
        $this->assertTrue($r['success'], $r['message']);
        $this->assertMatchesRegularExpression('/^2 document\(s\) in iFirma \(1 paid\), \d{4}-\d{2}-\d{2} – \d{4}-\d{2}-\d{2}\.$/u', $r['message']);
        $this->assertSame(2, $r['data']['count']['total']);
    }

    public function testCountRemoteBudgetSpentInsideTheFirstWindowHasNoEmptyPeriod()
    {
        Customer::$instances[5]->date_add = '2001-01-01 00:00:00';
        $this->seedLink('KOW-01');
        $this->t = new ScriptedTransport();
        IfirmaApiClient::setDefaultTransport($this->t);
        $next = 0;
        $this->t->queue('GET', '#faktury\.json#', function () use (&$next) {
            $rows = array();
            for ($i = 1; $i <= 100; ++$i) {
                $rows[] = array('FakturaId' => ++$next, 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 1, 'Zaplacono' => 0);
            }

            return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows))));
        });
        $this->actions = new IfirmaAdminActions($this->module);
        $r = $this->actions->customerCountRemote(5);
        $this->assertTrue($r['success'], $r['message']);
        $this->assertFalse($r['data']['count']['complete']);
        $this->assertSame('', $r['data']['count']['period_from']);
        $this->assertSame('At least 2000 document(s) in iFirma (0 paid); the call limit was reached before a full year was listed.', $r['message']);
    }

    public function testCountRemoteIncompleteAndFailure()
    {
        Customer::$instances[5]->date_add = '2001-01-01 00:00:00';
        Address::$instances[7]->vat_number = '1234563218';
        $this->seedLink('KOW-01');
        $this->t = new ScriptedTransport();
        IfirmaApiClient::setDefaultTransport($this->t);
        $this->t->queue('GET', '#faktury\.json#', function ($method, $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $rows = array();
            if (isset($q['kontrahent']) && !isset($q['strona']) && $q['dataDo'] === date('Y-m-d')) {
                for ($i = 1; $i <= 100; ++$i) {
                    $rows[] = array('FakturaId' => $i, 'Rodzaj' => 'prz_faktura_kraj', 'Brutto' => 1, 'Zaplacono' => 1);
                }
            }

            return array('body' => json_encode(array('response' => array('Kod' => 0, 'Informacja' => '', 'Wynik' => $rows))));
        });
        $r = $this->actions->customerCountRemote(5);
        $this->assertTrue($r['success'], $r['message']);
        $this->assertFalse($r['data']['count']['complete']);
        $this->assertMatchesRegularExpression('/^At least \d+ document\(s\) in iFirma \(\d+ paid\), \d{4}-\d{2}-\d{2} – \d{4}-\d{2}-\d{2}; the call limit was reached\.$/u', $r['message']);

        $this->t = new ScriptedTransport();
        IfirmaApiClient::setDefaultTransport($this->t);
        $this->t->queue('GET', '#faktury\.json#', function () {
            return array('status' => 0, 'error' => 'timeout');
        });
        $this->actions = new IfirmaAdminActions($this->module); // the service is built lazily per instance
        $r = $this->actions->customerCountRemote(5);
        $this->assertFalse($r['success']);
        $this->assertStringStartsWith('The documents could not be counted: ', $r['message']);
    }

    public function testServiceExceptionsBecomeAFailureEnvelope()
    {
        $service = $this->getMockBuilder('IfirmaCustomerContractorService')->disableOriginalConstructor()->getMock();
        $service->method('check')->willThrowException(new Exception('boom'));
        $this->actions->setCustomerService($service);

        $r = $this->actions->customerCheck(5);

        $this->assertFalse($r['success']);
        $this->assertSame('boom', $r['message']);
    }

    // ---- controller: envelope and criteria --------------------------------------------------------

    public function testControllerCheckReturnsTheEnvelope()
    {
        $this->seedLink('KOW-01');
        $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));

        $p = $this->call('CustomerCheck');

        $this->assertTrue($p['success']);
        $this->assertSame('Registered in iFirma as KOW-01', $p['message']);
        $this->assertSame('registered', $p['data']['check']['state']);
    }

    public function testControllerSearchReadsOnlyTheKnownCriteriaAsStrings()
    {
        $this->t->queue('GET', '#kontrahenci/#', $this->results(array()));

        $p = $this->call('CustomerSearch', array(
            'criteria' => array('lastname' => 'Nowak', 'firstname' => array('x'), 'bogus' => 'ignored', 'nip' => 1234563218),
        ));

        $this->assertTrue($p['success']);
        $this->assertSame(array('1234563218', 'Nowak'), $p['data']['search']['phrases']);
        $paths = array();
        foreach ($this->t->calls as $c) {
            $paths[] = parse_url($c['url'], PHP_URL_PATH);
        }
        $this->assertContains('/iapi/kontrahenci/1234563218.json', $paths);
        $this->assertContains('/iapi/kontrahenci/Nowak.json', $paths);

        $p = $this->call('CustomerSearch');
        $this->assertFalse($p['success']);
        $this->assertTrue($p['data']['search']['refused']);
    }

    public function testControllerCountRemoteIsReadOnlyAndNeedsNoEditPermission()
    {
        $this->setEditAccess(false);
        Customer::$instances[5]->date_add = date('Y-m-d H:i:s', time() - 86400);
        $this->t->queue('GET', '#faktury\.json#', $this->results(array()));

        $p = $this->call('CustomerCountRemote');

        $this->assertTrue($p['success'], $p['message']);
        $this->assertStringStartsWith('0 document(s) in iFirma (0 paid)', $p['message']);
    }

    public function testControllerRegisterWrapsTheContractorAndLinkState()
    {
        $this->setEditAccess(true);
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->notFound());
        $this->t->queue('GET', '#kontrahenci/Jan%20K\.json#', $this->notFound());
        $this->t->queue('POST', '#kontrahenci\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"Dodano kontrahenta","Wynik":"1"}}'));
        $this->t->queue('GET', '#kontrahenci/id/PS-C5\.json#', $this->found('PS-C5', 'Jan K'));

        $p = $this->call('CustomerRegister');

        $this->assertTrue($p['success'], $p['message']);
        $this->assertSame('created', $p['data']['contractor']['action']);
        $this->assertArrayHasKey('link_state', $p['data']);
    }

    // ---- Scenario: Employee without edit permission ---------------------------------------------

    public function testEmployeeWithoutEditPermissionCannotLinkUnlinkOrRegister()
    {
        $this->seedLink('OLD-01');
        $this->setEditAccess(false);

        foreach (array('CustomerLink' => array('identifier' => 'KOW-01'), 'CustomerUnlink' => array(), 'CustomerRegister' => array()) as $action => $req) {
            $p = $this->call($action, $req);
            $this->assertFalse($p['success'], $action);
            $this->assertSame('You do not have permission to edit customers.', $p['message'], $action);
        }
        $this->assertSame('OLD-01', $this->mapIdentifier());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testEmployeeWithEditPermissionViaAccessCanLink()
    {
        $this->seedLink('OLD-01');
        $this->setEditAccess(true);
        $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));

        $p = $this->call('CustomerLink', array('identifier' => 'KOW-01'));

        $this->assertTrue($p['success'], $p['message']);
        $this->assertSame('KOW-01', $p['data']['link_state']['identifier']);
        $this->assertSame('KOW-01', $this->mapIdentifier());
    }

    public function testPermissionIsPerProfile()
    {
        $this->seedLink('OLD-01');
        Access::reset();
        Access::grant('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', 2); // some other profile
        $p = $this->call('CustomerUnlink');

        $this->assertFalse($p['success']);
        $this->assertSame('OLD-01', $this->mapIdentifier());
    }

    // ---- Scenario: Missing token -----------------------------------------------------------------

    /**
     * Core's ModuleAdminController::init() rejects an ajax call whose token does not match
     * Tools::getAdminTokenLite('AdminIfirma') before any ajaxProcess<Action>() runs. The stub
     * reproduces that gate in dispatchAjax(); the module adds no check of its own, so this test
     * pins that a missing or wrong token never reaches the action.
     */
    public function testMissingOrWrongTokenNeverReachesTheAction()
    {
        $this->seedLink('OLD-01');
        $this->setEditAccess(true);

        foreach (array(array('token' => ''), array('token' => 'wrong'), array('token' => null)) as $req) {
            Tools::$values = array_merge(array('id_customer' => 5), $req);
            if ($req['token'] === null) {
                unset(Tools::$values['token']);
            }
            $controller = new IfirmaTestCapturingAdminController();
            $this->assertFalse($controller->dispatchAjax('CustomerUnlink'));
            $this->assertSame(array('Invalid token'), $controller->errors);
        }
        $this->assertSame('OLD-01', $this->mapIdentifier());
        $this->assertSame(array(), $this->t->calls);

        // with the right token the same dispatch reaches the action
        Tools::$values = array('token' => 'admin-token-AdminIfirma', 'id_customer' => 5);
        $controller = new IfirmaTestCapturingAdminController();
        try {
            $controller->dispatchAjax('CustomerUnlink');
            $this->fail('expected a response');
        } catch (IfirmaTestJsonResponse $e) {
            $this->assertTrue($e->payload['success']);
        }
        $this->assertNull($this->mapIdentifier());
    }
}
