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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/front/receipt.php';

/** Thrown by the budget twin instead of ending the request. */
class IfirmaTestBudgetEnding extends Exception
{
}

/** The front receipt controller whose endings throw, whose download budget runs on a fixed clock. */
class IfirmaTestBudgetReceiptController extends IfirmaReceiptModuleFrontController
{
    /** @var int */
    public static $now = 0;

    /** @var int|null */
    public $limitRetryAfter;

    protected function customerBudget()
    {
        return IfirmaDownloadBudget::forCustomers(function () {
            return IfirmaTestBudgetReceiptController::$now;
        });
    }

    protected function downloadDayCap()
    {
        return new IfirmaDownloadDayCap(function () {
            return IfirmaTestBudgetReceiptController::$now;
        });
    }

    protected function streamPdf($pdf, IfirmaReceipt $receipt)
    {
        throw new IfirmaTestBudgetEnding('streamed');
    }

    protected function notFound()
    {
        throw new IfirmaTestBudgetEnding('404');
    }

    protected function tooManyRequests()
    {
        throw new IfirmaTestBudgetEnding('429');
    }

    protected function serverError()
    {
        throw new IfirmaTestBudgetEnding('502');
    }

    protected function refusedByRateLimit($retryAfterSeconds, $message)
    {
        $this->limitRetryAfter = $retryAfterSeconds;
        throw new IfirmaTestBudgetEnding('429-limit');
    }
}

/**
 * Spec customer-receipt-visibility, "Customer downloads have their own request budget": customer PDF downloads
 * are counted against a budget of their own, so many accounts together cannot use up the module's shared
 * request limit that issuance, the status sync and the cron need.
 */
class DownloadBudgetFrontTest extends TestCase
{
    use IfirmaWiringFixture;

    const FIRST_CUSTOMER = 10;
    const CUSTOMERS = 8;
    const DOCUMENTS_EACH = 5;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        IfirmaTestBudgetReceiptController::$now = strtotime('2026-11-10 10:15:05');
        $this->t->queue('GET', '#\.pdf#', function () {
            return array('body' => "%PDF-1.4\nfake body\n%%EOF");
        });
        $this->t->queue('GET', '#faktury\.json#', function () {
            return $this->listBody(array($this->remoteRow()));
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

    /** @return string */
    private function minute()
    {
        return date('YmdHi', IfirmaTestBudgetReceiptController::$now);
    }

    /** What the visitor sees: 'streamed', '404', '429', '429-limit' or '502'. */
    private function download($token, $customerId)
    {
        $context = Context::getContext();
        $context->customer = Customer::$instances[$customerId];
        $context->customer->logged = true;
        Tools::$values = array('token' => $token);
        try {
            (new IfirmaTestBudgetReceiptController())->initContent();
        } catch (IfirmaTestBudgetEnding $e) {
            return $e->getMessage();
        }

        return 'no answer';
    }

    /** One customer with $documents issued documents; returns the tokens. */
    private function customerWithDocuments($id, $documents)
    {
        $customer = new Customer();
        $customer->id = $id;
        $customer->email = 'c' . $id . '@example.test';
        Customer::register($customer);
        $tokens = array();
        for ($i = 0; $i < $documents; $i++) {
            $token = 'tok' . $id . 'n' . $i;
            $this->makeReceipt(array('id_order' => $id * 100 + $i, 'id_customer' => $id, 'security_token' => $token));
            $tokens[] = $token;
        }

        return $tokens;
    }

    /** @return string[] */
    private function downloadsOfManyAccounts()
    {
        $answers = array();
        for ($c = 0; $c < self::CUSTOMERS; $c++) {
            $id = self::FIRST_CUSTOMER + $c;
            $tokens = $this->customerWithDocuments($id, self::DOCUMENTS_EACH);
            foreach (array(1, 2) as $round) {
                foreach ($tokens as $token) {
                    $answers[] = $this->download($token, $id);
                }
            }
        }

        return $answers;
    }

    public function testManyAccountsTogetherCannotSendMoreThanTwentyDownloadsToIfirmaInOneMinute()
    {
        $answers = $this->downloadsOfManyAccounts();

        $this->assertSame(20, count(array_filter($answers, function ($a) {
            return $a === 'streamed';
        })));
        $this->assertCount(20, $this->t->calls, 'only the budget reaches iFirma');
        $this->assertSame(60, count(array_filter($answers, function ($a) {
            return $a === '429-limit';
        })));
    }

    public function testAnIssuanceInTheSameMinuteIsNotRefusedByTheModulesLimit()
    {
        $this->downloadsOfManyAccounts();

        $decision = (new IfirmaRateGuard())->admit();

        $this->assertTrue($decision->isAllowed(), 'the downloads left room in the shared limit: ' . $decision->getMessage());
    }

    public function testTheMinuteBudgetRefusesTheTwentyFirstDownloadWithTheGenericLimitAnswer()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 21);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame('streamed', $this->download($tokens[$i], self::FIRST_CUSTOMER));
        }
        $before = count($this->t->calls);

