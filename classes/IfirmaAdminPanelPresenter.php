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

/**
 * The words and the small decisions of the admin order panel: the state of the record and its label, the
 * label of a data change request status, the form fields of a request, the name of the employee who
 * resolved one and the note on the contractor step of the stored request.
 *
 * The labels arrive already translated. The translation scanner only finds the string literals that
 * ifirma.php passes to its own translator, so the module owns them (Ifirma::adminPanelLabels()) and
 * hands them over, as it does for IfirmaMatchLabels.
 */
class IfirmaAdminPanelPresenter
{
    const STATE_NOT_ISSUED = 'not_issued';
    const STATE_HELD = 'held';
    const STATE_SUSPECTED = 'suspected';
    const STATE_UNKNOWN = 'unknown';
    const STATE_ERROR = 'error';
    const STATE_MISSING = 'missing';
    const STATE_PAID = 'paid';
    const STATE_ISSUED = 'issued';

    /** @var array */
    private $labels;

    /**
     * @param array $labels the translated words:
     *                      'state'          => STATE_* => label (every state)
     *                      'request_status' => data request status => label
     *                      'request_fields' => field of the data change request form => label
     *                      'contractor'     => the words of the contractor note: the actions (created,
     *                                          updated, kept, fallback), the sentences of the notes
     *                                          (assigned, lookup_failed, update_failed, create_rejected,
     *                                          reference_rejected, readback_failed), the words of the
     *                                          search (ambiguous, search_failed, found_nothing) and
     *                                          attached
     */
    public function __construct(array $labels)
    {
        $this->labels = $labels;
    }

    /**
     * @param IfirmaReceipt|null $receipt
     * @return string one of the STATE_* constants
     */
    public function state($receipt)
    {
        if (!$receipt) {
            return self::STATE_NOT_ISSUED;
        }
        if ($receipt->status === IfirmaReceipt::STATUS_HELD) {
            return self::STATE_HELD;
        }
        if ($receipt->status === IfirmaReceipt::STATUS_DUPLICATE_SUSPECTED) {
            return self::STATE_SUSPECTED;
        }
        if ($receipt->status === IfirmaReceipt::STATUS_UNKNOWN) {
            return self::STATE_UNKNOWN;
        }
        if ($receipt->status === IfirmaReceipt::STATUS_ERROR) {
            return self::STATE_ERROR;
        }
        if ($receipt->isIssued()) {
            if ($receipt->remote_missing) {
                return self::STATE_MISSING;
            }

            return $receipt->status === IfirmaReceipt::STATUS_PAID ? self::STATE_PAID : self::STATE_ISSUED;
        }

        return self::STATE_NOT_ISSUED;
    }

    /**
     * @param string $state a STATE_* constant
     * @return string the label of the state; the one of "not issued" for a state it does not know
     */
    public function stateLabel($state)
    {
        $labels = $this->labels['state'];

        return isset($labels[$state]) ? $labels[$state] : $labels[self::STATE_NOT_ISSUED];
    }

    /**
     * @param string|null $status a data request status
     * @return string the label of the status; the status itself when it has none (a value of another version)
     */
    public function requestStatusLabel($status)
    {
        $labels = $this->labels['request_status'];

        return isset($labels[$status]) ? $labels[$status] : (string) $status;
    }

    /**
     * The form of a data change request, one entry per field.
     *
     * @param string[] $names     the fields of the form, in display order
     * @param array    $requested field => the value the customer asked for
     * @return array[] name, label (the name itself when the field has none), value (a string, empty for a
     *                 value that is missing or not a scalar) and type (checkbox or text)
     */
    public function requestFields(array $names, array $requested)
    {
        $fields = array();
        foreach ($names as $name) {
            $fields[] = array(
                'name' => $name,
                'label' => isset($this->labels['request_fields'][$name]) ? $this->labels['request_fields'][$name] : $name,
                'value' => isset($requested[$name]) && !is_array($requested[$name]) ? (string) $requested[$name] : '',
                'type' => $name === 'want_invoice_for_company' ? 'checkbox' : 'text',
            );
        }

        return $fields;
    }

