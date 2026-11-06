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
require_once dirname(__FILE__) . '/IfirmaMatchLabels.php';
require_once dirname(__FILE__) . '/IfirmaContractorService.php';
require_once dirname(__FILE__) . '/IfirmaCustomerContractorService.php';
require_once dirname(__FILE__) . '/IfirmaReceiptService.php';
require_once dirname(__FILE__) . '/IfirmaPayloadBuilder.php';
require_once dirname(__FILE__) . '/IfirmaDataRequestService.php';

/**
 * Admin actions on contractors: registering the customer of an order in iFirma, and the customer page
 * (check, search, link, unlink, register, count the documents).
 *
 * Reached through IfirmaAdminActions.
 */
class IfirmaCustomerActions extends IfirmaActionSupport
{
    /** @var IfirmaCustomerContractorService|null */
    private $customerService;

    /**
     * Registers the order customer as an iFirma contractor without issuing a document.
     *
     * @param int $idOrder
     * @return array
     */
    public function registerContractor($idOrder)
    {
        return $this->guard(function () use ($idOrder) {
            $order = $this->loadOrder($idOrder);
            if (!$order) {
                return $this->result(false, $this->module->l('Invalid order.', 'ifirmaadminactions'));
            }
            if (!IfirmaContractorService::build($this->module)->isEnabled()) {
                return $this->result(
                    false,
                    $this->module->l('Contractor registration is disabled in the module settings.', 'ifirmaadminactions')
                );
            }
            $contractor = $this->contractorRecord($order, $idOrder);
            $resolution = $this->resolveContractor($order, $idOrder, $contractor);

            return $this->describeResolution($resolution, (string) $contractor['Identyfikator']);
        });
    }

    /**
     * The contractor record of the order: built from the buyer data of its approved data request when it has one,
     * from its invoice address otherwise.
     *
     * @param Order $order
     * @param int   $idOrder
     * @return array IfirmaPayloadBuilder::buildContractor() result
     */
    private function contractorRecord(Order $order, $idOrder)
    {
        return IfirmaPayloadBuilder::build($this->module)->buildContractor(
            $order,
            IfirmaDataRequestService::build($this->module)->getOverrideForOrder($idOrder)
        );
    }

    /**
     * Has iFirma look the contractor up and create or update it, matching it with the hints of the order.
     *
     * @param Order $order
     * @param int   $idOrder
     * @param array $contractor the record from contractorRecord()
     * @return IfirmaContractorResolution
     */
    private function resolveContractor(Order $order, $idOrder, array $contractor)
    {
        $receiptService = IfirmaReceiptService::build($this->module);
        $override = IfirmaDataRequestService::build($this->module)->getOverrideForOrder($idOrder);

        return IfirmaContractorService::build($this->module)->ensure(
            $contractor,
            (int) $order->id_customer,
            $receiptService->contractorHints($order, $contractor, $override)
        );
    }

    /**
     * The answer for what the registration came to: the contractor the customer stands for and what was done to it,
     * with the way the customer was linked in front of the sentence when it was linked to an existing contractor.
     *
     * @param IfirmaContractorResolution $resolution
     * @param string                     $moduleIdentifier the identifier the module gives the contractor itself
     * @return array
     */
    private function describeResolution($resolution, $moduleIdentifier)
    {
        $identifier = $resolution->getIdentifier() !== null ? (string) $resolution->getIdentifier() : $moduleIdentifier;
        $matchedBy = $resolution->getMatchedBy();
        $data = array(
            'identifier' => $identifier,
            'action' => $resolution->getAction(),
            'message' => $resolution->getMessage(),
            'matched_by' => $matchedBy,
            'note' => $resolution->getNote(),
            'assigned_identifier' => $resolution->getAssignedId(),
        );
        $linkNote = $this->linkNote($matchedBy, $identifier, $moduleIdentifier);
        $message = $this->successMessage($resolution->getAction(), $identifier);
        if ($message === null) {
            return $this->result(
                false,
                sprintf(
                    $this->module->l('Contractor %s could not be registered: %s', 'ifirmaadminactions'),
                    $identifier,
                    $resolution->getMessage()
                ),
                $data
            );
        }

        return $this->result(true, $linkNote === null ? $message : ucfirst($linkNote) . '. ' . $message, $data);
    }

