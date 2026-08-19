<?php
/**
 * iFirma module – table installation.
 *
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.2
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = array();

/*
 * One row per iFirma document (receipt / invoice) issued for a PrestaShop order.
 * We keep the raw request/response so the exchange is fully auditable, plus a
 * per-document security token used by the front-office PDF download controller.
 */
$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ifirma_receipt` (
    `id_ifirma_receipt` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order` INT(11) UNSIGNED NOT NULL,
    `id_customer` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `document_type` VARCHAR(32) NOT NULL DEFAULT "fakturaparagon",
    `ifirma_id` VARCHAR(64) NOT NULL DEFAULT "",
    `ifirma_number` VARCHAR(128) NOT NULL DEFAULT "",
    `status` VARCHAR(32) NOT NULL DEFAULT "created",
    `paid_amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
    `security_token` VARCHAR(64) NOT NULL DEFAULT "",
    `request_payload` TEXT NULL,
    `response_payload` TEXT NULL,
    `last_error` TEXT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_ifirma_receipt`),
    KEY `idx_order` (`id_order`),
    KEY `idx_customer` (`id_customer`),
    KEY `idx_token` (`security_token`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
