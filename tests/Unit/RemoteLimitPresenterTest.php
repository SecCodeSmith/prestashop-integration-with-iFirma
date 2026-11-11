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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRemoteLimitPresenter.php';

/**
 * Given the stored reading of the account's limit and the outcome of a click, when the configuration page shows the
 * limit box, then it states what is known, what is not, and why a check failed, with no key and no remote text (spec
 * ifirma-api-compliance "Optional limit visibility", "Remaining limit checked on demand", "A failed or refused limit
 * check is reported").
 */
class RemoteLimitPresenterTest extends TestCase
{
    /** @var int 2026-11-01 10:02:00 */
    private $now;

    protected function setUp(): void
    {
        $this->now = mktime(10, 2, 0, 11, 1, 2026);
    }

    private function labels()
    {
        return array(
            'title' => 'iFirma request limit',
            'not_checked' => 'The remaining limit was not checked yet.',
            'reading' => 'Used %1$d of %2$d requests, %3$d remaining (read %4$s).',
            'earlier_day' => 'Last reading, from %1$s: used %2$d of %3$d requests. It is not today\'s figure.',
            'nothing_left' => 'No request is left: the module sends none until tomorrow.',
            'unavailable' => 'The limit is currently unavailable.',
            'refused' => array(
                'minute' => 'Not checked: the minute limit of the module is reached.',
                'day' => 'Not checked: the day limit of the module is reached.',
                'backoff' => 'Not checked: iFirma asked the module to wait.',
                'auth' => 'Not checked: calls are paused after a rejected key.',
            ),
            'button' => 'Check the remaining iFirma limit',
            'one_request' => 'One click sends one request.',
            'no_key' => 'Enter the abonent key to read the limit.',
        );
    }

    private function presenter()
    {
        $now = $this->now;

        return new IfirmaRemoteLimitPresenter($this->labels(), '/admin?configure=ifirma&token=tok1', 'tok1', function () use ($now) {
            return $now;
        });
    }

    private function reading($used, $granted, $at = null, $day = null)
    {
        $at = $at !== null ? $at : $this->now;

        return new IfirmaLimitReading($day !== null ? $day : date('Ymd', $at), $used, $granted, $at);
    }

    public function testAStoredReadingShowsUsedGrantedRemainingAndTheTime()
    {
        $at = mktime(10, 2, 0, 11, 1, 2026);

        $html = $this->presenter()->render($this->reading(1200, 15000, $at));

        $this->assertStringContainsString('Used 1200 of 15000 requests, 13800 remaining (read 2026-11-01 10:02).', $html);
    }

    public function testNoReadingSaysTheLimitWasNotCheckedYetAndOffersTheButton()
    {
        $html = $this->presenter()->render(null);

        $this->assertStringContainsString('The remaining limit was not checked yet.', $html);
        $this->assertStringContainsString('name="submitIfirmaCheckLimit"', $html);
        $this->assertStringContainsString('Check the remaining iFirma limit', $html);
    }

    public function testAReadingOfAnotherDayIsShownWithItsDateAndNotAsTodays()
    {
        $yesterday = $this->now - 86400;

        $html = $this->presenter()->render($this->reading(900, 15000, $yesterday));

        $this->assertStringContainsString('Last reading, from 2026-10-31: used 900 of 15000 requests. It is not today&#039;s figure.', $html);
        $this->assertStringNotContainsString('remaining (read', $html);
    }

    public function testNothingLeftStatesThatNoRequestIsSentUntilTomorrow()
    {
        $html = $this->presenter()->render($this->reading(15000, 15000));

        $this->assertStringContainsString('Used 15000 of 15000 requests, 0 remaining', $html);
        $this->assertStringContainsString('No request is left: the module sends none until tomorrow.', $html);
    }

    public function testAnOverusedReadingNeverShowsNegativeRemaining()
    {
        $html = $this->presenter()->render($this->reading(15100, 15000));

        $this->assertStringContainsString('0 remaining', $html);
        $this->assertStringNotContainsString('-100', $html);
    }

    public function testACheckedOutcomeShowsTheFreshReading()
    {
        $reading = $this->reading(1200, 15000);

        $html = $this->presenter()->render($reading, IfirmaLimitCheck::checked($reading));

        $this->assertStringContainsString('Used 1200 of 15000 requests, 13800 remaining', $html);
        $this->assertStringContainsString('alert-success', $html);
    }

    /**
     * @return array[] window, text
     */
    public static function refusals()
    {
        return array(
            'minute' => array('minute', 'Not checked: the minute limit of the module is reached.'),
            'day' => array('day', 'Not checked: the day limit of the module is reached.'),
            'backoff' => array('backoff', 'Not checked: iFirma asked the module to wait.'),
            'auth' => array('auth', 'Not checked: calls are paused after a rejected key.'),
        );
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusalNamesTheWindowAndKeepsTheEarlierReading($window, $text)
    {
        $html = $this->presenter()->render($this->reading(1200, 15000), IfirmaLimitCheck::refused($window, 'detail'));

        $this->assertStringContainsString($text, $html);
        $this->assertStringContainsString('Used 1200 of 15000 requests', $html);
    }

    public function testARefusalWithoutAnEarlierReadingSaysTheLimitIsNotCheckedYet()
    {
        $html = $this->presenter()->render(null, IfirmaLimitCheck::refused('minute', ''));

        $this->assertStringContainsString('Not checked: the minute limit of the module is reached.', $html);
        $this->assertStringContainsString('The remaining limit was not checked yet.', $html);
    }

    public function testAnUnavailableOutcomeIsShortAndNeverShowsTheDetail()
    {
        $detail = 'iFirma error (Kod 401): <b>secret-key-abc</b> raw remote text';

        $html = $this->presenter()->render(null, IfirmaLimitCheck::unavailable($detail));

        $this->assertStringContainsString('The limit is currently unavailable.', $html);
        $this->assertStringNotContainsString('secret-key-abc', $html);
        $this->assertStringNotContainsString('raw remote text', $html);
        $this->assertStringNotContainsString('Kod', $html);
    }

    public function testARefusalWithAnUnknownWindowFallsBackToTheUnavailableNotice()
    {
        $html = $this->presenter()->render(null, IfirmaLimitCheck::refused('mystery', 'x'));

        $this->assertStringContainsString('The limit is currently unavailable.', $html);
    }

    public function testWithoutTheKeyTheHintReplacesTheButton()
    {
        $html = $this->presenter()->renderWithoutKey();

        $this->assertStringContainsString('Enter the abonent key to read the limit.', $html);
        $this->assertStringNotContainsString('submitIfirmaCheckLimit', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testTheButtonIsAPostFormCarryingTheTokenOfThePage()
    {
        $html = $this->presenter()->render(null);

        $this->assertStringContainsString('<form method="post" action="/admin?configure=ifirma&amp;token=tok1"', $html);
        $this->assertStringContainsString('<input type="hidden" name="token" value="tok1">', $html);
        $this->assertStringContainsString('One click sends one request.', $html);
    }

    public function testEveryValueIsEscaped()
    {
        $labels = $this->labels();
        $labels['title'] = '<script>t</script>';
        $labels['reading'] = '<i>%1$d</i> %2$d %3$d %4$s';
        $presenter = new IfirmaRemoteLimitPresenter($labels, '/a"><x', 'to"ken', function () {
            return $this->now;
        });

        $html = $presenter->render($this->reading(1, 2));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('"><x', $html);
        $this->assertStringNotContainsString('to"ken', $html);
    }
}
