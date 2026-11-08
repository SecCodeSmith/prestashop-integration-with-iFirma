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

require_once dirname(__FILE__) . '/IfirmaSchema.php';

/**
 * The single-use codes that prove a guest controls the e-mail address of an order.
 *
 * Public contract:
 *  - issue(Order, clientAddress): the plain six-digit code (zero padded), or null when a limit refused it
 *    (the newest code of the order is younger than RESEND_WINDOW seconds, ORDER_CAP_24H codes of the order
 *    exist in the last 24 hours, or IP_CAP_1H codes of the same client address exist in the last hour).
 *    The earlier unused codes of the order are invalidated. The plain code is returned once and never
 *    stored: the table keeps sha256(salt . code). The same hash is computed on a refusal, so both ways
 *    cost the same.
 *  - verify(idOrder, code): true once for the newest active code of the order; a wrong code counts an
 *    attempt and invalidates the code at MAX_ATTEMPTS; anything that is not six digits is false without
 *    touching a row.
 *  - cleanup(): deletes the rows whose expiry or use is older than 24 hours.
 *
 * The clock (a callable returning a unix timestamp) and the random source (a callable taking the highest
 * value and returning an integer from 0 to it) are test seams. There is no weaker generator than
 * random_int() / random_bytes(): when they fail the exception reaches the caller.
 */
class IfirmaGuestCodeService
{
    const LIFETIME = 900;
    const MAX_ATTEMPTS = 5;
    const RESEND_WINDOW = 60;
    const ORDER_CAP_24H = 5;
    const IP_CAP_1H = 20;
    const RETENTION = 86400;

    /** @var callable|null */
    private $clock;

    /** @var callable|null */
    private $random;

    /**
     * @param callable|null $clock  returns a unix timestamp
     * @param callable|null $random takes the highest value, returns an integer from 0 to it
     */
    public function __construct($clock = null, $random = null)
    {
        $this->clock = $clock;
        $this->random = $random;
    }

    /**
     * @return int
     */
    private function now()
    {
        return $this->clock !== null ? (int) call_user_func($this->clock) : time();
    }

    /**
     * @param int $timestamp
     * @return string
     */
    private function stamp($timestamp)
    {
        return date('Y-m-d H:i:s', (int) $timestamp);
    }

    /**
     * @return string
     */
    private function table()
    {
        return '`' . _DB_PREFIX_ . 'ifirma_guest_code`';
    }

    /**
     * @param string $clientAddress
     * @return string sha256 of the address with the cookie key: the table holds no raw address
     */
    private function addressHash($clientAddress)
    {
        $key = defined('_COOKIE_KEY_') ? (string) _COOKIE_KEY_ : '';

        return hash('sha256', $key . (string) $clientAddress);
    }

    /**
     * @param string $salt
     * @param string $code
     * @return string
     */
    private function codeHash($salt, $code)
    {
        return hash('sha256', $salt . $code);
    }

    /**
     * @return int a number from 0 to 999999 from the random source
     */
    private function randomNumber()
    {
        if ($this->random !== null) {
            return (int) call_user_func($this->random, 999999);
        }

        return random_int(0, 999999);
    }

