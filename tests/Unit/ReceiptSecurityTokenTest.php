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
 * Spec customer-receipt-visibility, "Download token generation": the download token comes from the secure
 * random generator only; when that generator is unavailable the record is stored without a token and the
 * failure is logged, and nothing weaker is made up. Kept apart from CreateReceiptTest, whose scenarios are
 * mirrored one by one in the request golden.
 */
class ReceiptSecurityTokenTest extends TestCase
{
    use IfirmaContractorMapDb;
    use IfirmaCreateReceiptScenario;

    protected function setUp(): void
    {
        $this->setUpCreateReceiptScenario();
    }

    protected function tearDown(): void
    {
        IfirmaReceiptService::$randomBytes = null;
    }

    public function testTheTokenIsFortyHexCharactersFromTheSecureGenerator()
    {
        $token = IfirmaReceiptService::generateSecurityToken();

        $this->assertSame(40, strlen($token));
        $this->assertTrue(ctype_xdigit($token));
        $this->assertNotSame($token, IfirmaReceiptService::generateSecurityToken());
    }

    public function testTheSeamReplacesTheGenerator()
    {
        IfirmaReceiptService::$randomBytes = function ($length) {
            return str_repeat("\x01", $length);
        };

        $this->assertSame(str_repeat('01', 20), IfirmaReceiptService::generateSecurityToken());
    }

    /** "Generator unavailable" */
    public function testGeneratorUnavailableGivesNoTokenAndLogs()
    {
        IfirmaReceiptService::$randomBytes = function () {
            throw new Exception('no entropy source');
        };

        $this->assertSame('', IfirmaReceiptService::generateSecurityToken());
        $logged = array_filter(PrestaShopLogger::$logs, function ($entry) {
            return strpos(json_encode($entry), 'secure random generator is unavailable') !== false;
        });
        $this->assertCount(1, $logged);
    }

    /** "Generator unavailable": the document is still created and the row is stored without a token. */
    public function testTheRowIsStoredWithoutATokenWhenTheGeneratorIsUnavailable()
    {
        $this->t->queue('POST', '#fakturakraj\.json#', array('body' => '{"response":{"Kod":0,"Informacja":"ok","Identyfikator":"77"}}'));
        $this->t->queue('GET', '#faktury\.json#', array('body' => json_encode(array('response' => array(
            'Kod' => 0,
            'Wynik' => array(array('FakturaId' => 77, 'PelnyNumer' => '7/2026')),
        )))));
        IfirmaReceiptService::$randomBytes = function () {
            throw new Exception('no entropy source');
        };

        $this->service->createReceipt($this->order());

        $rows = IfirmaTestState::objects('ifirma_receipt');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('created', $row['status']);
        $this->assertSame('77', (string) $row['ifirma_id']);
        $this->assertSame('', (string) $row['security_token']);
    }
}
