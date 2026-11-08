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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequestService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaFormToken.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaDataRequestNotifier.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaGuestCodeService.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaInput.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaIssuancePipeline.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaReceipt.php';

/**
 * The data-change form for a visitor who is not logged in. The visitor names an order (reference and
 * e-mail), receives a single-use code at the e-mail of the order, enters it, and then works with that one
 * order for 30 minutes. Steps: identify, verify, form, done. All rules of the request live in
 * IfirmaDataRequestService, the codes in IfirmaGuestCodeService.
 *
 * Nothing about an order is shown before the code is verified, and the answer to an identification is the
 * same message whether the order exists, matches, is eligible or a code was sent.
 */
class IfirmaGuestrequestModuleFrontController extends ModuleFrontController
{
    const SESSION_SECONDS = 1800;
    const COOKIE_KEY = 'ifirma_guest';

    /** The identification always takes at least this long, whatever the answer was (see identify()). */
    const IDENTIFY_MIN_MS = 1500;

    /** @var bool */
    public $ssl = true;

    /** @var string identify|verify|form|done|disabled */
    public $step = '';

    /** @var array field => message */
    public $fieldErrors = array();

    /** @var array submitted values, to refill the form */
    public $submitted = array();

    /** @var string */
    public $notice = '';

    /** @var string submitted|cancelled once the request was handled */
    public $outcome = '';

    /** @var string reference and e-mail the visitor typed (the only data kept between steps) */
    public $reference = '';

    /** @var string */
    public $email = '';

    /** @var string reference of the order of the confirmation page */
    public $doneReference = '';

    /** @var IfirmaDataRequestService */
    private $service;

    /**
     * @return IfirmaDataRequestService
     */
    private function service()
    {
        if ($this->service === null) {
            $this->service = IfirmaDataRequestService::build($this->module);
        }

        return $this->service;
    }

    /**
     * Protected so that a test can hand over a code service with a clock of its own.
     *
     * @return IfirmaGuestCodeService
     */
    protected function codeService()
    {
        return new IfirmaGuestCodeService();
    }

    /**
     * @return IfirmaDataRequestNotifier
     */
    protected function notifier()
    {
        return new IfirmaDataRequestNotifier();
    }

    /**
     * @return int unix timestamp
     */
    protected function now()
    {
        return time();
    }

    /**
     * @return float seconds with microseconds, to measure how long a request took (a test seam)
     */
    protected function clock()
    {
        return microtime(true);
    }

    /**
     * Waits (a test seam).
     *
     * @param int $microseconds
     * @return void
     */
    protected function pause($microseconds)
    {
        usleep((int) $microseconds);
    }

    /**
     * Resumes the issuance held by a request that was approved on submission. Never throws: the
     * visitor sees the confirmation whatever the pipeline does.
     *
     * @param IfirmaDataRequest $request
     * @return void
     */
    protected function resumeIssuance(IfirmaDataRequest $request)
    {
        try {
            IfirmaIssuancePipeline::build($this->module)->resumeAfterRequest($request);
        } catch (Throwable $e) {
            // the pipeline records its own failures on the receipt; nothing is shown to the visitor
            PrestaShopLogger::addLog('iFirma resume after guest data request ' . (int) $request->id . ' failed: ' . $e->getMessage(), 2);
        }
    }

    /**
     * @param string $name
     * @return string the request value as a string; an array gives ''
     */
    private function input($name)
    {
        return trim(IfirmaInput::scalar(Tools::getValue($name)));
    }

    /**
     * @return string
     */
    protected function cookieKey()
    {
        return IfirmaFormToken::cookieKey();
    }

    /**
     * @return bool whether the page works: the feature is switched on and the shop has a cookie key (the
     *              session signature, the form token and the code hashes are keyed with it)
     */
    private function isAvailable()
    {
        return $this->service()->isEnabled() && $this->cookieKey() !== '';
    }

    /**
     * @param int $idOrder
     * @param int $expiry
     * @return string
     */
    private function sign($idOrder, $expiry)
    {
        return hash_hmac('sha256', (int) $idOrder . '|' . (int) $expiry, $this->cookieKey());
    }

