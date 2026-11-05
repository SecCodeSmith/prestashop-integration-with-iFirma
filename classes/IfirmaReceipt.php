<?php
/**
 * @author    SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class IfirmaReceipt extends ObjectModel
{
    /** @var int */
    public $id_ifirma_receipt;

    /** @var int */
    public $id_order;

    /** @var int */
    public $id_customer;

    /** @var int */
    public $id_shop;

    /** @var string */
    public $document_type;

    /** @var string */
    public $ifirma_id;

    /** @var string */
    public $ifirma_number;

    /** @var string */
    public $status;

    /** @var float */
    public $paid_amount;

    /** @var string */
    public $security_token;

    /** @var string */
    public $request_payload;

    /** @var string */
    public $response_payload;

    /** @var string */
    public $last_error;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    /** @var string */
    public $source = 'module';

    /** @var string */
    public $order_reference = '';

    /** @var string|null YYYY-MM-DD issue date reported to/by iFirma */
    public $issue_date;

    /** @var string */
    public $delivery_status = 'none';

    /** @var string */
    public $delivery_channel = '';

    /** @var string|null */
    public $delivered_at;

    /** @var string|null */
    public $delivery_error;

    /** @var float|null */
    public $remote_paid_amount;

    /** @var bool */
    public $remote_sent = false;

    /** @var bool */
    public $remote_draft = false;

    /** @var bool */
    public $remote_missing = false;

    /** @var string */
    public $ksef_status = '';

    /** @var string */
    public $ksef_number = '';

    /** @var string|null */
    public $ksef_sent_at;

    /** @var string|null */
    public $ksef_error;

    /** @var string */
    public $linked_document = '';

    /** @var string|null */
    public $last_sync_at;

    /** @var string|null */
    public $sync_error;

    /** @var string|null JSON-encoded list */
    public $duplicate_candidates;

    /** @var int|null */
    public $id_data_request;

    const STATUS_CREATED = 'created';
    const STATUS_PAID = 'paid';
    const STATUS_ERROR = 'error';
    const STATUS_UNKNOWN = 'unknown';
    const STATUS_DUPLICATE_SUSPECTED = 'duplicate_suspected';
    /** A non-issued record whose last pipeline run stopped at the hold step. */
    const STATUS_HELD = 'held';

    const SOURCE_MODULE = 'module';
    const SOURCE_EXTERNAL = 'external';

    const DELIVERY_NONE = 'none';
    const DELIVERY_SENT = 'sent';
    const DELIVERY_FAILED = 'failed';
    const DELIVERY_SKIPPED = 'skipped';

    public static $definition = array(
        'table' => 'ifirma_receipt',
        'primary' => 'id_ifirma_receipt',
        'fields' => array(
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
            'id_customer' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'id_shop' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
            'document_type' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32),
            'ifirma_id' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64),
            'ifirma_number' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 128),
            'status' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32),
            'paid_amount' => array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
            'security_token' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64),
            'request_payload' => array('type' => self::TYPE_HTML),
            'response_payload' => array('type' => self::TYPE_HTML),
            'last_error' => array('type' => self::TYPE_HTML),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'source' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'order_reference' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64),
            'issue_date' => array('type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true),
            'delivery_status' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 16),
            'delivery_channel' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32),
            'delivered_at' => array('type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true),
            'delivery_error' => array('type' => self::TYPE_HTML),
            'remote_paid_amount' => array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'allow_null' => true),
            'remote_sent' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'remote_draft' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'remote_missing' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'ksef_status' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 32),
            'ksef_number' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64),
            'ksef_sent_at' => array('type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true),
            'ksef_error' => array('type' => self::TYPE_HTML),
            'linked_document' => array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 128),
            'last_sync_at' => array('type' => self::TYPE_DATE, 'validate' => 'isDate', 'allow_null' => true),
            'sync_error' => array('type' => self::TYPE_HTML),
            'duplicate_candidates' => array('type' => self::TYPE_HTML),
            'id_data_request' => array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true),
        ),
    );

    /**
     * @param int $idOrder
     * @return IfirmaReceipt|null
     */
    public static function getByOrder($idOrder)
    {
        $id = (int) Db::getInstance()->getValue(
            'SELECT id_ifirma_receipt FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE id_order = ' . (int) $idOrder . '
             ORDER BY id_ifirma_receipt DESC'
        );

        if (!$id) {
            return null;
        }

        return new self($id);
    }

    /**
     * @param string $token
     * @return IfirmaReceipt|null
     */
    public static function getByToken($token)
    {
        $token = pSQL($token);
        if ($token === '') {
            return null;
        }

        $id = (int) Db::getInstance()->getValue(
            'SELECT id_ifirma_receipt FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE security_token = "' . $token . '"'
        );

        if (!$id) {
            return null;
        }

        return new self($id);
    }

    /**
     * @param int $idCustomer
     * @return array
     */
    public static function getByCustomer($idCustomer)
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE id_customer = ' . (int) $idCustomer . '
             AND status != "error"
             ORDER BY id_ifirma_receipt DESC'
        );
    }

    /**
     * Number of the customer's documents per document type and status (every status, errors
     * included, external rows too).
     *
     * @param int $idCustomer
     * @return array rows of document_type, status, total (int)
     */
    public static function countByCustomer($idCustomer)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT document_type, status, COUNT(*) AS total FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE id_customer = ' . (int) $idCustomer . '
             GROUP BY document_type, status'
        );
        $out = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[] = array(
                    'document_type' => (string) $row['document_type'],
                    'status' => (string) $row['status'],
                    'total' => (int) $row['total'],
                );
            }
        }

        return $out;
    }

    /**
     * @return bool
     */
    public function isIssued()
    {
        return (bool) $this->ifirma_id && $this->status !== 'error';
    }

    /**
     * @return bool true when the document was issued outside the module
     */
    public function isExternal()
    {
        return $this->source === self::SOURCE_EXTERNAL;
    }

    /**
     * @return array decoded duplicate candidates (empty when none/invalid)
     */
    public function getDuplicateCandidates()
    {
        if (!$this->duplicate_candidates) {
            return array();
        }

        $decoded = json_decode($this->duplicate_candidates, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array $candidates
     */
    public function setDuplicateCandidates(array $candidates)
    {
        $this->duplicate_candidates = empty($candidates) ? null : json_encode(array_values($candidates));
    }

    /**
     * Marks the record as failed: the document was not issued. Sets the fields only; the caller
     * saves.
     *
     * @param string $message stored as given in last_error
     */
    public function markError($message)
    {
        $this->status = self::STATUS_ERROR;
        $this->last_error = $message;
    }

    /**
     * Marks the outcome as unknown: the request reached iFirma, so the document may exist there.
     * Sets the fields only; the caller saves.
     *
     * @param string $message stored as given in last_error
     */
    public function markUnknown($message)
    {
        $this->status = self::STATUS_UNKNOWN;
        $this->last_error = $message;
    }

    /**
     * Marks a non-issued record as held: the last pipeline run stopped at the hold step. Sets the
     * fields only; the caller saves.
     *
     * @param string $reason stored as given in last_error
     */
    public function markHeld($reason)
    {
        $this->status = self::STATUS_HELD;
        $this->last_error = $reason;
    }

    /**
     * Records a delivery outcome. Sets the fields only; the caller saves. delivered_at is not
     * part of it: a send stamps it, a disabled channel clears it and the other outcomes keep it,
     * so the caller decides.
     *
     * @param string $status  one of the DELIVERY_* constants
     * @param string $channel
     * @param string $error   '' when there is none
     */
    public function recordDelivery($status, $channel, $error)
    {
        $this->delivery_status = $status;
        $this->delivery_channel = $channel;
        $this->delivery_error = $error;
    }
}
