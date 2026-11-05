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
 * (IFIRMA_USER, the three IFIRMA_KEY_* keys, IFIRMA_BASE_URL) into an API client.
 *
 * The client keeps its settings private, so the tests check what reaches the transport: the
 * request URL (the base URL) and the Authentication header (the user, and the HMAC made with the
 * key that belongs to the key name). Three different keys make a swapped pair visible.
 */
class ApiClientFactoryTest extends TestCase
{
    const USER = 'ifirma-api@example.test';
    const CONFIGURED_BASE = 'http://configured.test/iapi/';
    const EXPLICIT_BASE = 'http://explicit.test/api/';

    /** @var ScriptedTransport */
    private $t;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
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
}
