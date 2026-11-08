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

require_once dirname(__FILE__) . '/IfirmaReceiptService.php';

require_once dirname(__FILE__) . '/IfirmaReceipt.php';
require_once dirname(__FILE__) . '/IfirmaDataRequest.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestNotifier.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestAutoAccept.php';
require_once dirname(__FILE__) . '/IfirmaSchema.php';
require_once dirname(__FILE__) . '/IfirmaFormToken.php';
require_once dirname(__FILE__) . '/IfirmaNamedLock.php';

/**
 * Business rules of the customer data-change request.
 *
 * Public contract:
 *  - canRequest(Order, Customer): array('allowed' => bool, 'reason' => disabled|not_owner|document_exists|pending_exists|ok)
 *  - validate(array): field => message (empty when valid)
 *  - collectInput(): array, the posted form (every field of fields(), in that order)
 *  - defaultsFromAddress(Address): array, the form values to start from (the invoice address)
 *  - submit / cancel / approve / reject: state machine pending -> approved|rejected|cancelled,
 *    refused transitions throw IfirmaDataRequestException (see code_name)
 *  - getOverrideForOrder(idOrder): array|null in the IfirmaPayloadBuilder::buildContractor override format
 *  - buildDiff, notifyMerchant, notifyCustomer, summarize, isValidToken
 *
 * notifyMerchant() and notifyCustomer() compose the e-mails (subject, template variables); the
 * IfirmaDataRequestNotifier delivers them, so the decisions can be tested with a recording notifier.
 */
class IfirmaDataRequestService
{
    /** Seconds the customer waits between two requests for one order. */
    const COOLDOWN = 600;

    /** Requests one customer may create in 24 hours. */
    const DAILY_CAP = 10;

    /** Longest admin note and rejection reason, in characters. */
    const ADMIN_TEXT_MAX = 1000;

    /** @var Module */
    private $module;

    /** @var callable|null returns "Y-m-d H:i:s" */
    private $clock;

    /** @var IfirmaDataRequestNotifier */
    private $notifier;

    /** @var IfirmaDataRequestAutoAccept */
    private $autoAccept;

    /**
     * @param Module $module
     * @param callable|null $clock
     * @param IfirmaDataRequestNotifier|null $notifier delivers the e-mails; the default one sends them
     * @param IfirmaDataRequestAutoAccept|null $autoAccept the auto-accept criteria
     */
    public function __construct(Module $module, $clock = null, ?IfirmaDataRequestNotifier $notifier = null, ?IfirmaDataRequestAutoAccept $autoAccept = null)
    {
        $this->module = $module;
        $this->clock = $clock;
        $this->notifier = $notifier ?: new IfirmaDataRequestNotifier();
        $this->autoAccept = $autoAccept ?: new IfirmaDataRequestAutoAccept();
    }

    /**
     * @param Module $module
     * @return IfirmaDataRequestService
     */
    public static function build(Module $module)
    {
        return new self($module);
    }

