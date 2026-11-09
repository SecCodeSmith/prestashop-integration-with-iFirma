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

    /** @var string|null when the customer last downloaded the document (download throttle) */
    public $last_download_at;

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
    /** The order waits for its document: recorded before the first iFirma request, or refused by the rate limit. */
    const STATUS_QUEUED = 'queued';

    const SOURCE_MODULE = 'module';
    const SOURCE_EXTERNAL = 'external';

    const DELIVERY_NONE = 'none';
    const DELIVERY_SENT = 'sent';
    const DELIVERY_FAILED = 'failed';
    const DELIVERY_SKIPPED = 'skipped';
    /** The document goes to KSeF and is delivered to the customer once KSeF has accepted it. */
    const DELIVERY_AWAITING_KSEF = 'awaiting_ksef';

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
            'last_download_at' => array('type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'allow_null' => true),
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
     * The issued documents the status cron has to look at, least recently synchronised first: not
     * paid yet, not seen as sent, never synchronised, or last synchronised before $staleBefore. A row
     * whose receipt does not load is left out.
     *
     * @param string $staleBefore YYYY-MM-DD HH:MM:SS; a last_sync_at before it counts as stale
     * @param int    $limit       at most this many receipts, and at least one
     * @return IfirmaReceipt[]
     */
    public static function findDueForSync($staleBefore, $limit)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_ifirma_receipt FROM `' . _DB_PREFIX_ . 'ifirma_receipt`
             WHERE ifirma_id != \'\' AND status != \'error\'
             AND (status != \'paid\' OR remote_sent = 0 OR last_sync_at IS NULL
                  OR last_sync_at < \'' . pSQL($staleBefore) . '\' OR delivery_status = \'' . self::DELIVERY_AWAITING_KSEF . '\')
             ORDER BY last_sync_at ASC, id_ifirma_receipt ASC
             LIMIT ' . max(1, (int) $limit)
        );

        $receipts = array();
        foreach ((array) $rows as $row) {
            $receipt = new self((int) $row['id_ifirma_receipt']);
            if (!empty($receipt->id)) {
                $receipts[] = $receipt;
            }
        }

        return $receipts;
    }

    /**
     * The queue: records in the state queued, the one that has waited longest first.
     *
     * @param int $limit at most this many records, and at least one
     * @return IfirmaReceipt[]
     */
    public static function findQueued($limit)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_ifirma_receipt FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE status = \'' . self::STATUS_QUEUED . '\'
             ORDER BY date_upd ASC, id_ifirma_receipt ASC
             LIMIT ' . max(1, (int) $limit)
        );

        $records = array();
        foreach ((array) $rows as $row) {
            $record = new self((int) $row['id_ifirma_receipt']);
            if (!empty($record->id)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @return int how many records are queued
     */
    public static function countQueued()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE status = \'' . self::STATUS_QUEUED . '\''
        );
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
     * @return bool whether the admin panel offers to issue the document: nothing is issued yet and the record
     *              is not held by a pending data change request
     */
    public function canCreate()
    {
        return !$this->isIssued() && (string) $this->status !== self::STATUS_HELD;
    }

    /**
     * @return bool whether the admin panel offers to issue the document anyway: nothing is issued yet and the
     *              last run ended in a suspected duplicate, an unknown outcome or an error
     */
    public function canIssueAnyway()
    {
        return !$this->isIssued() && in_array((string) $this->status, array(
            self::STATUS_DUPLICATE_SUSPECTED,
            self::STATUS_UNKNOWN,
            self::STATUS_ERROR,
        ), true);
    }

    /**
     * @return string the payment method (SposobZaplaty) of the stored request payload; '' when there is none
     */
    public function sentPaymentMethod()
    {
        $sent = $this->sentPayload();

        return isset($sent['SposobZaplaty']) ? (string) $sent['SposobZaplaty'] : '';
    }

    /**
     * The contractor note the stored request payload carries (its _contractor entry), for the admin panel. Only
     * what the entry holds: the wording belongs to the module class, which translates it.
     *
     * @return array|null action (string), message (the raw sentence of an older record, '' when none), note (the
     *                    structured note: code and params, or null) and attached (the contractor iFirma attached
     *                    to the document when the module could not reference one (D14), else ''); null when the
     *                    payload records no contractor action
     */
    public function contractorNote()
    {
        $sent = $this->sentPayload();
        if (!isset($sent['_contractor']['action'])) {
            return null;
        }
        $step = $sent['_contractor'];
        $attached = isset($step['document_identifier']) ? trim((string) $step['document_identifier']) : '';
        $referenced = isset($step['identifier']) ? trim((string) $step['identifier']) : '';

        return array(
            'action' => (string) $step['action'],
            'message' => isset($step['message']) ? (string) $step['message'] : '',
            'note' => isset($step['note']) && is_array($step['note']) ? $step['note'] : null,
            'attached' => $attached !== $referenced ? $attached : '',
        );
    }

    /**
     * @return array the stored request payload, decoded; empty when there is none or it is not a JSON object or list
     */
    private function sentPayload()
    {
        if (!$this->request_payload) {
            return array();
        }
        $sent = json_decode((string) $this->request_payload, true);

        return is_array($sent) ? $sent : array();
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
     * Marks a non-issued record as queued: the order waits for its document, either because it just reached the
     * trigger status or because the run was refused by the rate limit. Sets the fields only; the caller saves.
     *
     * @param string $message the refusal message, '' for a fresh queue entry
     * @throws LogicException when the document is already issued
     */
    public function markQueued($message)
    {
        if ($this->isIssued()) {
            throw new LogicException('A document that is already issued cannot be queued.');
        }
        $this->status = self::STATUS_QUEUED;
        $this->last_error = $message;
    }

    /**
     * Marks the record as a suspected duplicate: iFirma already holds a document that may be this
     * order's, so the module issued none. The record then holds no document and belongs to the
     * module until an admin links a candidate. Sets the fields only; the caller stores the
     * candidates and saves.
     *
     * @param string $reason stored as given in last_error
     */
    public function markSuspected($reason)
    {
        $this->status = self::STATUS_DUPLICATE_SUSPECTED;
        $this->ifirma_id = '';
        $this->source = self::SOURCE_MODULE;
        $this->last_error = $reason;
    }

    /**
     * Marks the record as paid. Sets the status only; the caller saves. The amount and the error
     * are not part of it: registering a payment stores the amount, clears the error and keeps
     * iFirma's answer, a status sync reads the amount into remote_paid_amount, and an adopted
     * document stores its amount whatever its status, so the caller decides.
     */
    public function markPaid()
    {
        $this->status = self::STATUS_PAID;
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
