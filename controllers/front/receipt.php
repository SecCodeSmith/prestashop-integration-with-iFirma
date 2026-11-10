<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaApiClient.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDownloadBudget.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDownloadDayCap.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDownloadThrottle.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaHttpOutput.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaInput.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaKsefDeliveryGate.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRateLimitedException.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceiptService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaSchema.php';

class IfirmaReceiptModuleFrontController extends ModuleFrontController
{
    /** What a refused customer is told to wait: one value for every window, so the header reveals none of them. */
    const LIMIT_RETRY_AFTER_SECONDS = 60;

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

        // A document that waits for KSeF acceptance is not the customer's yet. The answer is the one a wrong token
        // gets, so a probe cannot tell a held document from a foreign one, and it comes before the throttle claim
        // and any iFirma request: the refused request costs nothing.
        if (IfirmaKsefDeliveryGate::isWaitingState($receipt->delivery_status, $receipt->ksef_status, $receipt->ksef_number)) {
            $this->notFound();
        }

        // The customer's own day (all their documents, guests included: a guest order has a customer record too).
        // Checked before anything is claimed, so a refused request uses no throttle slot and no budget.
        $dayCap = $this->downloadDayCap();
        if ($dayCap->isExhausted((int) $receipt->id_customer)) {
            $this->refuseForTooManyRequests();
        }

        IfirmaSchema::ensureReceiptDownloadColumn();
        $throttle = new IfirmaDownloadThrottle();
        if (!$throttle->claim($receipt)) {
            $this->tooManyRequests();
        }

        // The customers' own budget, so downloads cannot use up the request limit issuance shares. It comes after
        // every check above: a refused request spends nothing. No log line: a refused request costs the attacker
        // nothing, so one would only flood the log.
        $budget = $this->customerBudget();
        if (!$budget->take()) {
            $this->refuseForTooManyRequests();
        }

        // Counted before the request leaves, so parallel requests all see it; given back when none was sent.
        $dayCap->take($receipt);

        try {
            $service = IfirmaReceiptService::build($this->module);
            $pdf = $service->fetchPdf($receipt, (bool) IfirmaInput::scalar(Tools::getValue('duplicate')));
        } catch (IfirmaRateLimitedException $e) {
            // Refused before it was sent: the unit and the day count go back, so both count what reached iFirma.
            $budget->release();
            $dayCap->giveBack($receipt);
            // Only reached after the ownership, token, KSeF-wait and throttle checks above. The customer is told
            // nothing about the limit; the shop owner finds the reason in the log.
            PrestaShopLogger::addLog('iFirma front download refused by the request limit (receipt ' . (int) $receipt->id . '): ' . $e->getMessage(), 1);
            $this->refuseForTooManyRequests();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('iFirma front download failed (receipt ' . (int) $receipt->id . '): ' . $e->getMessage(), 2);
            $this->serverError();
        }

        $this->streamPdf($pdf, $receipt);
    }

    /**
     * @return IfirmaDownloadBudget the budget of all customers' downloads; protected so a test can fix its clock
     */
    protected function customerBudget()
    {
        return IfirmaDownloadBudget::forCustomers();
    }

    /**
     * @return IfirmaDownloadDayCap the per-customer daily cap; protected so a test can fix its clock
     */
    protected function downloadDayCap()
    {
        return new IfirmaDownloadDayCap();
    }

    /**
     * Every limit answers the customer the same: no count, no window, one wait.
     */
    private function refuseForTooManyRequests()
    {
        $this->refusedByRateLimit(
            self::LIMIT_RETRY_AFTER_SECONDS,
            $this->module->l('Too many requests at the moment. Please try again shortly.', 'receipt')
        );
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
    protected function streamPdf($pdf, IfirmaReceipt $receipt)
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
     * Ends the request with a 429 that carries the seconds to wait; protected so a test can answer instead of ending.
     *
     * @codeCoverageIgnore exit ends the request; sendLimitAnswer() is tested on its own
     *
     * @param int    $retryAfterSeconds
     * @param string $message           generic text, free of counts and caps
     */
    protected function refusedByRateLimit($retryAfterSeconds, $message)
    {
        $this->sendLimitAnswer($retryAfterSeconds, $message);
        exit;
    }

    /**
     * @param int    $retryAfterSeconds
     * @param string $message
     */
    protected function sendLimitAnswer($retryAfterSeconds, $message)
    {
        IfirmaHttpOutput::tooManyRequests($retryAfterSeconds, $message);
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
