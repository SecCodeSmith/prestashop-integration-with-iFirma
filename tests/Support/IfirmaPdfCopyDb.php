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

/**
 * Makes the statements of IfirmaPdfCopyStore run against in-memory rows, under the contract of the table
 * ps_ifirma_pdf_copy: REPLACE replaces by the primary key, UNHEX decodes to the exact bytes, the fingerprint is a
 * CHAR(64), and a statement the table would refuse (malformed, or a content that is not an even run of hex digits)
 * raises an exception instead of being accepted. The customer subquery reads the ifirma_receipt rows kept by the
 * ObjectModel stub.
 *
 * Call scriptPdfCopyDb() in the test, after IfirmaTestState::resetAll(): the rows live on the test instance, so they
 * never reach another test.
 */
trait IfirmaPdfCopyDb
{
    /** @var array[] receipt id => row (id_ifirma_receipt, fingerprint, byte_length, content, stored_at) */
    private $pdfCopyRows = array();

    /** @var bool */
    private $pdfCopyWritesRefused = false;

    protected function scriptPdfCopyDb()
    {
        $this->pdfCopyRows = array();
        $this->pdfCopyWritesRefused = false;
        $db = Db::getInstance();
        $db->whenExecute('/^REPLACE INTO `ps_ifirma_pdf_copy`/', function ($sql) {
            return $this->replacePdfCopy($sql);
        });
        $db->whenGetRow('/^SELECT .+ FROM `ps_ifirma_pdf_copy`/s', function ($sql) {
            return $this->selectPdfCopy($sql);
        });
        $db->whenExecute('/^DELETE FROM `ps_ifirma_pdf_copy`/', function ($sql) {
            return $this->deletePdfCopies($sql);
        });
    }

    /**
     * @param int      $idReceipt
     * @param string   $content
     * @param string   $fingerprint
     * @param string   $storedAt    Y-m-d H:i:s
     * @param int|null $byteLength  the recorded length; strlen($content) when null
     */
    protected function seedPdfCopy($idReceipt, $content, $fingerprint, $storedAt, $byteLength = null)
    {
        $this->pdfCopyRows[(int) $idReceipt] = array(
            'id_ifirma_receipt' => (int) $idReceipt,
            'fingerprint' => (string) $fingerprint,
            'byte_length' => $byteLength === null ? strlen($content) : (int) $byteLength,
            'content' => (string) $content,
            'stored_at' => (string) $storedAt,
        );
    }

    /** @return array|null the stored row of a receipt: id_ifirma_receipt, fingerprint, byte_length, content, stored_at */
    protected function storedPdfCopyRow($idReceipt)
    {
        return isset($this->pdfCopyRows[(int) $idReceipt]) ? $this->pdfCopyRows[(int) $idReceipt] : null;
    }

    /** @return int */
    protected function pdfCopyRowCount()
    {
        return count($this->pdfCopyRows);
    }

    /** From now on the REPLACE is answered with a failure and stores nothing. */
    protected function refusePdfCopyWrites()
    {
        $this->pdfCopyWritesRefused = true;
    }

    private function replacePdfCopy($sql)
    {
        $statement = '/^REPLACE INTO `ps_ifirma_pdf_copy` \(`id_ifirma_receipt`, `fingerprint`, `byte_length`, `content`, `stored_at`\)'
            . ' VALUES \((\d+), \'([0-9a-f]{64})\', (\d+), UNHEX\(\'([0-9a-f]*)\'\), \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\'\)$/';
        if (!preg_match($statement, $sql, $m) || strlen($m[4]) % 2 !== 0) {
            throw new RuntimeException('The table would refuse this REPLACE statement.');
        }
        if ($this->pdfCopyWritesRefused) {
            return false;
        }
        $this->pdfCopyRows[(int) $m[1]] = array(
            'id_ifirma_receipt' => (int) $m[1],
            'fingerprint' => $m[2],
            'byte_length' => (int) $m[3],
            'content' => hex2bin($m[4]),
            'stored_at' => $m[5],
        );

        return true;
    }

    private function selectPdfCopy($sql)
    {
        // getRow() of PrestaShop appends LIMIT 1 itself, so a statement that carries its own would end up with two
        if (!preg_match('/^SELECT (.+?) FROM `ps_ifirma_pdf_copy` WHERE `?id_ifirma_receipt`? = (\d+)$/s', $sql, $m)) {
            throw new RuntimeException('The table would refuse this SELECT statement.');
        }
        $columns = array_map('trim', explode(',', str_replace('`', '', $m[1])));
        if (!isset($this->pdfCopyRows[(int) $m[2]])) {
            return false;
        }
        $row = array();
        foreach ($columns as $column) {
            if (!array_key_exists($column, $this->pdfCopyRows[(int) $m[2]])) {
                throw new RuntimeException('Unknown column ' . $column);
            }
            $row[$column] = $this->pdfCopyRows[(int) $m[2]][$column];
        }

        return $row;
    }

    /** @return int the number of rows the DELETE removed */
    private function deletePdfCopies($sql)
    {
        $byId = '/^DELETE FROM `ps_ifirma_pdf_copy` WHERE id_ifirma_receipt = (\d+)$/';
        $byCustomer = '/^DELETE FROM `ps_ifirma_pdf_copy` WHERE id_ifirma_receipt IN \(SELECT id_ifirma_receipt FROM `ps_ifirma_receipt` WHERE id_customer = (\d+)\)$/';
        $byAge = '/^DELETE FROM `ps_ifirma_pdf_copy` WHERE stored_at < \'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\' ORDER BY stored_at LIMIT (\d+)$/';
        $all = '/^DELETE FROM `ps_ifirma_pdf_copy`$/';

        if (preg_match($byId, $sql, $m)) {
            return $this->removePdfCopies(array((int) $m[1]), PHP_INT_MAX);
        }
        if (preg_match($byCustomer, $sql, $m)) {
            $ids = array();
            foreach (IfirmaTestState::objects('ifirma_receipt') as $id => $row) {
                if ((int) $row['id_customer'] === (int) $m[1]) {
                    $ids[] = (int) $id;
                }
            }

            return $this->removePdfCopies($ids, PHP_INT_MAX);
        }
        if (preg_match($byAge, $sql, $m)) {
            $older = array_filter($this->pdfCopyRows, function ($row) use ($m) {
                return $row['stored_at'] < $m[1];
            });
            uasort($older, function ($a, $b) {
                return strcmp($a['stored_at'], $b['stored_at']);
            });

            return $this->removePdfCopies(array_keys($older), (int) $m[2]);
        }
        if (preg_match($all, $sql)) {
            return $this->removePdfCopies(array_keys($this->pdfCopyRows), PHP_INT_MAX);
        }
        throw new RuntimeException('The table would refuse this DELETE statement.');
    }

    /** @return int how many of the ids existed and were removed, at most $limit */
    private function removePdfCopies(array $ids, $limit)
    {
        $removed = 0;
        foreach ($ids as $id) {
            if ($removed >= $limit) {
                break;
            }
            if (isset($this->pdfCopyRows[$id])) {
                unset($this->pdfCopyRows[$id]);
                ++$removed;
            }
        }

        return $removed;
    }
}