    /**
     * Says that the customer was linked to a contractor that already existed in iFirma, and how it was found.
     *
     * @param string $matchedBy        how the contractor was found (IfirmaContractorMap::BY_*)
     * @param string $identifier       the contractor the customer stands for
     * @param string $moduleIdentifier the identifier the module gives the contractor itself
     * @return string|null null for a contractor the module made itself, one it found under its own identifier or one
     *                     found in a way the module has no label for
     */
    private function linkNote($matchedBy, $identifier, $moduleIdentifier)
    {
        $matchLabels = $this->module->matchLabels();
        if (!in_array($matchedBy, $matchLabels->linkCodes(), true) || $identifier === $moduleIdentifier) {
            return null;
        }
        $matchedLabel = $matchLabels->label($matchedBy);

        return sprintf(
            $this->module->l('linked to existing contractor %s (matched by %s)', 'ifirmaadminactions'),
            $identifier,
            $matchedLabel
        );
    }

    /**
     * @param string $action     IfirmaContractorService::ACTION_*
     * @param string $identifier the contractor the customer stands for
     * @return string|null what was done to the contractor in iFirma; null when the registration failed
     */
    private function successMessage($action, $identifier)
    {
        switch ($action) {
            case IfirmaContractorService::ACTION_CREATED:
                return sprintf($this->module->l('Contractor %s created in iFirma.', 'ifirmaadminactions'), $identifier);
            case IfirmaContractorService::ACTION_UPDATED:
                return sprintf($this->module->l('Contractor %s updated in iFirma.', 'ifirmaadminactions'), $identifier);
            case IfirmaContractorService::ACTION_KEPT:
                return sprintf(
                    $this->module->l('Contractor %s already exists in iFirma and is up to date.', 'ifirmaadminactions'),
                    $identifier
                );
            default:
                return null;
        }
    }

    // ---- customer page (customer-contractor-panel) -----------------------------------------

    /**
     * Test seam: replaces the service built from the module configuration.
     *
     * @param IfirmaCustomerContractorService|null $service
     */
    public function setCustomerService(?IfirmaCustomerContractorService $service = null)
    {
        $this->customerService = $service;
    }

    /**
     * @return IfirmaCustomerContractorService
     */
    private function customerService()
    {
        if ($this->customerService === null) {
            $this->customerService = IfirmaCustomerContractorService::build($this->module);
        }

        return $this->customerService;
    }

    /**
     * @param int $idCustomer
     * @return Customer|null
     */
    private function loadCustomer($idCustomer)
    {
        if ((int) $idCustomer <= 0) {
            return null;
        }
        $customer = new Customer((int) $idCustomer);

        return Validate::isLoadedObject($customer) ? $customer : null;
    }

    /**
     * @return array
     */
    private function invalidCustomer()
    {
        return $this->result(false, $this->module->l('Invalid customer.', 'ifirmaadminactions'));
    }

