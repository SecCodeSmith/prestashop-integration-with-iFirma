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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaOrderGridTooltip.php';

/**
 * The words of the iFirma icon's tooltip in the Orders list (spec order-list-receipt-indicator, design D11).
 */
class OrderGridTooltipTest extends TestCase
{
    const POLISH = array(
        'invoice_issued' => 'Faktura wystawiona', 'invoice_sent' => 'Faktura wysłana', 'invoice_paid' => 'Faktura opłacona',
        'invoice_ksef' => 'Faktura czeka na KSeF', 'receipt_issued' => 'Paragon wystawiony', 'receipt_sent' => 'Paragon wysłany',
        'receipt_paid' => 'Paragon opłacony', 'receipt_ksef' => 'Paragon czeka na KSeF',
        'delivery_sent_invoice' => 'Wysłana do klienta: %s', 'delivery_sent_receipt' => 'Wysłany do klienta: %s',
        'delivery_none_invoice' => 'Jeszcze nie wysłana', 'delivery_none_receipt' => 'Jeszcze nie wysłany',
        'delivery_failed' => 'Wysyłka nieudana', 'unpaid_invoice' => 'Nieopłacona', 'unpaid_receipt' => 'Nieopłacony',
        'ksef_accepted' => 'KSeF: przyjęta (%s)', 'ksef_progress' => 'KSeF: wysłana, czeka na potwierdzenie',
        'state_none' => 'Brak dokumentu', 'state_queued' => 'W kolejce (limit iFirma)', 'click_to_issue' => 'kliknij, aby wystawić',
    );

    private function record(array $over = array())
    {
        return array_merge(array(
            'ifirma_document_number' => 'FV 12/2026', 'ifirma_document_status' => 'created', 'ifirma_document_state' => 'created',
            'ifirma_document_type' => 'fakturakraj', 'ifirma_document_delivery' => 'none', 'ifirma_document_sent_date' => '',
            'ifirma_document_ksef_status' => '', 'ifirma_document_ksef_number' => '', 'ifirma_document_error' => '',
        ), $over);
    }

