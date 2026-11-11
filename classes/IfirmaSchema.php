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
 * Single source of truth for the module database schema and configuration defaults.
 */
class IfirmaSchema
{
    /**
     * Length of the iFirma identifier column in the contractor map. Identifiers in real accounts
     * exceed the documented 15 characters, so a table created narrower is widened to this length.
     */
    const CONTRACTOR_IDENTIFIER_LENGTH = 64;

    /**
     * Columns added to the receipt table in 1.1.0.
     *
     * @return array column name => DDL fragment
     */
    public static function newReceiptColumnsFor110()
    {
        return array(
            'source' => "VARCHAR(16) NOT NULL DEFAULT 'module'",
            'order_reference' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'issue_date' => 'DATE NULL',
            'delivery_status' => "VARCHAR(16) NOT NULL DEFAULT 'none'",
            'delivery_channel' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'delivered_at' => 'DATETIME NULL',
            'delivery_error' => 'TEXT NULL',
            'remote_paid_amount' => 'DECIMAL(20,6) NULL',
            'remote_sent' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'remote_draft' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'remote_missing' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'ksef_status' => "VARCHAR(32) NOT NULL DEFAULT ''",
            'ksef_number' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'ksef_sent_at' => 'DATETIME NULL',
            'ksef_error' => 'TEXT NULL',
            'linked_document' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'last_sync_at' => 'DATETIME NULL',
            'sync_error' => 'TEXT NULL',
            'duplicate_candidates' => 'TEXT NULL',
            'id_data_request' => 'INT(11) UNSIGNED NULL',
        );
    }

    /**
     * Columns added to the receipt table in 1.5.0: when the PDF was last downloaded.
     *
     * @return array column name => DDL fragment
     */
    public static function newReceiptColumnsFor150()
    {
        return array(
            'last_download_at' => 'DATETIME NULL',
        );
    }

    /**
     * Columns added to the receipt table in 1.10.0: how many automatic delivery attempts were used and when the
     * next one is due. A row that exists before the upgrade keeps a NULL due time, so it is never retried.
     *
     * @return array column name => DDL fragment
     */
    public static function newReceiptColumnsFor1100()
    {
        return array(
            'delivery_attempts' => 'INT(11) UNSIGNED NOT NULL DEFAULT 0',
            'delivery_retry_at' => 'DATETIME NULL',
        );
    }

    /**
     * Columns added to the receipt table in 1.10.0 for the per-customer daily download cap: the day the downloads
     * below were counted on and how many PDF downloads of the document reached iFirma that day.
     *
     * @return array column name => DDL fragment
     */
    public static function newReceiptDownloadCountColumnsFor1100()
    {
        return array(
            'download_day' => 'DATE NULL',
            'download_count' => 'INT(11) UNSIGNED NOT NULL DEFAULT 0',
        );
    }

    /**
     * @return array column name => DDL fragment, in table order
     */
    public static function receiptColumns()
    {
        $columns = array(
            'id_ifirma_receipt' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'id_order' => 'INT(11) UNSIGNED NOT NULL',
            'id_customer' => 'INT(11) UNSIGNED NOT NULL DEFAULT 0',
            'id_shop' => 'INT(11) UNSIGNED NOT NULL DEFAULT 1',
            'document_type' => "VARCHAR(32) NOT NULL DEFAULT 'fakturaparagon'",
            'ifirma_id' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'ifirma_number' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'status' => "VARCHAR(32) NOT NULL DEFAULT 'created'",
            'paid_amount' => 'DECIMAL(20,6) NOT NULL DEFAULT 0',
            'security_token' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'request_payload' => 'TEXT NULL',
            'response_payload' => 'TEXT NULL',
            'last_error' => 'TEXT NULL',
            'date_add' => 'DATETIME NOT NULL',
            'date_upd' => 'DATETIME NOT NULL',
        );

        return array_merge(
            $columns,
            self::newReceiptColumnsFor110(),
            self::newReceiptColumnsFor150(),
            self::newReceiptColumnsFor1100(),
            self::newReceiptDownloadCountColumnsFor1100()
        );
    }

