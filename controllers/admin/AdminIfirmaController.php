<?php
/**
 * iFirma module – back-office AJAX controller.
 *
 * Serves the "Create Receipt", "Update status" and "Download PDF" actions
 * triggered from the order page. Access is protected by the standard
 * PrestaShop admin token bound to the hidden AdminIfirma tab.
 *
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiClient.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptService.php';

class AdminIfirmaController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        // PrestaShop normally injects the owning module; guard against edge cases.
        if (!($this->module instanceof Module)) {
            $this->module = Module::getInstanceByName('ifirma');
        }
    }

    /**
     * POST action=CreateReceipt&id_order=..  → issues a document in iFirma.
     */
    public function ajaxProcessCreateReceipt()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order)) {
            $this->ajaxError($this->module->l('Invalid order.', 'adminifirmacontroller'));
        }

        if (!$this->module->isConfigured()) {
            $this->ajaxError($this->module->l('The iFirma module is not configured yet.', 'adminifirmacontroller'));
        }

        try {
            $service = IfirmaReceiptService::build($this->module);
            $receipt = $service->createReceipt($order);
        } catch (Exception $e) {
            $this->ajaxError($e->getMessage());
        }

        $this->ajaxSuccess(
            $this->module->l('Receipt issued in iFirma.', 'adminifirmacontroller'),
            $this->receiptToArray($receipt)
        );
    }

    /**
     * POST action=UpdateStatus&id_order=..  → registers the payment in iFirma.
     */
    public function ajaxProcessUpdateStatus()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $receipt = IfirmaReceipt::getByOrder($idOrder);

        if (!$receipt || !$receipt->isIssued()) {
            $this->ajaxError($this->module->l('No issued iFirma document for this order.', 'adminifirmacontroller'));
        }

        $amount = Tools::getValue('amount', null);
        $amount = ($amount === null || $amount === '') ? null : (float) str_replace(',', '.', $amount);

        try {
            $service = IfirmaReceiptService::build($this->module);
            $receipt = $service->updateStatusPaid($receipt, $amount);
        } catch (Exception $e) {
            $this->ajaxError($e->getMessage());
        }

        $this->ajaxSuccess(
            $this->module->l('Payment registered in iFirma.', 'adminifirmacontroller'),
            $this->receiptToArray($receipt)
        );
    }

    /**
     * GET/POST action=DownloadPdf&id_order=..  → streams the PDF to the admin.
     */
    public function ajaxProcessDownloadPdf()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $receipt = IfirmaReceipt::getByOrder($idOrder);

        if (!$receipt || !$receipt->isIssued()) {
            header('HTTP/1.1 404 Not Found');
            exit($this->module->l('Document not found.', 'adminifirmacontroller'));
        }

        try {
            $service = IfirmaReceiptService::build($this->module);
            $pdf = $service->fetchPdf($receipt, (bool) Tools::getValue('duplicate'));
        } catch (Exception $e) {
            header('HTTP/1.1 502 Bad Gateway');
            exit($e->getMessage());
        }

        $this->streamPdf($pdf, $receipt);
    }

    /**
     * Stream raw PDF bytes as a download and stop the dispatcher.
     */
    private function streamPdf($pdf, IfirmaReceipt $receipt)
    {
        $filename = 'ifirma-' . ($receipt->ifirma_number ? preg_replace('/[^0-9A-Za-z._-]/', '_', $receipt->ifirma_number) : $receipt->ifirma_id) . '.pdf';

        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, must-revalidate');
        echo $pdf;
        exit;
    }

    /**
     * @return array subset safe to return to the browser
     */
    private function receiptToArray(IfirmaReceipt $receipt)
    {
        return array(
            'id' => (int) $receipt->id,
            'ifirma_id' => $receipt->ifirma_id,
            'ifirma_number' => $receipt->ifirma_number,
            'status' => $receipt->status,
            'paid_amount' => (float) $receipt->paid_amount,
        );
    }

    private function ajaxSuccess($message, array $data = array())
    {
        header('Content-Type: application/json');
        die(json_encode(array('success' => true, 'message' => $message, 'receipt' => $data)));
    }

    private function ajaxError($message)
    {
        header('Content-Type: application/json');
        die(json_encode(array('success' => false, 'message' => $message)));
    }
}
