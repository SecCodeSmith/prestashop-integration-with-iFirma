<?php
/**
 * iFirma module – stored document (receipt / invoice) model.
 *
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.2
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

    /** @var string iFirma endpoint slug used to issue the document (fakturaparagon|fakturakraj) */
    public $document_type;

    /** @var string Numeric identifier returned by iFirma (Identyfikator) */
    public $ifirma_id;

    /** @var string Human readable document number, e.g. "FV 12/2026" */
    public $ifirma_number;

    /** @var string created|paid|error */
    public $status;

    /** @var float amount registered as paid in iFirma */
    public $paid_amount;

    /** @var string random token guarding the front-office PDF download */
    public $security_token;

    /** @var string raw JSON request sent to iFirma */
    public $request_payload;

    /** @var string raw JSON response received from iFirma */
    public $response_payload;

    /** @var string last error message, if any */
    public $last_error;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

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
            // Free-form audit fields: raw JSON / API messages may contain any
            // characters (customer address text, iFirma messages), so they are
            // stored as-is without a content validator that could reject them.
            'request_payload' => array('type' => self::TYPE_HTML),
            'response_payload' => array('type' => self::TYPE_HTML),
            'last_error' => array('type' => self::TYPE_HTML),
            'date_add' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
            'date_upd' => array('type' => self::TYPE_DATE, 'validate' => 'isDate'),
        ),
    );

    /**
     * Return the (single, most recent) document issued for a given order.
     *
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
     * Load a document by its front-office download token.
     *
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
     * All successfully-issued documents that belong to a customer, newest first.
     *
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
     * True when the document was actually issued in iFirma (has an id).
     *
     * @return bool
     */
    public function isIssued()
    {
        return (bool) $this->ifirma_id && $this->status !== 'error';
    }
}
