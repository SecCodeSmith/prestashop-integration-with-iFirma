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

/** Thrown by the bulk test controller instead of ending the request. */
class IfirmaTestBulkJson extends Exception
{
    /** @var array */
    public $payload;

    public function __construct(array $payload)
    {
        parent::__construct('json response');
        $this->payload = $payload;
    }
}

/** Stand-in pipeline: creates the receipt row of the order it is asked to issue. */
class IfirmaTestBulkPipeline
{
    /** @var callable */
    public $onRun;

    public function __construct($onRun)
    {
        $this->onRun = $onRun;
    }

    public function run($order, array $options = array())
    {
        return call_user_func($this->onRun, $order);
    }

    public function describe($r)
    {
        return 'described:' . $r->outcome;
    }
}

/** Runner that records what the controller hands it and answers a fixed batch. */
class IfirmaTestBulkRecordingRunner extends IfirmaBulkRunner
{
    /** @var array[] kind, ids, options of every run() */
    public $calls = array();

    public function run($kind, array $ids, $idEmployee, array $options = array())
    {
        $this->calls[] = array('kind' => $kind, 'ids' => $ids, 'employee' => $idEmployee, 'options' => $options);

        return array('kind' => $kind, 'results' => array(), 'remaining' => array(), 'stopped' => '', 'processed' => 0);
    }
}

/** Stand-in for IfirmaAdminActions: records its calls and answers a scripted result. */
class IfirmaTestBulkActions
{
    /** @var array result of createReceipt() */
    public $create = array('success' => true, 'message' => 'Document issued.', 'data' => array('outcome' => 'issued'));

    /** @var array[] method and arguments of every call */
    public $calls = array();

    public function createReceipt($idOrder, $idEmployee)
    {
        $this->calls[] = array('createReceipt', $idOrder, $idEmployee);

        return $this->create;
    }

    public function updateStatus($idOrder, $amount = null)
    {
        $this->calls[] = array('updateStatus', $idOrder, $amount);

        return array('success' => true, 'message' => 'ok', 'data' => array());
    }

    public function linkCandidate($idOrder, $fakturaId, $idEmployee)
    {
        $this->calls[] = array('linkCandidate', $idOrder, $fakturaId, $idEmployee);

        return array('success' => true, 'message' => 'ok', 'data' => array());
    }
}

/** AdminIfirmaController with its runner and actions injected and its answer thrown. */
class IfirmaTestBulkController extends AdminIfirmaController
{
    /** @var IfirmaBulkRunner|null */
    public $runner;

    /** @var object|null */
    public $fakeActions;

    protected function output(array $payload)
    {
        throw new IfirmaTestBulkJson($payload);
    }

    protected function statusHeader($code)
    {
    }

    protected function bulkRunner()
    {
        return $this->runner ? $this->runner : parent::bulkRunner();
    }

    protected function actions()
    {
        return $this->fakeActions ? $this->fakeActions : parent::actions();
    }
}

/**
 * Spec: bulk issue / retry / resend / refresh (design D4), issue from the orders list (D5) and the raw reads of
 * the status update (module-endpoint-security: Paid amount validation, Array input).
 */
class AdminControllerBulkTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Configuration::updateValue('IFIRMA_CONTRACTOR_SYNC', 0);
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id = 1;
        Context::getContext()->employee->id_profile = 1;
        Access::reset();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    // ---- helpers ----------------------------------------------------------------------------

    private function registerOrder($id)
    {
        $o = $this->wiringOrder();
        $o->id = $id;
        $o->reference = 'REF' . $id;
        Order::register($o);
    }

    /** A runner whose pipeline issues every order it is given (a receipt row is stored). */
    private function issuingRunner()
    {
        $self = $this;
        $pipeline = new IfirmaTestBulkPipeline(function ($order) use ($self) {
            $self->storeReceipt(array('id_order' => (int) $order->id));
            $r = new IfirmaPipelineResult();
            $r->outcome = IfirmaPipelineResult::OUTCOME_ISSUED;
            $r->ok = true;

            return $r;
        });
        $runner = new IfirmaBulkRunner($this->module);
        $runner->setPipeline($pipeline);

        return $runner;
    }

    /** Public so the stand-in pipeline can store the row. */
    public function storeReceipt(array $props)
    {
        return $this->makeReceipt($props);
    }

    private function call($action, array $request, $configure = null)
    {
        Tools::$values = array_merge(array('token' => 'admin-token-AdminIfirma'), $request);
        $controller = new IfirmaTestBulkController();
        if ($configure) {
            call_user_func($configure, $controller);
        }
        try {
            $controller->{'ajaxProcess' . $action}();
        } catch (IfirmaTestBulkJson $e) {
            return $e->payload;
        }
        $this->fail('The action ' . $action . ' produced no response');
    }

    // ---- BulkRun ----------------------------------------------------------------------------

    public function testABatchOfOrdersAnswersOneResultPerIdWithItsCell()
    {
        foreach (array(15, 16, 17) as $id) {
            $this->registerOrder($id);
        }
        $runner = $this->issuingRunner();

        $payload = $this->call('BulkRun', array('kind' => 'issue', 'ids' => array(15, 16, 17)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertTrue($payload['success']);
        $data = $payload['data'];
        $this->assertSame('issue', $data['kind']);
        $this->assertSame(array(15, 16, 17), array_column($data['results'], 'id'));
        $this->assertSame(array('issued', 'issued', 'issued'), array_column($data['results'], 'outcome'));
        foreach ($data['results'] as $result) {
            $this->assertArrayHasKey('cell', $result);
            $this->assertStringContainsString('receipt_long', $result['cell']);
        }
        $this->assertSame(array(), $data['remaining']);
        $this->assertSame('', $data['stopped']);
        $this->assertSame('Processed 3 of 3.', $payload['message']);
    }

    public function testIdsBeyondTheBatchSizeComeBackAsRemaining()
    {
        foreach (array(15, 16, 17, 18, 19) as $id) {
            $this->registerOrder($id);
        }
        $runner = $this->issuingRunner();

        $payload = $this->call('BulkRun', array('kind' => 'issue', 'ids' => array(15, 16, 17, 18, 19)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertCount(3, $payload['data']['results']);
        $this->assertSame(array(18, 19), $payload['data']['remaining']);
        $this->assertSame('Processed 3 of 5.', $payload['message']);
    }

    public function testTheControllerHandsTheRunnerOnlyOneBatchOfCleanIds()
    {
        $runner = new IfirmaTestBulkRecordingRunner($this->module);

        $this->call('BulkRun', array('kind' => 'retry', 'ids' => array('4', 4, 'x', 0, array(9), 5, 6, 7, 8)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertCount(1, $runner->calls);
        $this->assertSame(array(4, 5, 6), $runner->calls[0]['ids']);
        $this->assertSame('retry', $runner->calls[0]['kind']);
        $this->assertSame(1, $runner->calls[0]['employee']);
    }

    public function testARetryBatchAnswersTheResultsOfTheRunner()
    {
        $this->registerOrder(15);
        $failed = $this->makeReceipt(array('ifirma_id' => '', 'ifirma_number' => '', 'status' => IfirmaReceipt::STATUS_ERROR));
        $runner = $this->issuingRunner();

        $payload = $this->call('BulkRun', array('kind' => 'retry', 'ids' => array((int) $failed->id)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertTrue($payload['success']);
        $this->assertSame(array((int) $failed->id), array_column($payload['data']['results'], 'id'));
        $this->assertSame('issued', $payload['data']['results'][0]['outcome']);
        $this->assertArrayNotHasKey('cell', $payload['data']['results'][0], 'only the issue kind carries a cell');
    }

    public function testAScalarIdsValueIsRefusedAndNothingRuns()
    {
        $runner = new IfirmaTestBulkRecordingRunner($this->module);

        $payload = $this->call('BulkRun', array('kind' => 'issue', 'ids' => '5'), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertFalse($payload['success']);
        $this->assertSame('The ids must be a list.', $payload['message']);
        $this->assertSame(array(), $runner->calls);
    }

    public function testAnUnknownKindIsRefusedAndNothingRuns()
    {
        $runner = new IfirmaTestBulkRecordingRunner($this->module);

        $payload = $this->call('BulkRun', array('kind' => 'delete', 'ids' => array(1)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertFalse($payload['success']);
        $this->assertSame('Unknown bulk action.', $payload['message']);
        $this->assertSame(array(), $runner->calls);
    }

    public function testAnArrayKindIsRefusedWithoutANotice()
    {
        $runner = new IfirmaTestBulkRecordingRunner($this->module);

        $payload = $this->call('BulkRun', array('kind' => array('issue'), 'ids' => array(1)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertFalse($payload['success']);
        $this->assertSame(array(), $runner->calls);
    }

    public function testTheFinalFlagAndThePreviousTotalsReachTheRunner()
    {
        $runner = new IfirmaTestBulkRecordingRunner($this->module);

        $this->call('BulkRun', array('kind' => 'issue', 'ids' => array(15), 'final' => '1', 'totals' => array('issued' => '4', 'failed' => 1)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $options = $runner->calls[0]['options'];
        $this->assertTrue($options['final']);
        $this->assertSame(array('issued' => 4, 'failed' => 1), $options['previous_totals']);
    }

    public function testTheTotalsReachTheRunnerOnANonFinalBatchToo()
    {
        $runner = new IfirmaTestBulkRecordingRunner($this->module);

        $this->call('BulkRun', array('kind' => 'issue', 'ids' => array(15), 'final' => '0', 'totals' => array('issued' => 2)), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $options = $runner->calls[0]['options'];
        $this->assertFalse($options['final']);
        $this->assertSame(array('issued' => 2), $options['previous_totals']);
    }

    public function testAnArrayTotalsEntryIsDropped()
    {
        $runner = new IfirmaTestBulkRecordingRunner($this->module);

        $this->call('BulkRun', array('kind' => 'issue', 'ids' => array(15), 'totals' => array('issued' => array(1), 'sent' => '3')), function ($c) use ($runner) {
            $c->runner = $runner;
        });

        $this->assertSame(array('sent' => 3), $runner->calls[0]['options']['previous_totals']);
    }

    // ---- IssueFromList ----------------------------------------------------------------------

    public function testASuccessfulIssueFromTheListAnswersTheIconCell()
    {
        $this->makeReceipt(array('ifirma_number' => 'FP 7/2026'));
        $actions = new IfirmaTestBulkActions();

        $payload = $this->call('IssueFromList', array('id_order' => 15), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });

        $this->assertTrue($payload['success']);
        $this->assertSame('Document issued.', $payload['message']);
        $this->assertSame('issued', $payload['data']['outcome']);
        $this->assertStringContainsString('receipt_long', $payload['data']['cell']);
        $this->assertStringContainsString('FP 7/2026', $payload['data']['cell']);
        $this->assertSame('FP 7/2026', $payload['data']['receipt']['ifirma_number']);
        $this->assertSame(array(array('createReceipt', 15, 1)), $actions->calls);
    }

    public function testAnAdoptedDocumentIsReportedAsAdopted()
    {
        $this->makeReceipt();
        $actions = new IfirmaTestBulkActions();
        $actions->create = array('success' => true, 'message' => 'Adopted.', 'data' => array('outcome' => 'adopted'));

        $payload = $this->call('IssueFromList', array('id_order' => 15), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });

        $this->assertSame('adopted', $payload['data']['outcome']);
    }

    public function testAGuardStopAnswersTheAnchorCellNamingTheSuspectedState()
    {
        $this->makeReceipt(array('ifirma_id' => '', 'ifirma_number' => '', 'status' => IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED));
        $actions = new IfirmaTestBulkActions();
        $actions->create = array('success' => false, 'message' => 'Possible duplicate.', 'data' => array('outcome' => 'suspected'));

        $payload = $this->call('IssueFromList', array('id_order' => 15), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });

        $this->assertFalse($payload['success']);
        $this->assertSame('Possible duplicate.', $payload['message']);
        $this->assertSame('suspected', $payload['data']['outcome']);
        $this->assertStringContainsString('js-ifirma-issue', $payload['data']['cell']);
        $this->assertStringContainsString('data-id-order="15"', $payload['data']['cell']);
        $word = 'duplicate_suspected';
        if (method_exists($this->module, 'gridCellLabels')) {
            $labels = $this->module->gridCellLabels();
            $word = isset($labels['state_duplicate_suspected']) ? $labels['state_duplicate_suspected'] : $word;
        }
        $this->assertStringContainsString(htmlspecialchars($word, ENT_QUOTES, 'UTF-8'), $payload['data']['cell']);
    }

    public function testAFailedIssueIsReportedAsFailed()
    {
        $actions = new IfirmaTestBulkActions();
        $actions->create = array('success' => false, 'message' => 'Rejected.', 'data' => array('outcome' => 'error'));

        $payload = $this->call('IssueFromList', array('id_order' => 15), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });

        $this->assertSame('failed', $payload['data']['outcome']);
        $this->assertStringContainsString('js-ifirma-issue', $payload['data']['cell'], 'no receipt: the Issue anchor');
        $this->assertSame(array(), $payload['data']['receipt']);
    }

    // ---- UpdateStatus: raw reads ------------------------------------------------------------------

    public function testAnAmountWrittenWithACommaReachesTheServiceUnchanged()
    {
        $actions = new IfirmaTestBulkActions();

        $this->call('UpdateStatus', array('id_order' => 15, 'amount' => '12,50'), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });

        $this->assertSame(array(array('updateStatus', 15, '12,50')), $actions->calls);
    }

    public function testAnEmptyAmountReachesTheServiceAsNull()
    {
        $actions = new IfirmaTestBulkActions();

        $this->call('UpdateStatus', array('id_order' => 15, 'amount' => ''), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });

        $this->assertSame(array(array('updateStatus', 15, null)), $actions->calls);
    }

    public function testAnArrayAmountReachesTheServiceAndIsRefusedThere()
    {
        $actions = new IfirmaTestBulkActions();
        $this->call('UpdateStatus', array('id_order' => 15, 'amount' => array('1')), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });
        $this->assertSame(array(array('updateStatus', 15, array('1'))), $actions->calls);

        $this->makeReceipt();
        IfirmaTestState::clearWrites();
        $payload = $this->call('UpdateStatus', array('id_order' => 15, 'amount' => array('1')));

        $this->assertFalse($payload['success']);
        $this->assertSame('The amount must be a positive number with at most two decimals.', $payload['message']);
        $this->assertSame(array(), ObjectModel::$writeLog);
    }

    public function testAnArrayFakturaIdBecomesAnEmptyString()
    {
        $actions = new IfirmaTestBulkActions();

        $this->call('LinkCandidate', array('id_order' => 15, 'faktura_id' => array('77')), function ($c) use ($actions) {
            $c->fakeActions = $actions;
        });

        $this->assertSame(array(array('linkCandidate', 15, '', 1)), $actions->calls);
    }
}
