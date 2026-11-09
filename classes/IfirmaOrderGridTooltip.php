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

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/IfirmaKsefStatus.php';

/**
 * The lines of the tooltip of the iFirma cell in the Orders list. Pure: a grid record and a table of words in, lines
 * out. The words are the module's (they are translated there); English defaults stand in for a missing one.
 *
 * An issued document: a headline "<kind and state>: <number>", then the delivery, "unpaid" when it is not paid, and the
 * KSeF line. A record that is not issued: the state in words, the short error of a failed issuance, and the hint that a
 * click issues the document.
 */
class IfirmaOrderGridTooltip
{
    const ERROR_LIMIT = 100;
    const SEPARATOR = ' – ';

    /**
     * @return string[] key => English word; a key ending in _invoice or _receipt has one word for each kind so that
     *                  languages with grammatical gender can agree
     */
    public static function defaultWords()
    {
        return array(
            'invoice_issued' => 'Invoice issued', 'invoice_sent' => 'Invoice sent', 'invoice_paid' => 'Invoice paid',
            'invoice_ksef' => 'Invoice waiting for KSeF',
            'receipt_issued' => 'Receipt issued', 'receipt_sent' => 'Receipt sent', 'receipt_paid' => 'Receipt paid',
            'receipt_ksef' => 'Receipt waiting for KSeF',
            'delivery_sent_invoice' => 'Sent to the customer: %s', 'delivery_sent_receipt' => 'Sent to the customer: %s',
            'delivery_none_invoice' => 'Not sent yet', 'delivery_none_receipt' => 'Not sent yet',
            'delivery_failed' => 'Delivery failed',
            'unpaid_invoice' => 'Unpaid', 'unpaid_receipt' => 'Unpaid',
            'ksef_accepted' => 'KSeF: accepted (%s)', 'ksef_progress' => 'KSeF: sent, awaiting confirmation',
            'ksef_waiting' => 'KSeF: waiting to be sent', 'ksef_error' => 'KSeF: error', 'ksef_rejected' => 'KSeF: rejected by KSeF',
            'state_none' => 'No document yet', 'state_error' => 'Issuance failed', 'state_unknown' => 'Unknown outcome',
            'state_duplicate_suspected' => 'Suspected duplicate', 'state_held' => 'Held (data change request)',
            'state_queued' => 'Queued (iFirma limit)', 'click_to_issue' => 'click to issue',
        );
    }

    /**
     * @param array $record a grid record carrying the IfirmaOrderGridIndicator::FIELD_* values
     * @param array $words  the module's words; missing keys fall back to the English defaults
     * @return string[] the lines, unescaped
     */
    public static function lines(array $record, array $words)
    {
        $words = array_merge(self::defaultWords(), $words);
        $number = isset($record['ifirma_document_number']) ? trim((string) $record['ifirma_document_number']) : '';

        return $number === '' ? self::notIssued($record, $words) : self::issued($record, $words, $number);
    }

    /**
     * @param array    $record
     * @param string[] $words
     * @param string   $number
     * @return string[]
     */
    private static function issued(array $record, array $words, $number)
    {
        $kind = self::kindOf(self::value($record, 'ifirma_document_type'));
        $delivery = self::value($record, 'ifirma_document_delivery');
        $paid = self::value($record, 'ifirma_document_status') === 'paid';
        $sent = $delivery === 'sent';
        $ksefStatus = self::value($record, 'ifirma_document_ksef_status');
        $ksefNumber = trim(self::value($record, 'ifirma_document_ksef_number'));

        if ($paid) {
            $state = 'paid';
        } elseif ($sent) {
            $state = 'sent';
        } elseif ($delivery === 'awaiting_ksef') {
            $state = 'ksef';
        } else {
            $state = 'issued';
        }
        $lines = array($words[$kind . '_' . $state] . ': ' . $number);

        if ($sent && self::value($record, 'ifirma_document_sent_date') !== '') {
            $lines[] = sprintf($words['delivery_sent_' . $kind], self::value($record, 'ifirma_document_sent_date'));
        } elseif ($delivery === 'failed') {
            $lines[] = $words['delivery_failed'];
        } elseif (!$sent) {
            $lines[] = $words['delivery_none_' . $kind];
        }
        if (!$paid) {
            $lines[] = $words['unpaid_' . $kind];
        }
        $ksefLine = self::ksefLine($ksefStatus, $ksefNumber, $delivery, $words);
        if ($ksefLine !== '') {
            $lines[] = $ksefLine;
        }

        return $lines;
    }

    /**
     * @param string   $status
     * @param string   $number
     * @param string   $delivery
     * @param string[] $words
     * @return string '' when the document has no KSeF state and does not wait for it
     */
    private static function ksefLine($status, $number, $delivery, array $words)
    {
        $class = IfirmaKsefStatus::classify($status, $number);
        if ($class === IfirmaKsefStatus::ACCEPTED) {
            return sprintf($words['ksef_accepted'], $number !== '' ? $number : $status);
        }
        if ($class === IfirmaKsefStatus::IN_PROGRESS) {
            return $words['ksef_progress'];
        }
        if ($class === IfirmaKsefStatus::REJECTED) {
            return strtoupper($status) === IfirmaKsefStatus::STATUS_REJECTED ? $words['ksef_rejected'] : $words['ksef_error'];
        }

        return $delivery === 'awaiting_ksef' ? $words['ksef_waiting'] : '';
    }

    /**
     * @param array    $record
     * @param string[] $words
     * @return string[]
     */
    private static function notIssued(array $record, array $words)
    {
        $state = self::value($record, 'ifirma_document_state');
        $key = $state === '' ? 'state_none' : 'state_' . $state;
        $text = isset($words[$key]) ? $words[$key] : $state;
        $error = self::value($record, 'ifirma_document_error');
        if ($state === 'error' && $error !== '') {
            $text .= ': ' . (mb_strlen($error, 'UTF-8') > self::ERROR_LIMIT ? mb_substr($error, 0, self::ERROR_LIMIT, 'UTF-8') . '…' : $error);
        }

        return array($text . self::SEPARATOR . $words['click_to_issue']);
    }

    /**
     * @param string $type a document type
     * @return string invoice for every faktura type, receipt for the paragon types
     */
    private static function kindOf($type)
    {
        return strpos($type, 'paragon') === 0 ? 'receipt' : 'invoice';
    }

    /**
     * @param array  $record
     * @param string $key
     * @return string
     */
    private static function value(array $record, $key)
    {
        return isset($record[$key]) && !is_array($record[$key]) ? (string) $record[$key] : '';
    }
}
