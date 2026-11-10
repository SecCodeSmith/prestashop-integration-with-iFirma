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

require_once dirname(__FILE__) . '/IfirmaLimitCheck.php';
require_once dirname(__FILE__) . '/IfirmaLimitReading.php';

/**
 * The limit box of the configuration page: the stored reading of the account's day limit (or that none was taken, or
 * that it is from an earlier day), the outcome of the employee's last click and the button that asks iFirma. It only
 * formats what it is given and sends no request. The detail of a failed check is for the log: it never reaches the page.
 *
 * The labels arrive already translated (the translation scanner only finds the literals in ifirma.php).
 */
class IfirmaRemoteLimitPresenter
{
    /** The name of the submit button the module's configuration page dispatches on. */
    const SUBMIT = 'submitIfirmaCheckLimit';

    /** @var array */
    private $labels;

    /** @var string */
    private $action;

    /** @var string */
    private $token;

    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /**
     * @param array         $labels 'title', 'not_checked', 'reading' (%1$d used, %2$d granted, %3$d remaining, %4$s time),
     *                              'earlier_day' (%1$s date, %2$d used, %3$d granted), 'nothing_left', 'unavailable',
     *                              'refused' (window => sentence for minute, day, backoff and auth), 'button',
     *                              'one_request' and 'no_key'
     * @param string        $action the URL the button posts to, with the back-office token
     * @param string        $token  the back-office token, as a hidden field
     * @param callable|null $clock
     */
    public function __construct(array $labels, $action, $token, $clock = null)
    {
        $this->labels = $labels;
        $this->action = (string) $action;
        $this->token = (string) $token;
        $this->clock = $clock;
    }

    /**
     * @param IfirmaLimitReading|null $stored the reading stored last, null when there is none
     * @param IfirmaLimitCheck|null   $check  the outcome of the click that produced this page, null on a plain view
     * @return string HTML
     */
    public function render($stored, $check = null)
    {
        return $this->panel(
            $this->outcomeNotice($check) . $this->readingBlock($stored, $check)
            . $this->form()
        );
    }

    /**
     * @return string HTML, the hint to enter the abonent key; no button
     */
    public function renderWithoutKey()
    {
        return $this->panel('<p class="text-muted">' . $this->esc($this->labels['no_key']) . '</p>');
    }

    /**
     * @param string $body
     * @return string HTML
     */
    private function panel($body)
    {
        return '<div class="panel" id="ifirma-limit-box">'
            . '<div class="panel-heading"><i class="icon-signal"></i> ' . $this->esc($this->labels['title']) . '</div>'
            . $body . '</div>';
    }

    /**
     * @param IfirmaLimitCheck|null $check
     * @return string HTML of the notice of a refused or failed check, '' otherwise
     */
    private function outcomeNotice($check)
    {
        if ($check === null || $check->status() === IfirmaLimitCheck::CHECKED) {
            return '';
        }
        $text = $this->labels['unavailable'];
        if ($check->status() === IfirmaLimitCheck::REFUSED && isset($this->labels['refused'][$check->window()])) {
            $text = $this->labels['refused'][$check->window()];
        }

        return '<div class="alert alert-warning" role="alert">' . $this->esc($text) . '</div>';
    }

    /**
     * @param IfirmaLimitReading|null $stored
     * @param IfirmaLimitCheck|null   $check
     * @return string HTML
     */
    private function readingBlock($stored, $check)
    {
        if ($stored === null) {
            return '<p class="text-muted">' . $this->esc($this->labels['not_checked']) . '</p>';
        }
        if ($stored->day() !== date('Ymd', $this->now())) {
            return '<p>' . $this->esc(sprintf(
                $this->labels['earlier_day'],
                $this->dayText($stored->day()),
                $stored->used(),
                $stored->granted()
            )) . '</p>';
        }

        $fresh = $check !== null && $check->status() === IfirmaLimitCheck::CHECKED;
        $line = sprintf(
            $this->labels['reading'],
            $stored->used(),
            $stored->granted(),
            $stored->remaining(),
            date('Y-m-d H:i', $stored->at())
        );
        $html = '<div class="alert ' . ($fresh ? 'alert-success' : 'alert-info') . '">' . $this->esc($line) . '</div>';
        if ($stored->remaining() === 0) {
            $html .= '<div class="alert alert-warning">' . $this->esc($this->labels['nothing_left']) . '</div>';
        }

        return $html;
    }

    /**
     * @return string HTML of the form with the check button
     */
    private function form()
    {
        return '<form method="post" action="' . $this->esc($this->action) . '" class="form-inline">'
            . '<input type="hidden" name="token" value="' . $this->esc($this->token) . '">'
            . '<button type="submit" class="btn btn-default" name="' . self::SUBMIT . '" value="1">'
            . $this->esc($this->labels['button']) . '</button>'
            . '<p class="help-block">' . $this->esc($this->labels['one_request']) . '</p>'
            . '</form>';
    }

    /**
     * @param string $day Ymd
     * @return string Y-m-d
     */
    private function dayText($day)
    {
        return substr($day, 0, 4) . '-' . substr($day, 4, 2) . '-' . substr($day, 6, 2);
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }

    /**
     * @param string $text
     * @return string
     */
    private function esc($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
