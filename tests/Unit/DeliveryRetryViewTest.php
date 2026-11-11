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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDeliveryRetryView.php';

/**
 * The retry line of a failed delivery (spec receipt-delivery "Retry state shown to the employee", receipt-documents-list
 * "Delivery retry shown in the list").
 */
class DeliveryRetryViewTest extends TestCase
{
    public function testARetryDueLaterIsShownWithTheAttemptsUsedAndTheTime()
    {
        $view = new IfirmaDeliveryRetryView();

        $this->assertSame('Attempt 1 of 3, next attempt at 2026-11-10 10:15', $view->text('failed', 1, '2026-11-10 10:15:42'));
    }

    public function testUsedUpAttemptsLeaveNoAutomaticAttempt()
    {
        $this->assertSame('No automatic attempt remains', (new IfirmaDeliveryRetryView())->text('failed', 3, null));
    }

    public function testAFailureWithAttemptsLeftButNothingDueWillNotBeRetried()
    {
        $this->assertSame('No automatic attempt will be made', (new IfirmaDeliveryRetryView())->text('failed', 1, ''));
    }

    public function testAnOldFailureHasNothingScheduled()
    {
        $this->assertSame('No automatic attempt is scheduled', (new IfirmaDeliveryRetryView())->text('failed', 0, '0000-00-00 00:00:00'));
    }

    public function testAZeroDateCountsAsNoDueTime()
    {
        $this->assertSame('No automatic attempt is scheduled', (new IfirmaDeliveryRetryView())->text('failed', 0, '0000-00-00 00:00:00'));
    }

    /**
     * @dataProvider deliveriesThatDidNotFail
     */
    public function testADeliveryThatDidNotFailHasNoRetryLine($status)
    {
        $this->assertSame('', (new IfirmaDeliveryRetryView())->text($status, 2, '2026-11-10 10:15:00'));
    }

    public function deliveriesThatDidNotFail()
    {
        return array(array('none'), array('sent'), array('skipped'), array('awaiting_ksef'), array(''));
    }

    public function testTheWordsAreReplaceableForAnotherLanguage()
    {
        $view = new IfirmaDeliveryRetryView(array('retry_exhausted' => 'Brak kolejnych prób'));

        $this->assertSame('Brak kolejnych prób', $view->text('failed', 3, null));
        $this->assertSame('No automatic attempt will be made', $view->text('failed', 2, null));
    }

    public function testTheWordsOfTheModuleAreTranslatedThroughIt()
    {
        $module = new class {
            public function l($string, $source)
            {
                return 'PL:' . $source . ':' . $string;
            }
        };

        $words = IfirmaDeliveryRetryView::translatedWords($module);

        $this->assertSame(array_keys(IfirmaDeliveryRetryView::defaultWords()), array_keys($words));
        $this->assertSame('PL:ifirmadeliveryretryview:No automatic attempt remains', $words['retry_exhausted']);
    }
}
