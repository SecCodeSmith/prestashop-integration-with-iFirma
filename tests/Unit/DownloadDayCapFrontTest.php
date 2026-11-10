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

require_once __DIR__ . '/DownloadBudgetFrontTest.php';

/**
 * Spec customer-receipt-visibility, "A customer's downloads are capped per day": one customer sends at most 30 PDF
 * downloads that reach iFirma per calendar day, all documents together, so one account cannot use up the budget all
 * customers share.
 */
class DownloadDayCapFrontTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaDownloadCountDb;

    const DAY = '2026-11-10';
    const A = 10;
    const B = 11;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptDownloadCountDb();
        IfirmaTestBudgetReceiptController::$now = strtotime(self::DAY . ' 10:15:05');
        $this->t->queue('GET', '#\.pdf#', function () {
            return array('body' => "%PDF-1.4\nfake body\n%%EOF");
        });
        $db = Db::getInstance();
        // every claim of the per-document throttle succeeds: the downloads below are spread over time
        $db->whenExecute('/UPDATE `\w*ifirma_receipt`\s+SET last_download_at/', 1);
        $db->whenGetValue('/WHERE security_token = "(\w+)"/', function ($sql, $m) {
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ($row['security_token'] === $m[1]) {
                    return $id;
                }
            }

            return false;
        });
    }

    protected function tearDown(): void
    {
        Tools::reset();
    }

    /** What the visitor sees: 'streamed', '404', '429', '429-limit' or '502'. */
    private function download($token, $customerId)
    {
        $context = Context::getContext();
        $context->customer = Customer::$instances[$customerId];
        $context->customer->logged = true;
        Tools::$values = array('token' => $token);
        $controller = new IfirmaTestBudgetReceiptController();
        try {
            $controller->initContent();
        } catch (IfirmaTestBudgetEnding $e) {
            $this->lastRetryAfter = $controller->limitRetryAfter;

            return $e->getMessage();
        }

        return 'no answer';
    }

    /** @var int|null */
    private $lastRetryAfter;

    /** One customer with documents; each starts with $countEach downloads today (0 = none yet). Returns the receipts. */
    private function customerWithDocuments($id, $documents, $countEach = 0, $day = self::DAY)
    {
        $customer = new Customer();
        $customer->id = $id;
        $customer->email = 'c' . $id . '@example.test';
        Customer::register($customer);
        $receipts = array();
        for ($i = 0; $i < $documents; $i++) {
            $props = array('id_order' => $id * 100 + $i, 'id_customer' => $id, 'security_token' => 'tok' . $id . 'n' . $i);
            if ($countEach > 0) {
                $props['download_day'] = $day;
                $props['download_count'] = $countEach;
            }
            $receipts[] = $this->makeReceipt($props);
        }

        return $receipts;
    }

    private function tokenOf(IfirmaReceipt $receipt)
    {
        return $receipt->security_token;
    }

    private function total($customerId)
    {
        $total = 0;
        foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
            if ((int) $row['id_customer'] === (int) $customerId) {
                $total += $this->storedDownloadCount($id);
            }
        }

        return $total;
    }

    public function testOneCustomerSendingTenDownloadsAMinuteForTwoHundredMinutesIsStoppedAtThirty()
    {
        $receipts = $this->customerWithDocuments(self::A, 5);
        $streamed = 0;
        for ($minute = 0; $minute < 200; $minute++) {
            IfirmaTestBudgetReceiptController::$now = strtotime(self::DAY . ' 00:00:05') + $minute * 60;
            for ($i = 0; $i < 10; $i++) {
                $streamed += $this->download($this->tokenOf($receipts[$i % 5]), self::A) === 'streamed' ? 1 : 0;
            }
        }

        $this->assertSame(30, $streamed);
        $this->assertCount(30, $this->t->calls, 'only 30 requests reach iFirma');
    }

    public function testAnotherCustomersDownloadStillWorksAfterTheFirstCustomerUsedUpTheirDay()
    {
        $receiptsA = $this->customerWithDocuments(self::A, 5, 6);
        $receiptsB = $this->customerWithDocuments(self::B, 1);

        $this->assertSame('429-limit', $this->download($this->tokenOf($receiptsA[0]), self::A));
        $this->assertSame('streamed', $this->download($this->tokenOf($receiptsB[0]), self::B));
    }

    public function testTheThirtyFirstDownloadOfTheDayIsRefusedWithTheGenericLimitAnswerAndSendsNothing()
    {
        $receipts = $this->customerWithDocuments(self::A, 5, 6);

        $answer = $this->download($this->tokenOf($receipts[2]), self::A);

        $this->assertSame('429-limit', $answer);
        $this->assertSame(60, $this->lastRetryAfter);
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(30, $this->total(self::A), 'the refused download is not counted');
    }

    public function testTheRefusedDownloadUsesNoUnitOfTheSharedBudgetAndNoSlotOfTheThrottle()
    {
        $receipts = $this->customerWithDocuments(self::A, 5, 6);
        Db::getInstance()->executed = array();

        $this->download($this->tokenOf($receipts[0]), self::A);

        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_CUSTOMER_MINUTE'));
        $this->assertFalse(Configuration::hasKey('IFIRMA_DL_CUSTOMER_DAY'));
        $claims = array_filter(Db::getInstance()->executed, function ($sql) {
            return strpos($sql, 'last_download_at') !== false;
        });
        $this->assertSame(array(), array_values($claims), 'the throttle was not claimed');
    }

    public function testTheThirtiethDownloadOfTheDayIsStillServedAndTheNextOneIsNot()
    {
        $receipts = $this->customerWithDocuments(self::A, 5, 5);
        $receipts[0] = IfirmaReceipt::loadFresh((int) $receipts[0]->id);
        ObjectModel::$registry['ifirma_receipt'][(int) $receipts[0]->id]['download_count'] = 9;

        $this->assertSame('streamed', $this->download($this->tokenOf($receipts[0]), self::A), 'the 30th');
        $this->assertSame('429-limit', $this->download($this->tokenOf($receipts[0]), self::A), 'the 31st');
    }

    public function testTheCountAddsUpAllDocumentsOfTheCustomer()
    {
        $receipts = $this->customerWithDocuments(self::A, 5);
        for ($i = 0; $i < 30; $i++) {
            IfirmaTestBudgetReceiptController::$now += 60;
            $this->assertSame('streamed', $this->download($this->tokenOf($receipts[$i % 5]), self::A), 'download ' . ($i + 1));
        }

        $this->assertSame('429-limit', $this->download($this->tokenOf($receipts[0]), self::A));
        $this->assertSame(30, $this->total(self::A));
    }

    public function testANewDayStartsANewCount()
    {
        $receipts = $this->customerWithDocuments(self::A, 5, 6, '2026-11-09');

        $this->assertSame('streamed', $this->download($this->tokenOf($receipts[0]), self::A));
        $this->assertSame(1, $this->storedDownloadCount((int) $receipts[0]->id));
    }

    public function testAnotherCustomersDocumentIsNotFoundEvenWhenTheOwnerUsedUpTheDay()
    {
        $receiptsA = $this->customerWithDocuments(self::A, 5, 6);
        $this->customerWithDocuments(self::B, 1);

        $this->assertSame('404', $this->download($this->tokenOf($receiptsA[0]), self::B));
    }

    public function testAWrongTokenIsNotFoundEvenWhenTheCustomerUsedUpTheDay()
    {
        $this->customerWithDocuments(self::A, 5, 6);

        $this->assertSame('404', $this->download('nosuchtoken', self::A));
    }

    public function testADocumentAwaitingKsefIsNotFoundEvenWhenTheCustomerUsedUpTheDay()
    {
        $this->customerWithDocuments(self::A, 5, 6);
        $this->makeReceipt(array(
            'id_customer' => self::A, 'security_token' => 'waiting1', 'id_order' => 999,
            'delivery_status' => 'awaiting_ksef', 'ksef_status' => 'WYSLANA', 'ksef_number' => '',
        ));

        $this->assertSame('404', $this->download('waiting1', self::A));
    }

    public function testADownloadRefusedByTheThrottleIsNotCounted()
    {
        $receipts = $this->customerWithDocuments(self::A, 1);
        Db::getInstance()->whenExecute('/UPDATE `\w*ifirma_receipt`\s+SET last_download_at/', 0);

        $this->assertSame('429', $this->download($this->tokenOf($receipts[0]), self::A));
        $this->assertSame(0, $this->total(self::A));
    }

    public function testADownloadRefusedByTheSharedBudgetIsNotCounted()
    {
        $receipts = $this->customerWithDocuments(self::A, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', date('YmdHi', IfirmaTestBudgetReceiptController::$now) . ':20');

        $this->assertSame('429-limit', $this->download($this->tokenOf($receipts[0]), self::A));
        $this->assertSame(0, $this->total(self::A));
    }

    public function testADownloadTheModulesRequestLimitRefusesIsNotCounted()
    {
        $receipts = $this->customerWithDocuments(self::A, 1);
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) (time() + 5000));

        $this->assertSame('429-limit', $this->download($this->tokenOf($receipts[0]), self::A));
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame(0, $this->total(self::A));
    }

    public function testADownloadThatReachedIfirmaIsCounted()
    {
        $receipts = $this->customerWithDocuments(self::A, 1);

        $this->assertSame('streamed', $this->download($this->tokenOf($receipts[0]), self::A));
        $this->assertSame(1, $this->total(self::A));
        $this->assertSame(self::DAY, IfirmaTestState::objects('ifirma_receipt')[(int) $receipts[0]->id]['download_day']);
    }

    public function testADownloadThatReachedIfirmaAndFailedThereIsCountedToo()
    {
        $receipts = $this->customerWithDocuments(self::A, 1);
        $failing = new ScriptedTransport();
        $failing->queue('GET', '#\.pdf#', array('status' => 404, 'body' => '{"response":{"Kod":404,"Informacja":"x"}}'));
        IfirmaApiClient::setDefaultTransport($failing);

        $this->assertSame('502', $this->download($this->tokenOf($receipts[0]), self::A));
        $this->assertSame(1, $this->total(self::A));
    }
}
