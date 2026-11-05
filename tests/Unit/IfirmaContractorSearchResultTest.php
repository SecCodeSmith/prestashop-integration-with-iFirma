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

/**
 * IfirmaContractorSearchResult: the immutable answer of IfirmaContractorService::search(), the rows
 * and the message of the failure that the service used to keep as state between its calls.
 */
class IfirmaContractorSearchResultTest extends TestCase
{
    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
    }

    private function rows()
    {
        return array(
            array('Identyfikator' => 'A-1', 'Nazwa' => 'Jan Kowalski'),
            array('Identyfikator' => 'A-2', 'Nazwa' => 'Jan Kowalski Handel'),
        );
    }

    public function testEachReaderAnswersWithItsOwnField()
    {
        $r = new IfirmaContractorSearchResult($this->rows(), 'cURL error: timeout');

        $this->assertSame($this->rows(), $r->getRows());
        $this->assertSame('cURL error: timeout', $r->getError());
    }

    public function testAnAnswerWithoutRowsAndWithoutErrorIsNoMatch()
    {
        $r = new IfirmaContractorSearchResult();

        $this->assertSame(array(), $r->getRows());
        $this->assertSame('', $r->getError());
    }

    public function testRowsAndErrorAreIndependent()
    {
        // rows without an error: the search worked
        $found = new IfirmaContractorSearchResult($this->rows());
        $this->assertSame($this->rows(), $found->getRows());
        $this->assertSame('', $found->getError());

        // an error without rows: the search failed and counts as no match
        $failed = new IfirmaContractorSearchResult(array(), 'Bledny podpis');
        $this->assertSame(array(), $failed->getRows());
        $this->assertSame('Bledny podpis', $failed->getError());
    }

    public function testTheErrorReadsAsAString()
    {
        // the readers of the old accessor cast the message to string; the object does that once
        $this->assertSame('', (new IfirmaContractorSearchResult(array(), null))->getError());
        $this->assertSame('500', (new IfirmaContractorSearchResult(array(), 500))->getError());
    }

    public function testRowsAreHandedOutByValue()
    {
        $r = new IfirmaContractorSearchResult($this->rows(), '');

        $rows = $r->getRows();
        $rows[0]['Identyfikator'] = 'changed';
        $rows[] = array('Identyfikator' => 'A-3');

        $this->assertSame($this->rows(), $r->getRows());
    }

    public function fieldProvider()
    {
        return array(
            'rows' => array('rows'),
            'error' => array('error'),
        );
    }

    /**
     * @dataProvider fieldProvider
     */
    public function testNoFieldCanBeWrittenFromOutside($field)
    {
        $r = new IfirmaContractorSearchResult($this->rows(), 'boom');

        try {
            $r->$field = 'changed';
            $this->fail('the ' . $field . ' field accepted a write from outside');
        } catch (Error $e) {
            $this->assertStringContainsString('private property', $e->getMessage());
        }

        $this->assertSame($this->rows(), $r->getRows());
        $this->assertSame('boom', $r->getError());
    }

    public function testItOffersNoMutator()
    {
        // immutable: the constructor and the two readers are the whole public surface
        $this->assertSame(
            array('__construct', 'getRows', 'getError'),
            get_class_methods(IfirmaContractorSearchResult::class)
        );
    }
}
