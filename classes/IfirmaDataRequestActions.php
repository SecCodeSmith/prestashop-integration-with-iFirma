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

require_once dirname(__FILE__) . '/IfirmaActionSupport.php';
require_once dirname(__FILE__) . '/IfirmaDataRequest.php';
require_once dirname(__FILE__) . '/IfirmaIssuancePipeline.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestService.php';

/**
 * Admin actions on customer data requests: approve or reject one, then resume the issuance it held.
 *
 * Reached through IfirmaAdminActions.
 */
class IfirmaDataRequestActions extends IfirmaActionSupport
{
    /**
     * @param int        $idRequest
     * @param int        $idEmployee
     * @param array|null $edited    edited field values, null to approve as requested
     * @param string     $note
     * @return array
     */
    public function approveRequest($idRequest, $idEmployee, ?array $edited = null, $note = '')
    {
        return $this->guard(function () use ($idRequest, $idEmployee, $edited, $note) {
            $request = IfirmaDataRequest::getById((int) $idRequest);
            if (!$request) {
                return $this->result(false, $this->module->l('The request was not found.', 'ifirmaadminactions'));
            }
            $service = IfirmaDataRequestService::build($this->module);
            $service->approve($request, (int) $idEmployee, $edited, $note);

            return $this->resolved($request, $this->module->l('Request approved.', 'ifirmaadminactions'));
        });
    }

    /**
     * @param int    $idRequest
     * @param int    $idEmployee
     * @param string $reason
     * @return array
     */
    public function rejectRequest($idRequest, $idEmployee, $reason)
    {
        return $this->guard(function () use ($idRequest, $idEmployee, $reason) {
            try {
                $request = IfirmaDataRequest::getById((int) $idRequest);
                if (!$request) {
                    return $this->result(false, $this->module->l('The request was not found.', 'ifirmaadminactions'));
                }
                $service = IfirmaDataRequestService::build($this->module);
                $service->reject($request, (int) $idEmployee, $reason);

                return $this->resolved($request, $this->module->l('Request rejected.', 'ifirmaadminactions'));
            } catch (IfirmaDataRequestException $e) {
                if ($e->code_name === 'reason_required') {
                    return $this->result(
                        false,
                        $this->module->l('A reason is required to reject the request.', 'ifirmaadminactions'),
                        array('code' => 'reason_required')
                    );
                }

                // every other refusal is the guard's to report
                throw $e;
            }
        });
    }

    /**
     * Resumes a held issuance after a request was resolved.
     *
     * @param IfirmaDataRequest $request
     * @param string            $message
     * @return array
     */
    private function resolved(IfirmaDataRequest $request, $message)
    {
        $pipeline = IfirmaIssuancePipeline::build($this->module);
        $result = $pipeline->resumeAfterRequest($request);
        $data = array('issuance_ran' => $result !== null, 'status' => $request->status, 'issued' => false);
        if ($result !== null) {
            $data['issued'] = (bool) $result->ok;
            $data['outcome'] = $result->outcome;
            $message .= ' ' . $pipeline->describe($result);
        }

        return $this->result(true, $message, $data);
    }
}
