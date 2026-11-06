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

class ContractorMapTest extends TestCase
{
    use IfirmaContractorMapDb;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        require_once dirname(__DIR__, 2) . '/classes/IfirmaContractorMap.php';
        $this->scriptContractorMapDb();
    }

    private function creates()
    {
        return array_values(array_filter(Db::getInstance()->executed, function ($q) {
            return strpos($q, 'CREATE TABLE IF NOT EXISTS `ps_ifirma_contractor_map`') === 0;
        }));
    }

    public function testRoundTrip()
    {
        $map = IfirmaContractorMap::link(7, 'KOWALSKI-01', 'nip', 'Jan Kowalski');

        $this->assertNotEmpty($map->id);
        $found = IfirmaContractorMap::getByCustomer(7);
        $this->assertInstanceOf(IfirmaContractorMap::class, $found);
        $this->assertSame('KOWALSKI-01', $found->ifirma_identifier);
        $this->assertSame('nip', $found->matched_by);
        $this->assertSame('Jan Kowalski', $found->ifirma_name);

        $byIdentifier = IfirmaContractorMap::getByIdentifier('KOWALSKI-01');
        $this->assertSame(7, (int) $byIdentifier->id_customer);
        $this->assertNull(IfirmaContractorMap::getByCustomer(8));
        $this->assertNull(IfirmaContractorMap::getByIdentifier('NOPE'));
    }

    public function testLinkKeepsAFullLengthIdentifierWithoutTruncation()
    {
        IfirmaContractorMap::link(7, 'EC_JACEK ORZECHO', 'name');

        $this->assertSame('EC_JACEK ORZECHO', IfirmaContractorMap::getByCustomer(7)->ifirma_identifier);
        $this->assertSame('EC_JACEK ORZECHO', IfirmaContractorMap::getByIdentifier('EC_JACEK ORZECHO')->ifirma_identifier);
    }

    public function testIdentifierColumnIsDefinedWith64Characters()
    {
        $this->assertSame(64, IfirmaContractorMap::$definition['fields']['ifirma_identifier']['size']);
    }

    public function testIdentifierFieldIsDefinedKeyForKeyAsDeclared()
    {
        $this->assertSame(
            array('type' => ObjectModel::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 64),
            IfirmaContractorMap::$definition['fields']['ifirma_identifier']
        );
    }

    public function identifierLengthProvider()
    {
        $atTheLimit = str_repeat('A', 63) . 'B';
        $polish = str_repeat("\xC5\xBB", 64);

        return array(
            'ascii, exactly 64 characters is kept whole' => array($atTheLimit, $atTheLimit),
            'ascii, 65 characters lose the 65th' => array($atTheLimit . 'C', $atTheLimit),
            'multibyte, exactly 64 characters is kept whole' => array($polish, $polish),
            'multibyte, 65 characters are cut by characters, not bytes' => array($polish . "\xC5\xBB", $polish),
        );
    }

    /**
     * @dataProvider identifierLengthProvider
     */
    public function testLinkStoresAtMostTheFirst64CharactersOfTheIdentifier($given, $stored)
    {
        $map = IfirmaContractorMap::link(7, $given, 'nip', 'Jan Kowalski');

        $this->assertSame($stored, $map->ifirma_identifier);
        $rows = array_values($this->mapRows());
        $this->assertCount(1, $rows);
        $this->assertSame($stored, $rows[0]['ifirma_identifier']);
        $this->assertSame($stored, IfirmaContractorMap::getByCustomer(7)->ifirma_identifier);
    }

    public function testSecondLinkForTheSameCustomerReplacesTheFirst()
    {
        IfirmaContractorMap::link(7, 'OLD-1', 'module', 'Old');
        IfirmaContractorMap::link(7, 'NEW-1', 'import-nip', 'New');

        $rows = $this->mapRows();
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('NEW-1', $row['ifirma_identifier']);
        $this->assertSame('import-nip', $row['matched_by']);
        $this->assertNull(IfirmaContractorMap::getByIdentifier('OLD-1'));
    }

    public function testUnlinkCustomer()
    {
        IfirmaContractorMap::link(7, 'A-1', 'nip');
        IfirmaContractorMap::link(8, 'B-1', 'name');

        $this->assertTrue(IfirmaContractorMap::unlinkCustomer(7));
        $this->assertFalse(IfirmaContractorMap::unlinkCustomer(7));
        $this->assertNull(IfirmaContractorMap::getByCustomer(7));
        $this->assertNotNull(IfirmaContractorMap::getByCustomer(8));
    }

    public function testCountByMatchType()
    {
        IfirmaContractorMap::link(1, 'A', 'nip');
        IfirmaContractorMap::link(2, 'B', 'nip');
        IfirmaContractorMap::link(3, 'C', 'module');

        $this->assertSame(array('module' => 1, 'nip' => 2), IfirmaContractorMap::countByMatchType());
    }

    public function testCountByMatchTypeOnAnEmptyTable()
    {
        $this->assertSame(array(), IfirmaContractorMap::countByMatchType());
    }

    public function testTableIsCreatedLazilyExactlyOnce()
    {
        $this->assertCount(0, $this->creates());

        IfirmaContractorMap::getByCustomer(1);
        IfirmaContractorMap::link(1, 'A', 'nip');
        IfirmaContractorMap::countByMatchType();

        $creates = $this->creates();
        $this->assertCount(1, $creates);
        $this->assertStringContainsString('UNIQUE KEY `uniq_customer` (`id_customer`)', $creates[0]);
    }

    public function modelCallProvider()
    {
        // link() and unlinkCustomer() reach the table through getByCustomer(), which checks it first
        return array(
            'getByCustomer' => array('getByCustomer', array(7)),
            'getByIdentifier' => array('getByIdentifier', array('KOWALSKI-01')),
            'link' => array('link', array(7, 'KOWALSKI-01', 'nip')),
            'unlinkCustomer' => array('unlinkCustomer', array(7)),
            'countByMatchType' => array('countByMatchType', array()),
            'truncateAll' => array('truncateAll', array()),
        );
    }

    /**
     * @dataProvider modelCallProvider
     */
    public function testEveryModelCallEnsuresTheTableExactlyOnce($method, $args)
    {
        $this->assertCount(0, $this->creates());

        call_user_func_array(array('IfirmaContractorMap', $method), $args);
        call_user_func_array(array('IfirmaContractorMap', $method), $args);

        $this->assertCount(1, $this->creates());
    }

    public function testTruncateAll()
    {
        $this->assertTrue(IfirmaContractorMap::truncateAll());

        $this->assertContains('TRUNCATE TABLE `ps_ifirma_contractor_map`', Db::getInstance()->executed);
    }
}
