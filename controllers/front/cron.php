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

/**
 * Cron endpoint: bulk status sync (spec: receipt-status-sync).
 * URL: /module/ifirma/cron?token=<IFIRMA_CRON_TOKEN>
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiClient.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaHttpStatus.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaStatusSyncService.php';

class IfirmaCronModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent()
    {
        $this->ajax = true;
        $service = IfirmaStatusSyncService::build($this->module);

        if (!$service->isValidCronToken(Tools::getValue('token'))) {
            $this->respond(403, array('error' => 'Forbidden'));
        } else {
            $this->respond(200, $service->runCron());
        }
    }

    /**
     * Only place with header()/exit (not unit-testable).
     *
     * @param int   $status
     * @param array $payload
     */
    protected function respond($status, array $payload)
    {
        if (ob_get_level()) {
            ob_end_clean();
        }
        header(IfirmaHttpStatus::statusLine($status));
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
