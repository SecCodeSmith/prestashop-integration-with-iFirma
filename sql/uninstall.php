<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = array();

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ifirma_receipt`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ifirma_data_request`;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
