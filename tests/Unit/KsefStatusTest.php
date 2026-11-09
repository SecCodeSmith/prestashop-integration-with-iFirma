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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaKsefStatus.php';

/**
 * How a stored or reported KSeF status is read (design D5), and the read of a document's state from iFirma.
 */
class KsefStatusTest extends TestCase
{
    /**
     * @return array[] label => array(status, number, expected class)
     */
    public function statuses()
    {
        return array(
            'accepted status' => array('PRZYJETA', '', IfirmaKsefStatus::ACCEPTED),
            'a number without a status' => array('', 'NIP-20261101-ABC', IfirmaKsefStatus::ACCEPTED),
            'a number beats a sent status' => array('WYSLANA', 'NIP-20261101-ABC', IfirmaKsefStatus::ACCEPTED),
            'error' => array('BLAD', '', IfirmaKsefStatus::REJECTED),
            'rejected' => array('ODRZUCONA', '', IfirmaKsefStatus::REJECTED),
            'sent' => array('WYSLANA', '', IfirmaKsefStatus::IN_PROGRESS),
            'scheduled' => array('ZAPLANOWANA', '', IfirmaKsefStatus::IN_PROGRESS),
            'an unknown value is in progress, so it causes neither a resubmission nor a delivery' => array('PRZETWARZANIE', '', IfirmaKsefStatus::IN_PROGRESS),
            'empty' => array('', '', IfirmaKsefStatus::NONE),
            'blank' => array('  ', ' ', IfirmaKsefStatus::NONE),
            'null' => array(null, null, IfirmaKsefStatus::NONE),
            'lower case is read like upper case' => array('przyjeta', '', IfirmaKsefStatus::ACCEPTED),
        );
    }

    /**
     * @dataProvider statuses
     */
    public function testClassify($status, $number, $expected)
    {
        $this->assertSame($expected, IfirmaKsefStatus::classify($status, $number));
    }

    public function testTheShortcutsAgreeWithTheClassification()
    {
        $this->assertTrue(IfirmaKsefStatus::isAccepted('PRZYJETA', ''));
        $this->assertFalse(IfirmaKsefStatus::isAccepted('WYSLANA', ''));
        $this->assertTrue(IfirmaKsefStatus::isRejected('ODRZUCONA', ''));
        $this->assertTrue(IfirmaKsefStatus::isInProgress('ZAPLANOWANA', ''));
        $this->assertTrue(IfirmaKsefStatus::isEmpty('', ''));
        $this->assertFalse(IfirmaKsefStatus::isEmpty('WYSLANA', ''));
    }
}
