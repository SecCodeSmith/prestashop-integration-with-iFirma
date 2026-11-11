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

// Real-database check of IfirmaPdfCopyStore: a PDF of exactly 1 MiB that holds all 256 byte values must come back
// byte for byte, a second save for the same record must leave one row, and a metadata read must not load the blob.
// The record id is far above any real receipt id; the table has no foreign key, and the row is deleted at the end.
$psRoot = getenv('PS_ROOT') ? rtrim(getenv('PS_ROOT'), '/\\') : '/var/www/html';
require $psRoot . '/config/config.inc.php';
require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPdfCopyStore.php';

$fail = 0;
function check($cond, $label, $detail = '')
{
    global $fail;
    echo '[' . ($cond ? ' OK ' : 'FAIL') . '] ' . $label . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
    if (!$cond) {
        $fail++;
    }
}

$recordId = 2147480000;
$fingerprintA = hash('sha256', 'smoke copy a');
$fingerprintB = hash('sha256', 'smoke copy b');
$store = new IfirmaPdfCopyStore();
$db = Db::getInstance();

$content = '%PDF-';
$content .= str_repeat(implode('', array_map('chr', range(0, 255))), 4095);
$content .= substr(implode('', array_map('chr', range(0, 255))), 0, IfirmaPdfCopyStore::MAX_BYTES - strlen($content));
check(strlen($content) === IfirmaPdfCopyStore::MAX_BYTES, 'the test content is exactly 1 MiB', (string) strlen($content));

$store->delete($recordId);

check($store->save($recordId, $content, $fingerprintA) === true, 'the 1 MiB copy is saved');

$copy = $store->find($recordId);
check($copy !== null, 'the copy is read back');
if ($copy !== null) {
    check(hash('sha256', (string) $copy->content()) === hash('sha256', $content), 'the SHA-256 of the bytes is unchanged');
    check($copy->isIntact(), 'the copy reports itself intact');
    check($copy->byteLength() === strlen($content), 'the recorded length is the saved length');
    check($copy->fingerprint() === $fingerprintA, 'the fingerprint is the saved one');
}

$second = '%PDF-1.4 the second, different content ' . "\x00\xff'\"\\";
check($store->save($recordId, $second, $fingerprintB) === true, 'a second copy for the same record is saved');
$rows = (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ifirma_pdf_copy` WHERE id_ifirma_receipt = ' . (int) $recordId);
check($rows === 1, 'exactly one row is left for the record', (string) $rows);
$replaced = $store->find($recordId);
check($replaced !== null && $replaced->content() === $second, 'the row holds the second content');
check($replaced !== null && $replaced->fingerprint() === $fingerprintB, 'the row holds the second fingerprint');

$meta = $store->findWithoutContent($recordId);
check($meta !== null && $meta->content() === null, 'the read without content returns a null content');
check($meta !== null && $meta->byteLength() === strlen($second), 'the read without content still reports the length');

check($store->delete($recordId) === true, 'the row is deleted');
check($store->find($recordId) === null, 'the row is gone');

echo $fail === 0 ? "OK\n" : "FAIL: {$fail} check(s) failed\n";
exit($fail === 0 ? 0 : 1);