    /**
     * @param int|null $idEmployee
     * @return string the employee's name; "#id" for an employee without a name; '' for none
     */
    public function employeeName($idEmployee)
    {
        $idEmployee = (int) $idEmployee;
        if ($idEmployee <= 0) {
            return '';
        }
        $employee = new Employee($idEmployee);
        $name = trim($employee->firstname . ' ' . $employee->lastname);

        return $name !== '' ? $name : '#' . $idEmployee;
    }

    /**
     * The note of the contractor step, in the back-office language, followed by the contractor iFirma
     * attached when the module referenced another one.
     *
     * @param array|null $step IfirmaReceipt::contractorNote()
     * @return string '' when there is no step, or its action is not one the module knows
     */
    public function contractorNote($step)
    {
        if ($step === null) {
            return '';
        }
        $note = $this->actionNote($step['action'], $step['message'], $step['note']);
        if ($step['attached'] !== '') {
            $note = trim($note . ' ' . sprintf($this->word('attached'), $step['attached']));
        }

        return $note;
    }

    /**
     * @param string     $action
     * @param string     $message raw (English) sentence stored with older records
     * @param array|null $note    structured note (code, params) rendered in the back-office language
     * @return string
     */
    private function actionNote($action, $message, $note)
    {
        if (!in_array($action, array('created', 'updated', 'kept', 'fallback'), true)) {
            return '';
        }
        $label = $this->word($action);
        $params = is_array($note) && isset($note['params']) && is_array($note['params']) ? $note['params'] : array();
        $code = is_array($note) && isset($note['code']) ? (string) $note['code'] : '';

        if ($action === 'created' && $code === 'assigned') {
            return sprintf($this->word('assigned'), $this->param($params, 'identifier'));
        }
        if ($action !== 'fallback') {
            return $label;
        }
        $failure = $this->fallbackNote($code, $params);
        if ($failure !== null) {
            return $failure;
        }

        return $message !== '' ? $label . ' (' . $message . ')' : $label;
    }

    /**
     * @param string $code   the code of the structured note
     * @param array  $params its parameters
     * @return string|null the sentence for the code; null for a code without wording
     */
    private function fallbackNote($code, array $params)
    {
        $identifier = $this->param($params, 'identifier');
        $error = $this->param($params, 'error');

        switch ($code) {
            case 'lookup_failed':
                return sprintf($this->word('lookup_failed'), $identifier, $error);
            case 'update_failed':
                return sprintf($this->word('update_failed'), $identifier, $error);
            case 'create_rejected':
                return sprintf($this->word('create_rejected'), $error);
            case 'reference_rejected':
                return sprintf($this->word('reference_rejected'), $identifier, $error);
            case 'readback_failed':
                return $this->readbackNote($params);
        }

        return null;
    }

    /**
     * @param array $params tried (list of identifier and answer), search (none, ambiguous or error), search_error
     * @return string
     */
    private function readbackNote(array $params)
    {
        $tried = array();
        $entries = isset($params['tried']) && is_array($params['tried']) ? $params['tried'] : array();
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $line = $this->param($entry, 'identifier') . ': ' . $this->param($entry, 'answer');
            $tried[$line] = $line;
        }
        $search = isset($params['search']) ? (string) $params['search'] : 'none';
        if ($search === 'ambiguous') {
            $searchLabel = $this->word('ambiguous');
        } elseif ($search === 'error') {
            $searchLabel = sprintf($this->word('search_failed'), $this->param($params, 'search_error'));
        } else {
            $searchLabel = $this->word('found_nothing');
        }

        return sprintf($this->word('readback_failed'), implode('; ', $tried), $searchLabel);
    }

    /**
     * @param array  $values
     * @param string $key
     * @return string the value as a string; '' when it is missing
     */
    private function param(array $values, $key)
    {
        return isset($values[$key]) ? (string) $values[$key] : '';
    }

    /**
     * @param string $key
     * @return string a word of the contractor note
     */
    private function word($key)
    {
        return $this->labels['contractor'][$key];
    }
}
