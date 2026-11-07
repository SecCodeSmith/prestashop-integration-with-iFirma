<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiClient.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDownloadThrottle.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaHttpOutput.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaInput.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaSchema.php';

class IfirmaReceiptModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();

        if (!$this->context->customer->isLogged()) {
            $this->redirectToLogin();
        }

        $token = $this->readToken();
        if ($token === '') {
            $this->notFound();
        }
        $receipt = IfirmaReceipt::getByToken($token);

        if (!$receipt || !$receipt->isIssued()) {
            $this->notFound();
        }

        if ((int) $receipt->id_customer !== (int) $this->context->customer->id) {
            $this->notFound();
        }

        IfirmaSchema::ensureReceiptDownloadColumn();
        $throttle = new IfirmaDownloadThrottle();
        if (!$throttle->claim($receipt)) {
            $this->tooManyRequests();
        }

        try {
            $service = IfirmaReceiptService::build($this->module);
            $pdf = $service->fetchPdf($receipt, (bool) IfirmaInput::scalar(Tools::getValue('duplicate')));
        } catch (Exception $e) {
            PrestaShopLogger::addLog('iFirma front download failed (receipt ' . (int) $receipt->id . '): ' . $e->getMessage(), 2);
            $this->serverError();
        }

        $this->streamPdf($pdf, $receipt);
    }

    /**
     * The `token` request value as a string; an array (`token[]=x`) gives ''.
     *
     * @return string
     */
    public function readToken()
    {
        return IfirmaInput::scalar(Tools::getValue('token'));
    }

    /**
     * Streams the document and ends the request.
     *
     * @codeCoverageIgnore header() output and exit end the request; IfirmaHttpOutput::pdf() is tested on its own
     */
    private function streamPdf($pdf, IfirmaReceipt $receipt)
    {
        IfirmaHttpOutput::pdf($pdf, $receipt->ifirma_number ?: $receipt->ifirma_id);
        exit;
    }

    private function redirectToLogin()
    {
        $back = $this->context->link->getModuleLink($this->module->name, 'receipt', array('token' => $this->readToken()), true);
        Tools::redirect($this->context->link->getPageLink('authentication', true, null, array('back' => $back)));
    }

    /**
     * Ends the request with a 404; protected so a test can answer instead of ending.
     *
     * @codeCoverageIgnore header() and exit end the request, so the harness cannot run the body; initContent() is tested through an overriding twin
     */
    protected function notFound()
    {
        header('HTTP/1.1 404 Not Found');
        exit($this->module->l('Document not found.', 'receipt'));
    }

    /**
     * Ends the request with a 429; protected so a test can answer instead of ending.
     *
     * @codeCoverageIgnore header() and exit end the request, so the harness cannot run the body; initContent() is tested through an overriding twin
     */
    protected function tooManyRequests()
    {
        header('HTTP/1.1 429 Too Many Requests');
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit($this->module->l('Please wait a moment before downloading the document again.', 'receipt'));
    }

    /**
     * Ends the request with a 502; protected so a test can answer instead of ending.
     *
     * @codeCoverageIgnore header() and exit end the request, so the harness cannot run the body; initContent() is tested through an overriding twin
     */
    protected function serverError()
    {
        header('HTTP/1.1 502 Bad Gateway');
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit($this->module->l('The document is temporarily unavailable. Please try again later.', 'receipt'));
    }
}
