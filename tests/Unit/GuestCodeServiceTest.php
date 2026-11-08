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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaGuestCodeService.php';

/**
 * IfirmaGuestCodeService against an in-memory twin of the ifirma_guest_code table: the Db stub answers the
 * statements of the service from the rows kept here, the clock and the random source are seams.
 */
class GuestCodeServiceTest extends TestCase
{
    const TABLE = '`ps_ifirma_guest_code`';

    /** @var array[] id => row of the table */
    private $rows = array();

    /** @var int */
    private $nextId = 1;

    /** @var int the clock of the service */
    private $now;

    /** @var int[] numbers the random source hands out, then 0 */
    private $numbers = array();

    /** @var string[] every statement that changes the table */
    private $writes = array();

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        $this->rows = array();
        $this->nextId = 1;
        $this->writes = array();
        $this->numbers = array();
        $this->now = strtotime('2026-11-08 10:00:00');
        $this->installTable();
    }

    private function stamp($ts)
    {
        return date('Y-m-d H:i:s', $ts);
    }

    private function service()
    {
        $self = $this;

        return new IfirmaGuestCodeService(
            function () use ($self) {
                return $self->now;
            },
            function ($max) use ($self) {
                return $self->numbers ? array_shift($self->numbers) : 0;
            }
        );
    }

    private function order($id)
    {
        $o = new Order();
        $o->id = $id;

        return $o;
    }

    /** Makes the Db stub behave like the table for the statements of the service. */
    private function installTable()
    {
        $self = $this;
        $db = Db::getInstance();
        $t = self::TABLE;

        $db->whenExecute('/^DELETE FROM ' . $t . '\s+WHERE expires_at < \'([^\']+)\'\s+OR \(used_at IS NOT NULL AND used_at < \'([^\']+)\'\)/', function ($sql, $m) use ($self) {
            $self->writes[] = $sql;
            foreach ($self->rows as $id => $row) {
                if ($row['expires_at'] < $m[1] || ($row['used_at'] !== null && $row['used_at'] < $m[2])) {
                    unset($self->rows[$id]);
                }
            }

            return true;
        });
        $db->whenExecute('/^UPDATE ' . $t . ' SET invalidated = 1\s+WHERE id_order = (\d+) AND used_at IS NULL/', function ($sql, $m) use ($self) {
            $self->writes[] = $sql;
            foreach ($self->rows as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $row['used_at'] === null) {
                    $self->rows[$id]['invalidated'] = 1;
                }
            }

            return true;
        });
        $db->whenExecute('/^INSERT INTO ' . $t . '.*VALUES \((\d+), \'(\w+)\', \'(\w+)\', 0, \'(\w+)\',\s+\'([^\']+)\', \'([^\']+)\', NULL, 0\)/s', function ($sql, $m) use ($self) {
            $self->writes[] = $sql;
            $self->rows[$self->nextId++] = array(
                'id_order' => (int) $m[1], 'code_hash' => $m[2], 'salt' => $m[3], 'attempts' => 0, 'ip_hash' => $m[4],
                'date_add' => $m[5], 'expires_at' => $m[6], 'used_at' => null, 'invalidated' => 0,
            );

            return true;
        });
        $db->whenExecute('/^UPDATE ' . $t . ' SET attempts = attempts \+ 1 WHERE id_ifirma_guest_code = (\d+)/', function ($sql, $m) use ($self) {
            $self->writes[] = $sql;
            $self->rows[(int) $m[1]]['attempts']++;

            return true;
        });
        $db->whenExecute('/^UPDATE ' . $t . ' SET invalidated = 1\s+WHERE id_ifirma_guest_code = (\d+) AND attempts >= (\d+)/', function ($sql, $m) use ($self) {
            $self->writes[] = $sql;
            if ($self->rows[(int) $m[1]]['attempts'] >= (int) $m[2]) {
                $self->rows[(int) $m[1]]['invalidated'] = 1;
            }

            return true;
        });
        $db->whenExecute('/^UPDATE ' . $t . ' SET used_at = \'([^\']+)\'\s+WHERE id_ifirma_guest_code = (\d+) AND used_at IS NULL AND invalidated = 0/', function ($sql, $m) use ($self) {
            $self->writes[] = $sql;
            $row = $self->rows[(int) $m[2]];
            if ($row['used_at'] !== null || $row['invalidated']) {
                return 0;
            }
            $self->rows[(int) $m[2]]['used_at'] = $m[1];

            return 1;
        });
        $count = function ($pattern, $field) use ($self, $db, $t) {
            $db->whenGetValue('/^SELECT COUNT\(\*\) FROM ' . $t . '\s+WHERE ' . $pattern . '/', function ($sql, $m) use ($self, $field) {
                $n = 0;
                foreach ($self->rows as $row) {
                    if ((string) $row[$field] === (string) $m[1] && $row['date_add'] >= $m[2]) {
                        $n++;
                    }
                }

                return $n;
            });
        };
        $count('id_order = (\d+)\s+AND date_add >= \'([^\']+)\'', 'id_order');
        $count('ip_hash = \'(\w+)\'\s+AND date_add >= \'([^\']+)\'', 'ip_hash');
        $db->whenGetValue('/^SELECT COUNT\(\*\) FROM ' . $t . '\s+WHERE id_order = (\d+)\s+AND date_add > \'([^\']+)\'/', function ($sql, $m) use ($self) {
            $n = 0;
            foreach ($self->rows as $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $row['date_add'] > $m[2]) {
                    $n++;
                }
            }

            return $n;
        });
        $db->whenGetRow('/^SELECT id_ifirma_guest_code, code_hash, salt FROM ' . $t . '\s+WHERE id_order = (\d+)\s+AND used_at IS NULL AND invalidated = 0\s+AND expires_at > \'([^\']+)\'/', function ($sql, $m) use ($self) {
            foreach (array_reverse($self->rows, true) as $id => $row) {
                if ((int) $row['id_order'] === (int) $m[1] && $row['used_at'] === null && !$row['invalidated'] && $row['expires_at'] > $m[2]) {
                    return array('id_ifirma_guest_code' => $id, 'code_hash' => $row['code_hash'], 'salt' => $row['salt']);
                }
            }

            return false;
        });
    }

    // ---- the code ------------------------------------------------------------------------------------

    public function testTheCodeHasSixDigitsAndIsZeroPadded()
    {
        $this->numbers = array(42, 999999, 0);
        $service = $this->service();

        $this->assertSame('000042', $service->issue($this->order(1), '10.0.0.1'));
        $this->assertSame('999999', $service->issue($this->order(2), '10.0.0.1'));
        $this->assertSame('000000', $service->issue($this->order(3), '10.0.0.1'));
    }

    public function testWithoutASeamTheCodeComesFromTheSecureRandomSource()
    {
        $service = new IfirmaGuestCodeService();

        $code = $service->issue($this->order(1), '10.0.0.1');

        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertTrue($service->verify(1, $code));
    }

    public function testACodeIsAcceptedInsideItsFifteenMinutesAndRefusedAfterThem()
    {
        $this->numbers = array(123456, 654321);
        $service = $this->service();
        $service->issue($this->order(1), '10.0.0.1');
        $this->now += 14 * 60;
        $this->assertTrue($service->verify(1, '123456'));

        $this->now = strtotime('2026-11-08 11:00:00');
        $service->issue($this->order(2), '10.0.0.1');
        $this->now += 16 * 60;
        $this->assertFalse($service->verify(2, '654321'));
    }

    public function testACodeWorksOnce()
    {
        $this->numbers = array(123456);
        $service = $this->service();
        $service->issue($this->order(1), '10.0.0.1');

        $this->assertTrue($service->verify(1, '123456'));
        $this->assertFalse($service->verify(1, '123456'));
        $this->assertNotNull(reset($this->rows)['used_at']);
    }

    public function testANewCodeInvalidatesTheEarlierOne()
    {
        $this->numbers = array(111111, 222222);
        $service = $this->service();
        $service->issue($this->order(1), '10.0.0.1');
        $this->now += 120;
        $service->issue($this->order(1), '10.0.0.1');

        $this->assertFalse($service->verify(1, '111111'));
        $this->assertTrue($service->verify(1, '222222'));
    }

    public function testACodeOfAnotherOrderIsNotAccepted()
    {
        $this->numbers = array(111111);
        $service = $this->service();
        $service->issue($this->order(1), '10.0.0.1');

        $this->assertFalse($service->verify(2, '111111'));
        $this->assertFalse($service->verify(0, '111111'));
    }

    public function testTheFifthWrongAttemptInvalidatesTheCodeAndTheRightCodeIsThenRefused()
    {
        $this->numbers = array(123456);
        $service = $this->service();
        $service->issue($this->order(1), '10.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->verify(1, '000001'));
        }

        $this->assertSame(5, reset($this->rows)['attempts']);
        $this->assertSame(1, reset($this->rows)['invalidated']);
        $this->assertFalse($service->verify(1, '123456'));
    }

    public function testFourWrongAttemptsLeaveTheCodeUsable()
    {
        $this->numbers = array(123456);
        $service = $this->service();
        $service->issue($this->order(1), '10.0.0.1');

        for ($i = 0; $i < 4; $i++) {
            $this->assertFalse($service->verify(1, '000001'));
        }

        $this->assertTrue($service->verify(1, '123456'));
    }

    // ---- the limits ----------------------------------------------------------------------------------

    public function testAnImmediateResendIsRefusedAndTheWindowOpensAfterAMinute()
    {
        $this->numbers = array(111111, 222222, 333333);
        $service = $this->service();
        $this->assertSame('111111', $service->issue($this->order(1), '10.0.0.1'));

        $this->now += 30;
        $this->assertNull($service->issue($this->order(1), '10.0.0.1'));
        $this->assertCount(1, $this->rows);

        $this->now += 31;
        // the refused request drew its number too (the same work either way)
        $this->assertSame('333333', $service->issue($this->order(1), '10.0.0.1'));
        $this->assertCount(2, $this->rows);
    }

    public function testAnOrderGetsAtMostFiveCodesInADay()
    {
        $service = $this->service();
        for ($i = 0; $i < 5; $i++) {
            $this->numbers = array(100000 + $i);
            $this->assertNotNull($service->issue($this->order(1), '10.0.0.1'), 'code ' . ($i + 1));
            $this->now += 61;
        }

        $this->assertNull($service->issue($this->order(1), '10.0.0.1'));
        $this->assertCount(5, $this->rows);

        // the day goes by: the cap no longer counts the old codes
        $this->now += 86400;
        $this->assertNotNull($service->issue($this->order(1), '10.0.0.1'));
    }

    public function testAClientAddressGetsAtMostTwentyCodesInAnHour()
    {
        $service = $this->service();
        for ($i = 1; $i <= 20; $i++) {
            $this->assertNotNull($service->issue($this->order($i), '10.0.0.1'), 'code ' . $i);
        }

        $this->assertNull($service->issue($this->order(21), '10.0.0.1'));
        $this->assertCount(20, $this->rows);
        // another address is not affected
        $this->assertNotNull($service->issue($this->order(21), '10.0.0.2'));

        // an hour later the first address may ask again
        $this->now += 3601;
        $this->assertNotNull($service->issue($this->order(22), '10.0.0.1'));
    }

    // ---- what is stored ------------------------------------------------------------------------------

    public function testTheTableHoldsOnlyTheHashOfTheCodeAndOfTheAddress()
    {
        $this->numbers = array(481516);
        $service = $this->service();
        $code = $service->issue($this->order(1), '203.0.113.9');
        $this->assertSame('481516', $code);

        $row = reset($this->rows);
        $this->assertSame(hash('sha256', $row['salt'] . $code), $row['code_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row['salt']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['ip_hash']);
        $this->assertNotSame('203.0.113.9', $row['ip_hash']);
        $this->assertSame($this->stamp($this->now + 900), $row['expires_at']);

        // neither the code nor the address appears in anything sent to the database (the hex values are
        // taken out first: a hash may contain the six digits by chance)
        $db = Db::getInstance();
        $all = implode("\n", array_merge($db->queries, $db->executed));
        $all = preg_replace('/[0-9a-f]{32,64}/', 'HEX', $all);
        $this->assertStringNotContainsString('481516', $all);
        $this->assertStringNotContainsString('203.0.113.9', $all);
        $this->assertSame(array(), $db->inserts);
        $this->assertSame(array(), $db->updates);
    }

    public function testInputThatIsNotSixDigitsIsRefusedWithoutTouchingTheTable()
    {
        $this->numbers = array(123456);
        $service = $this->service();
        $service->issue($this->order(1), '10.0.0.1');
        $before = count(Db::getInstance()->queries);
        $writes = $this->writes;

        foreach (array('12345', '1234567', 'abcdef', '12 456', '', ' 23456', "12345\n", 123456, null, array('123456')) as $input) {
            $this->assertFalse($service->verify(1, $input), var_export($input, true));
        }

        $this->assertSame($before, count(Db::getInstance()->queries));
        $this->assertSame($writes, $this->writes);
        $this->assertSame(0, reset($this->rows)['attempts']);
    }

    public function testAnOrderWithoutAnActiveCodeIsRefused()
    {
        $this->assertFalse($this->service()->verify(77, '123456'));
        $this->assertSame(array(), $this->rows);
    }

    /**
     * Db::getRow() appends its own "LIMIT 1"; a second LIMIT in the statement is a syntax error on MariaDB,
     * which made every code check fail on a real shop (red team 1.6.0, dimension 1).
     */
    public function testTheVerifyQueryLeavesTheLimitToGetRow()
    {
        $this->service()->verify(77, '123456');

        $select = null;
        foreach (array_reverse(Db::getInstance()->queries) as $sql) {
            if (strpos($sql, 'SELECT id_ifirma_guest_code') === 0) {
                $select = $sql;
                break;
            }
        }
        $this->assertNotNull($select, 'the verification reads the newest active code with getRow()');
        $this->assertStringNotContainsStringIgnoringCase('LIMIT', $select);
    }

    // ---- housekeeping ----------------------------------------------------------------------------------

    private function seed($idOrder, $dateAdd, $expiresAt, $usedAt)
    {
        $this->rows[$this->nextId++] = array(
            'id_order' => $idOrder, 'code_hash' => str_repeat('a', 64), 'salt' => str_repeat('b', 32), 'attempts' => 0,
            'ip_hash' => str_repeat('c', 64), 'date_add' => $dateAdd, 'expires_at' => $expiresAt, 'used_at' => $usedAt,
            'invalidated' => 0,
        );
    }

    public function testIssuingDeletesTheExpiredAndUsedRowsOlderThanADay()
    {
        $old = $this->stamp($this->now - 2 * 86400);
        $oldExpiry = $this->stamp($this->now - 2 * 86400 + 900);
        $this->seed(7, $old, $oldExpiry, $oldExpiry);          // used two days ago
        $this->seed(8, $old, $oldExpiry, null);                // expired two days ago, never used
        $recent = $this->stamp($this->now - 3600);
        $this->seed(9, $recent, $this->stamp($this->now - 2700), $recent); // used an hour ago: kept
        $this->numbers = array(123456);

        $this->service()->issue($this->order(1), '10.0.0.1');

        $orders = array();
        foreach ($this->rows as $row) {
            $orders[] = $row['id_order'];
        }
        $this->assertSame(array(9, 1), $orders);
    }

    public function testCleanupCanBeCalledOnItsOwn()
    {
        $old = $this->stamp($this->now - 3 * 86400);
        $this->seed(7, $old, $old, null);

        $this->service()->cleanup();

        $this->assertSame(array(), $this->rows);
    }
}
