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
 * Raised when a data-change request action is refused.
 *
 * `code_name` is a machine readable reason (disabled, not_owner, document_exists,
 * pending_exists, not_pending, reason_required, invalid, invalid_token),
 * `errors` holds field => message for validation failures.
 */
class IfirmaDataRequestException extends Exception
{
    /** @var array field => message */
    public $errors = array();

    /** @var string */
    public $code_name = '';
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
            // TYPE_HTML on purpose: ObjectModel writes TYPE_STRING through strip_tags(nl2br()), which would
            // destroy line breaks and "<" in a note. The text is validated to 1000 characters by the service and
            // always escaped on output.
            'customer_note' => array('type' => self::TYPE_HTML, 'size' => 1000),
            'admin_note' => array('type' => self::TYPE_HTML, 'size' => 1000),
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
     * Refuses a request that is not waiting for a decision any more.
     *
     * @return void
     * @throws IfirmaDataRequestException not_pending
     */
    public function assertPending()
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw self::refusal('not_pending', 'This request is no longer pending.');
        }
    }

    /**
     * Marks the request approved with the final buyer data. Only sets fields: saving it and
     * telling the customer stay with the caller.
     *
     * @param int    $employeeId
     * @param array  $final normalized buyer data the document is issued with
     * @param string $note  admin note, stored trimmed
     * @param string $now   "Y-m-d H:i:s"
     * @return void
     * @throws IfirmaDataRequestException not_pending
     */
    public function approve($employeeId, array $final, $note, $now)
    {
        $this->assertPending();
        $this->setApprovedData($final);
        $this->status = self::STATUS_APPROVED;
        $this->resolved_by = (int) $employeeId;
        $this->admin_note = trim((string) $note);
        $this->date_resolved = $now;
    }

    /**
     * Marks the request rejected. Only sets fields, like approve().
     *
     * @param int    $employeeId
     * @param string $note  why the request was rejected: required, stored trimmed
     * @param string $now   "Y-m-d H:i:s"
     * @return void
     * @throws IfirmaDataRequestException not_pending, reason_required
     */
    public function reject($employeeId, $note, $now)
    {
        $this->assertPending();
        $reason = trim((string) $note);
        if ($reason === '') {
            throw self::refusal('reason_required', 'A reason is required.');
        }
        $this->status = self::STATUS_REJECTED;
        $this->admin_note = $reason;
        $this->resolved_by = (int) $employeeId;
        $this->date_resolved = $now;
    }

    /**
     * The entity has no translator, so the message is the untranslated default; the service
     * raises the same refusal again, worded in the module language.
     *
     * @param string $code machine readable reason
     * @param string $message
     * @return IfirmaDataRequestException
     */
    private static function refusal($code, $message)
    {
        $e = new IfirmaDataRequestException($message);
        $e->code_name = $code;

        return $e;
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

    /**
     * @return int orders held by pending data requests
     */
    public static function countOrdersOnHold()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(DISTINCT id_order) FROM `' . _DB_PREFIX_ . 'ifirma_data_request`
             WHERE status = \'pending\''
        );
    }
}
