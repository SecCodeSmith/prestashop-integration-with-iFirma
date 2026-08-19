<?php
/**
 * iFirma module – front-office PDF download.
 *
 * A logged-in customer can download the PDF of a document that belongs to them.
 * The PDF is streamed live from iFirma so it always reflects the current state;
 * nothing sensitive is stored on the shop's disk.
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

class IfirmaReceiptModuleFrontController extends ModuleFrontController
{
    /** @var bool no header/footer/theme – we only emit a PDF */
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();

        // Must be an authenticated customer.
        if (!$this->context->customer->isLogged()) {
            $this->redirectToLogin();
        }

        $token = Tools::getValue('token');
        $receipt = IfirmaReceipt::getByToken($token);

        if (!$receipt || !$receipt->isIssued()) {
            $this->notFound();
        }

        // Ownership check: the document must belong to the logged-in customer.
        if ((int) $receipt->id_customer !== (int) $this->context->customer->id) {
            $this->notFound();
        }

        try {
            $service = IfirmaReceiptService::build($this->module);
            $pdf = $service->fetchPdf($receipt, (bool) Tools::getValue('duplicate'));
        } catch (Exception $e) {
            PrestaShopLogger::addLog('iFirma front download failed (receipt ' . (int) $receipt->id . '): ' . $e->getMessage(), 2);
            $this->serverError();
        }

        $this->streamPdf($pdf, $receipt);
    }

    private function streamPdf($pdf, IfirmaReceipt $receipt)
    {
        $number = $receipt->ifirma_number ?: $receipt->ifirma_id;
        $filename = 'ifirma-' . preg_replace('/[^0-9A-Za-z._-]/', '_', $number) . '.pdf';

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

    private function redirectToLogin()
    {
        $back = $this->context->link->getModuleLink($this->module->name, 'receipt', array('token' => Tools::getValue('token')), true);
        Tools::redirect($this->context->link->getPageLink('authentication', true, null, array('back' => $back)));
    }

    private function notFound()
    {
        header('HTTP/1.1 404 Not Found');
        exit($this->module->l('Document not found.', 'receipt'));
    }

    private function serverError()
    {
        header('HTTP/1.1 502 Bad Gateway');
        exit($this->module->l('The document is temporarily unavailable. Please try again later.', 'receipt'));
    }
}
