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

use PHPUnit\Framework\TestCase;

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaPdfCopyStore.php';

/**
 * IfirmaPdfCopyStore against the in-memory twin of the ifirma_pdf_copy table (IfirmaPdfCopyDb).
 */
class PdfCopyStoreTest extends TestCase
{
    use IfirmaPdfCopyDb;
    use IfirmaSentQueries;

    const NOW = '2026-11-11 10:00:00';
    const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const OTHER_FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var IfirmaPdfCopyStore */
    private $store;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->scriptPdfCopyDb();
        $this->store = new IfirmaPdfCopyStore(null, function () {
            return strtotime(PdfCopyStoreTest::NOW);
        });
    }

    private function receiptOfCustomer($idReceipt, $idCustomer)
    {
        ObjectModel::$registry['ifirma_receipt'][$idReceipt] = array('id_ifirma_receipt' => $idReceipt, 'id_customer' => $idCustomer);
    }

    // ---- save and find: the bytes survive ------------------------------------------------------------

    public function testBytesWithNulQuotesBackslashesAndInvalidUtf8AreReadBackIdentical()
    {
        $content = "%PDF-1.4\n\x00\x01'\"\\\\ \xff\xfe\xc3\x28 \x1a\x00 'DROP TABLE x;-- \\' end";

        $this->assertTrue($this->store->save(17, $content, self::FINGERPRINT));
        $copy = $this->store->find(17);

        $this->assertInstanceOf(IfirmaPdfCopy::class, $copy);
        $this->assertSame($content, $copy->content());
        $this->assertSame(strlen($content), $copy->byteLength());
        $this->assertSame(self::FINGERPRINT, $copy->fingerprint());
        $this->assertSame(self::NOW, $copy->storedAt());
        $this->assertSame(17, $copy->receiptId());
        $this->assertTrue($copy->isIntact());
    }

    public function testEveryByteValueSurvivesTheRoundTrip()
    {
        $content = '%PDF-' . implode('', array_map('chr', range(0, 255)));

        $this->store->save(3, $content, self::FINGERPRINT);

        $this->assertSame(hash('sha256', $content), hash('sha256', $this->store->find(3)->content()));
    }

    public function testSaveSendsOneReplaceThatHoldsTheContentOnlyAsHexadecimal()
    {
        $content = "%PDF-1.4 secret'bytes\"\\\x00 end";

        $this->store->save(17, $content, self::FINGERPRINT);

        $sent = $this->sentQueries();
        $this->assertCount(2, $sent, 'the table ensure and the REPLACE');
        $this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS `ps_ifirma_pdf_copy`', $sent[0]);
        $this->assertMatchesRegularExpression(
            '/^REPLACE INTO `ps_ifirma_pdf_copy` \(`id_ifirma_receipt`, `fingerprint`, `byte_length`, `content`, `stored_at`\) VALUES \(17, \'' . self::FINGERPRINT . '\', ' . strlen($content) . ', UNHEX\(\'[0-9a-f]+\'\), \'' . self::NOW . '\'\)$/',
            $sent[1]
        );
        $this->assertStringContainsString("UNHEX('" . bin2hex($content) . "')", $sent[1]);
        $this->assertStringNotContainsString('secret', $sent[1]);
        $this->assertStringNotContainsString('%PDF', $sent[1]);
        $this->assertStringNotContainsString("\x00", $sent[1]);
        $this->assertStringNotContainsString('\\', $sent[1]);
    }

    public function testASecondSaveForTheSameDocumentLeavesOneRowWithTheNewerBytes()
    {
        $this->store->save(17, '%PDF-first', self::FINGERPRINT);
        $this->store->save(17, '%PDF-second one', self::OTHER_FINGERPRINT);

        $this->assertSame(1, $this->pdfCopyRowCount());
        $copy = $this->store->find(17);
        $this->assertSame('%PDF-second one', $copy->content());
        $this->assertSame(self::OTHER_FINGERPRINT, $copy->fingerprint());
    }

    public function testExactlyTheLargestAllowedContentIsStored()
    {
        $content = '%PDF-' . str_repeat('a', IfirmaPdfCopyStore::MAX_BYTES - 5);

        $this->assertTrue($this->store->save(17, $content, self::FINGERPRINT));

        $this->assertSame(IfirmaPdfCopyStore::MAX_BYTES, $this->storedPdfCopyRow(17)['byte_length']);
        $this->assertTrue($this->store->find(17)->isIntact());
    }

    public function testAContentOneByteOverTheLimitIsRefusedWithoutAStatement()
    {
        $content = '%PDF-' . str_repeat('a', IfirmaPdfCopyStore::MAX_BYTES - 4);

        $this->assertFalse($this->store->save(17, $content, self::FINGERPRINT));

        $this->assertSame(array(), $this->sentQueries());
    }

    /**
     * @dataProvider refusedSaves
     */
    public function testASaveThatCannotBeValidIsRefusedWithoutAStatement($id, $content, $fingerprint)
    {
        $this->assertFalse($this->store->save($id, $content, $fingerprint));

        $this->assertSame(array(), $this->sentQueries());
        $this->assertSame(0, $this->pdfCopyRowCount());
    }

    public function refusedSaves()
    {
        return array(
            'an empty content' => array(17, '', self::FINGERPRINT),
            'a content that is not a string' => array(17, array('%PDF'), self::FINGERPRINT),
            'a null content' => array(17, null, self::FINGERPRINT),
            'a fingerprint that is too short' => array(17, '%PDF-x', 'abc'),
            'a fingerprint in capitals' => array(17, '%PDF-x', strtoupper(self::FINGERPRINT)),
            'a fingerprint with a quote' => array(17, '%PDF-x', "'" . substr(self::FINGERPRINT, 1)),
            'a fingerprint with a trailing newline' => array(17, '%PDF-x', self::FINGERPRINT . "\n"),
            'a fingerprint that is not a string' => array(17, '%PDF-x', null),
            'id zero' => array(0, '%PDF-x', self::FINGERPRINT),
            'a negative id' => array(-4, '%PDF-x', self::FINGERPRINT),
            'a non numeric id' => array('abc', '%PDF-x', self::FINGERPRINT),
        );
    }

    public function testASaveTheDatabaseRefusesReturnsFalseAndStoresNothing()
    {
        $this->refusePdfCopyWrites();

        $this->assertFalse($this->store->save(17, '%PDF-x', self::FINGERPRINT));

        $this->assertSame(0, $this->pdfCopyRowCount());
    }

    public function testASaveFailsWhenTheTableCannotBeEnsured()
    {
        Db::getInstance()->whenExecute('/^CREATE TABLE IF NOT EXISTS `ps_ifirma_pdf_copy`/', false);

        $this->assertFalse($this->store->save(17, '%PDF-x', self::FINGERPRINT));

        $this->assertSame(0, $this->pdfCopyRowCount());
        $this->assertCount(1, $this->sentQueries(), 'only the ensure was sent');
    }

    // ---- reads --------------------------------------------------------------------------------------

    public function testFindReturnsNullForADocumentWithoutACopy()
    {
        $this->assertNull($this->store->find(99));
        $this->assertNull($this->store->findWithoutContent(99));
    }

    public function testFindReturnsNullForANonPositiveIdWithoutAStatement()
    {
        $this->assertNull($this->store->find(0));
        $this->assertNull($this->store->findWithoutContent(-1));

        $this->assertSame(array(), $this->sentQueries());
    }

    public function testFindReadsWithGetRowAndAddsNoLimitOfItsOwn()
    {
        $this->seedPdfCopy(17, '%PDF-x', self::FINGERPRINT, '2026-11-01 08:00:00');

        $this->store->find(17);

        $reads = array_values(array_filter($this->sentQueries(), function ($sql) {
            return strpos($sql, 'SELECT') === 0;
        }));
        $this->assertCount(1, $reads);
        $this->assertStringNotContainsString('LIMIT', $reads[0]);
        $this->assertStringEndsWith('WHERE id_ifirma_receipt = 17', $reads[0]);
    }

    public function testTheReadWithoutContentDoesNotSelectTheContentColumn()
    {
        $this->seedPdfCopy(17, '%PDF-x', self::FINGERPRINT, '2026-11-01 08:00:00');

        $copy = $this->store->findWithoutContent(17);

        $this->assertNull($copy->content());
        $this->assertSame(6, $copy->byteLength());
        $this->assertSame('2026-11-01 08:00:00', $copy->storedAt());
        $this->assertSame(self::FINGERPRINT, $copy->fingerprint());
        $selects = array_filter($this->sentQueries(), function ($sql) {
            return strpos($sql, 'SELECT') === 0;
        });
        $this->assertCount(1, $selects);
        foreach ($selects as $sql) {
            $this->assertStringNotContainsString('content', $sql);
        }
    }

    public function testAStoredRowWhoseBytesWereCutShortIsReadAsAnInactiveCopy()
    {
        $this->seedPdfCopy(17, '%PDF-cut', self::FINGERPRINT, '2026-11-01 08:00:00', 5000);

        $this->assertFalse($this->store->find(17)->isIntact());
    }

    public function testReadsFailClosedWhenTheTableCannotBeEnsured()
    {
        $this->seedPdfCopy(17, '%PDF-x', self::FINGERPRINT, '2026-11-01 08:00:00');
        Db::getInstance()->whenExecute('/^CREATE TABLE IF NOT EXISTS `ps_ifirma_pdf_copy`/', false);

        $this->assertNull($this->store->find(17));
        $this->assertNull($this->store->findWithoutContent(17));
    }

    // ---- deletes ------------------------------------------------------------------------------------

    public function testDeleteRemovesOneDocumentsCopyOnly()
    {
        $this->seedPdfCopy(17, '%PDF-a', self::FINGERPRINT, '2026-11-01 08:00:00');
        $this->seedPdfCopy(18, '%PDF-b', self::FINGERPRINT, '2026-11-01 08:00:00');

        $this->assertTrue($this->store->delete(17));

        $this->assertNull($this->storedPdfCopyRow(17));
        $this->assertNotNull($this->storedPdfCopyRow(18));
    }

    public function testDeleteOfADocumentWithoutACopyStillSucceeds()
    {
        $this->assertTrue($this->store->delete(17));
    }

    public function testDeleteSendsNothingForANonPositiveId()
    {
        $this->assertFalse($this->store->delete(0));

        $this->assertSame(array(), $this->sentQueries());
    }

    public function testDeletingForOneCustomerLeavesAnotherCustomersCopyAlone()
    {
        $this->receiptOfCustomer(17, 5);
        $this->receiptOfCustomer(18, 5);
        $this->receiptOfCustomer(19, 6);
        foreach (array(17, 18, 19, 20) as $id) {
            $this->seedPdfCopy($id, '%PDF-' . $id, self::FINGERPRINT, '2026-11-01 08:00:00');
        }

        $this->assertTrue($this->store->deleteForCustomer(5));

        $this->assertNull($this->storedPdfCopyRow(17));
        $this->assertNull($this->storedPdfCopyRow(18));
        $this->assertNotNull($this->storedPdfCopyRow(19), 'another customer');
        $this->assertNotNull($this->storedPdfCopyRow(20), 'a copy of a document the module no longer knows');
    }

    public function testDeletingForACustomerSendsTheIdAsADigitString()
    {
        $this->store->deleteForCustomer("5");

        $deletes = array_values(array_filter($this->sentQueries(), function ($sql) {
            return strpos($sql, 'DELETE') === 0;
        }));
        $this->assertSame(
            'DELETE FROM `ps_ifirma_pdf_copy` WHERE id_ifirma_receipt IN (SELECT id_ifirma_receipt FROM `ps_ifirma_receipt` WHERE id_customer = 5)',
            $deletes[0]
        );
    }

    public function testDeletingForANonPositiveCustomerSendsNothing()
    {
        $this->assertFalse($this->store->deleteForCustomer(0));

        $this->assertSame(array(), $this->sentQueries());
    }

    public function testTheCutoffDeleteRemovesOnlyOlderCopiesAndReportsTheCount()
    {
        $this->seedPdfCopy(1, '%PDF-a', self::FINGERPRINT, '2026-10-01 00:00:00');
        $this->seedPdfCopy(2, '%PDF-b', self::FINGERPRINT, '2026-10-02 00:00:00');
        $this->seedPdfCopy(3, '%PDF-c', self::FINGERPRINT, '2026-11-10 00:00:00');
        $this->seedPdfCopy(4, '%PDF-d', self::FINGERPRINT, '2026-10-15 00:00:00');

        $deleted = $this->store->deleteStoredBefore(strtotime('2026-10-15 00:00:00'), 50);

        $this->assertSame(2, $deleted);
        $this->assertNull($this->storedPdfCopyRow(1));
        $this->assertNull($this->storedPdfCopyRow(2));
        $this->assertNotNull($this->storedPdfCopyRow(3));
        $this->assertNotNull($this->storedPdfCopyRow(4), 'stored exactly at the cutoff is not older');
    }

    public function testTheCutoffDeleteRespectsTheLimitAndRemovesTheOldestFirst()
    {
        $this->seedPdfCopy(1, '%PDF-a', self::FINGERPRINT, '2026-10-03 00:00:00');
        $this->seedPdfCopy(2, '%PDF-b', self::FINGERPRINT, '2026-10-01 00:00:00');
        $this->seedPdfCopy(3, '%PDF-c', self::FINGERPRINT, '2026-10-02 00:00:00');

        $deleted = $this->store->deleteStoredBefore(strtotime('2026-11-01 00:00:00'), 2);

        $this->assertSame(2, $deleted);
        $this->assertNotNull($this->storedPdfCopyRow(1), 'the newest of the expired ones stays for the next run');
        $this->assertNull($this->storedPdfCopyRow(2));
        $this->assertNull($this->storedPdfCopyRow(3));
    }

    public function testTheCutoffDeleteFormatsTheTimestampItselfAndCastsTheLimit()
    {
        $this->store->deleteStoredBefore('1793786400', '7 OR 1=1');

        $deletes = array_values(array_filter($this->sentQueries(), function ($sql) {
            return strpos($sql, 'DELETE') === 0;
        }));
        $this->assertSame(
            "DELETE FROM `ps_ifirma_pdf_copy` WHERE stored_at < '" . date('Y-m-d H:i:s', 1793786400) . "' ORDER BY stored_at LIMIT 7",
            $deletes[0]
        );
    }

    /**
     * @dataProvider limitsBelowOne
     */
    public function testTheCutoffDeleteWithALimitBelowOneSendsNothing($limit)
    {
        $this->seedPdfCopy(1, '%PDF-a', self::FINGERPRINT, '2026-10-01 00:00:00');

        $this->assertSame(0, $this->store->deleteStoredBefore(strtotime('2026-11-01 00:00:00'), $limit));

        $this->assertSame(array(), $this->sentQueries());
        $this->assertSame(1, $this->pdfCopyRowCount());
    }

    public function limitsBelowOne()
    {
        return array('zero' => array(0), 'negative' => array(-3), 'text' => array('many'));
    }

    public function testTheCutoffDeleteReportsZeroWhenTheTableCannotBeEnsured()
    {
        Db::getInstance()->whenExecute('/^CREATE TABLE IF NOT EXISTS `ps_ifirma_pdf_copy`/', false);

        $this->assertSame(0, $this->store->deleteStoredBefore(strtotime('2026-11-01 00:00:00'), 50));
    }

    public function testTheCutoffDeleteReportsZeroWhenTheDatabaseRefusesTheStatement()
    {
        Db::getInstance()->whenExecute('/^DELETE FROM `ps_ifirma_pdf_copy`/', false);
        $this->seedPdfCopy(1, '%PDF-a', self::FINGERPRINT, '2026-10-01 00:00:00');

        $this->assertSame(0, $this->store->deleteStoredBefore(strtotime('2026-11-01 00:00:00'), 50));
        $this->assertSame(1, $this->pdfCopyRowCount());
    }

    public function testDeleteAllEmptiesTheTable()
    {
        $this->seedPdfCopy(1, '%PDF-a', self::FINGERPRINT, '2026-10-01 00:00:00');
        $this->seedPdfCopy(2, '%PDF-b', self::FINGERPRINT, '2026-11-10 00:00:00');

        $this->assertTrue($this->store->deleteAll());

        $this->assertSame(0, $this->pdfCopyRowCount());
    }

    public function testTheDeletesFailWhenTheTableCannotBeEnsured()
    {
        Db::getInstance()->whenExecute('/^CREATE TABLE IF NOT EXISTS `ps_ifirma_pdf_copy`/', false);

        $this->assertFalse($this->store->delete(17));
        $this->assertFalse($this->store->deleteForCustomer(5));
        $this->assertFalse($this->store->deleteAll());
    }

    public function testTheTableIsEnsuredOnceForAllTheCallsOfARequest()
    {
        $this->store->save(17, '%PDF-x', self::FINGERPRINT);
        $this->store->find(17);
        $this->store->delete(17);

        $creates = array_filter($this->sentQueries(), function ($sql) {
            return strpos($sql, 'CREATE TABLE') === 0;
        });
        $this->assertCount(1, $creates);
    }

    public function testTheStoreResolvesTheClockAndTheDatabaseWhenNoneIsInjected()
    {
        $store = new IfirmaPdfCopyStore();

        $this->assertTrue($store->save(17, '%PDF-x', self::FINGERPRINT));
        $this->assertEqualsWithDelta(time(), strtotime($this->storedPdfCopyRow(17)['stored_at']), 5);
    }

    /**
     * @dataProvider idsThatAreNotWholePositiveNumbers
     */
    public function testAnIdThatIsNotAWholePositiveNumberNeverReachesAStatement($id)
    {
        $this->assertFalse($this->store->save($id, '%PDF-x', self::FINGERPRINT));
        $this->assertNull($this->store->find($id));
        $this->assertNull($this->store->findWithoutContent($id));
        $this->assertFalse($this->store->delete($id));
        $this->assertFalse($this->store->deleteForCustomer($id));

        $this->assertSame(array(), $this->sentQueries());
    }

    public function idsThatAreNotWholePositiveNumbers()
    {
        return array(
            'an array' => array(array('x')),
            'true' => array(true),
            'a string with a tail' => array('17 OR 1=1'),
            'a string with a newline' => array("17\n"),
            'an exponent' => array('1e3'),
            'a float' => array(17.9),
            'above the column range' => array('4294967296'),
            'a leading zero' => array('017'),
        );
    }

    public function testAnIdGivenAsADigitStringIsAccepted()
    {
        $this->assertTrue($this->store->save('17', '%PDF-x', self::FINGERPRINT));
        $this->assertSame(17, $this->store->find('17')->receiptId());
    }

    public function testAnExceptionOfTheDatabaseIsLeftToTheCaller()
    {
        Db::getInstance()->whenExecute('/^REPLACE INTO `ps_ifirma_pdf_copy`/', function () {
            throw new RuntimeException('packet too large');
        });

        $this->expectException(RuntimeException::class);
        $this->store->save(17, '%PDF-x', self::FINGERPRINT);
    }
}
