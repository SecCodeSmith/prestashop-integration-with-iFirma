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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaLimitCheckAction.php';

/**
 * Given the employee's request to check the remaining limit, when it is not allowed nothing is asked, and when iFirma
 * gives no answer the detail goes to the log only (spec ifirma-api-compliance "Limit check is a protected action" and
 * "A failed or refused limit check is reported").
 */
class LimitCheckActionTest extends TestCase
{
    /** @var int how many times iFirma was asked */
    private $asked = 0;

    /** @var string[] */
    private $logged = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->asked = 0;
        $this->logged = array();
    }

    private function action($allowed, $answer)
    {
        $self = $this;
        $limit = new IfirmaRemoteLimit(function () use ($self, $answer) {
            $self->asked++;

            return $answer;
        }, null, function () {
            return array('window' => '', 'detail' => "HTTP 401\nreturned by iFirma. " . str_repeat('x', 400));
        });

        return new IfirmaLimitCheckAction($limit, function () use ($allowed) {
            return $allowed;
        }, function ($message) use ($self) {
            $self->logged[] = $message;
        });
    }

    public function testAnAllowedCheckAsksIFirmaOnceAndReportsTheReading()
    {
        $check = $this->action(true, array('used' => 10, 'granted' => 100))->run();

        $this->assertSame(1, $this->asked);
        $this->assertSame(IfirmaLimitCheck::CHECKED, $check->status());
        $this->assertSame(array(), $this->logged);
    }

    public function testACheckThatIsNotAllowedAsksNothingAndReportsNothing()
    {
        $check = $this->action(false, array('used' => 10, 'granted' => 100))->run();

        $this->assertNull($check);
        $this->assertSame(0, $this->asked);
        $this->assertNull(IfirmaRemoteLimit::stored());
    }

    public function testAnUnavailableLimitIsLoggedOnceWithAShortSingleLineDetail()
    {
        $check = $this->action(true, null)->run();

        $this->assertSame(IfirmaLimitCheck::UNAVAILABLE, $check->status());
        $this->assertCount(1, $this->logged);
        $this->assertStringStartsWith('iFirma: the remaining limit could not be read: HTTP 401 returned by iFirma. ', $this->logged[0]);
        $this->assertStringNotContainsString("\n", $this->logged[0]);
        $this->assertLessThan(300, strlen($this->logged[0]));
    }

    public function testARefusalIsNotLoggedAsAFailure()
    {
        $self = $this;
        $limit = new IfirmaRemoteLimit(function () {
            return null;
        }, null, function () {
            return array('window' => 'minute', 'detail' => 'refused');
        });
        $action = new IfirmaLimitCheckAction($limit, function () {
            return true;
        }, function ($message) use ($self) {
            $self->logged[] = $message;
        });

        $check = $action->run();

        $this->assertSame(IfirmaLimitCheck::REFUSED, $check->status());
        $this->assertSame(array(), $this->logged);
    }
}