        $this->assertSame('429-limit', $this->download($tokens[20], self::FIRST_CUSTOMER));
        $this->assertCount($before, $this->t->calls, 'the refused download sends nothing');
        $this->assertSame($this->minute() . ':20', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testTheRefusedDownloadIsToldTheSameWaitAsEveryOtherLimit()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':20');
        $controller = new IfirmaTestBudgetReceiptController();
        Context::getContext()->customer = Customer::$instances[self::FIRST_CUSTOMER];
        Context::getContext()->customer->logged = true;
        Tools::$values = array('token' => $tokens[0]);

        try {
            $controller->initContent();
            $this->fail('expected the limit answer');
        } catch (IfirmaTestBudgetEnding $e) {
            $this->assertSame('429-limit', $e->getMessage());
        }

        $this->assertSame(60, $controller->limitRetryAfter);
    }

    public function testTheDayBudgetIsUsedUpWhileTheModulesOwnDayCountStaysUnchanged()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_DAY', '20261110:2000');

        $this->assertSame('429-limit', $this->download($tokens[0], self::FIRST_CUSTOMER));
        $this->assertSame(array(), $this->t->calls);
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_DAY_WINDOW'), 'the module day counter was not touched');
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_WINDOW'));
    }

    public function testADayRefusalGivesTheMinuteUnitBack()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_DAY', '20261110:2000');
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':7');

        $this->download($tokens[0], self::FIRST_CUSTOMER);

        $this->assertSame($this->minute() . ':7', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testTheNextMinuteStartsANewBudget()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':20');
        $this->assertSame('429-limit', $this->download($tokens[0], self::FIRST_CUSTOMER));

        IfirmaTestBudgetReceiptController::$now += 60;

        $this->assertSame('streamed', $this->download($tokens[0], self::FIRST_CUSTOMER));
    }

    public function testAnotherCustomersDocumentIsNotFoundEvenWhenTheBudgetIsUsedUp()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        $this->customerWithDocuments(self::FIRST_CUSTOMER + 1, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':20');

        $this->assertSame('404', $this->download($tokens[0], self::FIRST_CUSTOMER + 1));
    }

    public function testAWrongTokenIsNotFoundEvenWhenTheBudgetIsUsedUp()
    {
        $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':20');

        $this->assertSame('404', $this->download('nosuchtoken', self::FIRST_CUSTOMER));
    }

    public function testADocumentAwaitingKsefIsNotFoundEvenWhenTheBudgetIsUsedUp()
    {
        $this->customerWithDocuments(self::FIRST_CUSTOMER, 0);
        $this->makeReceipt(array(
            'id_customer' => self::FIRST_CUSTOMER, 'security_token' => 'waiting1',
            'delivery_status' => 'awaiting_ksef', 'ksef_status' => 'WYSLANA', 'ksef_number' => '',
        ));
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':20');

        $this->assertSame('404', $this->download('waiting1', self::FIRST_CUSTOMER));
    }

    public function testADownloadRefusedByTheThrottleUsesNoBudget()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Db::getInstance()->whenExecute('/UPDATE `\w*ifirma_receipt`\s+SET last_download_at/', 0);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':3');

        $this->assertSame('429', $this->download($tokens[0], self::FIRST_CUSTOMER));
        $this->assertSame($this->minute() . ':3', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testADownloadRefusedByTheBudgetItselfUsesNoMoreBudget()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':20');

        $this->download($tokens[0], self::FIRST_CUSTOMER);
        $this->download($tokens[0], self::FIRST_CUSTOMER);

        $this->assertSame($this->minute() . ':20', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
    }

    public function testADownloadThatTheSharedGuardRefusesGivesItsUnitBack()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':3');
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) (time() + 5000));

        $this->assertSame('429-limit', $this->download($tokens[0], self::FIRST_CUSTOMER));
        $this->assertSame(array(), $this->t->calls);
        $this->assertSame($this->minute() . ':3', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'), 'nothing reached iFirma, so nothing was spent');
    }

    public function testADownloadThatReachesIfirmaSpendsOneUnit()
    {
        $tokens = $this->customerWithDocuments(self::FIRST_CUSTOMER, 1);
        Configuration::updateValue('IFIRMA_DL_CUSTOMER_MINUTE', $this->minute() . ':3');

        $this->assertSame('streamed', $this->download($tokens[0], self::FIRST_CUSTOMER));
        $this->assertSame($this->minute() . ':4', Configuration::get('IFIRMA_DL_CUSTOMER_MINUTE'));
        $this->assertSame('20261110:1', Configuration::get('IFIRMA_DL_CUSTOMER_DAY'));
    }
}