    /**
     * Opens the verified session for one order. The cookie value uses ':' because the PrestaShop cookie
     * refuses '|' in a value.
     *
     * @param int $idOrder
     * @return void
     */
    private function openSession($idOrder)
    {
        $expiry = $this->now() + self::SESSION_SECONDS;
        $key = self::COOKIE_KEY;
        $this->context->cookie->$key = (int) $idOrder . ':' . $expiry . ':' . $this->sign($idOrder, $expiry);
    }

    /**
     * @return void
     */
    private function clearSession()
    {
        $key = self::COOKIE_KEY;
        if (isset($this->context->cookie->$key)) {
            unset($this->context->cookie->$key);
        }
    }

    /**
     * @return int the order of the verified session, 0 when there is none, it expired or it was tampered with
     */
    public function verifiedOrderId()
    {
        $key = self::COOKIE_KEY;
        $raw = isset($this->context->cookie->$key) ? (string) $this->context->cookie->$key : '';
        if ($raw === '') {
            return 0;
        }
        $parts = explode(':', $raw);
        if (count($parts) === 3) {
            $idOrder = (int) $parts[0];
            $expiry = (int) $parts[1];
            if ($idOrder > 0 && $expiry > $this->now() && hash_equals($this->sign($idOrder, $expiry), (string) $parts[2])) {
                return $idOrder;
            }
        }
        $this->clearSession();

        return 0;
    }

    /**
     * Finds the order with the reference whose customer has the e-mail, ignoring case. Does the same
     * lookup whatever the answer is.
     *
     * @param string $reference
     * @param string $email
     * @return array|null array(Order, Customer)
     */
    private function findOrder($reference, $email)
    {
        $found = null;
        $orders = $reference !== '' ? Order::getByReference($reference) : array();
        if (is_iterable($orders)) {
            foreach ($orders as $order) {
                $customer = new Customer((int) $order->id_customer);
                if ($found === null && $email !== '' && Validate::isLoadedObject($customer)
                    && mb_strtolower(trim((string) $customer->email), 'UTF-8') === mb_strtolower($email, 'UTF-8')) {
                    $found = array($order, $customer);
                }
            }
        }

        return $found;
    }

    /**
     * @return void
     */
    private function guardLoggedIn()
    {
        if ($this->context->customer->isLogged()) {
            $this->clearSession();
            Tools::redirect($this->context->link->getPageLink('history', true));
        }
    }

    public function postProcess()
    {
        $started = $this->clock();
        $this->guardLoggedIn();
        $this->reference = $this->input('reference');
        $this->email = $this->input('email');

        if (!IfirmaInput::isPost()) {
            // a link or an image tag is a GET: nothing changes over it, whatever it carries
            return;
        }

        $action = '';
        foreach (array('identifyIfirmaGuest', 'verifyIfirmaGuest', 'submitIfirmaDataRequest', 'cancelIfirmaDataRequest') as $name) {
            if (Tools::getValue($name)) {
                $action = $name;
                break;
            }
        }
        if ($action === '') {
            return;
        }

        if (!$this->isAvailable()) {
            $this->errors[] = $this->service()->reasonMessage('disabled');

            return;
        }
        if (!IfirmaFormToken::isValid(IfirmaInput::scalar(Tools::getValue('token')), $this->context->cookie, $this->cookieKey())) {
            $this->errors[] = $this->service()->reasonMessage('invalid_token');
            $this->step = $action === 'verifyIfirmaGuest' ? 'verify' : '';

            return;
        }

        switch ($action) {
            case 'identifyIfirmaGuest':
                $this->identify($started);
                break;
            case 'verifyIfirmaGuest':
                $this->verify();
                break;
            default:
                $this->handleRequest($action === 'submitIfirmaDataRequest');
        }
    }

