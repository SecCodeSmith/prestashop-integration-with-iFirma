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
 * MySQL 8 runs with NO_ZERO_DATE and STRICT_TRANS_TABLES by default and then refuses any comparison of a DATE with the
 * literal '0000-00-00' (error 1525, "Incorrect DATE value"): the back-office Orders list answered HTTP 500 on such a shop.
 * MariaDB, which the test stack uses, accepts it, so these tests guard the SQL text itself.
 *
 * Scenario: Given a shop on MySQL 8 with its default sql_mode, when an employee opens the Orders list or a customer's
 * order page syncs the document status, then the query runs and the issue date falls back to the record date when no
 * issue date is stored (NULL, or a zero date left by an older lax server).
 */
class ZeroDateSqlTest extends TestCase
{
    public function testTheIssueDateExpressionFallsBackToTheRecordDateWithoutAZeroDateLiteral()
    {
        $this->assertSame(
            "COALESCE(IF(ifr.issue_date < '1000-01-01', NULL, ifr.issue_date), DATE(ifr.date_add))",
            IfirmaIssueDateSql::expression('ifr')
        );
    }

    public function testTheIssueDateExpressionOfTheReceiptTableItself()
    {
        $this->assertSame(
            "COALESCE(IF(issue_date < '1000-01-01', NULL, issue_date), DATE(date_add))",
            IfirmaIssueDateSql::expression()
        );
    }

    public function testTheOrdersListReadsTheIssueDateThroughTheSharedExpression()
    {
        $sql = IfirmaOrderGridIndicator::selectSql();

        $this->assertStringContainsString(IfirmaIssueDateSql::expression(IfirmaOrderGridIndicator::JOIN_ALIAS), $sql[count($sql) - 1]);
    }

    public function testTheSameDaySiblingLookupMatchesTheDateThroughTheSharedExpression()
    {
        IfirmaReceipt::findStaleIssuedOfCustomerOnDate(7, 3, '2026-11-14', '2026-11-16 00:00:00');
        $queries = Db::getInstance()->queries;

        $this->assertStringContainsString(IfirmaIssueDateSql::expression() . " = '2026-11-14'", end($queries));
    }

    public function testNoShippedSqlComparesWithTheZeroDate()
    {
        $this->assertSame(array(), self::sqlStringsNamingTheZeroDate());
    }

    /**
     * A PHP comparison with the bare literal ('0000-00-00' or '0000-00-00 00:00:00') is fine: only a string that holds
     * more than the literal is SQL text sent to the server.
     *
     * @return string[] file:line of every such SQL string
     */
    private static function sqlStringsNamingTheZeroDate()
    {
        $root = dirname(__DIR__, 2) . '/';
        $files = array($root . 'ifirma.php');
        foreach (array('classes', 'controllers', 'upgrade') as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $bare = array("'0000-00-00'", '"0000-00-00"', "'0000-00-00 00:00:00'", '"0000-00-00 00:00:00"');
        $found = array();
        foreach ($files as $file) {
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (!is_array($token) || !in_array($token[0], array(T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE), true)) {
                    continue;
                }
                if (strpos($token[1], '0000-00-00') !== false && !in_array($token[1], $bare, true)) {
                    $found[] = substr($file, strlen($root)) . ':' . $token[2];
                }
            }
        }

        return $found;
    }
}
