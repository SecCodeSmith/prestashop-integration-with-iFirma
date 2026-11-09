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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaKsefStateReader.php';

/**
 * The read of a document's KSeF state from iFirma (design D5).
 */
class KsefStateReaderTest extends TestCase
{
    /** @var ScriptedTransport */
    private $t;

    /** @var IfirmaApiClient */
    private $client;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->t = new ScriptedTransport();
        $this->client = new IfirmaApiClient('u@example.com', array('faktura' => str_repeat('1', 64)), 'http://ifirma-mock:8001/iapi/', 30, $this->t);
    }

    private function read()
    {
        return (new IfirmaKsefStateReader($this->client))->read('fakturakraj', '77');
    }

    public function testTheStatusAndNumberAreReadFromTheDetail()
    {
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Kod":0,"KsefStatus":"PRZYJETA","KsefNumer":"5260001122-20261101-ABC-01"}}'));

        $state = $this->read();

        $this->assertTrue($state->isReadable());
        $this->assertSame('PRZYJETA', $state->getStatus());
        $this->assertSame('5260001122-20261101-ABC-01', $state->getNumber());
        $this->assertFalse($state->isRateLimited());
        $this->assertSame('', $state->getError());
    }

    public function testMissingKeysReadAsEmpty()
    {
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Kod":0,"PelnyNumer":"7/2026"}}'));

        $state = $this->read();

        $this->assertTrue($state->isReadable());
        $this->assertSame(array('', ''), array($state->getStatus(), $state->getNumber()));
    }

    public function testNullValuesReadAsEmpty()
    {
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Kod":0,"KsefStatus":null,"KsefNumer":null}}'));

        $state = $this->read();

        $this->assertSame(array('', ''), array($state->getStatus(), $state->getNumber()));
    }

    public function testAnErrorCodeMakesTheStateUnreadable()
    {
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '{"response":{"Kod":404,"Informacja":"Nie znaleziono dokumentu"}}'));

        $state = $this->read();

        $this->assertFalse($state->isReadable());
        $this->assertStringContainsString('Nie znaleziono dokumentu', $state->getError());
        $this->assertFalse($state->isRateLimited());
    }

    public function testATransportFailureMakesTheStateUnreadable()
    {
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('status' => 0, 'error' => 'timeout'));

        $state = $this->read();

        $this->assertFalse($state->isReadable());
        $this->assertStringContainsString('timeout', $state->getError());
    }

    public function testARateLimitedReadIsReportedAsSuch()
    {
        $fixed = mktime(10, 15, 20, 11, 1, 2026);
        $this->client->setClock(function () use ($fixed) {
            return $fixed;
        });
        Configuration::updateValue('IFIRMA_RATE_WINDOW', date('YmdHi', $fixed) . ':80');

        $state = $this->read();

        $this->assertFalse($state->isReadable());
        $this->assertTrue($state->isRateLimited());
        $this->assertSame(array(), $this->t->calls);
    }

    public function testABodyThatIsNotJsonIsUnreadable()
    {
        $this->t->queue('GET', '#fakturakraj/77\.json#', array('body' => '<html>oops</html>'));

        $this->assertFalse($this->read()->isReadable());
    }
}
