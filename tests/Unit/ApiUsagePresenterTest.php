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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiUsagePresenter.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRequestKind.php';

/**
 * Given the module's own request counts, when the configuration page shows them, then the minute and the day appear
 * next to their caps, every kind appears with its count (zeros too), a running back-off is named, and everything is
 * escaped (spec api-usage-accounting "Usage shown on the configuration page").
 */
class ApiUsagePresenterTest extends TestCase
{
    private function labels()
    {
        $kinds = array();
        foreach (IfirmaRequestKind::all() as $kind) {
            $kinds[$kind] = 'Label of ' . $kind;
        }

        return array(
            'title' => 'Module requests',
            'intro' => 'Requests the module sent to iFirma.',
            'window_minute' => 'Current minute',
            'window_day' => 'Today',
            'of' => '%1$d of %2$d',
            'by_kind' => 'Today by kind of request',
            'kinds' => $kinds,
            'other_clients' => 'Other clients of the account',
            'backoff' => 'No requests are sent until %s.',
        );
    }

    private function windows($minute = 0, $day = 0)
    {
        return array('minute' => array('used' => $minute, 'cap' => 80), 'day' => array('used' => $day, 'cap' => 14000));
    }

    private function zeros(array $changes = array())
    {
        $counts = array_fill_keys(IfirmaRequestKind::all(), 0);

        return array_merge($counts, $changes);
    }

    private function render(array $windows, array $byKind, $backoffUntil = 0)
    {
        return (new IfirmaApiUsagePresenter($this->labels()))->render($windows, $byKind, $backoffUntil);
    }

    public function testTheMinuteAndTheDayAreShownNextToTheirCaps()
    {
        $html = $this->render($this->windows(6, 250), $this->zeros(array('pdf' => 40)));

        $this->assertStringContainsString('Current minute', $html);
        $this->assertStringContainsString('6 of 80', $html);
        $this->assertStringContainsString('250 of 14000', $html);
    }

    public function testEveryKindIsShownWithItsCountAndZerosToo()
    {
        $html = $this->render($this->windows(6, 250), $this->zeros(array('pdf' => 40, 'status' => 210)));

        foreach (IfirmaRequestKind::all() as $kind) {
            $this->assertStringContainsString('Label of ' . $kind, $html, $kind);
        }
        $this->assertMatchesRegularExpression('#Label of pdf</td>\s*<td[^>]*>40</td>#', $html);
        $this->assertMatchesRegularExpression('#Label of status</td>\s*<td[^>]*>210</td>#', $html);
        $this->assertMatchesRegularExpression('#Label of ksef</td>\s*<td[^>]*>0</td>#', $html);
    }

    public function testNothingSentTodayShowsEveryCountAsZero()
    {
        $html = $this->render($this->windows(), $this->zeros());

        $this->assertStringContainsString('0 of 80', $html);
        $this->assertStringContainsString('0 of 14000', $html);
        $this->assertStringNotContainsString('Other clients of the account', $html);
        $this->assertStringNotContainsString('No requests are sent until', $html);
    }

    public function testTheDifferenceToTheKindsIsShownAsRequestsOfOtherClients()
    {
        // iFirma's own figure raised the day counter to 1200 while this module sent 300
        $html = $this->render($this->windows(2, 1200), $this->zeros(array('status' => 200, 'pdf' => 100)));

        $this->assertMatchesRegularExpression('#Other clients of the account</td>\s*<td[^>]*>900</td>#', $html);
    }

    public function testAKindSumAboveTheDayCountShowsNoOtherClients()
    {
        $html = $this->render($this->windows(0, 3), $this->zeros(array('status' => 5)));

        $this->assertStringNotContainsString('Other clients of the account', $html);
    }

    public function testARunningBackoffNamesTheTimeNothingIsSentUntil()
    {
        $until = mktime(10, 30, 15, 11, 1, 2026);

        $html = $this->render($this->windows(), $this->zeros(), $until);

        $this->assertStringContainsString('No requests are sent until ' . date('Y-m-d H:i:s', $until) . '.', $html);
    }

    public function testAnUnknownKindIsShownByItsCodeEscaped()
    {
        $html = $this->render($this->windows(), array('<script>' => 3));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEveryLabelIsEscaped()
    {
        $labels = $this->labels();
        $labels['title'] = '<b>x</b>';
        $labels['kinds']['pdf'] = '"><img src=x>';

        $html = (new IfirmaApiUsagePresenter($labels))->render($this->windows(), $this->zeros(), 0);

        $this->assertStringNotContainsString('<b>x', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testRenderingNeedsNoDatabaseOrRequest()
    {
        $transport = new ScriptedTransport();
        IfirmaApiClient::setDefaultTransport($transport);

        $this->render($this->windows(1, 1), $this->zeros());

        $this->assertSame(array(), $transport->calls);
        IfirmaApiClient::setDefaultTransport(null);
    }
}
