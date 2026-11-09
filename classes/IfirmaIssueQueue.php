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

require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaPayloadBuilder.php';

/**
 * Records that an order waits for its document, before any request to iFirma is made. The record is the
 * queue: the admin panel, the Orders-list cell, the filters and the needs-attention list all read the state
 * of that one row.
 */
class IfirmaIssueQueue
{
    /**
     * Records the order as queued, with the refusal message (empty for an order that just reached the trigger
     * status). A document that is already issued, an order held by a data-change request, and the two states that
     * need a person (an unknown outcome and a suspected duplicate) keep their state: the run that follows decides
     * about them as it always did. A record that is already queued is written only to give a fresh entry its
     * first message, so a record keeps its place in the queue.
     *
     * @param Order  $order
     * @param string $message
     * @return IfirmaReceipt the order's record
     */
    public function enqueue(Order $order, $message = '')
    {
        $row = IfirmaReceipt::getByOrder((int) $order->id);
        if ($row instanceof IfirmaReceipt && (string) $row->status === IfirmaReceipt::STATUS_QUEUED) {
            return $this->giveFirstMessage($row, (string) $message);
        }
        if ($row instanceof IfirmaReceipt && $this->keepsItsState($row)) {
            return $row;
        }
        if (!$row instanceof IfirmaReceipt) {
            $row = new IfirmaReceipt();
        }
        $row->id_order = (int) $order->id;
        $row->id_customer = (int) $order->id_customer;
        $row->id_shop = (int) $order->id_shop;
        $row->order_reference = IfirmaPayloadBuilder::buildOrderReference($order);
        $row->markQueued((string) $message);
        $row->save();

        return $row;
    }

    /**
     * A fresh queue entry has no message yet; the first refusal gives it one. Later refusals leave the record
     * alone, so it keeps its place in the queue.
     *
     * @param IfirmaReceipt $row   a queued record
     * @param string        $message
     * @return IfirmaReceipt
     */
    private function giveFirstMessage(IfirmaReceipt $row, $message)
    {
        if ((string) $row->last_error === '' && $message !== '') {
            $row->last_error = $message;
            $row->save();
        }

        return $row;
    }

    /**
     * @param IfirmaReceipt $row
     * @return bool
     */
    private function keepsItsState(IfirmaReceipt $row)
    {
        return $row->isIssued() || in_array((string) $row->status, array(
            IfirmaReceipt::STATUS_HELD,
            IfirmaReceipt::STATUS_UNKNOWN,
            IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED,
        ), true);
    }
}
