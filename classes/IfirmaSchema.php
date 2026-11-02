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
            'linked_document' => "VARCHAR(128) NOT NULL DEFAULT ''",
            'last_sync_at' => 'DATETIME NULL',
            'sync_error' => 'TEXT NULL',
            'duplicate_candidates' => 'TEXT NULL',
            'id_data_request' => 'INT(11) UNSIGNED NULL',
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

        return array_merge($columns, self::newReceiptColumnsFor110());
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
            'date_add' => 'DATETIME NOT NULL',
            'date_upd' => 'DATETIME NOT NULL',
            'date_resolved' => 'DATETIME NULL',
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
            . "    KEY `idx_token` (`security_token`)\n"
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
            'IFIRMA_DOC_TYPE' => 'fakturaparagon',
            'IFIRMA_PAYMENT_METHOD' => 'PRZ',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'order',
            'IFIRMA_PAYMENT_METHOD_MAP' => '',
            'IFIRMA_PAYMENT_TYPE_TOKEN' => 'prz_faktura_kraj',
            'IFIRMA_DEFAULT_UNIT' => 'szt.',
            'IFIRMA_AUTO_CREATE_STATE' => 0,
            'IFIRMA_MARK_PAID_ON_CREATE' => 1,
            'IFIRMA_SEND_CHANNEL' => 'shop_mail',
            'IFIRMA_SEND_TEXT' => '',
            'IFIRMA_DUPLICATE_CHECK' => 1,
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => 60,
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0.01',
            'IFIRMA_ALLOW_DATA_REQUEST' => 1,
            'IFIRMA_NOTIFY_EMAIL' => '',
            'IFIRMA_SYNC_INTERVAL_MIN' => 15,
            'IFIRMA_CRON_TOKEN' => null,
            'IFIRMA_CRON_MAX_CALLS' => 40,
            'IFIRMA_RATE_MAX_PER_MIN' => 80,
            'IFIRMA_RATE_WINDOW' => '',
        );
    }

    /**
     * @return string 32 hex characters
     */
    public static function generateCronToken()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(16));
            } catch (Exception $e) {
                // fall through to the fallback below
            }
        }

        return substr(hash('sha256', uniqid('ifirma', true) . mt_rand()), 0, 32);
    }
}
