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
 * Customer request to change the buyer data on a receipt/invoice.
 */
class IfirmaDataRequest extends ObjectModel
{
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    /** @var int */
    public $id_ifirma_data_request;

    /** @var int */
    public $id_order;

    /** @var int */
    public $id_customer = 0;

    /** @var int */
    public $id_shop = 1;

    /** @var string */
    public $status = self::STATUS_PENDING;

    /** @var string|null JSON */
    public $requested_data;

    /** @var string|null JSON */
    public $approved_data;

    /** @var string|null */
    public $customer_note;

    /** @var string|null */
    public $admin_note;

    /** @var int|null */
    public $resolved_by;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    /** @var string|null */
    public $date_resolved;

    public static $definition = array(
        'table' => 'ifirma_data_request',
        'primary' => 'id_ifirma_data_request',
        'fields' => array(
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_customer' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'id_shop' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'status' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'requested_data' => array('type' => self::TYPE_HTML),
            'approved_data' => array('type' => self::TYPE_HTML),
            'customer_note' => array('type' => self::TYPE_HTML),
            'admin_note' => array('type' => self::TYPE_HTML),
            'resolved_by' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_resolved' => array('type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true),
        ),
    );

    /**
     * @param string|null $json
     * @return array
     */
    private static function decode($json)
    {
        if (!$json) {
            return array();
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @return array
     */
    public function getRequestedData()
    {
        return self::decode($this->requested_data);
    }

    /**
     * @param array $data
     */
    public function setRequestedData(array $data)
    {
        $this->requested_data = json_encode($data);
    }

    /**
     * @return array
     */
    public function getApprovedData()
    {
        return self::decode($this->approved_data);
    }

    /**
     * @param array $data
     */
    public function setApprovedData(array $data)
    {
        $this->approved_data = json_encode($data);
    }

    /**
     * @param int $id
     * @return IfirmaDataRequest|null
     */
    public static function getById($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }
        $request = new self($id);

        return Validate::isLoadedObject($request) ? $request : null;
    }

    /**
     * @param int $idOrder
     * @return IfirmaDataRequest|null
     */
    public static function getPendingByOrder($idOrder)
    {
        return self::loadFirst($idOrder, self::STATUS_PENDING);
    }

    /**
     * @param int $idOrder
     * @return IfirmaDataRequest|null latest approved request
     */
    public static function getApprovedByOrder($idOrder)
    {
        return self::loadFirst($idOrder, self::STATUS_APPROVED);
    }

    /**
     * @param int $idOrder
     * @param string $status
     * @return IfirmaDataRequest|null
     */
    private static function loadFirst($idOrder, $status)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT id_ifirma_data_request FROM `' . _DB_PREFIX_ . 'ifirma_data_request`
             WHERE id_order = ' . (int) $idOrder . '
             AND status = "' . pSQL($status) . '"
             ORDER BY id_ifirma_data_request DESC'
        );

        return $id ? self::getById($id) : null;
    }

    /**
     * @param int $idOrder
     * @return array rows, newest first
     */
    public static function getByOrder($idOrder)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ifirma_data_request`
             WHERE id_order = ' . (int) $idOrder . '
             ORDER BY id_ifirma_data_request DESC'
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * @param int $idCustomer
     * @return int
     */
    public static function countPendingByCustomer($idCustomer)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_data_request`
             WHERE id_customer = ' . (int) $idCustomer . '
             AND status = "' . pSQL(self::STATUS_PENDING) . '"'
        );
    }
}