    /**
     * Deletes the expired and used codes older than 24 hours.
     *
     * @return void
     */
    public function cleanup()
    {
        IfirmaSchema::ensureGuestCodeTable();
        $limit = pSQL($this->stamp($this->now() - self::RETENTION));
        Db::getInstance()->execute(
            'DELETE FROM ' . $this->table() . '
             WHERE expires_at < \'' . $limit . '\'
             OR (used_at IS NOT NULL AND used_at < \'' . $limit . '\')'
        );
    }

    /**
     * @param Order  $order
     * @param string $clientAddress
     * @return string|null the six-digit code, null when a limit refused it
     */
    public function issue(Order $order, $clientAddress)
    {
        IfirmaSchema::ensureGuestCodeTable();
        $this->cleanup();

        $now = $this->now();
        $idOrder = (int) $order->id;
        $code = str_pad((string) $this->randomNumber(), 6, '0', STR_PAD_LEFT);
        $salt = bin2hex(random_bytes(16));
        $hash = $this->codeHash($salt, $code);
        $ipHash = $this->addressHash($clientAddress);

        $db = Db::getInstance();
        $recent = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . $this->table() . '
             WHERE id_order = ' . $idOrder . '
             AND date_add > \'' . pSQL($this->stamp($now - self::RESEND_WINDOW)) . '\''
        );
        $perOrder = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . $this->table() . '
             WHERE id_order = ' . $idOrder . '
             AND date_add >= \'' . pSQL($this->stamp($now - 86400)) . '\''
        );
        $perAddress = (int) $db->getValue(
            'SELECT COUNT(*) FROM ' . $this->table() . '
             WHERE ip_hash = \'' . pSQL($ipHash) . '\'
             AND date_add >= \'' . pSQL($this->stamp($now - 3600)) . '\''
        );
        if ($recent > 0 || $perOrder >= self::ORDER_CAP_24H || $perAddress >= self::IP_CAP_1H) {
            return null;
        }

        $db->execute(
            'UPDATE ' . $this->table() . ' SET invalidated = 1
             WHERE id_order = ' . $idOrder . ' AND used_at IS NULL'
        );
        $db->execute(
            'INSERT INTO ' . $this->table() . '
             (id_order, code_hash, salt, attempts, ip_hash, date_add, expires_at, used_at, invalidated)
             VALUES (' . $idOrder . ', \'' . pSQL($hash) . '\', \'' . pSQL($salt) . '\', 0, \'' . pSQL($ipHash) . '\',
             \'' . pSQL($this->stamp($now)) . '\', \'' . pSQL($this->stamp($now + self::LIFETIME)) . '\', NULL, 0)'
        );

        return $code;
    }

    /**
     * @param int    $idOrder
     * @param string $code
     * @return bool
     */
    public function verify($idOrder, $code)
    {
        if (!is_string($code) || !preg_match('/^[0-9]{6}$/', $code)) {
            return false;
        }

        $db = Db::getInstance();
        $row = $db->getRow(
            'SELECT id_ifirma_guest_code, code_hash, salt FROM ' . $this->table() . '
             WHERE id_order = ' . (int) $idOrder . '
             AND used_at IS NULL AND invalidated = 0
             AND expires_at > \'' . pSQL($this->stamp($this->now())) . '\'
             ORDER BY id_ifirma_guest_code DESC LIMIT 1'
        );
        if (!is_array($row) || empty($row['id_ifirma_guest_code'])) {
            // the same work as for a real row
            hash_equals($this->codeHash(str_repeat('0', 32), $code), $this->codeHash(str_repeat('1', 32), $code));

            return false;
        }

        $id = (int) $row['id_ifirma_guest_code'];
        if (hash_equals((string) $row['code_hash'], $this->codeHash((string) $row['salt'], $code))) {
            // consumed by one conditional UPDATE, so two parallel requests cannot both pass
            $db->execute(
                'UPDATE ' . $this->table() . ' SET used_at = \'' . pSQL($this->stamp($this->now())) . '\'
                 WHERE id_ifirma_guest_code = ' . $id . ' AND used_at IS NULL AND invalidated = 0'
            );

            return (int) $db->Affected_Rows() === 1;
        }

        $db->execute(
            'UPDATE ' . $this->table() . ' SET attempts = attempts + 1 WHERE id_ifirma_guest_code = ' . $id
        );
        $db->execute(
            'UPDATE ' . $this->table() . ' SET invalidated = 1
             WHERE id_ifirma_guest_code = ' . $id . ' AND attempts >= ' . self::MAX_ATTEMPTS
        );

        return false;
    }
}
