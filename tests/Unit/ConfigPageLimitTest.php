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
 * Given the configuration page, when an employee opens it or clicks "Check the remaining iFirma limit", then the page
 * shows what the module counted and what was last read without sending a request, and the click is a protected action
 * (specs api-usage-accounting "Usage shown on the configuration page", ifirma-api-compliance "Optional limit
 * visibility", "Remaining limit checked on demand", "A failed or refused limit check is reported" and "Limit check is a
 * protected action"). The requests go through Ifirma::getContent() like in ModuleHooksTest.
 */
class ConfigPageLimitTest extends TestCase
{
    use IfirmaWiringFixture;

    const KEY = '2222222222222222222222222222222222222222222222222222222222222222';

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Configuration::updateValue('IFIRMA_KEY_ABONENT', self::KEY);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    private function limitJson($used, $granted)
    {
        return '{"response":{"Kod":0,"LimitWykorzystany":' . $used . ',"LimitPrzyznany":' . $granted . '}}';
    }

    private function queueLimit($used, $granted)
    {
        $this->t->queue('GET', '#abonent/limit\.json#', array('body' => $this->limitJson($used, $granted)));
    }

    /**
     * @param string $method the HTTP method of the request
     * @return string the page
     */
    private function clickCheck($method = 'POST')
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        Tools::$values = array('submitIfirmaCheckLimit' => '1', 'token' => 'admin-token-AdminModules');

        return $this->module->getContent();
    }

    // ---- a page view sends nothing -----------------------------------------------------------------------------------

    public function testOpeningThePageSendsNoRequestAndSaysTheLimitWasNotCheckedYet()
    {
        $page = $this->module->getContent();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('The remaining limit has not been checked yet.', $page);
        $this->assertStringContainsString('name="submitIfirmaCheckLimit"', $page);
        $this->assertStringContainsString('Check the remaining iFirma limit', $page);
    }

    public function testOpeningThePageShowsTheLastStoredReadingWithItsTime()
    {
        $at = mktime(10, 2, 0, 11, 1, 2026);
        Configuration::updateValue('IFIRMA_REMOTE_LIMIT', date('Ymd') . ':1200:15000:' . $at);

        $page = $this->module->getContent();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('Used 1200 of 15000 requests, 13800 remaining (read 2026-11-01 10:02).', $page);
    }

    public function testAReadingOfAnotherDayIsShownWithItsDateOnOpeningThePage()
    {
        Configuration::updateValue('IFIRMA_REMOTE_LIMIT', '20250101:900:15000:' . mktime(8, 0, 0, 1, 1, 2025));

        $page = $this->module->getContent();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('Last reading, from 2025-01-01: used 900 of 15000 requests.', $page);
    }

    public function testWithoutTheAbonentKeyThePageShowsTheHintAndNoButtonAndSendsNothing()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', '');

        $page = $this->module->getContent();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('To read the remaining iFirma limit, enter the abonent API key and save.', $page);
        $this->assertStringNotContainsString('submitIfirmaCheckLimit', $page);
    }

    public function testTheLimitIsNoLongerAFieldOfTheSettingsForm()
    {
        $this->module->getContent();

        $names = array();
        foreach (HelperForm::$lastForm[0]['form']['input'] as $input) {
            $names[] = $input['name'];
        }
        $this->assertNotContains('IFIRMA_API_LIMIT', $names, 'a form inside the settings form would be nested; the box is a panel of its own');
    }

    // ---- the click ---------------------------------------------------------------------------------------------------

    public function testAClickSendsExactlyOneRequestAndShowsUsedGrantedRemainingAndTheTime()
    {
        $this->queueLimit(1200, 15000);

        $page = $this->clickCheck();

        $this->assertCount(1, $this->t->calls);
        $this->assertMatchesRegularExpression('#Used 1200 of 15000 requests, 13800 remaining \(read \d{4}-\d{2}-\d{2} \d{2}:\d{2}\)\.#', $page);
    }

    public function testAClickAsksAgainAlthoughACronReadingIsFresh()
    {
        Configuration::updateValue('IFIRMA_REMOTE_LIMIT', date('Ymd') . ':1100:15000:' . (time() - 120));
        $this->queueLimit(1200, 15000);

        $page = $this->clickCheck();

        $this->assertCount(1, $this->t->calls);
        $this->assertStringContainsString('Used 1200 of 15000 requests', $page);
    }

    public function testAClickWithNothingLeftSaysTheModuleSendsNothingUntilTomorrow()
    {
        $this->queueLimit(15000, 15000);

        $page = $this->clickCheck();

        $this->assertStringContainsString('0 remaining', $page);
        $this->assertStringContainsString('the module sends no request to iFirma until tomorrow.', $page);
    }

    public function testTheClickIsCountedInTheMinuteTheDayAndKindLimit()
    {
        $this->queueLimit(5, 15000);

        $this->clickCheck();

        $usage = new IfirmaApiUsage();
        $this->assertSame(1, $usage->todayByKind()['limit']);
        $this->assertSame(1, $usage->summary()['minute']);
        $this->assertSame(5, $usage->summary()['day'], 'raised to the account count of iFirma');
    }

    public function testFiveClicksInAMinuteSendFiveRequestsAndTheMinuteCapStopsTheSixth()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 5);
        for ($i = 0; $i < 6; $i++) {
            $this->queueLimit(10 + $i, 15000);
        }

        for ($i = 0; $i < 5; $i++) {
            $this->clickCheck();
        }
        $sixth = $this->clickCheck();

        $this->assertCount(5, $this->t->calls);
        $this->assertStringContainsString('the module reached its limit of requests per minute', $sixth);
    }

    // ---- protection --------------------------------------------------------------------------------------------------

    public function testAGetRequestCarryingTheSubmitNameSendsNothingAndChangesNothing()
    {
        $this->queueLimit(1200, 15000);

        $page = $this->clickCheck('GET');

        $this->assertCount(0, $this->t->calls);
        $this->assertNull(IfirmaRemoteLimit::stored());
        $this->assertStringContainsString('The remaining limit has not been checked yet.', $page);
    }

    public function testAPostedCheckWithAWrongOrMissingTokenSendsNothing()
    {
        $this->queueLimit(1200, 15000);
        foreach (array(array('token' => 'forged'), array()) as $extra) {
            Tools::$values = array_merge(array('submitIfirmaCheckLimit' => '1'), $extra);
            $this->module->getContent();
        }

        $this->assertCount(0, $this->t->calls);
        $this->assertNull(IfirmaRemoteLimit::stored());
    }

    public function testAPostedCheckWithoutTheAbonentKeySendsNothing()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', '');
        $this->queueLimit(1200, 15000);

        $page = $this->clickCheck();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('enter the abonent API key', $page);
    }

    public function testAnEmployeeWithoutTheRightToConfigureTheModuleSendsNothing()
    {
        Module::$deniedPermissions = array('configure');
        $this->queueLimit(1200, 15000);

        $page = $this->clickCheck();

        $this->assertCount(0, $this->t->calls);
        $this->assertNull(IfirmaRemoteLimit::stored());
        $this->assertStringNotContainsString('Used 1200', $page);
    }

    // ---- the check cannot be done ------------------------------------------------------------------------------------

    public function testARefusalByTheMinuteCapSendsNothingNamesTheMinuteLimitAndKeepsTheEarlierReading()
    {
        $at = time() - 300;
        Configuration::updateValue('IFIRMA_REMOTE_LIMIT', date('Ymd') . ':1200:15000:' . $at);
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 3);
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi') . ':3');
        $this->queueLimit(1300, 15000);

        $page = $this->clickCheck();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('The limit was not checked because the module reached its limit of requests per minute.', $page);
        $this->assertStringContainsString('Used 1200 of 15000 requests', $page);
        $this->assertSame(date('Ymd') . ':1200:15000:' . $at, Configuration::get('IFIRMA_REMOTE_LIMIT'));
    }

    public function testAnIFirmaErrorShowsTheGenericNoticeKeepsTheEarlierReadingAndLogsTheDetail()
    {
        Configuration::updateValue('IFIRMA_REMOTE_LIMIT', date('Ymd') . ':1200:15000:' . (time() - 300));
        $this->t->queue('GET', '#abonent/limit\.json#', array('body' => '{"response":{"Kod":5,"Informacja":"<script>alert(1)</script> boom"}}'));

        $page = $this->clickCheck();

        $this->assertCount(1, $this->t->calls);
        $this->assertStringContainsString('iFirma API limit is currently unavailable.', $page);
        $this->assertStringContainsString('Used 1200 of 15000 requests', $page);
        $this->assertStringNotContainsString('<script>', $page);
        $this->assertStringNotContainsString('boom', $page);
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertStringContainsString('boom', PrestaShopLogger::$logs[0]['message']);
    }

    public function testAWrongAbonentKeyShowsTheGenericNoticeAndLogsTheDetailWithoutTheKey()
    {
        $this->t->queue('GET', '#abonent/limit\.json#', array('status' => 401, 'body' => ''));

        $page = $this->clickCheck();

        $this->assertStringContainsString('iFirma API limit is currently unavailable.', $page);
        $this->assertStringNotContainsString(self::KEY, $page);
        $this->assertCount(1, PrestaShopLogger::$logs);
        $this->assertStringContainsString('HTTP 401', PrestaShopLogger::$logs[0]['message']);
        $this->assertStringNotContainsString(self::KEY, PrestaShopLogger::$logs[0]['message']);
    }

    public function testAnUnusableAnswerShowsTheNoticeAndChangesNoCounter()
    {
        $this->t->queue('GET', '#abonent/limit\.json#', array('body' => '{"response":{"Kod":0}}'));

        $page = $this->clickCheck();

        $this->assertStringContainsString('iFirma API limit is currently unavailable.', $page);
        $this->assertNull(IfirmaRemoteLimit::stored());
        $this->assertSame(date('Ymd') . ':1', Configuration::get('IFIRMA_RATE_DAY_WINDOW'), 'only the counted request, no raise');
    }

    public function testARunningAuthenticationCoolDownRefusesTheClickAndSendsNothing()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', time() . '|HTTP 401 returned by iFirma.');

        $page = $this->clickCheck();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('iFirma rejected the credentials a moment ago', $page);
    }

    // ---- the Module requests box -------------------------------------------------------------------------------------

    public function testTheModuleRequestsBoxShowsTheCountsNextToTheCapsAndSendsNothing()
    {
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi') . ':6');
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd') . ':250');
        Configuration::updateValue('IFIRMA_USAGE_PDF', date('Y-m-d') . ':40');

        $page = $this->module->getContent();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('Module requests', $page);
        $this->assertStringContainsString('6 of 80', $page);
        $this->assertStringContainsString('250 of 14000', $page);
        $this->assertMatchesRegularExpression('#PDF downloads</td><td[^>]*>40</td>#', $page);
    }

    public function testTheModuleRequestsBoxIsShownWithoutTheAbonentKeyAndShowsZeros()
    {
        Configuration::updateValue('IFIRMA_KEY_ABONENT', '');

        $page = $this->module->getContent();

        $this->assertCount(0, $this->t->calls);
        $this->assertStringContainsString('0 of 80', $page);
        $this->assertMatchesRegularExpression('#Status checks \(document lists and details\)</td><td[^>]*>0</td>#', $page);
    }

    public function testARunningBackoffIsNamedInTheModuleRequestsBox()
    {
        $until = time() + 600;
        Configuration::updateValue('IFIRMA_RATE_BACKOFF_UNTIL', (string) $until);

        $page = $this->module->getContent();

        $this->assertStringContainsString('no request is sent until ' . date('Y-m-d H:i:s', $until), $page);
    }

    public function testTheDifferenceToTheKindsIsShownAsOtherClientsAfterTheLimitWasRaised()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', date('Ymd') . ':1200');
        Configuration::updateValue('IFIRMA_USAGE_STATUS', date('Y-m-d') . ':300');

        $page = $this->module->getContent();

        $this->assertMatchesRegularExpression('#Other clients of the account[^<]*</td><td[^>]*>900</td>#', $page);
    }

    public function testEveryKindIsLabelledInTheModuleRequestsBox()
    {
        $page = $this->module->getContent();

        foreach (array('Creating documents', 'Status checks', 'PDF downloads', 'Sending documents by e-mail', 'KSeF submissions',
            'Payments', 'Contractors', 'Limit checks', 'Other requests') as $label) {
            $this->assertStringContainsString($label, $page, $label);
        }
    }
}
