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
 * IfirmaApiClient::fromConfiguration(): the one place that turns the module Configuration
 * (IFIRMA_USER, the three IFIRMA_KEY_* keys, IFIRMA_BASE_URL) into an API client, and the
 * build() methods of the services (plus the module's limit box) that construct their client
 * from that Configuration.
 *
 * The client keeps its settings private, so the tests check what reaches the transport: the
 * request URL (the base URL) and the Authentication header (the user, and the HMAC made with the
 * key that belongs to the key name). Three different keys make a swapped pair visible.
 */
class ApiClientFactoryTest extends TestCase
{
    const USER = 'ifirma-api@example.test';
    // a single-label host: IfirmaSettings accepts plain http for it, as for a Docker service
    const CONFIGURED_BASE = 'http://configured/iapi/';
    const EXPLICIT_BASE = 'http://explicit.test/api/';

    /** @var ScriptedTransport */
    private $t;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        IfirmaApiClient::resetRequestState();
        $this->t = new ScriptedTransport();
        IfirmaApiClient::setDefaultTransport($this->t);

        $keys = $this->keys();
        Configuration::updateValue('IFIRMA_USER', self::USER);
        Configuration::updateValue('IFIRMA_KEY_FAKTURA', $keys['faktura']);
        Configuration::updateValue('IFIRMA_KEY_ABONENT', $keys['abonent']);
        Configuration::updateValue('IFIRMA_KEY_RACHUNEK', $keys['rachunek']);
        Configuration::updateValue('IFIRMA_BASE_URL', self::CONFIGURED_BASE);
    }

    protected function tearDown(): void
    {
        IfirmaSettings::$devMode = null;
        IfirmaApiClient::resetRequestState();
        IfirmaApiClient::setDefaultTransport(null);
    }

    /**
     * @return array key name => hexadecimal API key stored in the matching IFIRMA_KEY_* setting
     */
    private function keys()
    {
        return array(
            'faktura' => str_repeat('a1', 16),
            'abonent' => str_repeat('b2', 16),
            'rachunek' => str_repeat('c3', 16),
        );
    }

    /**
     * Sends one request per key name through the client and checks what reached the transport:
     * the URL starts with $base, and the Authentication header carries the configured user and
     * the HMAC of that key name's own key.
     *
     * @param IfirmaApiClient $client
     * @param string          $base   base URL (with the trailing slash) the requests must go to
     */
    private function assertClientConfigured(IfirmaApiClient $client, $base)
    {
        foreach ($this->keys() as $keyName => $hexKey) {
            $this->t->queue('GET', '#/probe\.json$#', array('body' => '{"response":{"Kod":0}}'));
            $client->get('probe.json', $keyName);

            $url = $base . 'probe.json';
            $this->assertSame($url, $this->t->lastCall()['url'], $keyName . ': request URL');
            $this->assertSame(
                'IAPIS user=' . self::USER . ', hmac-sha1=' . hash_hmac('sha1', $url . self::USER . $keyName, hex2bin($hexKey)),
                $this->t->lastAuthHeader(),
                $keyName . ': user and key in the Authentication header'
            );
        }
    }

    public function testReadsTheUserAndEachOfTheThreeKeysFromConfiguration()
    {
        $client = IfirmaApiClient::fromConfiguration();

        $this->assertInstanceOf('IfirmaApiClient', $client);
        $this->assertClientConfigured($client, self::CONFIGURED_BASE);
        $this->assertCount(3, $this->t->calls);
    }

    public function testAnExplicitBaseUrlWinsOverTheConfiguredOne()
    {
        $client = IfirmaApiClient::fromConfiguration(self::EXPLICIT_BASE);

        $this->assertClientConfigured($client, self::EXPLICIT_BASE);
    }

    public function testNoOrEmptyExplicitBaseUrlFallsBackToTheConfiguredOne()
    {
        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(null), self::CONFIGURED_BASE);
        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(''), self::CONFIGURED_BASE);
    }

    public function testWithoutAConfiguredBaseUrlTheClientTalksToIfirma()
    {
        Configuration::deleteByName('IFIRMA_BASE_URL');
        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(), IfirmaApiClient::BASE_URL);

        Configuration::updateValue('IFIRMA_BASE_URL', '');
        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(), IfirmaApiClient::BASE_URL);
    }

    public function refusedStoredBaseUrls()
    {
        return array(
            'plain http to a public host' => array('http://sandbox.example/iapi/'),
            'a query string' => array('https://sandbox.example/iapi/?x=1'),
            'a fragment' => array('https://sandbox.example/iapi/#top'),
            'credentials' => array('https://user:pw@sandbox.example/iapi/'),
            'not an address' => array('abc'),
        );
    }

    /**
     * @dataProvider refusedStoredBaseUrls
     */
    public function testAStoredBaseUrlThatFailsTheCheckIsNotUsedAndIsLoggedOncePerRequest($stored)
    {
        IfirmaSettings::$devMode = false;
        Configuration::updateValue('IFIRMA_BASE_URL', $stored);

        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(), IfirmaApiClient::BASE_URL);
        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(null), IfirmaApiClient::BASE_URL);

        $this->assertCount(1, PrestaShopLogger::$logs, 'one entry for the request, not one per client');
        $this->assertSame(2, PrestaShopLogger::$logs[0]['severity']);
        $this->assertStringContainsString($stored, PrestaShopLogger::$logs[0]['message']);

        // a new request logs again
        IfirmaApiClient::resetRequestState();
        IfirmaApiClient::fromConfiguration();
        $this->assertCount(2, PrestaShopLogger::$logs);
    }

    public function testAStoredBaseUrlThatPassesTheCheckIsUsedWithoutALogEntry()
    {
        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(), self::CONFIGURED_BASE);
        $this->assertSame(array(), PrestaShopLogger::$logs);

        // the mock of the test stack stays acceptable
        Configuration::updateValue('IFIRMA_BASE_URL', 'http://ifirma-mock:8001/iapi/');
        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(), 'http://ifirma-mock:8001/iapi/');
        $this->assertSame(array(), PrestaShopLogger::$logs);
    }

    public function testAnExplicitBaseUrlIsNotCheckedAgainstTheStoredOne()
    {
        IfirmaSettings::$devMode = false;
        Configuration::updateValue('IFIRMA_BASE_URL', 'http://sandbox.example/iapi/');

        $this->assertClientConfigured(IfirmaApiClient::fromConfiguration(self::EXPLICIT_BASE), self::EXPLICIT_BASE);
        $this->assertSame(array(), PrestaShopLogger::$logs);
    }

    public function testAKeyThatIsNotConfiguredStaysMissingAndNothingIsSent()
    {
        Configuration::deleteByName('IFIRMA_KEY_RACHUNEK');
        $client = IfirmaApiClient::fromConfiguration();

        $this->assertNull($client->get('probe.json', 'rachunek'));
        $this->assertSame('Missing iFirma API key "rachunek".', $client->getLastError());
        $this->assertSame(array(), $this->t->calls);

        // the other two keys are unaffected
        $this->t->queue('GET', '#/probe\.json$#', array('body' => '{"response":{"Kod":0}}'));
        $this->assertSame(array('response' => array('Kod' => 0)), $client->get('probe.json', 'faktura'));
        $this->assertCount(1, $this->t->calls);
    }

    // ---- build() of the services ----------------------------------------------------------------

    /**
     * @return array class => array(class, properties leading from the built object to its client)
     */
    public function builtServices()
    {
        return array(
            'IfirmaReceiptService' => array('IfirmaReceiptService', array('client')),
            'IfirmaIssuancePipeline' => array('IfirmaIssuancePipeline', array('service', 'client')),
            'IfirmaDeliveryService' => array('IfirmaDeliveryService', array('client')),
            'IfirmaStatusSyncService' => array('IfirmaStatusSyncService', array('client')),
            'IfirmaKsefService' => array('IfirmaKsefService', array('client')),
            'IfirmaDuplicateGuard' => array('IfirmaDuplicateGuard', array('client')),
            'IfirmaContractorService' => array('IfirmaContractorService', array('client')),
            'IfirmaCustomerContractorService' => array('IfirmaCustomerContractorService', array('client')),
        );
    }

    /**
     * Follows private properties from a built object down to the IfirmaApiClient it holds (the
     * services keep their collaborators private, and have no getter for them).
     *
     * @param object   $object
     * @param string[] $path   property names, outermost first
     * @return IfirmaApiClient
     */
    private function clientOf($object, array $path)
    {
        foreach ($path as $property) {
            $reflection = new ReflectionProperty(get_class($object), $property);
            $reflection->setAccessible(true);
            $object = $reflection->getValue($object);
        }
        $this->assertInstanceOf('IfirmaApiClient', $object, implode('->', $path));

        return $object;
    }

    /**
     * @param string   $class service class whose static build(Module) is called
     * @param string[] $path  see clientOf()
     * @return IfirmaApiClient
     */
    private function builtClient($class, array $path)
    {
        $built = call_user_func(array($class, 'build'), new Module());
        $this->assertInstanceOf($class, $built);

        return $this->clientOf($built, $path);
    }

    /**
     * @dataProvider builtServices
     */
    public function testBuildCreatesItsClientFromConfiguration($class, array $path)
    {
        $this->assertClientConfigured($this->builtClient($class, $path), self::CONFIGURED_BASE);
    }

    /**
     * @dataProvider builtServices
     */
    public function testBuildTalksToIfirmaWhenNoBaseUrlIsConfigured($class, array $path)
    {
        Configuration::deleteByName('IFIRMA_BASE_URL');
        $this->assertClientConfigured($this->builtClient($class, $path), IfirmaApiClient::BASE_URL);

        Configuration::updateValue('IFIRMA_BASE_URL', '');
        $this->assertClientConfigured($this->builtClient($class, $path), IfirmaApiClient::BASE_URL);
    }

    public function testPipelineBuildSharesOneClientAcrossItsCollaborators()
    {
        $pipeline = IfirmaIssuancePipeline::build(new Module());
        $client = $this->clientOf($pipeline, array('service', 'client'));

        $this->assertSame($client, $this->clientOf($pipeline, array('guard', 'client')));
        $this->assertSame($client, $this->clientOf($pipeline, array('delivery', 'client')));
        $this->assertSame($client, $this->clientOf($pipeline, array('delivery', 'service', 'client')));
        $this->assertSame($client, $this->clientOf($pipeline, array('ksef', 'client')));
    }

    public function testDeliveryServiceBuildSharesItsClientWithItsReceiptService()
    {
        $delivery = IfirmaDeliveryService::build(new Module());

        $this->assertSame(
            $this->clientOf($delivery, array('client')),
            $this->clientOf($delivery, array('service', 'client'))
        );
    }

    public function testCustomerContractorServiceBuildSharesItsClientWithItsContractorService()
    {
        $service = IfirmaCustomerContractorService::build(new Module());

        $this->assertSame(
            $this->clientOf($service, array('client')),
            $this->clientOf($service, array('contractors', 'client'))
        );
    }

    // ---- the module's own client (API limit box) ------------------------------------------------

    public function testTheModuleBuildsItsClientFromConfiguration()
    {
        // The limit box only uses the abonent key; this probes all three through the private builder.
        $module = IfirmaTestModuleLoader::loadModule();
        $buildApiClient = new ReflectionMethod($module, 'buildApiClient');
        $buildApiClient->setAccessible(true);

        $this->assertClientConfigured($buildApiClient->invoke($module), self::CONFIGURED_BASE);
    }

    public function testTheModuleLimitBoxAsksIfirmaThroughAClientConfiguredFromConfiguration()
    {
        $module = IfirmaTestModuleLoader::loadModule();
        $this->t->queue('GET', '#abonent/limit\.json$#', array('body' => '{"response":{"Kod":0,"LimitWykorzystany":12,"LimitPrzyznany":15000}}'));

        $module->getContent();

        $url = self::CONFIGURED_BASE . 'abonent/limit.json';
        $keys = $this->keys();
        $this->assertCount(1, $this->t->calls);
        $this->assertSame($url, $this->t->lastCall()['url']);
        $this->assertSame(
            'IAPIS user=' . self::USER . ', hmac-sha1=' . hash_hmac('sha1', $url . self::USER . 'abonent', hex2bin($keys['abonent'])),
            $this->t->lastAuthHeader()
        );
    }

    public function testTheModuleLimitBoxFallsBackToIfirmaWithoutAConfiguredBaseUrl()
    {
        Configuration::deleteByName('IFIRMA_BASE_URL');
        $module = IfirmaTestModuleLoader::loadModule();
        $this->t->queue('GET', '#abonent/limit\.json$#', array('body' => '{"response":{"Kod":0,"LimitWykorzystany":1,"LimitPrzyznany":2}}'));

        $module->getContent();

        $this->assertCount(1, $this->t->calls);
        $this->assertSame(IfirmaApiClient::BASE_URL . 'abonent/limit.json', $this->t->lastCall()['url']);
    }

    /**
     * The client lets a transport failure through; the limit box must still render.
     *
     * @dataProvider limitRequestFailures
     */
    public function testTheModuleLimitBoxReportsAnUnavailableLimitWhenTheRequestThrows($thrown)
    {
        $module = IfirmaTestModuleLoader::loadModule();
        IfirmaApiClient::setDefaultTransport(new ThrowingLimitTransport($thrown));

        $module->getContent();

        $limitBox = null;
        foreach (HelperForm::$lastForm[0]['form']['input'] as $input) {
            if ($input['name'] === 'IFIRMA_API_LIMIT') {
                $limitBox = $input['html_content'];
            }
        }
        $this->assertSame('<div class="alert alert-warning">iFirma API limit is currently unavailable.</div>', $limitBox);
    }

    public static function limitRequestFailures()
    {
        return array(
            'exception' => array(new RuntimeException('connection reset')),
            'error' => array(new Error('transport broke')),
        );
    }
}

/** Transport whose every request throws the given Throwable. */
class ThrowingLimitTransport
{
    /** @var Throwable */
    private $thrown;

    public function __construct(Throwable $thrown)
    {
        $this->thrown = $thrown;
    }

    public function request($method, $url, array $headers, $body, $timeout)
    {
        throw $this->thrown;
    }
}