    public function testASentUnpaidInvoiceInEnglish()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array('ifirma_document_delivery' => 'sent', 'ifirma_document_sent_date' => '2026-11-09')), array());

        $this->assertSame(array('Invoice sent: FV 12/2026', 'Sent to the customer: 2026-11-09', 'Unpaid'), $lines);
    }

    public function testAnIssuedReceiptInPolish()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array(
            'ifirma_document_number' => '7/2026', 'ifirma_document_type' => 'paragon',
        )), self::POLISH);

        $this->assertSame(array('Paragon wystawiony: 7/2026', 'Jeszcze nie wysłany', 'Nieopłacony'), $lines);
    }

    public function testASentInvoiceInPolish()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array('ifirma_document_delivery' => 'sent', 'ifirma_document_sent_date' => '2026-11-09')), self::POLISH);

        $this->assertSame(array('Faktura wysłana: FV 12/2026', 'Wysłana do klienta: 2026-11-09', 'Nieopłacona'), $lines);
    }

    public function testAPaidDocumentHasNoUnpaidLine()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array(
            'ifirma_document_status' => 'paid', 'ifirma_document_delivery' => 'sent', 'ifirma_document_sent_date' => '2026-11-09',
        )), self::POLISH);

        $this->assertSame(array('Faktura opłacona: FV 12/2026', 'Wysłana do klienta: 2026-11-09'), $lines);
    }

    public function testAnInvoiceWaitingForKsef()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array(
            'ifirma_document_delivery' => 'awaiting_ksef', 'ifirma_document_ksef_status' => 'WYSLANA',
        )), self::POLISH);

        $this->assertSame('Faktura czeka na KSeF: FV 12/2026', $lines[0]);
        $this->assertContains('KSeF: wysłana, czeka na potwierdzenie', $lines);
    }

    public function testAnAcceptedInvoiceNamesTheKsefNumber()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array(
            'ifirma_document_ksef_number' => '1234567890-20261109-ABCDEF123456-01', 'ifirma_document_delivery' => 'sent',
        )), array());

        $this->assertContains('KSeF: accepted (1234567890-20261109-ABCDEF123456-01)', $lines);
    }

    public function testAFailedDeliveryHasItsLineAndTheHeadlineStaysIssued()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array('ifirma_document_delivery' => 'failed')), array());

        $this->assertSame('Invoice issued: FV 12/2026', $lines[0]);
        $this->assertContains('Delivery failed', $lines);
    }

    public function testAKsefErrorAndARejectionAreDifferentLines()
    {
        $error = IfirmaOrderGridTooltip::lines($this->record(array('ifirma_document_ksef_status' => 'BLAD')), array());
        $rejected = IfirmaOrderGridTooltip::lines($this->record(array('ifirma_document_ksef_status' => 'ODRZUCONA')), array());

        $this->assertContains('KSeF: error', $error);
        $this->assertContains('KSeF: rejected by KSeF', $rejected);
    }

    public function testTheHeadlineCarriesTheIdentifierWhenNoNumberIsStored()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array('ifirma_document_number' => '123456')), array());

        $this->assertSame('Invoice issued: 123456', $lines[0]);
    }

    public function testAFakturaparagonIsAnInvoice()
    {
        $lines = IfirmaOrderGridTooltip::lines($this->record(array('ifirma_document_type' => 'fakturaparagon')), array());

        $this->assertSame('Invoice issued: FV 12/2026', $lines[0]);
    }

    public function testAnOrderWithoutARecord()
    {
        $en = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => ''), array());
        $pl = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => ''), self::POLISH);

        $this->assertSame(array('No document yet – click to issue'), $en);
        $this->assertSame(array('Brak dokumentu – kliknij, aby wystawić'), $pl);
    }

    public function testAFailedIssuanceNamesTheError()
    {
        $lines = IfirmaOrderGridTooltip::lines(array(
            'ifirma_document_number' => '', 'ifirma_document_state' => 'error', 'ifirma_document_error' => 'Kontrahent nie istnieje',
        ), array());

        $this->assertSame(array('Issuance failed: Kontrahent nie istnieje – click to issue'), $lines);
    }

    public function testALongErrorIsCutAtOneHundredCharactersWithAnEllipsis()
    {
        $lines = IfirmaOrderGridTooltip::lines(array(
            'ifirma_document_number' => '', 'ifirma_document_state' => 'error', 'ifirma_document_error' => str_repeat('ż', 101),
        ), array());

        $this->assertSame('Issuance failed: ' . str_repeat('ż', 100) . "… – click to issue", $lines[0]);
    }

    public function testAnErrorOfExactlyOneHundredCharactersIsNotCut()
    {
        $lines = IfirmaOrderGridTooltip::lines(array(
            'ifirma_document_number' => '', 'ifirma_document_state' => 'error', 'ifirma_document_error' => str_repeat('a', 100),
        ), array());

        $this->assertSame('Issuance failed: ' . str_repeat('a', 100) . " – click to issue", $lines[0]);
    }

    public function testAQueuedOrder()
    {
        $en = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => 'queued'), array());
        $pl = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => 'queued'), self::POLISH);

        $this->assertSame(array("Queued (iFirma limit) – click to issue"), $en);
        $this->assertSame(array("W kolejce (limit iFirma) – kliknij, aby wystawić"), $pl);
    }

    public function testTheOtherNotIssuedStates()
    {
        $unknown = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => 'unknown'), array());
        $suspected = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => 'duplicate_suspected'), array());
        $held = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => 'held'), array());

        $this->assertSame("Unknown outcome – click to issue", $unknown[0]);
        $this->assertSame("Suspected duplicate – click to issue", $suspected[0]);
        $this->assertStringStartsWith('Held', $held[0]);
    }

    public function testAStateWithoutAWordFallsBackToTheRawState()
    {
        $lines = IfirmaOrderGridTooltip::lines(array('ifirma_document_number' => '', 'ifirma_document_state' => 'weird'), array());

        $this->assertSame("weird – click to issue", $lines[0]);
    }
}
