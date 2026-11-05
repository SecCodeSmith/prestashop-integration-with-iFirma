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
 * IfirmaCustomerIndex: the three reads of load() and the lookups fromRows() builds from their rows.
 */
class CustomerIndexTest extends TestCase
{
    use IfirmaFailingDbReads;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaCustomerIndex.php';
    }

    // ---- helpers ----------------------------------------------------------------------------

    private function customer($id, $email, $first = 'X', $last = 'Y')
    {
        return array('id_customer' => $id, 'email' => $email, 'firstname' => $first, 'lastname' => $last);
    }

    private function address($idCustomer, array $over = array())
    {
        return array_merge(array(
            'id_customer' => $idCustomer,
            'vat_number' => '',
            'dni' => '',
            'postcode' => '',
            'company' => '',
            'firstname' => '',
            'lastname' => '',
        ), $over);
    }

    private function stored($idCustomer, $identifier, $type)
    {
        return array('id_customer' => $idCustomer, 'ifirma_identifier' => $identifier, 'matched_by' => $type);
    }

    /**
     * @param string $sql
     * @return string the statement with every run of whitespace turned into one space
     */
    private function collapse($sql)
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    // ---- fromRows: the NIP lookup -----------------------------------------------------------

    public function testNipsComeFromTheVatNumberAndTheDniAsDigitsOfAtLeastTen()
    {
        $index = IfirmaCustomerIndex::fromRows(array(), array(
            $this->address(2, array('vat_number' => 'PL 525-224-84-81')),
            $this->address(1, array('vat_number' => '5252248481', 'dni' => '123456789')),
            $this->address(3, array('dni' => '12345678901')),
            $this->address(4, array('vat_number' => '123456789')),
        ), array());

        $this->assertSame(array(
            '5252248481' => array(2 => true, 1 => true),
            '12345678901' => array(3 => true),
        ), $index->nip);
    }

    // ---- fromRows: the e-mail lookup --------------------------------------------------------

    public function testEmailsAreTrimmedAndLowerCasedAsUnicodeAndBlankOnesAreLeftOut()
    {
        $index = IfirmaCustomerIndex::fromRows(array(
            $this->customer(3, "  \xC5\x81ukasz@Example.TEST "),
            $this->customer(1, 'LUKASZ-X@example.test'),
            $this->customer(2, "\xC5\x82UKASZ@example.test"),
            $this->customer(4, ''),
            $this->customer(5, '   '),
        ), array(), array());

        $this->assertSame(array(
            "\xC5\x82ukasz@example.test" => array(3 => true, 2 => true),
            'lukasz-x@example.test' => array(1 => true),
        ), $index->email);
    }

    // ---- fromRows: the name lookup ----------------------------------------------------------

    public function testNamesAreKeyedByNormalisedNameAndPostcodeDigits()
    {
        $index = IfirmaCustomerIndex::fromRows(
            array(
                $this->customer(1, 'a@example.test', 'Jan', 'Nowak'),
                $this->customer(2, 'b@example.test', 'Ola', 'Ziel'),
                $this->customer(3, 'c@example.test', 'Ewa', 'Brak'),
                $this->customer(4, 'd@example.test', '', ''),
            ),
            array(
                $this->address(1, array('company' => 'ACME Sp. z o.o.', 'firstname' => 'Jan', 'lastname' => 'Nowak', 'postcode' => '00-001')),
                $this->address(1, array('firstname' => 'Anna', 'lastname' => 'Inna', 'postcode' => '30-002')),
                $this->address(2, array('company' => 'Beta', 'firstname' => 'A', 'lastname' => 'B', 'postcode' => '')),
                $this->address(4, array('postcode' => '11-111')),
            ),
            array()
        );

        // addresses first (company, then the person on it), customers' own names after them;
        // customer 2 has no postcode, customer 3 no address, customer 4 no name: nothing to key
        $this->assertSame(array(
            'acme sp z o o|00001' => array(1 => true),
            'jan nowak|00001' => array(1 => true),
            'anna inna|30002' => array(1 => true),
            'jan nowak|30002' => array(1 => true),
        ), $index->name);
    }

    public function testTheSameNameAtTheSamePostcodeCollectsEveryCustomer()
    {
        $index = IfirmaCustomerIndex::fromRows(
            array($this->customer(1, 'a@example.test', 'Jan', 'Kowalski')),
            array(
                $this->address(2, array('firstname' => 'Jan', 'lastname' => 'Kowalski', 'postcode' => '00-001')),
                $this->address(1, array('postcode' => '00-001')),
            ),
            array()
        );

        $this->assertSame(array('jan kowalski|00001' => array(2 => true, 1 => true)), $index->name);
    }

    // ---- fromRows: the stored links ---------------------------------------------------------

    public function testStoredLinksAreKeyedByCustomerAndHoldStrings()
    {
        $index = IfirmaCustomerIndex::fromRows(array(), array(), array(
            array('id_customer' => '7', 'ifirma_identifier' => 123, 'matched_by' => 'import-nip'),
            $this->stored(5, 'A-5', 'manual'),
            array('id_customer' => 9, 'ifirma_identifier' => 'N-9', 'matched_by' => null),
        ));

        $this->assertSame(array(
            7 => array('identifier' => '123', 'type' => 'import-nip'),
            5 => array('identifier' => 'A-5', 'type' => 'manual'),
            9 => array('identifier' => 'N-9', 'type' => ''),
        ), $index->existing);
    }

    public function testTheLastStoredLinkOfACustomerWins()
    {
        $index = IfirmaCustomerIndex::fromRows(array(), array(), array(
            $this->stored(7, 'FIRST', 'import-nip'),
            $this->stored(5, 'A-5', 'manual'),
            $this->stored(7, 'LATER', 'import-name'),
        ));

        $this->assertSame(array(
            7 => array('identifier' => 'LATER', 'type' => 'import-name'),
            5 => array('identifier' => 'A-5', 'type' => 'manual'),
        ), $index->existing);
    }

    public function testRowsThatAreNotAnArrayCountAsNoRows()
    {
        foreach (array(false, null, 'rows') as $notRows) {
            $index = IfirmaCustomerIndex::fromRows($notRows, $notRows, $notRows);

            $this->assertSame(array(), $index->nip);
            $this->assertSame(array(), $index->email);
            $this->assertSame(array(), $index->name);
            $this->assertSame(array(), $index->existing);
        }
    }

    // ---- load: the only reads ---------------------------------------------------------------

    /**
     * Scripts what the three reads of load() return.
     */
    private function scriptShop()
    {
        $db = Db::getInstance();
        $db->whenExecuteS('/FROM `ps_customer`/', array($this->customer(1, 'A@example.test', 'Jan', 'Nowak')));
        $db->whenExecuteS('/FROM `ps_address`/', array($this->address(1, array('vat_number' => '525-224-84-81', 'company' => 'ACME', 'postcode' => '00-001'))));
        $db->whenExecuteS('/FROM `ps_ifirma_contractor_map`/', array($this->stored(1, 'OLD', 'manual')));
    }

    public function testLoadReadsTheShopWithExactlyThreeQueriesInThisOrder()
    {
        $this->scriptShop();

        IfirmaCustomerIndex::load();

        $this->assertSame(array(
            'SELECT id_customer, email, firstname, lastname FROM `ps_customer`',
            'SELECT id_customer, vat_number, dni, postcode, company, firstname, lastname FROM `ps_address` WHERE id_customer > 0 AND deleted = 0',
            'SELECT id_customer, ifirma_identifier, matched_by FROM `ps_ifirma_contractor_map`',
        ), array_map(array($this, 'collapse'), Db::getInstance()->queries));
    }

    public function testLoadBuildsTheLookupsFromThoseRows()
    {
        $this->scriptShop();

        $index = IfirmaCustomerIndex::load();

        $this->assertSame(array('5252248481' => array(1 => true)), $index->nip);
        $this->assertSame(array('a@example.test' => array(1 => true)), $index->email);
        $this->assertSame(array('acme|00001' => array(1 => true), 'jan nowak|00001' => array(1 => true)), $index->name);
        $this->assertSame(array(1 => array('identifier' => 'OLD', 'type' => 'manual')), $index->existing);
    }

    public function failingReads()
    {
        // what each read feeds: the customers the e-mails, the addresses the NIPs and (with the
        // postcodes of the customers' own names) the names, the stored links the existing links
        return array(
            'customers' => array('customer', array('email')),
            'addresses' => array('address', array('nip', 'name')),
            'stored links' => array('ifirma_contractor_map', array('existing')),
        );
    }

    /**
     * @dataProvider failingReads
     */
    public function testAReadThatFailsLeavesWhatItFeedsEmptyAndTheOthersBuilt($failingTable, array $emptyLookups)
    {
        $this->failingRead($failingTable);
        $this->scriptShop();

        $index = IfirmaCustomerIndex::load();

        $lookups = array('nip' => $index->nip, 'email' => $index->email, 'name' => $index->name, 'existing' => $index->existing);
        foreach ($lookups as $lookup => $entries) {
            if (in_array($lookup, $emptyLookups, true)) {
                $this->assertSame(array(), $entries, $lookup);
            } else {
                $this->assertNotSame(array(), $entries, $lookup);
            }
        }
        $this->assertCount(3, Db::getInstance()->queries);
    }
}