    /**
     * @return array column name => DDL fragment, in table order
     */
    public static function dataRequestColumns()
    {
        return array(
            'id_ifirma_data_request' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'id_order' => 'INT(11) UNSIGNED NOT NULL',
            'id_customer' => 'INT(11) UNSIGNED NOT NULL DEFAULT 0',
            'id_shop' => 'INT(11) UNSIGNED NOT NULL DEFAULT 1',
            'status' => "VARCHAR(16) NOT NULL DEFAULT 'pending'",
            'requested_data' => 'TEXT NULL',
            'approved_data' => 'TEXT NULL',
            'customer_note' => 'TEXT NULL',
            'admin_note' => 'TEXT NULL',
            'resolved_by' => 'INT(11) UNSIGNED NULL',
            'auto_mode' => 'VARCHAR(8) NULL',
            'date_add' => 'DATETIME NOT NULL',
            'date_upd' => 'DATETIME NOT NULL',
            'date_resolved' => 'DATETIME NULL',
        );
    }

    /**
     * @return array column name => DDL fragment, in table order
     */
    public static function contractorMapColumns()
    {
        return array(
            'id_ifirma_contractor_map' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'id_customer' => 'INT(11) UNSIGNED NOT NULL',
            'ifirma_identifier' => 'VARCHAR(' . self::CONTRACTOR_IDENTIFIER_LENGTH . ') NOT NULL',
            'matched_by' => "VARCHAR(16) NOT NULL DEFAULT ''",
            'ifirma_name' => "VARCHAR(150) NOT NULL DEFAULT ''",
            'date_add' => 'DATETIME NOT NULL',
            'date_upd' => 'DATETIME NOT NULL',
        );
    }

    /**
     * @return array column name => DDL fragment, in table order
     */
    public static function guestCodeColumns()
    {
        return array(
            'id_ifirma_guest_code' => 'INT(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'id_order' => 'INT(11) UNSIGNED NOT NULL',
            'code_hash' => 'CHAR(64) NOT NULL',
            'salt' => 'CHAR(32) NOT NULL',
            'attempts' => 'TINYINT(3) UNSIGNED NOT NULL DEFAULT 0',
            'ip_hash' => "CHAR(64) NOT NULL DEFAULT ''",
            'date_add' => 'DATETIME NOT NULL',
            'expires_at' => 'DATETIME NOT NULL',
            'used_at' => 'DATETIME NULL',
            'invalidated' => 'TINYINT(1) NOT NULL DEFAULT 0',
        );
    }

    /**
     * @param array $columns
     * @return string
     */
    private static function columnLines($columns)
    {
        $lines = array();
        foreach ($columns as $name => $ddl) {
            $lines[] = '    `' . $name . '` ' . $ddl;
        }

        return implode(",\n", $lines);
    }

    /**
     * @return string
     */
    public static function receiptCreateSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ifirma_receipt` (' . "\n"
            . self::columnLines(self::receiptColumns()) . ",\n"
            . "    PRIMARY KEY (`id_ifirma_receipt`),\n"
            . "    KEY `idx_order` (`id_order`),\n"
            . "    KEY `idx_customer` (`id_customer`),\n"
            . "    KEY `idx_token` (`security_token`),\n"
            . "    KEY `idx_date_upd` (`date_upd`)\n"
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
    }

    /**
     * @return string
     */
    public static function dataRequestCreateSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ifirma_data_request` (' . "\n"
            . self::columnLines(self::dataRequestColumns()) . ",\n"
            . "    PRIMARY KEY (`id_ifirma_data_request`),\n"
            . "    KEY `idx_order` (`id_order`),\n"
            . "    KEY `idx_customer` (`id_customer`),\n"
            . "    KEY `idx_status` (`status`)\n"
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
    }

    /**
     * @return string
     */
    public static function contractorMapCreateSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ifirma_contractor_map` (' . "
"
            . self::columnLines(self::contractorMapColumns()) . ",
"
            . "    PRIMARY KEY (`id_ifirma_contractor_map`),
"
            . "    UNIQUE KEY `uniq_customer` (`id_customer`),
"
            . "    KEY `idx_identifier` (`ifirma_identifier`)
"
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
    }

    /**
     * @return string
     */
    public static function guestCodeCreateSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ifirma_guest_code` (' . "\n"
            . self::columnLines(self::guestCodeColumns()) . ",\n"
            . "    PRIMARY KEY (`id_ifirma_guest_code`),\n"
            . "    KEY `idx_order` (`id_order`),\n"
            . "    KEY `idx_ip_date` (`ip_hash`, `date_add`)\n"
            . ') ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
    }

