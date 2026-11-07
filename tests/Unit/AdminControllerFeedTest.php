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

/** Thrown by the feed test controller instead of ending the request. */
class IfirmaTestFeedJson extends Exception
{
    /** @var array */
    public $payload;

    public function __construct(array $payload)
    {
        parent::__construct('json response');
        $this->payload = $payload;
    }
}

/** A feed that answers fixed items and counts instead of querying the database. */
class IfirmaTestFeedStub extends IfirmaIssueFeed
{
    /** @var array[] */
    public $items = array();

    /** @var int */
    public $unseen = 0;

    /** @var string[] the "since" values asked for */
    public $since = array();

    public function latest($limit = self::DEFAULT_LIMIT)
    {
        return $this->items;
    }

    public function unseenCount($since)
    {
        $this->since[] = $since;

        return $this->unseen;
    }
}

class IfirmaTestFeedController extends AdminIfirmaController
{
    /** @var IfirmaIssueFeed|null */
    public $feed;

    /** @var int[] */
    public $statuses = array();

    protected function output(array $payload)
    {
        throw new IfirmaTestFeedJson($payload);
    }

    protected function statusHeader($code)
    {
        $this->statuses[] = $code;
    }

    protected function issueFeed()
    {
        return $this->feed ? $this->feed : parent::issueFeed();
    }
}

/**
 * Spec: receipt-issue-notifications (Entries, Polling and feed access).
 */
class AdminControllerFeedTest extends TestCase
{
    use IfirmaWiringFixture;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Context::getContext()->employee = new Employee(1);
        Context::getContext()->employee->id = 7;
        Context::getContext()->employee->id_profile = 1;
        Access::reset();
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
    }

    private function call($action, $feed = null)
    {
        Tools::$values = array('token' => 'admin-token-AdminIfirma');
        $controller = new IfirmaTestFeedController();
        $controller->feed = $feed;
        try {
            $controller->{'ajaxProcess' . $action}();
        } catch (IfirmaTestFeedJson $e) {
            return array($e->payload, $controller);
        }
        $this->fail('The action ' . $action . ' produced no response');
    }

    public function testTheItemsCarryTheSixKeysTheFormattedTimeAndTheOrderUrl()
    {
        $feed = new IfirmaTestFeedStub();
        $feed->unseen = 3;
        $feed->items = array(array(
            'id_ifirma_receipt' => 9,
            'id_order' => 42,
            'reference' => 'ABCDEFGHI',
            'kind' => IfirmaIssueFeed::KIND_DELIVERY_FAILED,
            'number' => 'FP 7/2026',
            'time' => '2026-11-07 13:40:59',
            'last_error' => 'x',
        ));

        list($payload) = $this->call('IssueFeed', $feed);

        $this->assertTrue($payload['success']);
        $data = $payload['data'];
        $this->assertSame(3, $data['unseen']);
        $this->assertCount(1, $data['items']);
        $item = $data['items'][0];
        $this->assertSame(array('id_order', 'kind', 'kind_label', 'number', 'reference', 'time', 'url'), $this->sorted(array_keys($item)));
        $this->assertSame(42, $item['id_order']);
        $this->assertSame('ABCDEFGHI', $item['reference']);
        $this->assertSame('delivery_failed', $item['kind']);
        $this->assertSame('FP 7/2026', $item['number']);
        $this->assertSame('2026-11-07 13:40', $item['time']);
        $this->assertStringStartsWith('https://shop.test/admin/index.php?controller=AdminOrders', $item['url']);
        $this->assertStringStartsWith('https://shop.test/admin/index.php?controller=AdminIfirma', $data['list_url']);
    }

    public function testTheKindLabelIsTheCodeWhenTheModuleHasNoLabels()
    {
        $feed = new IfirmaTestFeedStub();
        $feed->items = array(array(
            'id_ifirma_receipt' => 1, 'id_order' => 1, 'reference' => 'R', 'kind' => 'held', 'number' => '', 'time' => '', 'last_error' => '',
        ));

        list($payload) = $this->call('IssueFeed', $feed);

        $label = $payload['data']['items'][0]['kind_label'];
        if (method_exists($this->module, 'issueKindLabels')) {
            $labels = $this->module->issueKindLabels();
            $this->assertSame(isset($labels['held']) ? $labels['held'] : 'held', $label);
        } else {
            $this->assertSame('held', $label);
        }
    }

    public function testTheUnseenCountIsAskedSinceTheSeenMarkOfTheEmployee()
    {
        $feed = new IfirmaTestFeedStub();
        Configuration::updateValue(IfirmaIssueFeed::SEEN_KEY_PREFIX . '7', '2026-11-07 10:00:00');

        $this->call('IssueFeed', $feed);

        $this->assertSame(array('2026-11-07 10:00:00'), $feed->since);
    }

    public function testMarkingTheFeedSeenStoresAMarkForTheEmployeeAndAnswersZero()
    {
        $this->assertSame('', Configuration::get(IfirmaIssueFeed::SEEN_KEY_PREFIX . '7') ? 'set' : '');

        list($payload) = $this->call('IssueFeedSeen');

        $this->assertTrue($payload['success']);
        $this->assertSame(array('unseen' => 0), $payload['data']);
        $mark = Configuration::get(IfirmaIssueFeed::SEEN_KEY_PREFIX . '7');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $mark);
    }

    public function testAnEmployeeWithoutViewGets403ForBothActions()
    {
        Access::reset();
        Context::getContext()->employee->id_profile = 4;
        Access::grant(IfirmaPermission::ROLE_CREATE, 4);

        foreach (array('IssueFeed', 'IssueFeedSeen') as $action) {
            list($payload, $controller) = $this->call($action, new IfirmaTestFeedStub());
            $this->assertSame(array(403), $controller->statuses, $action);
            $this->assertFalse($payload['success'], $action);
            $this->assertSame('You do not have permission to see iFirma documents.', $payload['message'], $action);
        }
        $this->assertSame('', (string) Configuration::get(IfirmaIssueFeed::SEEN_KEY_PREFIX . '7'), 'no mark stored');
    }

    private function sorted(array $values)
    {
        sort($values);

        return $values;
    }
}