    /**
     * Looks the shown identifier up in iFirma once.
     *
     * @param int $idCustomer
     * @return array
     */
    public function customerCheck($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $check = $this->customerService()->check((int) $idCustomer);
            switch ($check['state']) {
                case IfirmaCustomerContractorService::CHECK_REGISTERED:
                    $check['label'] = sprintf($this->module->l('Registered in iFirma as %s', 'ifirmaadminactions'), $check['identifier']);
                    break;
                case IfirmaCustomerContractorService::CHECK_MISSING:
                    $check['label'] = $this->module->l('Not found in iFirma', 'ifirmaadminactions');
                    break;
                default:
                    $check['label'] = sprintf($this->module->l('Could not check: %s', 'ifirmaadminactions'), $check['message']);
            }

            return $this->result(
                $check['state'] !== IfirmaCustomerContractorService::CHECK_ERROR,
                $check['label'],
                array('check' => $check)
            );
        });
    }

    /**
     * @param array $criteria firstname, lastname, company, nip, email, phone
     * @return array
     */
    public function customerSearch(array $criteria)
    {
        return $this->guard(function () use ($criteria) {
            $search = $this->customerService()->searchCandidates($criteria);
            if (!empty($search['refused'])) {
                return $this->result(
                    false,
                    $this->module->l('iFirma searches contractors by name, company or NIP. E-mail and phone are only used to confirm the results; fill in a name, company or NIP.', 'ifirmaadminactions'),
                    array('search' => $search)
                );
            }

            $count = count($search['rows']);
            $message = $count > 0
                ? sprintf($this->module->l('Found %d contractor(s).', 'ifirmaadminactions'), $count)
                : $this->module->l('No contractor was found in iFirma.', 'ifirmaadminactions');
            if (!empty($search['errors'])) {
                $parts = array();
                foreach ($search['errors'] as $phrase => $error) {
                    $parts[] = $phrase . ': ' . $error;
                }
                $message .= ' ' . sprintf($this->module->l('Search failed for: %s', 'ifirmaadminactions'), implode('; ', $parts));
            }

            return $this->result(true, $message, array('search' => $search));
        });
    }

    /**
     * @param int    $idCustomer
     * @param string $identifier
     * @return array
     */
    public function customerLink($idCustomer, $identifier)
    {
        return $this->guard(function () use ($idCustomer, $identifier) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $service = $this->customerService();
            $link = $service->link((int) $idCustomer, $identifier);
            $data = array('link_state' => $service->linkState((int) $idCustomer));
            $shown = $link['identifier'];

            switch ($link['code']) {
                case IfirmaCustomerContractorService::LINK_LINKED:
                    return $this->result(true, sprintf($this->module->l('Customer linked to contractor %s.', 'ifirmaadminactions'), $shown), $data);
                case IfirmaCustomerContractorService::LINK_NOT_FOUND:
                    return $this->result(
                        false,
                        sprintf($this->module->l('Contractor %s was not found in iFirma; nothing was linked.', 'ifirmaadminactions'), $shown),
                        $data
                    );
                case IfirmaCustomerContractorService::LINK_ERROR:
                    return $this->result(
                        false,
                        sprintf($this->module->l('Contractor %s could not be checked: %s', 'ifirmaadminactions'), $shown, $link['message']),
                        $data
                    );
                default:
                    return $this->result(false, $this->module->l('Invalid contractor identifier.', 'ifirmaadminactions'), $data);
            }
        });
    }

    /**
     * @param int $idCustomer
     * @return array
     */
    public function customerUnlink($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $service = $this->customerService();
            $removed = $service->unlink((int) $idCustomer);
            $data = array('link_state' => $service->linkState((int) $idCustomer));

            if ($removed) {
                return $this->result(true, $this->module->l('The link was removed. Nothing was changed in iFirma.', 'ifirmaadminactions'), $data);
            }

            return $this->result(false, $this->module->l('The customer was not linked.', 'ifirmaadminactions'), $data);
        });
    }

    /**
     * Registers the customer through the newest order, using the order registration path.
     *
     * @param int $idCustomer
     * @return array data: contractor (as registerContractor), link_state
     */
    public function registerCustomer($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $orders = Order::getCustomerOrders((int) $idCustomer);
            if (empty($orders)) {
                return $this->result(false, $this->module->l('Registration needs at least one order of this customer.', 'ifirmaadminactions'));
            }
            $first = reset($orders);

            $result = $this->registerContractor((int) $first['id_order']);
            $result['data'] = array(
                'contractor' => $result['data'],
                'link_state' => $this->customerService()->linkState((int) $idCustomer),
            );

            return $result;
        });
    }

    /**
     * @param int $idCustomer
     * @return array
     */
    public function customerCountRemote($idCustomer)
    {
        return $this->guard(function () use ($idCustomer) {
            if (!$this->loadCustomer($idCustomer)) {
                return $this->invalidCustomer();
            }
            $count = $this->customerService()->remoteCount((int) $idCustomer);
            if (empty($count['ok'])) {
                return $this->result(
                    false,
                    sprintf($this->module->l('The documents could not be counted: %s', 'ifirmaadminactions'), $count['error']),
                    array('count' => $count)
                );
            }

            if (empty($count['complete']) && (string) $count['period_from'] === '') {
                // the call budget ran out before a single yearly window was listed completely
                return $this->result(
                    true,
                    sprintf(
                        $this->module->l('At least %1$d document(s) in iFirma (%2$d paid); the call limit was reached before a full year was listed.', 'ifirmaadminactions'),
                        (int) $count['total'],
                        (int) $count['paid']
                    ),
                    array('count' => $count)
                );
            }

            $format = !empty($count['complete'])
                ? $this->module->l('%1$d document(s) in iFirma (%2$d paid), %3$s – %4$s.', 'ifirmaadminactions')
                : $this->module->l('At least %1$d document(s) in iFirma (%2$d paid), %3$s – %4$s; the call limit was reached.', 'ifirmaadminactions');

            return $this->result(
                true,
                sprintf($format, (int) $count['total'], (int) $count['paid'], $count['period_from'], $count['period_to']),
                array('count' => $count)
            );
        });
    }
}
