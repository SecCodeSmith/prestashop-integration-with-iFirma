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

    /**
     * @return int
     */
    private function customerId()
    {
        return (int) Tools::getValue('id_customer');
    }

    /**
     * Whether the employee may edit customers (spec: customer-contractor-panel, D8).
     *
     * @return bool
     */
    private function canEditCustomers()
    {
        $idProfile = (isset($this->context->employee) && $this->context->employee) ? (int) $this->context->employee->id_profile : 0;
        if ($idProfile <= 0) {
            return false;
        }

        return (bool) Access::isGranted('ROLE_MOD_TAB_ADMINCUSTOMERS_UPDATE', $idProfile);
    }

    /**
     * Refuses the request when the employee may not edit customers.
     * Responds (and ends the request) with the permission message.
     *
     * @return bool true when the action may proceed
     */
    private function requireEditCustomers()
    {
        if ($this->canEditCustomers()) {
            return true;
        }
        $this->respond(array(
            'success' => false,
            'message' => $this->module->l('You do not have permission to edit customers.', 'adminifirmacontroller'),
            'data' => array(),
        ));

        return false;
    }

    public function ajaxProcessCustomerCheck()
    {
        $this->respond($this->actions()->customerCheck($this->customerId()));
    }

    public function ajaxProcessCustomerSearch()
    {
        $posted = Tools::getValue('criteria', null);
        $criteria = array();
        foreach (array('firstname', 'lastname', 'company', 'nip', 'email', 'phone') as $key) {
            $criteria[$key] = (is_array($posted) && isset($posted[$key]) && !is_array($posted[$key])) ? (string) $posted[$key] : '';
        }

        $this->respond($this->actions()->customerSearch($criteria));
    }

    public function ajaxProcessCustomerLink()
    {
        if (!$this->requireEditCustomers()) {
            return;
        }
        $this->respond($this->actions()->customerLink($this->customerId(), trim((string) Tools::getValue('identifier'))));
    }

    public function ajaxProcessCustomerUnlink()
    {
        if (!$this->requireEditCustomers()) {
            return;
        }
        $this->respond($this->actions()->customerUnlink($this->customerId()));
    }

    public function ajaxProcessCustomerRegister()
    {
        if (!$this->requireEditCustomers()) {
            return;
        }
        $this->respond($this->actions()->registerCustomer($this->customerId()));
    }

    public function ajaxProcessCustomerCountRemote()
    {
        $this->respond($this->actions()->customerCountRemote($this->customerId()));
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
        $this->output(array(
            'success' => true,
            'message' => $message,
            'data' => $data,
            'receipt' => isset($data['receipt']) ? $data['receipt'] : array(),
        ));
    }

    /**
     * Sends the JSON envelope and ends the request. Overridable so tests can capture the payload.
     *
     * @param array $payload
     */
    protected function output(array $payload)
    {
        header('Content-Type: application/json');
        die(json_encode($payload));
    }

    private function ajaxError($message, array $data = array())
    {
        $this->output(array(
            'success' => false,
            'message' => $message,
            'data' => $data,
            'receipt' => isset($data['receipt']) ? $data['receipt'] : array(),
        ));
    }
}