    /**
     * Sends a code when the data matches an eligible order. The visitor is told the same in every case,
     * and after the same time: the mail is sent only for a matching order, which takes a while, so every
     * branch is padded to IDENTIFY_MIN_MS (counted from the start of the request) and the answer time
     * does not tell whether the reference and the e-mail matched.
     *
     * @param float $started the clock() at the start of the request
     * @return void
     */
    private function identify($started)
    {
        $found = $this->findOrder($this->reference, $this->email);
        $sent = false;
        if ($found !== null) {
            $receipt = IfirmaReceipt::getByOrder((int) $found[0]->id);
            if (!($receipt && $receipt->isIssued())) {
                $sent = $this->sendCode($found[0], $found[1]);
            }
        }
        if (!$sent) {
            // the same hash work as an issued code
            hash('sha256', $this->reference . '|' . $this->email);
        }

        $this->notice = $this->module->l('If the data matches an order, we have sent a code to its e-mail address.', 'guestrequest');
        $this->step = 'verify';
        $this->padIdentify($started);
    }

    /**
     * @param float $started the clock() at the start of the request
     * @return void
     */
    private function padIdentify($started)
    {
        $remaining = (int) round(self::IDENTIFY_MIN_MS * 1000 - ($this->clock() - $started) * 1000000);
        if ($remaining > 0) {
            $this->pause($remaining);
        }
    }

    /**
     * @param Order    $order
     * @param Customer $customer
     * @return bool whether a code was mailed
     */
    private function sendCode(Order $order, Customer $customer)
    {
        try {
            $code = $this->codeService()->issue($order, Tools::getRemoteAddr());
            if ($code === null) {
                return false;
            }
            $reference = htmlspecialchars((string) $order->reference, ENT_QUOTES, 'UTF-8');

            return $this->notifier()->sendCode(
                $customer,
                $order,
                sprintf(
                    $this->module->l('Your verification code from %s', 'guestrequest'),
                    trim((string) preg_replace('/\s+/', ' ', (string) Configuration::get('PS_SHOP_NAME')))
                ),
                array(
                    '{firstname}' => htmlspecialchars((string) $customer->firstname, ENT_QUOTES, 'UTF-8'),
                    '{order_reference}' => $reference,
                    '{code}' => $code,
                    '{minutes}' => (string) (int) (IfirmaGuestCodeService::LIFETIME / 60),
                )
            );
        } catch (Throwable $e) {
            // the visitor gets the neutral answer either way; the cause goes to the shop log without the address
            PrestaShopLogger::addLog('iFirma guest code for order ' . (int) $order->id . ' not sent: ' . $e->getMessage(), 2);

            return false;
        }
    }

    /**
     * @return void
     */
    private function verify()
    {
        $found = $this->findOrder($this->reference, $this->email);
        $ok = false;
        try {
            // an unknown order costs the same call, which finds no row
            $ok = $this->codeService()->verify($found !== null ? (int) $found[0]->id : 0, $this->input('code'));
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('iFirma guest code verification failed: ' . $e->getMessage(), 2);
            $ok = false;
        }

        if ($ok && $found !== null) {
            $this->openSession((int) $found[0]->id);
            $this->step = 'form';

            return;
        }
        $this->errors[] = $this->module->l('Invalid or expired code. You can ask for a new one below.', 'guestrequest');
        $this->step = 'verify';
    }

    /**
     * Submits or cancels the request of the verified order.
     *
     * @param bool $submit false cancels
     * @return void
     */
    private function handleRequest($submit)
    {
        $idOrder = $this->verifiedOrderId();
        $order = $idOrder > 0 ? new Order($idOrder) : null;
        if ($order === null || (int) IfirmaInput::scalar(Tools::getValue('id_order')) !== $idOrder
            || !Validate::isLoadedObject($order)) {
            $this->refuseSession();

            return;
        }
        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->refuseSession();

            return;
        }

