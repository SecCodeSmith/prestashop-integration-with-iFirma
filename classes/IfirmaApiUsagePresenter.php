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

/**
 * The "Module requests" box of the configuration page: the module's own requests of the current minute and of today
 * next to their caps, today's count per kind, the requests of other clients of the account that iFirma's own figure
 * implies, and the time a running back-off ends. It only formats what it is given and sends no request.
 *
 * The labels arrive already translated (the translation scanner only finds the literals in ifirma.php).
 */
class IfirmaApiUsagePresenter
{
    const WINDOW_MINUTE = 'minute';
    const WINDOW_DAY = 'day';

    /** @var array */
    private $labels;

    /**
     * @param array $labels 'title', 'intro', 'window_minute', 'window_day', 'of' (a "%1$d of %2$d" format),
     *                      'by_kind', 'kinds' (kind => label), 'other_clients' and 'backoff' (a format with %s for the time)
     */
    public function __construct(array $labels)
    {
        $this->labels = $labels;
    }

    /**
     * @param array $windows      array('minute' => array('used' => int, 'cap' => int), 'day' => array(...))
     * @param array $byKind       kind => requests sent today
     * @param int   $backoffUntil unix time a running back-off ends, 0 when none runs
     * @return string HTML
     */
    public function render(array $windows, array $byKind, $backoffUntil)
    {
        return '<div class="panel" id="ifirma-usage-box">'
            . '<div class="panel-heading"><i class="icon-bar-chart"></i> ' . $this->esc($this->labels['title']) . '</div>'
            . '<p class="help-block">' . $this->esc($this->labels['intro']) . '</p>'
            . $this->windowsTable($windows)
            . $this->backoffNotice((int) $backoffUntil)
            . $this->kindsTable($byKind, (int) $windows[self::WINDOW_DAY]['used'])
            . '</div>';
    }

    /**
     * @param array $windows
     * @return string HTML
     */
    private function windowsTable(array $windows)
    {
        $rows = '';
        foreach (array(self::WINDOW_MINUTE, self::WINDOW_DAY) as $name) {
            $rows .= $this->row(
                $this->labels['window_' . $name],
                sprintf($this->labels['of'], (int) $windows[$name]['used'], (int) $windows[$name]['cap'])
            );
        }

        return '<table class="table" id="ifirma-usage-windows"><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param int $backoffUntil
     * @return string HTML, '' when no back-off runs
     */
    private function backoffNotice($backoffUntil)
    {
        if ($backoffUntil <= 0) {
            return '';
        }

        return '<div class="alert alert-warning">'
            . $this->esc(sprintf($this->labels['backoff'], date('Y-m-d H:i:s', $backoffUntil))) . '</div>';
    }

    /**
     * @param array $byKind
     * @param int   $dayUsed what the day window counts
     * @return string HTML
     */
    private function kindsTable(array $byKind, $dayUsed)
    {
        $rows = '';
        foreach ($byKind as $kind => $count) {
            $rows .= $this->row($this->kindLabel((string) $kind), (string) (int) $count);
        }
        // iFirma's own figure raised the day counter above what the kinds add up to: the rest is other clients' use
        $others = $dayUsed - (int) array_sum($byKind);
        if ($others > 0) {
            $rows .= $this->row($this->labels['other_clients'], (string) $others);
        }

        return '<h4>' . $this->esc($this->labels['by_kind']) . '</h4>'
            . '<table class="table" id="ifirma-usage-kinds"><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param string $kind
     * @return string the translated label, the code itself for a kind that has none
     */
    private function kindLabel($kind)
    {
        return isset($this->labels['kinds'][$kind]) ? $this->labels['kinds'][$kind] : $kind;
    }

    /**
     * @param string $label
     * @param string $value
     * @return string HTML of one table row
     */
    private function row($label, $value)
    {
        return '<tr><td>' . $this->esc($label) . '</td><td class="text-right">' . $this->esc($value) . '</td></tr>';
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
