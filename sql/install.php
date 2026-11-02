<?php
/**
 * @author    SCM Jakub Berechowskir
 * @license   SecCodeSmith Commercial License v1.3
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/../classes/IfirmaSchema.php';

$sql = array(
    IfirmaSchema::receiptCreateSql(),
    IfirmaSchema::dataRequestCreateSql(),
);

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