    /**
     * @return string
     */
    private function now()
    {
        return is_callable($this->clock) ? (string) call_user_func($this->clock) : date('Y-m-d H:i:s');
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) Configuration::get('IFIRMA_ALLOW_DATA_REQUEST');
    }

    /**
     * Whether a submitted form token is the one of this visitor (see IfirmaFormToken; the controllers call that class directly).
     *
     * @param mixed $given
     * @return bool
     */
    public static function isValidToken($given)
    {
        return IfirmaFormToken::isValid($given, Context::getContext()->cookie, IfirmaFormToken::cookieKey());
    }

    /**
     * @param Order $order
     * @param Customer $customer
     * @return array array('allowed' => bool, 'reason' => string)
     */
    public function canRequest(Order $order, Customer $customer)
    {
        if (!$this->isEnabled()) {
            return $this->verdict('disabled');
        }
        if (!$customer->id || (int) $order->id_customer !== (int) $customer->id) {
            return $this->verdict('not_owner');
        }
        $receipt = IfirmaReceipt::getByOrder((int) $order->id);
        if ($receipt && $receipt->isIssued()) {
            return $this->verdict('document_exists');
        }
        if ($this->hasPendingForOrder((int) $order->id)) {
            return $this->verdict('pending_exists');
        }
        if ($this->inCooldown((int) $order->id, (int) $customer->id)) {
            return $this->verdict('cooldown');
        }
        if ($this->dailyCapReached((int) $customer->id)) {
            return $this->verdict('daily_cap');
        }

        return $this->verdict('ok');
    }

    /**
     * The window starts when the customer's newest request for the order was created, or when it was
     * cancelled; an administrator's approval or rejection does not restart it.
     *
     * @param int $idOrder
     * @param int $idCustomer
     * @return bool true when that moment is less than COOLDOWN seconds ago
     */
    private function inCooldown($idOrder, $idCustomer)
    {
        $row = Db::getInstance()->getRow(
            'SELECT status, date_add, date_resolved FROM `' . _DB_PREFIX_ . 'ifirma_data_request`
             WHERE id_order = ' . (int) $idOrder . ' AND id_customer = ' . (int) $idCustomer . '
             ORDER BY date_add DESC, id_ifirma_data_request DESC'
        ); // no LIMIT here: Db::getRow() appends its own "LIMIT 1" and a second one is a syntax error on MariaDB
        if (!is_array($row) || empty($row['date_add'])) {
            return false;
        }
        $from = $row['date_add'];
        if (isset($row['status']) && $row['status'] === IfirmaDataRequest::STATUS_CANCELLED && !empty($row['date_resolved'])) {
            $from = $row['date_resolved'];
        }
        $at = strtotime((string) $from);

        return $at !== false && strtotime($this->now()) - $at < self::COOLDOWN;
    }

    /**
     * @param int $idCustomer
     * @return bool true when the customer created DAILY_CAP requests in the last 24 hours
     */
    private function dailyCapReached($idCustomer)
    {
        $since = date('Y-m-d H:i:s', strtotime($this->now()) - 86400);
        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_data_request`
             WHERE id_customer = ' . (int) $idCustomer . '
             AND date_add >= "' . pSQL($since) . '"'
        );

        return $count >= self::DAILY_CAP;
    }

    /**
     * @param string $reason
     * @return array
     */
    private function verdict($reason)
    {
        return array('allowed' => $reason === 'ok', 'reason' => $reason);
    }

    /**
     * @param string $reason
     * @return string customer facing text
     */
    public function reasonMessage($reason)
    {
        switch ($reason) {
            case 'disabled':
                return $this->module->l('Changing the invoice data is not available.', 'ifirmadatarequestservice');
            case 'not_owner':
                return $this->module->l('This order does not belong to you.', 'ifirmadatarequestservice');
            case 'document_exists':
                return $this->module->l('The document has already been issued; please contact the shop to change it.', 'ifirmadatarequestservice');
            case 'pending_exists':
                return $this->module->l('A change request for this order is already pending.', 'ifirmadatarequestservice');
            case 'not_pending':
                return $this->module->l('This request is no longer pending.', 'ifirmadatarequestservice');
            case 'reason_required':
                return $this->module->l('A reason is required.', 'ifirmadatarequestservice');
            case 'invalid_token':
                return $this->module->l('Invalid form token.', 'ifirmadatarequestservice');
            case 'cooldown':
                return $this->module->l('Please wait 10 minutes before sending another request for this order.', 'ifirmadatarequestservice');
            case 'daily_cap':
                return $this->module->l('You have reached the limit of requests for today.', 'ifirmadatarequestservice');
            case 'busy':
                return $this->module->l('Another request for this order is being processed. Please wait a moment and try again.', 'ifirmadatarequestservice');
        }

        return $this->module->l('The request could not be processed.', 'ifirmadatarequestservice');
    }

    /**
     * @return string[] accepted input keys
     */
    public function fields()
    {
        return array(
            'want_invoice_for_company', 'company', 'vat_number', 'firstname', 'lastname',
            'address1', 'address2', 'postcode', 'city', 'id_country', 'customer_note',
        );
    }

    /**
     * Reads the posted form: every field of fields(), in that order, through Tools::getValue()
     * (so a field that was not posted is false). Nothing else of the request is read.
     *
     * @return array field => value as the request holds it
     */
    public function collectInput()
    {
        $input = array();
        foreach ($this->fields() as $field) {
            $input[$field] = Tools::getValue($field);
        }

        return $input;
    }

    /**
     * The values the form starts from when nothing was submitted: those of the invoice address.
     * The VAT number falls back to the dni; a company is only ticked when the address has both a
     * company name and a number. The values are shown as the address holds them.
     *
     * @param Address $address
     * @return array the keys of fields(), in that order; customer_note is empty
     */
    public function defaultsFromAddress(Address $address)
    {
        $vat = $this->invoiceVat($address);

        return array(
            'want_invoice_for_company' => $address->company !== '' && $address->company !== null && $vat,
            'company' => $address->company,
            'vat_number' => $vat,
            'firstname' => $address->firstname,
            'lastname' => $address->lastname,
            'address1' => $address->address1,
            'address2' => $address->address2,
            'postcode' => $address->postcode,
            'city' => $address->city,
            'id_country' => $address->id_country,
            'customer_note' => '',
        );
    }

    /**
     * The VAT number an address is invoiced under: its vat_number, or its dni when that is blank.
     *
     * @param Address $address
     * @return string|null the vat_number as the address holds it (not trimmed), else its dni as it
     *                     holds it (null when it holds none)
     */
    private function invoiceVat(Address $address)
    {
        return trim((string) $address->vat_number) !== '' ? $address->vat_number : $address->dni;
    }

    /**
     * @param string $value
     * @return string digits only
     */
    public function normalizeNip($value)
    {
        $value = preg_replace('/^\s*PL/i', '', (string) $value);

        return preg_replace('/\D/', '', $value);
    }

    /**
     * @param array $input
     * @return array field => message
     */
    public function validate(array $input)
    {
        $errors = array();
        $get = function ($key) use ($input) {
            return isset($input[$key]) && !is_array($input[$key]) ? trim((string) $input[$key]) : '';
        };
        $required = $this->module->l('This field is required.', 'ifirmadatarequestservice');

        if ($get('postcode') === '') {
            $errors['postcode'] = $required;
        }
        if ($get('city') === '') {
            $errors['city'] = $required;
        }

        if (!empty($input['want_invoice_for_company'])) {
            if ($get('company') === '') {
                $errors['company'] = $required;
            }
            if ($get('vat_number') === '') {
                $errors['vat_number'] = $required;
            } elseif (strlen($this->normalizeNip($get('vat_number'))) !== 10) {
                $errors['vat_number'] = $this->module->l('The VAT number must have exactly 10 digits.', 'ifirmadatarequestservice');
            }
        } else {
            if ($get('firstname') === '') {
                $errors['firstname'] = $required;
            }
            if ($get('lastname') === '') {
                $errors['lastname'] = $required;
            }
        }

        if (isset($input['id_country']) && $input['id_country'] !== '' && $input['id_country'] !== null
            && (!is_scalar($input['id_country']) || !ctype_digit((string) $input['id_country']) || (int) $input['id_country'] <= 0)) {
            $errors['id_country'] = $this->module->l('Select a valid country.', 'ifirmadatarequestservice');
        } elseif ($get('id_country') === '') {
            $errors['id_country'] = $this->module->l('Please choose a country.', 'ifirmadatarequestservice');
        }

        $invalid = $this->module->l('This value contains characters that are not allowed.', 'ifirmadatarequestservice');
        foreach (array('firstname' => 'isName', 'lastname' => 'isName', 'address1' => 'isAddress',
            'address2' => 'isAddress', 'city' => 'isCityName') as $field => $check) {
            if (!isset($errors[$field]) && $get($field) !== '' && !Validate::$check($get($field))) {
                $errors[$field] = $invalid;
            }
        }

        if (!isset($errors['id_country']) && $get('id_country') !== '') {
            $country = new Country((int) $get('id_country'));
            if (!Validate::isLoadedObject($country) || !$country->active) {
                $errors['id_country'] = $this->module->l('Select a valid country.', 'ifirmadatarequestservice');
            } elseif (!isset($errors['postcode']) && !empty($country->zip_code_format)
                && !$country->checkZipCode($get('postcode'))) {
                $errors['postcode'] = $this->module->l('The postcode does not match the format of the selected country.', 'ifirmadatarequestservice');
            }
        }
        if (!isset($errors['postcode']) && $get('postcode') !== '' && !Validate::isPostCode($get('postcode'))) {
            $errors['postcode'] = $invalid;
        }

        $limits = array(
            'company' => 150, 'firstname' => 65, 'lastname' => 65, 'address1' => 65,
            'address2' => 65, 'postcode' => 16, 'city' => 65, 'customer_note' => 1000,
        );
        foreach ($limits as $field => $max) {
            if (!isset($errors[$field]) && mb_strlen($get($field), 'UTF-8') > $max) {
                $errors[$field] = sprintf($this->module->l('Maximum length is %d characters.', 'ifirmadatarequestservice'), $max);
            }
        }

        return $errors;
    }

    /**
     * Filtered, normalized data (without the note).
     *
     * @param array $input
     * @return array
     */
    private function normalizeData(array $input)
    {
        $get = function ($key) use ($input) {
            return isset($input[$key]) && !is_array($input[$key]) ? trim((string) $input[$key]) : '';
        };
        $company = !empty($input['want_invoice_for_company']);

        return array(
            'want_invoice_for_company' => $company,
            'company' => $company ? $get('company') : '',
            'vat_number' => $company ? $this->normalizeNip($get('vat_number')) : '',
            'firstname' => $get('firstname'),
            'lastname' => $get('lastname'),
            'address1' => $get('address1'),
            'address2' => $get('address2'),
            'postcode' => $get('postcode'),
            'city' => $get('city'),
            'id_country' => (int) $get('id_country'),
        );
    }

    /**
     * @param string $code
     * @param array $errors
     * @param string|null $message
     * @return IfirmaDataRequestException
     */
    private function fail($code, array $errors = array(), $message = null)
    {
        $e = new IfirmaDataRequestException($message !== null ? $message : $this->reasonMessage($code));
        $e->code_name = $code;
        $e->errors = $errors;

        return $e;
    }

    /**
     * @param mixed  $text  an admin note or a rejection reason
     * @param string $field the key of the field-level message
     * @throws IfirmaDataRequestException when the text is longer than ADMIN_TEXT_MAX characters
     */
    private function assertAdminText($text, $field)
    {
        if (is_array($text) || mb_strlen(trim((string) $text), 'UTF-8') <= self::ADMIN_TEXT_MAX) {
            return;
        }
        $message = sprintf($this->module->l('Maximum length is %d characters.', 'ifirmadatarequestservice'), self::ADMIN_TEXT_MAX);

        throw $this->fail('invalid', array($field => $message), $message);
    }

    /**
     * @param Order $order
     * @param Customer $customer
     * @param array $input
     * @return IfirmaDataRequest
     * @throws IfirmaDataRequestException
     */
    public function submit(Order $order, Customer $customer, array $input)
    {
        // The eligibility check and the save run under one lock per order: parallel submissions cannot all
        // pass "nothing pending / cool-down / daily cap" before any of them has stored its request.
        $lock = 'ifirma_request_' . (int) $order->id;
        if (!IfirmaNamedLock::acquire($lock, 2)) {
            throw $this->fail('busy');
        }
        try {
            $request = $this->store($order, $customer, $input);
        } finally {
            IfirmaNamedLock::release($lock);
        }
        if ($request->isApproved()) {
            $this->notifyCustomer($request, 'approved');
        }
        $this->notifyMerchant($request, $order);

        return $request;
    }

    /**
     * Checks, stores and (when the criteria say so) approves the request. Runs under the order lock of submit().
     *
     * @param Order $order
     * @param Customer $customer
     * @param array $input
     * @return IfirmaDataRequest
     * @throws IfirmaDataRequestException
     */
    private function store(Order $order, Customer $customer, array $input)
    {
        $verdict = $this->canRequest($order, $customer);
        if (!$verdict['allowed']) {
            throw $this->fail($verdict['reason']);
        }
        $errors = $this->validate($input);
        if (!empty($errors)) {
            throw $this->fail('invalid', $errors, $this->module->l('Please correct the highlighted fields.', 'ifirmadatarequestservice'));
        }

        $note = isset($input['customer_note']) && !is_array($input['customer_note']) ? trim((string) $input['customer_note']) : '';
        $request = new IfirmaDataRequest();
        $request->id_order = (int) $order->id;
        $request->id_customer = (int) $customer->id;
        $request->id_shop = (int) $order->id_shop ?: 1;
        $request->status = IfirmaDataRequest::STATUS_PENDING;
        $normalized = $this->normalizeData($input);
        $request->setRequestedData($normalized);
        $request->customer_note = $note;
        IfirmaSchema::ensureDataRequestAutoModeColumn();
        $request->save();

        $mode = $this->autoAcceptMode($normalized, $order);
        if ($mode !== null) {
            $request->approve(
                0,
                $normalized,
                $mode === IfirmaDataRequestAutoAccept::MODE_ALL
                    ? $this->module->l('Approved automatically: every valid request', 'ifirmadatarequestservice')
                    : $this->module->l('Approved automatically: safe fields only', 'ifirmadatarequestservice'),
                $this->now(),
                $mode
            );
            $request->save();
        }

        return $request;
    }

    /**
     * Asks the auto-accept criteria about a stored request. A failure of the check leaves the request
     * pending: the employee decides.
     *
     * @param array $normalized the request's data
     * @param Order $order
     * @return string|null the mode that accepts it (safe|all); null when it waits for an employee
     */
    private function autoAcceptMode(array $normalized, Order $order)
    {
        try {
            if ($this->autoAccept->mode() === IfirmaDataRequestAutoAccept::MODE_NONE) {
                return null;
            }

            return $this->autoAccept->decide($normalized, $this->currentValues(new Address((int) $order->id_address_invoice)));
        } catch (Exception $e) {
            PrestaShopLogger::addLog('iFirma auto-accept check failed (order ' . (int) $order->id . '): ' . $e->getMessage(), 2);

            return null;
        }
    }

    /**
     * @param IfirmaDataRequest $request
     * @param Customer $customer
     * @return bool
     * @throws IfirmaDataRequestException
     */
    public function cancel(IfirmaDataRequest $request, Customer $customer)
    {
        if (!$customer->id || (int) $request->id_customer !== (int) $customer->id) {
            throw $this->fail('not_owner');
        }
        if ($request->status !== IfirmaDataRequest::STATUS_PENDING) {
            throw $this->fail('not_pending');
        }
        $request->status = IfirmaDataRequest::STATUS_CANCELLED;
        $request->date_resolved = $this->now();
        $request->save();

        return true;
    }

    /**
     * @param IfirmaDataRequest $request
     * @param int $idEmployee
     * @param array|null $editedData
     * @param string $adminNote
     * @return IfirmaDataRequest
     * @throws IfirmaDataRequestException
     */
    public function approve(IfirmaDataRequest $request, $idEmployee, ?array $editedData = null, $adminNote = '')
    {
        // A resolved request is refused before its edits are looked at. The entity has no
        // translator, so its refusal is raised again here, worded by the module. approve() below
        // repeats the check and cannot refuse after this one.
        try {
            $request->assertPending();
        } catch (IfirmaDataRequestException $e) {
            throw $this->fail($e->code_name, $e->errors);
        }
        $this->assertAdminText($adminNote, 'admin_note');
        if ($editedData !== null) {
            $errors = $this->validate($editedData);
            if (!empty($errors)) {
                throw $this->fail('invalid', $errors, $this->module->l('Please correct the highlighted fields.', 'ifirmadatarequestservice'));
            }
            $final = $this->normalizeData($editedData);
        } else {
            $final = $this->normalizeData($request->getRequestedData());
        }

        $request->approve((int) $idEmployee, $final, $adminNote, $this->now());
        $request->save();

        $this->notifyCustomer($request, 'approved');

        return $request;
    }

    /**
     * @param IfirmaDataRequest $request
     * @param int $idEmployee
     * @param string $reason
     * @return IfirmaDataRequest
     * @throws IfirmaDataRequestException
     */
    public function reject(IfirmaDataRequest $request, $idEmployee, $reason)
    {
        try {
            $request->assertPending();
        } catch (IfirmaDataRequestException $e) {
            throw $this->fail($e->code_name, $e->errors);
        }
        $this->assertAdminText($reason, 'reason');
        try {
            $request->reject((int) $idEmployee, $reason, $this->now());
        } catch (IfirmaDataRequestException $e) {
            throw $this->fail($e->code_name, $e->errors);
        }
        $request->save();

        $this->notifyCustomer($request, 'rejected');

        return $request;
    }

    /**
     * @param int $idOrder
     * @return bool
     */
    public function hasPendingForOrder($idOrder)
    {
        return $this->getPendingForOrder($idOrder) !== null;
    }

    /**
     * @param int $idOrder
     * @return IfirmaDataRequest|null
     */
    public function getPendingForOrder($idOrder)
    {
        return IfirmaDataRequest::getPendingByOrder((int) $idOrder);
    }

    /**
     * @param int $idOrder
     * @return array|null override for IfirmaPayloadBuilder::buildContractor()
     */
    public function getOverrideForOrder($idOrder)
    {
        $request = IfirmaDataRequest::getApprovedByOrder((int) $idOrder);
        if (!$request) {
            return null;
        }
        $data = $request->getApprovedData();
        if (empty($data)) {
            return null;
        }

        return $this->normalizeData($data);
    }

    /**
     * The values of an address the comparison with a request works on.
     *
     * @param Address $current
     * @return array field => string; vat_number is normalised (digits only), id_country is the id as a string
     */
    public function currentValues(Address $current)
    {
        return array(
            'company' => (string) $current->company,
            'vat_number' => $this->normalizeNip($this->invoiceVat($current)),
            'firstname' => (string) $current->firstname,
            'lastname' => (string) $current->lastname,
            'address1' => (string) $current->address1,
            'address2' => (string) $current->address2,
            'postcode' => (string) $current->postcode,
            'city' => (string) $current->city,
            'id_country' => (string) (int) $current->id_country,
        );
    }

    /**
     * @param array $requested
     * @param Address $current
     * @return array list of array('field', 'current', 'requested')
     */
    public function buildDiff(array $requested, Address $current)
    {
        $diff = array();
        foreach ($this->currentValues($current) as $field => $value) {
            $new = isset($requested[$field]) ? trim((string) $requested[$field]) : '';
            if ($field === 'id_country') {
                $new = (string) (int) $new;
            }
            if (trim($value) !== $new) {
                $diff[] = array('field' => $field, 'current' => trim($value), 'requested' => $new);
            }
        }

        return $diff;
    }

    /**
     * @param IfirmaDataRequest $request
     * @param Order $order
     * @return bool
     */
    public function notifyMerchant(IfirmaDataRequest $request, Order $order)
    {
        $to = $this->notifier->merchantAddress();
        if ($to === '') {
            return false;
        }

        $customer = new Customer((int) $request->id_customer);
        $requested = $request->getRequestedData();
        $diffLines = array();
        foreach ($this->buildDiff($requested, new Address((int) $order->id_address_invoice)) as $d) {
            $diffLines[] = $d['field'] . ': ' . ($d['current'] === '' ? '-' : $d['current'])
                . ' -> ' . ($d['requested'] === '' ? '-' : $d['requested']);
        }

        $context = Context::getContext();
        $adminLink = '';
        try {
            if (isset($context->link) && method_exists($context->link, 'getAdminLink')) {
                $adminLink = (string) $context->link->getAdminLink(
                    'AdminOrders',
                    true,
                    array(),
                    array('id_order' => (int) $order->id, 'vieworder' => 1)
                );
            }
        } catch (Exception $e) {
            $adminLink = '';
        }

        $vars = array(
            '{order_reference}' => $this->esc($order->reference),
            '{order_id}' => (int) $order->id,
            '{customer_name}' => $this->esc(trim($customer->firstname . ' ' . $customer->lastname)),
            '{customer_email}' => $this->esc($customer->email),
            '{requested_data}' => $this->renderData($requested),
            '{diff}' => $diffLines ? $this->esc(implode("\n", $diffLines)) : $this->module->l('No differences.', 'ifirmadatarequestservice'),
            '{note}' => $this->esc($request->customer_note),
            '{admin_link}' => $adminLink,
            '{auto_line}' => $this->autoLine($request),
        );

        return $this->notifier->sendToMerchant(
            $to,
            $order,
            $this->module->l('Invoice data change request', 'ifirmadatarequestservice') . ' ' . $order->reference,
            $vars
        );
    }

    /**
     * @param IfirmaDataRequest $request
     * @return string the merchant mail's line for an automatic approval; '' for any other request
     */
    private function autoLine(IfirmaDataRequest $request)
    {
        if (!$request->isAutoApproved()) {
            return '';
        }

        return $request->auto_mode === IfirmaDataRequestAutoAccept::MODE_ALL
            ? $this->module->l('Approved automatically (mode: all).', 'ifirmadatarequestservice')
            : $this->module->l('Approved automatically (mode: safe).', 'ifirmadatarequestservice');
    }

    /**
     * @param IfirmaDataRequest $request
     * @param string $decision approved|rejected
     * @return bool
     */
    public function notifyCustomer(IfirmaDataRequest $request, $decision)
    {
        $decision = $decision === 'approved' ? 'approved' : 'rejected';
        $customer = new Customer((int) $request->id_customer);
        if (!$customer->email) {
            return false;
        }
        $order = new Order((int) $request->id_order);

        $vars = array(
            '{firstname}' => $this->esc($customer->firstname),
            '{order_reference}' => $this->esc($order->reference),
            '{reason}' => $this->esc($request->admin_note),
            '{approved_data}' => $decision === 'approved' ? $this->renderData($request->getApprovedData()) : '',
        );
        $subject = $decision === 'approved'
            ? $this->module->l('Your invoice data change was approved', 'ifirmadatarequestservice')
            : $this->module->l('Your invoice data change was rejected', 'ifirmadatarequestservice');

        return $this->notifier->sendToCustomer($decision, $customer, $order, $subject, $vars);
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param array $data
     * @return string escaped "field: value" lines (templates use white-space: pre-line)
     */
    private function renderData(array $data)
    {
        $lines = array();
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            if ($value === '' || $value === null || $value === 0) {
                continue;
            }
            $lines[] = $key . ': ' . (is_scalar($value) ? $value : json_encode($value));
        }

        return $this->esc(implode("\n", $lines));
    }

    /**
     * @param IfirmaDataRequest $request
     * @return array
     */
    public function summarize(IfirmaDataRequest $request)
    {
        return array(
            'id' => (int) $request->id,
            'status' => $request->status,
            'date_add' => $request->date_add,
            'date_upd' => $request->date_upd,
            'date_resolved' => $request->date_resolved,
            'requester' => (int) $request->id_customer,
            'resolved_by' => $request->resolved_by === null ? null : (int) $request->resolved_by,
            'requested_data' => $request->getRequestedData(),
            'approved_data' => $request->getApprovedData(),
            'admin_note' => (string) $request->admin_note,
            'customer_note' => (string) $request->customer_note,
        );
    }
}
