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

/**
 * The invoice data of one customer: the names and e-mail of the customer, and the company, phone and
 * NIP of the latest invoice address, with the fallbacks the contractor search form starts from.
 *
 * load() reads the customer and the invoice addresses once. The accessors answer from what it loaded,
 * so a caller that needs several answers does not read the database again for each of them.
 */
class IfirmaCustomerInvoiceProfile
{
    /** @var Customer */
    private $customer;

    /** @var Address[] invoice addresses, the latest first */
    private $addresses;

    /** @var Address|null the latest invoice address: company, phone and the form's NIP come from it */
    private $latest;

    /**
     * @param Customer  $customer
     * @param Address[] $addresses invoice addresses, the latest first
     */
    public function __construct(Customer $customer, array $addresses)
    {
        $this->customer = $customer;
        $this->addresses = $addresses;
        $this->latest = $addresses ? reset($addresses) : null;
    }

    /**
     * Reads the customer and the invoice addresses: those of the customer's orders in the order
     * Order::getCustomerOrders() returns them (newest first), then the customer's own addresses
     * (highest id first), each address once. An address that does not load is left out.
     *
     * @param int $idCustomer
     * @return IfirmaCustomerInvoiceProfile
     */
    public static function load($idCustomer)
    {
        $orderIds = self::orderAddressIds($idCustomer);
        $customer = new Customer((int) $idCustomer);
        $ids = array_merge($orderIds, self::ownAddressIds($customer));

        return new self($customer, self::loadAddresses($ids));
    }

    /**
     * @return string first name of the customer, trimmed
     */
    public function firstname()
    {
        return trim((string) $this->customer->firstname);
    }

    /**
     * @return string last name of the customer, trimmed
     */
    public function lastname()
    {
        return trim((string) $this->customer->lastname);
    }

    /**
     * @return string e-mail of the customer, trimmed
     */
    public function email()
    {
        return trim((string) $this->customer->email);
    }

    /**
     * @return string company of the latest address, trimmed; the company of the customer when the
     *                address has none
     */
    public function company()
    {
        $company = $this->latest ? trim((string) $this->latest->company) : '';

        return $company !== '' ? $company : trim((string) $this->customer->company);
    }

    /**
     * @return string phone of the latest address, trimmed; its mobile phone when it has no phone;
     *                '' without an address
     */
    public function phone()
    {
        if (!$this->latest) {
            return '';
        }
        $phone = trim((string) $this->latest->phone);

        return $phone !== '' ? $phone : trim((string) $this->latest->phone_mobile);
    }

    /**
     * The NIP the search form starts from.
     *
     * @return string the digits of the VAT number of the latest address, whatever their count; when
     *                it has none, tenDigitNip()
     */
    public function nip()
    {
        $nip = $this->latest ? self::digits($this->latest->vat_number) : '';

        return $nip !== '' ? $nip : $this->tenDigitNip();
    }

    /**
     * The NIP the remote document count searches by.
     *
     * @return string the digits of the first address, latest first, whose VAT number has exactly
     *                ten, or ''
     */
    public function tenDigitNip()
    {
        foreach ($this->addresses as $address) {
            $digits = self::digits($address->vat_number);
            if (strlen($digits) === 10) {
                return $digits;
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     * @return string only the digits of $value
     */
    private static function digits($value)
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    /**
     * @param int $idCustomer
     * @return int[] invoice address of every order, in the order the orders come; none when the
     *               query throws an Exception or answers with no list
     */
    private static function orderAddressIds($idCustomer)
    {
        try {
            $orders = Order::getCustomerOrders((int) $idCustomer);
        } catch (Exception $e) {
            $orders = array();
        }
        $ids = array();
        if (is_array($orders)) {
            foreach ($orders as $order) {
                if (!empty($order['id_address_invoice'])) {
                    $ids[] = (int) $order['id_address_invoice'];
                }
            }
        }

        return $ids;
    }

    /**
     * @param Customer $customer
     * @return int[] ids of the customer's own addresses, highest first
     */
    private static function ownAddressIds(Customer $customer)
    {
        $idLang = (int) Context::getContext()->language->id;
        $own = array();
        foreach ((array) $customer->getAddresses($idLang) as $row) {
            if (isset($row['id_address'])) {
                $own[] = (int) $row['id_address'];
            }
        }
        rsort($own);

        return $own;
    }

    /**
     * @param int[] $ids
     * @return Address[] each address once, in the order of $ids; those that do not load are left out
     */
    private static function loadAddresses(array $ids)
    {
        $addresses = array();
        foreach (array_unique($ids) as $id) {
            $address = new Address($id);
            if (Validate::isLoadedObject($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
