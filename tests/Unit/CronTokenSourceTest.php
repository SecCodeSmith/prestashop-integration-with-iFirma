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
 * Where the cron endpoint reads its token from (admin-receipt-ops-and-hardening 8.3): the query parameter, else
 * the X-Ifirma-Token header; an empty configured token, an empty given one and an array are refused.
 */
class CronTokenSourceTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaContractorMapDb;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptContractorMapDb();
        unset($_SERVER['HTTP_X_IFIRMA_TOKEN']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_IFIRMA_TOKEN']);
        IfirmaApiClient::setDefaultTransport(null);
    }

    /**
     * Runs the cron front controller with the request in Tools::$values and the header in $_SERVER.
     *
     * @return array array(status, payload) the controller answered
     */
    private function call()
    {
        IfirmaTestModuleLoader::loadFrontController('cron');
        $controller = new class extends IfirmaCronModuleFrontController {
            public $answer;

            protected function respond($status, array $payload)
            {
                $this->answer = array($status, $payload);
            }
        };
        $controller->initContent();

        return $controller->answer;
    }

    public function testAWrongTokenRunsNoJobAndMakesNoRequest()
    {
        Tools::$values = array('token' => 'not-the-token');
        $transport = new ScriptedTransport();
        IfirmaApiClient::setDefaultTransport($transport);

        list($status, $payload) = $this->call();

        $this->assertSame(403, $status);
        $this->assertSame(array('error' => 'Forbidden'), $payload);
        $this->assertSame(array(), $transport->calls);
        $locked = array_filter(Db::getInstance()->queries, function ($sql) {
            return strpos($sql, 'GET_LOCK') !== false;
        });
        $this->assertSame(array(), array_values($locked), 'not even the run lock was taken');
    }

    public function testTheRightTokenAnswersWithTheSyncKeysAtTheTopAndTheQueueBlock()
    {
        Tools::$values = array('token' => 'cron-token-1');

        list($status, $payload) = $this->call();

        $this->assertSame(200, $status);
        foreach (array('receipts_checked', 'updated', 'marked_paid', 'marked_missing', 'errors', 'rate_limited', 'calls',
            'dates_total', 'dates_processed', 'held_orders', 'started_at', 'finished_at', 'auth_blocked', 'message') as $key) {
            $this->assertArrayHasKey($key, $payload, $key);
        }
        $this->assertSame(array('handled' => 0, 'succeeded' => 0, 'failed' => 0, 'left' => 0), $payload['queue']);
        $this->assertSame('', $payload['rate_limited_window']);
    }

    public function testTokenInTheHeader()
    {
        Tools::$values = array();
        $_SERVER['HTTP_X_IFIRMA_TOKEN'] = 'cron-token-1';

        $answer = $this->call();

        $this->assertSame(200, $answer[0]);
        $this->assertArrayHasKey('receipts_checked', $answer[1]);
    }

    public function testTokenInTheQuery()
    {
        Tools::$values = array('token' => 'cron-token-1');

        $this->assertSame(200, $this->call()[0]);
    }

    public function testTheQueryTokenWinsOverTheHeader()
    {
        Tools::$values = array('token' => 'wrong');
        $_SERVER['HTTP_X_IFIRMA_TOKEN'] = 'cron-token-1';

        $this->assertSame(403, $this->call()[0], 'a wrong query token is not rescued by a right header');

        Tools::$values = array('token' => 'cron-token-1');
        $_SERVER['HTTP_X_IFIRMA_TOKEN'] = 'wrong';

        $this->assertSame(200, $this->call()[0], 'a right query token does not need the header');
    }

    public function testNoTokenAnywhereIsRefused()
    {
        Tools::$values = array();

        $answer = $this->call();

        $this->assertSame(403, $answer[0]);
        $this->assertSame(array('error' => 'Forbidden'), $answer[1]);
    }

    public function testAWrongHeaderIsRefused()
    {
        $_SERVER['HTTP_X_IFIRMA_TOKEN'] = 'cron-token-2';

        $this->assertSame(403, $this->call()[0]);
    }

    public function testEmptyConfiguredToken()
    {
        Configuration::updateValue('IFIRMA_CRON_TOKEN', '');
        Tools::$values = array('token' => '');
        $_SERVER['HTTP_X_IFIRMA_TOKEN'] = '';

        $this->assertSame(403, $this->call()[0], 'an empty token matches nothing');

        Configuration::deleteByName('IFIRMA_CRON_TOKEN');
        $this->assertSame(403, $this->call()[0], 'and so does a missing one');
    }

    public function testAnArrayTokenIsRefused()
    {
        Tools::$values = array('token' => array('x'));

        $this->assertSame(403, $this->call()[0]);

        $_SERVER['HTTP_X_IFIRMA_TOKEN'] = 'cron-token-1';
        $this->assertSame(200, $this->call()[0], 'an array in the query falls back to the header');
    }
}
