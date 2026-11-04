<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiClient.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaAdminActions.php';

class AdminIfirmaController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        if (!($this->module instanceof Module)) {
            $this->module = Module::getInstanceByName('ifirma');
        }
    }

    /**
     * @return IfirmaAdminActions
     */
    private function actions()
    {
        return new IfirmaAdminActions($this->module);
    }

    /**
     * @return int
     */
    private function employeeId()
    {
        return (isset($this->context->employee) && $this->context->employee) ? (int) $this->context->employee->id : 0;
    }

    /**
     * @return int
     */
    private function orderId()
    {
        return (int) Tools::getValue('id_order');
    }

    public function ajaxProcessCreateReceipt()
    {
        $this->respond($this->actions()->createReceipt($this->orderId(), $this->employeeId()));
    }

    public function ajaxProcessIssueAnyway()
    {
        $this->respond($this->actions()->issueAnyway($this->orderId(), $this->employeeId()));
    }

    public function ajaxProcessLinkCandidate()
    {
        $this->respond($this->actions()->linkCandidate(
            $this->orderId(),
            trim((string) Tools::getValue('faktura_id')),
            $this->employeeId()
        ));
    }

    public function ajaxProcessUpdateStatus()
    {
        $amount = Tools::getValue('amount', null);
        $amount = ($amount === null || $amount === '' || $amount === false) ? null : (float) str_replace(',', '.', $amount);

        $this->respond($this->actions()->updateStatus($this->orderId(), $amount));
    }

    public function ajaxProcessResendDocument()
    {
        $this->respond($this->actions()->resend($this->orderId()));
    }

    public function ajaxProcessSendToKsef()
    {
        $this->respond($this->actions()->sendToKsef($this->orderId()));
    }

    public function ajaxProcessRefreshStatus()
    {
        $this->respond($this->actions()->refreshStatus($this->orderId()));
    }

    public function ajaxProcessRegisterContractor()
    {
        $result = $this->actions()->registerContractor($this->orderId());

        $this->respond(array(
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => array('contractor' => $result['data']),
        ));
    }

    public function ajaxProcessApproveRequest()
    {
        $data = Tools::getValue('data', null);
        $edited = is_array($data) ? $data : null;

        $this->respond($this->actions()->approveRequest(
            (int) Tools::getValue('id_request'),
            $this->employeeId(),
            $edited,
            (string) Tools::getValue('admin_note')
        ));
    }

    public function ajaxProcessRejectRequest()
    {
        $this->respond($this->actions()->rejectRequest(
            (int) Tools::getValue('id_request'),
            $this->employeeId(),
            (string) Tools::getValue('reason')
        ));
    }

    public function ajaxProcessDownloadPdf()
    {
        $idOrder = $this->orderId();
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
     * @param array $result array('success', 'message', 'data')
     */
    private function respond(array $result)
    {
        if (!empty($result['success'])) {
            $this->ajaxSuccess($result['message'], isset($result['data']) ? $result['data'] : array());
        }

        $this->ajaxError($result['message'], isset($result['data']) ? $result['data'] : array());
    }

    private function ajaxSuccess($message, array $data = array())
    {
        header('Content-Type: application/json');
        die(json_encode(array(
            'success' => true,
            'message' => $message,
            'data' => $data,
            'receipt' => isset($data['receipt']) ? $data['receipt'] : array(),
        )));
    }

    private function ajaxError($message, array $data = array())
    {
        header('Content-Type: application/json');
        die(json_encode(array(
            'success' => false,
            'message' => $message,
            'data' => $data,
            'receipt' => isset($data['receipt']) ? $data['receipt'] : array(),
        )));
    }
}
