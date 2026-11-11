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

require_once dirname(__FILE__) . '/IfirmaPdfCopy.php';
require_once dirname(__FILE__) . '/IfirmaSchema.php';

/**
 * Persists one copy of a document PDF per receipt in `ifirma_pdf_copy`, and removes copies. It stores and reads bytes;
 * whether a copy may be served is decided elsewhere.
 *
 * The bytes travel as hexadecimal: pSQL() would strip tags and escape the bytes in a way that damages a PDF, and raw
 * bytes inside a utf8 statement can be rejected or altered by the connection. Every value in every statement is an
 * integer, a validated fingerprint, hex made by bin2hex() or a date this class formats. A database failure that
 * raises an exception is not caught here; the caller decides.
 */
class IfirmaPdfCopyStore
{
    /** The largest PDF kept, so that its hex statement stays under the 4 MiB default max_allowed_packet. */
    const MAX_BYTES = 1048576;

    /** The largest value of an INT(11) UNSIGNED column. */
    const MAX_ID = 4294967295;

    const DATE_FORMAT = 'Y-m-d H:i:s';
    const FINGERPRINT_PATTERN = '/^[0-9a-f]{64}\z/';

    /** @var Db|null resolved at call time when null */
    private $db;

    /** @var callable|null returns the unix time; time() when null */
    private $clock;

    /**
     * @param Db|null       $db
     * @param callable|null $clock
     */
    public function __construct($db = null, $clock = null)
    {
        $this->db = $db;
        $this->clock = $clock;
    }

    /**
     * Stores the copy of a document, replacing the one it had.
     *
     * @param int    $receiptId
     * @param string $content     the PDF bytes
     * @param string $fingerprint 64 lowercase hexadecimal digits
     * @return bool false when the copy is not storable or the database refused it
     */
    public function save($receiptId, $content, $fingerprint)
    {
        $receiptId = self::wholeId($receiptId);
        if ($receiptId < 1 || !$this->isStorable($content) || !$this->isFingerprint($fingerprint)) {
            return false;
        }
        if (!IfirmaSchema::ensurePdfCopyTable()) {
            return false;
        }

        return (bool) $this->db()->execute(
            'REPLACE INTO `' . $this->table() . '` (`id_ifirma_receipt`, `fingerprint`, `byte_length`, `content`, `stored_at`)'
            . ' VALUES (' . $receiptId . ", '" . $fingerprint . "', " . (int) strlen($content)
            . ", UNHEX('" . bin2hex($content) . "'), '" . $this->now() . "')"
        );
    }

    /**
     * @param int $receiptId
     * @return IfirmaPdfCopy|null with its content
     */
    public function find($receiptId)
    {
        return $this->read($receiptId, 'id_ifirma_receipt, fingerprint, byte_length, content, stored_at');
    }

    /**
     * @param int $receiptId
     * @return IfirmaPdfCopy|null without its content (a metadata read that does not load the blob)
     */
    public function findWithoutContent($receiptId)
    {
        return $this->read($receiptId, 'id_ifirma_receipt, fingerprint, byte_length, stored_at');
    }

    /**
     * @param int $receiptId
     * @return bool
     */
    public function delete($receiptId)
    {
        $receiptId = self::wholeId($receiptId);
        if ($receiptId < 1) {
            return false;
        }

        return $this->deleteWhere('WHERE id_ifirma_receipt = ' . $receiptId);
    }

    /**
     * Deletes the copies of every document of one customer.
     *
     * @param int $customerId
     * @return bool
     */
    public function deleteForCustomer($customerId)
    {
        $customerId = self::wholeId($customerId);
        if ($customerId < 1) {
            return false;
        }

        return $this->deleteWhere(
            'WHERE id_ifirma_receipt IN (SELECT id_ifirma_receipt FROM `' . _DB_PREFIX_ . 'ifirma_receipt` WHERE id_customer = ' . $customerId . ')'
        );
    }

    /**
     * Deletes up to $limit copies stored before a moment, the oldest first.
     *
     * @param int $cutoffTimestamp unix time; copies stored strictly before it go
     * @param int $limit
     * @return int the number of copies deleted
     */
    public function deleteStoredBefore($cutoffTimestamp, $limit)
    {
        $limit = (int) $limit;
        if ($limit < 1 || !IfirmaSchema::ensurePdfCopyTable()) {
            return 0;
        }
        $deleted = $this->db()->execute(
            'DELETE FROM `' . $this->table() . "` WHERE stored_at < '" . date(self::DATE_FORMAT, (int) $cutoffTimestamp)
            . "' ORDER BY stored_at LIMIT " . $limit
        );

        return $deleted ? (int) $this->db()->Affected_Rows() : 0;
    }

    /**
     * @return bool
     */
    public function deleteAll()
    {
        return $this->deleteWhere('');
    }

    /**
     * @param int    $receiptId
     * @param string $columns   fixed column list of the caller
     * @return IfirmaPdfCopy|null
     */
    private function read($receiptId, $columns)
    {
        $receiptId = self::wholeId($receiptId);
        if ($receiptId < 1 || !IfirmaSchema::ensurePdfCopyTable()) {
            return null;
        }
        // no LIMIT: getRow() of PrestaShop appends LIMIT 1 itself
        $row = $this->db()->getRow(
            'SELECT ' . $columns . ' FROM `' . $this->table() . '` WHERE id_ifirma_receipt = ' . $receiptId,
            false
        );
        if (!is_array($row)) {
            return null;
        }

        return new IfirmaPdfCopy(
            $row['id_ifirma_receipt'],
            $row['fingerprint'],
            $row['byte_length'],
            $row['stored_at'],
            isset($row['content']) ? $row['content'] : null
        );
    }

    /**
     * @param string $condition a WHERE clause built from integers, or empty
     * @return bool
     */
    private function deleteWhere($condition)
    {
        if (!IfirmaSchema::ensurePdfCopyTable()) {
            return false;
        }

        return (bool) $this->db()->execute(trim('DELETE FROM `' . $this->table() . '` ' . $condition));
    }

    /**
     * An id is an int or a string of digits within INT UNSIGNED; anything else is 0, which every method refuses, so
     * an array, a float or a string with a tail never turns into the id of another row.
     *
     * @param mixed $id
     * @return int
     */
    private static function wholeId($id)
    {
        $isWhole = is_int($id) || (is_string($id) && preg_match('/^[1-9][0-9]{0,9}\z/', $id) === 1);

        return $isWhole && $id >= 1 && $id <= self::MAX_ID ? (int) $id : 0;
    }

    /**
     * @param mixed $content
     * @return bool
     */
    private function isStorable($content)
    {
        return is_string($content) && $content !== '' && strlen($content) <= self::MAX_BYTES;
    }

    /**
     * @param mixed $fingerprint
     * @return bool
     */
    private function isFingerprint($fingerprint)
    {
        return is_string($fingerprint) && preg_match(self::FINGERPRINT_PATTERN, $fingerprint) === 1;
    }

    /** @return string the formatted current time */
    private function now()
    {
        $clock = $this->clock;

        return date(self::DATE_FORMAT, $clock === null ? time() : (int) call_user_func($clock));
    }

    /** @return string */
    private function table()
    {
        return _DB_PREFIX_ . 'ifirma_pdf_copy';
    }

    /** @return Db */
    private function db()
    {
        return $this->db === null ? Db::getInstance() : $this->db;
    }
}
