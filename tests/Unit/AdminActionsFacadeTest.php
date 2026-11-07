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
 * What the AdminIfirma controller and the other callers rely on in IfirmaAdminActions, whichever classes
 * do the work behind it:
 * - every public method, with its signature;
 * - every argument reaching the code that uses it, and every answer coming back unchanged;
 * - the customer service seam, and the service that is built once per instance;
 * - the module and the translation domain behind every message.
 *
 * The answers of the single actions are pinned in AdminActionsTest, AdminControllerCustomerTest and
 * ErrorGuardTest; this class adds what those leave open.
 */
class AdminActionsFacadeTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function suspectedReceipt()
    {
        return $this->makeReceipt(array(
            'status' => 'duplicate_suspected',
            'ifirma_id' => '',
            'ifirma_number' => '',
            'duplicate_candidates' => json_encode(array(
                'candidates' => array(IfirmaDuplicateMatcher::summarize($this->remoteRow())),
                'resolution' => null,
            )),
        ));
    }

    private function found($identifier)
    {
        return array('body' => json_encode(array('response' => array(
            'Kod' => 0, 'Informacja' => '', 'Wynik' => array('Identyfikator' => $identifier, 'Nazwa' => 'Jan Kowalski'),
        ))));
    }

    /** A customer service double whose check() raises $message. */
    private function serviceRaising($message)
    {
        $service = $this->getMockBuilder('IfirmaCustomerContractorService')->disableOriginalConstructor()->getMock();
        $service->method('check')->willThrowException(new Exception($message));

        return $service;
    }

    // ---- the public surface ----------------------------------------------------------------------

    /**
     * @return array[] method => array(method, signature)
     */
    public function publicMethods()
    {
        $signatures = array(
            '__construct(Module $module)',
            'createReceipt($idOrder, $idEmployee)',
            'issueAnyway($idOrder, $idEmployee)',
            'linkCandidate($idOrder, $fakturaId, $idEmployee)',
            'updateStatus($idOrder, $amount = null)',
            'resend($idOrder)',
            'sendToKsef($idOrder)',
            'refreshStatus($idOrder)',
            'registerContractor($idOrder)',
            'setCustomerService(?IfirmaCustomerContractorService $service = null)',
            'customerCheck($idCustomer)',
            'customerSearch(array $criteria)',
            'customerLink($idCustomer, $identifier)',
            'customerUnlink($idCustomer)',
            'registerCustomer($idCustomer)',
            'customerCountRemote($idCustomer)',
            'approveRequest($idRequest, $idEmployee, ?array $edited = null, $note = \'\')',
            'rejectRequest($idRequest, $idEmployee, $reason)',
            'receiptToArray(IfirmaReceipt $receipt)',
        );
        $cases = array();
        foreach ($signatures as $signature) {
            $name = substr($signature, 0, strpos($signature, '('));
            $cases[$name] = array($name, $signature);
        }

        return $cases;
    }

    /**
     * @dataProvider publicMethods
     */
    public function testEveryMethodTheCallersUseIsPublicWithItsSignature($name, $signature)
    {
        $method = new ReflectionMethod('IfirmaAdminActions', $name);

        $this->assertTrue($method->isPublic(), $name);
        $this->assertFalse($method->isStatic(), $name);
        $params = array();
        foreach ($method->getParameters() as $p) {
            $text = '$' . $p->getName();
            if ($p->hasType()) {
                $text = ($p->getType()->allowsNull() ? '?' : '') . $p->getType()->getName() . ' ' . $text;
            }
            if ($p->isDefaultValueAvailable()) {
                $default = $p->getDefaultValue();
                $text .= ' = ' . ($default === null ? 'null' : var_export($default, true));
            }
            $params[] = $text;
        }
        $this->assertSame($signature, $name . '(' . implode(', ', $params) . ')');
        $this->assertFalse($method->hasReturnType(), $name);
    }

    // ---- translation -------------------------------------------------------------------------------

    /** A module whose translator answers "<domain>:<text>", so that a message shows where it was translated. */
    private function taggedModule()
    {
        $module = $this->getMockBuilder(get_class($this->module))->onlyMethods(array('l'))->getMock();
        $module->method('l')->willReturnCallback(function ($string, $specific = null) {
            return $specific . ':' . $string;
        });

        return $module;
    }

    public function testEveryAreaTranslatesThroughTheModuleItWasGivenWithTheDomainOfTheFacade()
    {
        $actions = new IfirmaAdminActions($this->taggedModule());

        $this->assertSame('ifirmaadminactions:Invalid order.', $actions->createReceipt(999, 3)['message']);
        $this->assertSame('ifirmaadminactions:Invalid customer.', $actions->customerCheck(0)['message']);
        $this->assertSame('ifirmaadminactions:The request was not found.', $actions->approveRequest(999, 3)['message']);
        $this->assertSame('ifirmaadminactions:Invalid order.', $actions->registerContractor(999)['message']);
    }

    public function testTheServicesTheActionsBuildGetTheModuleOfTheFacadeToo()
    {
        $actions = new IfirmaAdminActions($this->taggedModule());
        $this->queueFullSuccess();

        $this->assertSame('ifirmaissuancepipeline:Document issued: 7/2026', $actions->createReceipt(15, 3)['message']);
    }

    /**
     * The messages of the actions are translated under the domain they always had, whichever class of the family
     * now holds them. TranslationCoverageTest only needs the pair of domain and text to exist in the translation
     * file, which another domain can satisfy too.
     */
    public function testEveryTranslatedStringOfTheActionsKeepsTheTranslationDomainOfTheFacade()
    {
        $calls = 0;
        $domains = array();
        foreach (array('IfirmaAdminActions', 'IfirmaActionSupport', 'IfirmaReceiptActions', 'IfirmaCustomerActions', 'IfirmaDataRequestActions') as $class) {
            $source = file_get_contents(dirname(__DIR__, 2) . '/classes/' . $class . '.php');
            $calls += preg_match_all('/->l\(\s*\'(?:[^\'\\\\]|\\\\.)*\'(?:\s*,\s*\'([A-Za-z0-9_]+)\')?/', $source, $found);
            $domains = array_merge($domains, $found[1]);
        }

        $this->assertSame(48, $calls);
        $this->assertSame(array('ifirmaadminactions'), array_values(array_unique($domains)));
    }

    public function testTheFacadeOffersExactlyTheMethodsOfTheCallers()
    {
        $public = array();
        foreach ((new ReflectionClass('IfirmaAdminActions'))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $public[] = $method->getName();
        }
        $expected = array_keys($this->publicMethods());
        sort($public);
        sort($expected);

        $this->assertSame($expected, $public);
    }

    // ---- the document row --------------------------------------------------------------------------

    public function testReceiptToArrayAnswersEveryFieldOfTheDocumentWithItsType()
    {
        $receipt = $this->makeReceipt(array(
            'status' => 'paid',
            'source' => 'external',
            'paid_amount' => '99.50',
            'issue_date' => '2026-03-04',
            'remote_paid_amount' => '10.5',
            'remote_sent' => 1,
            'remote_draft' => 1,
            'remote_missing' => 1,
            'ksef_status' => 'WYSLANA',
            'ksef_number' => 'KS-9',
            'ksef_sent_at' => '2026-03-05 06:07:08',
            'ksef_error' => 'ksef boom',
            'linked_document' => '88',
            'last_sync_at' => '2026-03-06 07:08:09',
            'sync_error' => 'sync boom',
            'delivery_status' => 'sent',
            'delivery_channel' => 'shop_mail',
            'delivered_at' => '2026-03-07 08:09:10',
            'delivery_error' => 'mail boom',
        ));

        $this->assertSame(
            array(
                'id' => 1,
                'ifirma_id' => '77',
                'ifirma_number' => '7/2026',
                'status' => 'paid',
                'source' => 'external',
                'paid_amount' => 99.5,
                'issue_date' => '2026-03-04',
                'remote_paid_amount' => 10.5,
                'remote_sent' => true,
                'remote_draft' => true,
                'remote_missing' => true,
                'ksef_status' => 'WYSLANA',
                'ksef_number' => 'KS-9',
                'ksef_sent_at' => '2026-03-05 06:07:08',
                'ksef_error' => 'ksef boom',
                'linked_document' => '88',
                'last_sync_at' => '2026-03-06 07:08:09',
                'sync_error' => 'sync boom',
                'delivery_status' => 'sent',
                'delivery_channel' => 'shop_mail',
                'delivered_at' => '2026-03-07 08:09:10',
                'delivery_error' => 'mail boom',
            ),
            $this->actions->receiptToArray($receipt)
        );
    }

    public function testReceiptToArrayKeepsAnUnknownRemotePaymentNullAndTurnsTheRestIntoEmptyValues()
    {
        $receipt = $this->makeReceipt(array(
            'remote_paid_amount' => null,
            'remote_sent' => 0,
            'remote_draft' => 0,
            'remote_missing' => 0,
            'ksef_status' => null,
            'ksef_number' => null,
            'ksef_error' => null,
            'linked_document' => null,
            'sync_error' => null,
        ));

        $row = $this->actions->receiptToArray($receipt);

        $this->assertNull($row['remote_paid_amount']);
        $this->assertFalse($row['remote_sent']);
        $this->assertFalse($row['remote_draft']);
        $this->assertFalse($row['remote_missing']);
        foreach (array('ksef_status', 'ksef_number', 'ksef_error', 'linked_document', 'sync_error') as $key) {
            $this->assertSame('', $row[$key], $key);
        }
        $this->assertSame(array_keys($row), array(
            'id', 'ifirma_id', 'ifirma_number', 'status', 'source', 'paid_amount', 'issue_date', 'remote_paid_amount',
            'remote_sent', 'remote_draft', 'remote_missing', 'ksef_status', 'ksef_number', 'ksef_sent_at', 'ksef_error',
            'linked_document', 'last_sync_at', 'sync_error', 'delivery_status', 'delivery_channel', 'delivered_at',
            'delivery_error',
        ));
    }

    public function testReceiptToArrayAnswersTheIdAsAnInteger()
    {
        $receipt = $this->makeReceipt();
        $receipt->id = '1';

        $this->assertSame(1, $this->actions->receiptToArray($receipt)['id']);
    }

    public function testAZeroRemotePaymentIsNotAnUnknownOne()
    {
        $receipt = $this->makeReceipt(array('remote_paid_amount' => 0));

        $this->assertSame(0.0, $this->actions->receiptToArray($receipt)['remote_paid_amount']);
    }

    // ---- arguments that reach the code behind an action ----------------------------------------------

    public function testLinkCandidateRecordsTheEmployeeAndAnswersWithTheLinkedDocument()
    {
        $this->suspectedReceipt();

        $r = $this->actions->linkCandidate(15, '77', 3);

        $linked = new IfirmaReceipt(1);
        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Document 7/2026 linked to the order.',
                'data' => array('receipt' => $this->actions->receiptToArray($linked)),
            ),
            $r
        );
        $resolution = $linked->getDuplicateCandidates()['resolution'];
        $this->assertSame('link', $resolution['action']);
        $this->assertSame(3, $resolution['id_employee']);
        $this->assertSame('77', (string) $linked->ifirma_id);
    }

    public function testLinkCandidateNamesTheDocumentByItsIdWhenItHasNoNumber()
    {
        $candidate = IfirmaDuplicateMatcher::summarize($this->remoteRow(array('PelnyNumer' => '')));
        $this->makeReceipt(array(
            'status' => 'duplicate_suspected',
            'ifirma_id' => '',
            'ifirma_number' => '',
            'duplicate_candidates' => json_encode(array('candidates' => array($candidate), 'resolution' => null)),
        ));

        $r = $this->actions->linkCandidate(15, '77', 3);

        $this->assertTrue($r['success']);
        $this->assertSame('Document 77 linked to the order.', $r['message']);
    }

    public function testUpdateStatusRegistersTheGivenAmountAndTheAmountPaidWhenNoneIsGiven()
    {
        Order::$instances[15]->totalPaidReal = 77.25;
        $this->makeReceipt();
        $this->t->queue('POST', '#faktury/wplaty/#', array('body' => '{"response":{"Kod":0,"Informacja":"ok"}}'));

        $partial = $this->actions->updateStatus(15, 50.5);

        $this->assertTrue($partial['success'], $partial['message']);
        $this->assertSame('Payment registered in iFirma.', $partial['message']);
        $this->assertSame(array('receipt'), array_keys($partial['data']));
        $this->assertSame(50.5, $partial['data']['receipt']['paid_amount']);
        $this->assertSame(50.5, json_decode($this->posts()[0]['body'], true)['Kwota']);

        $this->t->queue('POST', '#faktury/wplaty/#', array('body' => '{"response":{"Kod":0,"Informacja":"ok"}}'));

        $full = $this->actions->updateStatus(15);

        $this->assertTrue($full['success'], $full['message']);
        $this->assertSame(77.25, $full['data']['receipt']['paid_amount']);
        $this->assertSame(77.25, json_decode($this->posts()[1]['body'], true)['Kwota']);
    }

    public function testRejectRequestStoresTheEmployeeAndTheReasonAndAnswersWithTheState()
    {
        $request = $this->makeRequest('pending');

        $r = $this->actions->rejectRequest((int) $request->id, 4, 'NIP does not match');

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Request rejected.',
                'data' => array('issuance_ran' => false, 'status' => 'rejected', 'issued' => false),
            ),
            $r
        );
        $stored = new IfirmaDataRequest((int) $request->id);
        $this->assertSame(4, (int) $stored->resolved_by);
        $this->assertSame('NIP does not match', $stored->admin_note);
        $this->assertSame('rejected', $stored->status);
    }

    public function testApproveRequestStoresTheEmployeeAndTheNoteAndAnswersWithTheState()
    {
        $request = $this->makeRequest('pending');

        $r = $this->actions->approveRequest((int) $request->id, 4, null, 'checked');

        $this->assertSame(
            array(
                'success' => true,
                'message' => 'Request approved.',
                'data' => array('issuance_ran' => false, 'status' => 'approved', 'issued' => false),
            ),
            $r
        );
        $stored = new IfirmaDataRequest((int) $request->id);
        $this->assertSame(4, (int) $stored->resolved_by);
        $this->assertSame('checked', $stored->admin_note);
        $this->assertSame('approved', $stored->status);
    }

    // ---- the customer service ------------------------------------------------------------------------

    public function testTheServiceSetThroughTheSeamAnswersEveryCustomerAction()
    {
        $service = $this->getMockBuilder('IfirmaCustomerContractorService')->disableOriginalConstructor()->getMock();
        foreach (array('check', 'searchCandidates', 'link', 'unlink', 'linkState', 'remoteCount') as $method) {
            $service->method($method)->willThrowException(new Exception($method . ' of the double'));
        }
        $this->actions->setCustomerService($service);

        $this->assertSame('check of the double', $this->actions->customerCheck(5)['message']);
        $this->assertSame('searchCandidates of the double', $this->actions->customerSearch(array('lastname' => 'Nowak'))['message']);
        $this->assertSame('link of the double', $this->actions->customerLink(5, 'KOW-01')['message']);
        $this->assertSame('unlink of the double', $this->actions->customerUnlink(5)['message']);
        $this->assertSame('remoteCount of the double', $this->actions->customerCountRemote(5)['message']);
        // the registration ends in the link state read of the same double
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        $this->assertSame('linkState of the double', $this->actions->registerCustomer(5)['message']);
    }

    public function testSettingNoServiceGoesBackToTheServiceOfTheModuleConfiguration()
    {
        IfirmaContractorMap::link(5, 'KOW-01', IfirmaContractorMap::BY_MANUAL, 'Jan Kowalski');
        foreach (array('null' => true, 'nothing' => false) as $how => $withNull) {
            $this->actions->setCustomerService($this->serviceRaising('double used'));
            $this->assertSame('double used', $this->actions->customerCheck(5)['message'], $how);

            if ($withNull) {
                $this->actions->setCustomerService(null);
            } else {
                $this->actions->setCustomerService();
            }
            $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));

            $r = $this->actions->customerCheck(5);

            $this->assertTrue($r['success'], $how . ': ' . $r['message']);
            $this->assertSame('Registered in iFirma as KOW-01', $r['message'], $how);
        }
    }

    public function testTheSeamBelongsToTheInstanceItWasSetOn()
    {
        $this->actions->setCustomerService($this->serviceRaising('double used'));
        $other = new IfirmaAdminActions($this->module);
        IfirmaContractorMap::link(5, 'KOW-01', IfirmaContractorMap::BY_MANUAL, 'Jan Kowalski');
        $this->t->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));

        $this->assertSame('Registered in iFirma as KOW-01', $other->customerCheck(5)['message']);
        $this->assertSame('double used', $this->actions->customerCheck(5)['message']);
    }

    public function testTheServiceOfTheConfigurationIsBuiltOnFirstUseAndKeptForTheInstance()
    {
        IfirmaContractorMap::link(5, 'KOW-01', IfirmaContractorMap::BY_MANUAL, 'Jan Kowalski');
        $first = new ScriptedTransport();
        $first->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));
        $first->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));
        IfirmaApiClient::setDefaultTransport($first);
        $actions = new IfirmaAdminActions($this->module);

        // nothing is built, and nothing is asked, before a customer action runs
        $this->assertCount(0, $first->calls);

        $actions->customerCheck(5);
        $this->assertCount(1, $first->calls);

        // a transport that becomes the default later is not seen by the service this instance already built
        $second = new ScriptedTransport();
        $second->queue('GET', '#kontrahenci/id/KOW-01\.json#', $this->found('KOW-01'));
        IfirmaApiClient::setDefaultTransport($second);
        $actions->customerCheck(5);
        $this->assertCount(2, $first->calls);
        $this->assertCount(0, $second->calls);

        // an instance of its own builds a service of its own
        (new IfirmaAdminActions($this->module))->customerCheck(5);
        $this->assertCount(2, $first->calls);
        $this->assertCount(1, $second->calls);
    }
}
