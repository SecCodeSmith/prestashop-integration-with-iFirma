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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRemoteLimit.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiUsage.php';

/**
 * Given an employee asks for the remaining limit, when the module checks it, then iFirma is asked once and the answer is
 * stored; when the check cannot be done, nothing changes and the outcome says why (spec ifirma-api-compliance
 * "Remaining limit checked on demand" and "A failed or refused limit check is reported").
 */
class RemoteLimitCheckTest extends TestCase
{
    const KEY = '2222222222222222222222222222222222222222222222222222222222222222';

    /** @var int */
    public $now;

    /** @var array[]|null[] what the fake fetch answers, oldest first */
    private $answers = array();

    /** @var int */
    private $asked = 0;

    /** @var array what the fake failure seam reports */
    private $failure = array('window' => '', 'detail' => '');

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->now = mktime(10, 2, 0, 11, 1, 2026);
        $this->answers = array();
        $this->asked = 0;
        $this->failure = array('window' => '', 'detail' => '');
    }

    private function remoteLimit()
    {
        $self = $this;

        return new IfirmaRemoteLimit(function () use ($self) {
            $self->asked++;

            return $self->answers ? array_shift($self->answers) : null;
        }, function () use ($self) {
            return $self->now;
        }, function () use ($self) {
            return $self->failure;
        });
    }

    private function day()
    {
        return date('Ymd', $this->now);
    }

    public function testACheckReportsUsedGrantedRemainingAndTheTimeOfTheReading()
    {
        $this->answers[] = array('used' => 1200, 'granted' => 15000);

        $check = $this->remoteLimit()->check();

        $this->assertSame(IfirmaLimitCheck::CHECKED, $check->status());
        $this->assertSame(1200, $check->reading()->used());
        $this->assertSame(15000, $check->reading()->granted());
        $this->assertSame(13800, $check->reading()->remaining());
        $this->assertSame($this->now, $check->reading()->at());
        $this->assertSame($this->day(), $check->reading()->day());
    }

    public function testACheckAsksIFirmaAgainAlthoughAFreshReadingExists()
    {
        $this->answers[] = array('used' => 1200, 'granted' => 15000);
        $this->answers[] = array('used' => 1250, 'granted' => 15000);
        $remote = $this->remoteLimit();
        $remote->refresh();
        $this->now += 120;

        $check = $remote->check();

        $this->assertSame(2, $this->asked);
        $this->assertSame(1250, $check->reading()->used());
        $this->assertSame($this->now, IfirmaRemoteLimit::stored()->at());
    }

    public function testACheckRaisesTheDayCounterToWhatIFirmaCounts()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':300');
        $this->answers[] = array('used' => 1200, 'granted' => 15000);

        $this->remoteLimit()->check();

        $this->assertSame($this->day() . ':1200', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testACheckNeverLowersTheDayCounter()
    {
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':1500');
        $this->answers[] = array('used' => 1200, 'granted' => 15000);

        $this->remoteLimit()->check();

        $this->assertSame($this->day() . ':1500', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testNothingLeftIsReportedAsZeroRemaining()
    {
        $this->answers[] = array('used' => 15000, 'granted' => 15000);

        $check = $this->remoteLimit()->check();

        $this->assertSame(0, $check->reading()->remaining());
    }

    public function testAnErrorFromIFirmaIsUnavailableAndChangesNothing()
    {
        $this->answers[] = array('used' => 1200, 'granted' => 15000);
        $remote = $this->remoteLimit();
        $remote->refresh();
        Configuration::updateValue('IFIRMA_RATE_DAY_WINDOW', $this->day() . ':1250');
        $this->now += 120;
        $this->answers[] = null;
        $this->failure = array('window' => '', 'detail' => 'iFirma error (Kod 5)');

        $check = $remote->check();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertSame('iFirma error (Kod 5)', $check->detail());
        $this->assertSame(1200, IfirmaRemoteLimit::stored()->used(), 'the earlier reading stays');
        $this->assertSame($this->day() . ':1250', Configuration::get('IFIRMA_RATE_DAY_WINDOW'));
    }

    public function testAnUnusableAnswerIsUnavailableAndChangesNothing()
    {
        $this->answers[] = array('used' => 5, 'granted' => 0);

        $check = $this->remoteLimit()->check();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertNull(IfirmaRemoteLimit::stored());
        $this->assertFalse(Configuration::hasKey('IFIRMA_RATE_DAY_WINDOW'));
    }

    /**
     * @return array[] window
     */
    public static function refusingWindows()
    {
        return array(
            'minute cap' => array('minute'),
            'day cap' => array('day'),
            'back-off' => array('backoff'),
            'authentication cool-down' => array(IfirmaLimitCheck::WINDOW_AUTH),
        );
    }

    /**
     * @dataProvider refusingWindows
     */
    public function testAnOwnRefusalIsReportedWithItsWindowAndChangesNothing($window)
    {
        $this->answers[] = array('used' => 1200, 'granted' => 15000);
        $remote = $this->remoteLimit();
        $remote->check();
        $this->now += 60;
        $this->answers[] = null;
        $this->failure = array('window' => $window, 'detail' => 'refused');

        $check = $remote->check();

        $this->assertSame(IfirmaLimitCheck::REFUSED, $check->status());
        $this->assertSame($window, $check->window());
        $this->assertSame(1200, IfirmaRemoteLimit::stored()->used());
    }

    public function testAnAnswerRefusedByIFirmaItselfIsUnavailableNotARefusalOfTheModule()
    {
        $this->answers[] = null;
        $this->failure = array('window' => 'server', 'detail' => 'HTTP 429');

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $this->remoteLimit()->check()->status());
    }

    /**
     * @return array[] thrown
     */
    public static function thrownByTheTransport()
    {
        return array(
            'exception' => array(new RuntimeException('connection reset')),
            'error' => array(new Error('transport broke')),
        );
    }

    /**
     * @dataProvider thrownByTheTransport
     */
    public function testAThrowingTransportIsUnavailableAndChangesNothing($thrown)
    {
        $remote = new IfirmaRemoteLimit(function () use ($thrown) {
            throw $thrown;
        });

        $check = $remote->check();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertSame(get_class($thrown) . ': ' . $thrown->getMessage(), $check->detail());
        $this->assertNull(IfirmaRemoteLimit::stored());
    }

    public function testWithoutAFailureSeamAFailedCheckIsUnavailable()
    {
        $remote = new IfirmaRemoteLimit(function () {
            return null;
        });

        $check = $remote->check();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertSame('', $check->detail());
    }

    public function testTheStoredReadingIsNullWhenNothingUsableIsStored()
    {
        foreach (array('', 'garbage', '20261101:1:2', '20261101:a:2:3', '20261101:1:0:5') as $stored) {
            Configuration::updateValue('IFIRMA_REMOTE_LIMIT', $stored);

            $this->assertNull(IfirmaRemoteLimit::stored(), var_export($stored, true));
        }
    }

    public function testTheStoredReadingOfAnEarlierDayIsStillReadable()
    {
        Configuration::updateValue('IFIRMA_REMOTE_LIMIT', '20261031:900:15000:' . ($this->now - 90000));

        $reading = IfirmaRemoteLimit::stored();

        $this->assertSame('20261031', $reading->day());
        $this->assertSame(900, $reading->used());
    }

    // ---- through a real client -----------------------------------------------------------------------------------------

    /**
     * @param ScriptedTransport $transport
     * @return IfirmaApiClient
     */
    private function client(ScriptedTransport $transport, $abonentKey = self::KEY)
    {
        $self = $this;
        $client = new IfirmaApiClient('u@example.com', array('abonent' => $abonentKey), 'http://ifirma-mock:8001/iapi/', 30, $transport);
        $client->setClock(function () use ($self) {
            return $self->now;
        });

        return $client;
    }

    private function limitJson($used, $granted)
    {
        return '{"response":{"Kod":0,"LimitWykorzystany":' . $used . ',"LimitPrzyznany":' . $granted . '}}';
    }

    public function testTheClickSendsOneRequestAndCountsItAsKindLimit()
    {
        $transport = new ScriptedTransport();
        $transport->queue('GET', '#abonent/limit\.json$#', array('body' => $this->limitJson(1200, 15000)));
        $self = $this;

        $check = IfirmaRemoteLimit::forClient($this->client($transport), function () use ($self) {
            return $self->now;
        })->check();

        $usage = new IfirmaApiUsage(function () use ($self) {
            return $self->now;
        });
        $this->assertCount(1, $transport->calls);
        $this->assertSame(13800, $check->reading()->remaining());
        $this->assertSame(1, $usage->todayByKind()[IfirmaRequestKind::KIND_LIMIT]);
        $this->assertSame(1, $usage->summary()['minute']);
        $this->assertSame(1200, $usage->summary()['day'], 'raised to iFirma\'s own count');
    }

    public function testARefusalByTheMinuteCapSendsNothingAndIsReportedWithTheWindow()
    {
        Configuration::updateValue('IFIRMA_RATE_MAX_PER_MIN', 2);
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $this->now) . ':2');
        $transport = new ScriptedTransport();
        $self = $this;

        $check = IfirmaRemoteLimit::forClient($this->client($transport), function () use ($self) {
            return $self->now;
        })->check();

        $this->assertCount(0, $transport->calls);
        $this->assertSame(IfirmaLimitCheck::REFUSED, $check->status());
        $this->assertSame('minute', $check->window());
    }

    public function testARunningAuthenticationCoolDownRefusesTheCheckAndSendsNothing()
    {
        Configuration::updateValue('IFIRMA_AUTH_FAILURE', $this->now . '|HTTP 401 returned by iFirma.');
        $transport = new ScriptedTransport();
        $self = $this;

        $check = IfirmaRemoteLimit::forClient($this->client($transport), function () use ($self) {
            return $self->now;
        })->check();

        $this->assertCount(0, $transport->calls);
        $this->assertSame(IfirmaLimitCheck::REFUSED, $check->status());
        $this->assertSame(IfirmaLimitCheck::WINDOW_AUTH, $check->window());
    }

    public function testAWrongKeyIsUnavailableAndTheDetailHoldsNoKey()
    {
        $transport = new ScriptedTransport();
        $transport->queue('GET', '#abonent/limit\.json$#', array('status' => 401, 'body' => ''));
        $self = $this;

        $check = IfirmaRemoteLimit::forClient($this->client($transport), function () use ($self) {
            return $self->now;
        })->check();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertSame('HTTP 401 returned by iFirma.', $check->detail());
        $this->assertStringNotContainsString(self::KEY, $check->detail());
        $this->assertNull(IfirmaRemoteLimit::stored());
    }

    public function testAnAnswerRefusedByIFirmaForTheLimitIsUnavailable()
    {
        $transport = new ScriptedTransport();
        $transport->queue('GET', '#abonent/limit\.json$#', array('status' => 429, 'body' => ''));
        $self = $this;

        $check = IfirmaRemoteLimit::forClient($this->client($transport), function () use ($self) {
            return $self->now;
        })->check();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertCount(1, $transport->calls);
    }

    public function testWithoutTheAbonentKeyTheClientSendsNothingAndTheCheckIsUnavailable()
    {
        $transport = new ScriptedTransport();
        $self = $this;

        $check = IfirmaRemoteLimit::forClient($this->client($transport, ''), function () use ($self) {
            return $self->now;
        })->check();

        $this->assertCount(0, $transport->calls);
        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
    }
}