    /**
     * Whether a table of the current database has the named column.
     *
     * @param string $table  full table name, prefix included
     * @param string $column
     * @return bool
     */
    public static function columnExists($table, $column)
    {
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '" . pSQL($table) . "' AND COLUMN_NAME = '" . pSQL($column) . "'"
        ) !== 0;
    }

    /**
     * Whether a table of the current database has the named index.
     *
     * @param string $table full table name, prefix included
     * @param string $index
     * @return bool
     */
    public static function indexExists($table, $index)
    {
        return (int) Db::getInstance()->getValue(
            "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '" . pSQL($table) . "' AND INDEX_NAME = '" . pSQL($index) . "'"
        ) !== 0;
    }

    /** @var bool the guest code table was already ensured in this request */
    private static $guestCodeEnsured = false;

    /**
     * Creates the guest code table when missing (shops upgraded to 1.6.0 before it existed).
     * Executes at most once per request.
     *
     * @return bool
     */
    public static function ensureGuestCodeTable()
    {
        if (self::$guestCodeEnsured) {
            return true;
        }
        self::$guestCodeEnsured = (bool) Db::getInstance()->execute(self::guestCodeCreateSql());

        return self::$guestCodeEnsured;
    }

    /** @var bool the contractor map table was already ensured in this request */
    private static $contractorMapEnsured = false;

    /**
     * Creates the contractor map table when missing (shops upgraded to 1.1.0 before it existed).
     * Executes at most once per request.
     *
     * @return bool
     */
    public static function ensureContractorMapTable()
    {
        if (self::$contractorMapEnsured) {
            return true;
        }
        $ok = (bool) Db::getInstance()->execute(self::contractorMapCreateSql());
        if ($ok) {
            self::widenContractorMapIdentifier();
        }
        self::$contractorMapEnsured = $ok;

        return $ok;
    }

    /**
     * iFirma identifiers seen in real accounts exceed the documented 15 characters (e.g. names
     * turned into identifiers). Tables created before the column was widened are altered in place.
     * Skipped when the current length cannot be read.
     *
     * @return bool
     */
    public static function widenContractorMapIdentifier()
    {
        $table = _DB_PREFIX_ . 'ifirma_contractor_map';
        $length = Db::getInstance()->getValue(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME = '" . pSQL($table) . "' AND COLUMN_NAME = 'ifirma_identifier'"
        );
        if (!is_numeric($length) || (int) $length <= 0 || (int) $length >= self::CONTRACTOR_IDENTIFIER_LENGTH) {
            return true;
        }

        return (bool) Db::getInstance()->execute(
            'ALTER TABLE `' . $table . '` MODIFY `ifirma_identifier` VARCHAR(' . self::CONTRACTOR_IDENTIFIER_LENGTH . ') NOT NULL'
        );
    }

    /** @var bool the KSeF receipt columns were already ensured in this request */
    private static $receiptKsefEnsured = false;

    /**
     * Adds the KSeF bookkeeping columns to the receipt table when missing. Idempotent; at most once per request.
     *
     * @return bool
     */
    public static function ensureReceiptKsefColumns()
    {
        if (self::$receiptKsefEnsured) {
            return true;
        }
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'ifirma_receipt';
        $columns = self::newReceiptColumnsFor110();
        $ok = true;
        foreach (array('ksef_sent_at', 'ksef_error') as $column) {
            if (self::columnExists($table, $column)) {
                continue;
            }
            if (!$db->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $columns[$column])) {
                $ok = false;
            }
        }
        self::$receiptKsefEnsured = $ok;

        return $ok;
    }

    /** @var bool the download column was already ensured in this request */
    private static $receiptDownloadEnsured = false;

    /**
     * Adds the last_download_at column to the receipt table when missing. Idempotent; at most once per request.
     *
     * @return bool
     */
    public static function ensureReceiptDownloadColumn()
    {
        if (self::$receiptDownloadEnsured) {
            return true;
        }
        $table = _DB_PREFIX_ . 'ifirma_receipt';
        $ok = true;
        if (!self::columnExists($table, 'last_download_at')) {
            $columns = self::newReceiptColumnsFor150();
            $ok = (bool) Db::getInstance()->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `last_download_at` ' . $columns['last_download_at']
            );
        }
        self::$receiptDownloadEnsured = $ok;

        return $ok;
    }

    /** @var bool the retry columns were already ensured in this request */
    private static $receiptRetryEnsured = false;

    /**
     * Adds the delivery_attempts and delivery_retry_at columns to the receipt table when missing. Idempotent; at
     * most once per request. No row is written: existing rows get attempts 0 and no due time.
     *
     * @return bool
     */
    public static function ensureReceiptRetryColumns()
    {
        if (self::$receiptRetryEnsured) {
            return true;
        }
        $table = _DB_PREFIX_ . 'ifirma_receipt';
        $ok = true;
        foreach (self::newReceiptColumnsFor1100() as $column => $definition) {
            if (self::columnExists($table, $column)) {
                continue;
            }
            if (!Db::getInstance()->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition)) {
                $ok = false;
            }
        }
        self::$receiptRetryEnsured = $ok;

        return $ok;
    }

    /** @var bool the download count columns were already ensured in this request */
    private static $receiptDownloadCountEnsured = false;

    /**
     * Adds the download_day and download_count columns to the receipt table when missing. Idempotent; at most once
     * per request. No row is written: existing rows get count 0 and no day.
     *
     * @return bool
     */
    public static function ensureReceiptDownloadCountColumns()
    {
        if (self::$receiptDownloadCountEnsured) {
            return true;
        }
        $table = _DB_PREFIX_ . 'ifirma_receipt';
        $ok = true;
        foreach (self::newReceiptDownloadCountColumnsFor1100() as $column => $definition) {
            if (self::columnExists($table, $column)) {
                continue;
            }
            if (!Db::getInstance()->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition)) {
                $ok = false;
            }
        }
        self::$receiptDownloadCountEnsured = $ok;

        return $ok;
    }

    /** @var bool the auto_mode column was already ensured in this request */
    private static $dataRequestAutoModeEnsured = false;

    /**
     * Adds the auto_mode column to the data request table when missing. Idempotent; at most once per request.
     *
     * @return bool
     */
    public static function ensureDataRequestAutoModeColumn()
    {
        if (self::$dataRequestAutoModeEnsured) {
            return true;
        }
        $table = _DB_PREFIX_ . 'ifirma_data_request';
        $ok = true;
        if (!self::columnExists($table, 'auto_mode')) {
            $columns = self::dataRequestColumns();
            $ok = (bool) Db::getInstance()->execute(
                'ALTER TABLE `' . $table . '` ADD COLUMN `auto_mode` ' . $columns['auto_mode']
            );
        }
        self::$dataRequestAutoModeEnsured = $ok;

        return $ok;
    }

    /** @var bool the date index was already ensured in this request */
    private static $receiptDateIndexEnsured = false;

    /**
     * Adds the idx_date_upd index to the receipt table when missing. Idempotent; at most once per request.
     *
     * @return bool
     */
    public static function ensureReceiptDateIndex()
    {
        if (self::$receiptDateIndexEnsured) {
            return true;
        }
        $table = _DB_PREFIX_ . 'ifirma_receipt';
        $ok = true;
        if (!self::indexExists($table, 'idx_date_upd')) {
            $ok = (bool) Db::getInstance()->execute('ALTER TABLE `' . $table . '` ADD KEY `idx_date_upd` (`date_upd`)');
        }
        self::$receiptDateIndexEnsured = $ok;

        return $ok;
    }

    /**
     * Forget that the table was ensured (tests).
     */
    public static function resetEnsureCache()
    {
        self::$contractorMapEnsured = false;
        self::$guestCodeEnsured = false;
        self::$receiptKsefEnsured = false;
        self::$receiptDownloadEnsured = false;
        self::$receiptRetryEnsured = false;
        self::$receiptDownloadCountEnsured = false;
        self::$dataRequestAutoModeEnsured = false;
        self::$receiptDateIndexEnsured = false;
    }

    /**
     * Defaults for every module configuration key. A null default means
     * "generate" (the cron token).
     *
     * @return array
     */
    public static function configurationDefaults()
    {
        return array(
            'IFIRMA_BASE_URL' => class_exists('IfirmaApiClient') ? IfirmaApiClient::BASE_URL : 'https://www.ifirma.pl/iapi/',
            'IFIRMA_USER' => '',
            'IFIRMA_KEY_FAKTURA' => '',
            'IFIRMA_KEY_ABONENT' => '',
            'IFIRMA_KEY_RACHUNEK' => '',
            'IFIRMA_DOC_TYPE' => 'fakturakraj',
            'IFIRMA_SIGNATURE_TYPE' => 'BPO',
            'IFIRMA_PAYMENT_METHOD' => 'PRZ',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'order',
            'IFIRMA_PAYMENT_METHOD_MAP' => '',
            'IFIRMA_CONTRACTOR_SYNC' => 1,
            'IFIRMA_PAYMENT_TYPE_TOKEN' => 'prz_faktura_kraj',
            'IFIRMA_DEFAULT_UNIT' => 'szt.',
            'IFIRMA_AUTO_CREATE_STATE' => 0,
            'IFIRMA_MARK_PAID_ON_CREATE' => 1,
            'IFIRMA_SEND_CHANNEL' => 'shop_mail',
            'IFIRMA_SEND_TEXT' => '',
            'IFIRMA_SEND_COPY_MODE' => 'none',
            'IFIRMA_SEND_COPY_EMPLOYEE' => 0,
            'IFIRMA_KSEF_MODE' => 'b2b',
            'IFIRMA_KSEF_SUBMITTER' => 'module',
            'IFIRMA_DUPLICATE_CHECK' => 1,
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => 60,
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0.01',
            'IFIRMA_DOC_TEMPLATE' => '',
            'IFIRMA_ORDER_STAMP_PREFIX' => 'PS-ORDER',
            'IFIRMA_SHOP_URL' => '',
            'IFIRMA_ALLOW_DATA_REQUEST' => 1,
            'IFIRMA_DATA_REQUEST_AUTO_ACCEPT' => 'none',
            'IFIRMA_NOTIFY_EMAIL' => '',
            'IFIRMA_SYNC_INTERVAL_MIN' => 15,
            'IFIRMA_CRON_TOKEN' => null,
            'IFIRMA_CRON_MAX_CALLS' => 40,
            'IFIRMA_CRON_MAX_ITEMS' => 10,
            'IFIRMA_RATE_MAX_PER_MIN' => 80,
            'IFIRMA_RATE_MAX_PER_DAY' => 14000,
            'IFIRMA_RATE_WINDOW' => '',
            'IFIRMA_RATE_DAY_WINDOW' => '',
            'IFIRMA_RATE_BACKOFF_UNTIL' => '',
            'IFIRMA_DL_CUSTOMER_MINUTE' => '',
            'IFIRMA_DL_CUSTOMER_DAY' => '',
            'IFIRMA_DL_EMPLOYEE_MINUTE' => '',
            'IFIRMA_DL_EMPLOYEE_DAY' => '',
            'IFIRMA_USAGE_DOCUMENT' => '',
            'IFIRMA_USAGE_STATUS' => '',
            'IFIRMA_USAGE_PDF' => '',
            'IFIRMA_USAGE_EMAIL' => '',
            'IFIRMA_USAGE_KSEF' => '',
            'IFIRMA_USAGE_PAYMENT' => '',
            'IFIRMA_USAGE_CONTRACTOR' => '',
            'IFIRMA_USAGE_LIMIT' => '',
            'IFIRMA_USAGE_OTHER' => '',
            'IFIRMA_REMOTE_LIMIT' => '',
            'IFIRMA_AUTH_FAILURE' => '',
        );
    }

    /**
     * Test seam: callable(int $length): string used instead of random_bytes() when set.
     *
     * @var callable|null
     */
    public static $randomBytes = null;

    /**
     * A token from the operating system's secure random generator. There is no weaker fallback: a token that
     * could be guessed is worse than none.
     *
     * @return string 32 hex characters
     * @throws RuntimeException when the secure random generator is unavailable
     */
    public static function generateCronToken()
    {
        $bytes = null;
        try {
            if (is_callable(self::$randomBytes)) {
                $bytes = call_user_func(self::$randomBytes, 16);
            } elseif (function_exists('random_bytes')) {
                $bytes = random_bytes(16);
            }
        } catch (Throwable $e) {
            $bytes = null;
        }
        if (!is_string($bytes) || strlen($bytes) !== 16) {
            throw new RuntimeException('The secure random generator is unavailable');
        }

        return bin2hex($bytes);
    }
}