        try {
            if ($submit) {
                $input = $this->service()->collectInput();
                $this->submitted = $input;
                $request = $this->service()->submit($order, $customer, $input);
                if ($request->isApproved()) {
                    $this->resumeIssuance($request);
                }
            } else {
                $request = $this->service()->getPendingForOrder((int) $order->id);
                if (!$request) {
                    throw new IfirmaDataRequestException($this->service()->reasonMessage('not_pending'));
                }
                $this->service()->cancel($request, $customer);
            }
        } catch (IfirmaDataRequestException $e) {
            $this->errors[] = $e->getMessage();
            $this->fieldErrors = $e->errors;
            $this->step = 'form';

            return;
        } catch (Throwable $e) {
            // anything else (a database error, a type error) must not reach the error page of the shop, which
            // would show its text to a stranger: the log gets the class only
            PrestaShopLogger::addLog('iFirma guest data request for order ' . (int) $order->id . ' failed: ' . get_class($e), 3);
            $this->errors[] = $this->module->l('The request could not be saved. Please try again.', 'guestrequest');
            $this->step = 'form';

            return;
        }

        $this->clearSession();
        $this->outcome = $submit ? 'submitted' : 'cancelled';
        $this->doneReference = (string) $order->reference;
        $this->step = 'done';
    }

    /**
     * @return void
     */
    private function refuseSession()
    {
        $this->clearSession();
        $this->errors[] = $this->module->l('Your verification has expired or does not match this order. Please identify the order again.', 'guestrequest');
        $this->step = 'identify';
    }

    public function initContent()
    {
        parent::initContent();
        $this->guardLoggedIn();

        $service = $this->service();
        $vars = array(
            'ifirma_errors' => $this->errors,
            'ifirma_notice' => $this->notice,
            'ifirma_token' => IfirmaFormToken::forVisitor($this->context->cookie, $this->cookieKey()),
            'ifirma_form_action' => $this->context->link->getModuleLink($this->module->name, 'guestrequest', array(), true),
            'ifirma_reference' => $this->reference !== '' ? $this->reference : $this->input('reference'),
            'ifirma_email' => $this->email,
            'ifirma_field_errors' => $this->fieldErrors,
            'ifirma_outcome' => $this->outcome,
            'ifirma_done_reference' => $this->doneReference,
            'ifirma_order' => null,
            'ifirma_pending' => null,
            'ifirma_values' => array(),
            'ifirma_countries' => array(),
            'ifirma_eligibility' => array('allowed' => false, 'reason' => ''),
            'ifirma_reason_message' => '',
        );

        $step = $this->step;
        if (!$this->isAvailable()) {
            $step = 'disabled';
            $vars['ifirma_errors'] = array();
            $vars['ifirma_reason_message'] = $service->reasonMessage('disabled');
        } elseif ($step === '' || $step === 'form') {
            $step = 'identify';
            $idOrder = $this->verifiedOrderId();
            $order = $idOrder > 0 ? new Order($idOrder) : null;
            $customer = $order !== null && Validate::isLoadedObject($order) ? new Customer((int) $order->id_customer) : null;
            if ($customer !== null && Validate::isLoadedObject($customer)) {
                $step = 'form';
                $vars = array_merge($vars, $this->formVars($order, $customer));
            }
        }

        $vars['ifirma_step'] = $step;
        $this->context->smarty->assign($vars);
        $this->setTemplate('module:ifirma/views/templates/front/guestrequest.tpl');
    }

    /**
     * @param Order    $order
     * @param Customer $customer
     * @return array the template variables of the form step
     */
    private function formVars(Order $order, Customer $customer)
    {
        $service = $this->service();
        $eligibility = $service->canRequest($order, $customer);
        $pending = $service->getPendingForOrder((int) $order->id);
        $address = new Address((int) $order->id_address_invoice);

        $countries = array();
        if (method_exists('Country', 'getCountries')) {
            $countries = Country::getCountries((int) $this->context->language->id, true);
        }
        $values = $this->submitted;
        if (empty($values)) {
            $values = $service->defaultsFromAddress($address);
        }

        return array(
            'ifirma_order' => $order,
            'ifirma_eligibility' => $eligibility,
            'ifirma_reason_message' => $eligibility['allowed'] ? '' : $service->reasonMessage($eligibility['reason']),
            'ifirma_pending' => $pending ? $service->summarize($pending) : null,
            'ifirma_values' => $values,
            'ifirma_countries' => $countries,
        );
    }
}
