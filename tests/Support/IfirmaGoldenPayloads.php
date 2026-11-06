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
 * For TestCases that compare everything one createReceipt() scenario sends to iFirma with a fixture
 * (refactor-srp-splits 5.1).
 *
 * A fixture is tests/fixtures/payloads/<class>-<test>[.<variant>].json, named after the golden test
 * that checks it (CreateReceiptGoldenTest::testSuccessStampsReference reads
 * create-receipt-success-stamps-reference.json). It holds, for one scenario:
 *  - from      the test of the source class whose scenario it is; the golden test itself for a scenario
 *              the source class lacks
 *  - requests  every POST and PUT the module sent, oldest first: method, path and the decoded JSON body
 *  - stored    the request_payload of every write of the receipt, oldest first (decoded)
 *  - threw     the message of the exception the call ended in; null when it returned
 *
 * Bodies are compared as decoded structures, key for key and type for type, so a changed key order,
 * value or cast fails. Their bytes are checked too: a wire body must be the compact encoding with
 * unescaped unicode and slashes the API client sends, a stored payload the pretty encoding with
 * unescaped unicode that encodeRequest() stores. DataWystawienia and DataSprzedazy are the only
 * values that move with the clock; a value equal to today's date is read as {today}.
 *
 * To capture the fixtures again after a change of the payload on purpose, run the golden tests with
 * IFIRMA_GOLDEN_RECORD=1: they write the files (the tests then show as skipped) and check nothing.
 * Review the diff of the fixtures before committing it.
 */
trait IfirmaGoldenPayloads
{
    /** @var string[] fixtures written in this test (IFIRMA_GOLDEN_RECORD=1) */
    private $goldenRecorded = array();

    /**
     * Runs one scenario and compares what it sent with its fixture.
     *
     * @param ScriptedTransport $transport the transport the scenario's client sends through
     * @param callable          $run       makes the createReceipt() call; an Exception it throws is part of the scenario
     * @param string            $variant   '' or a name for one of several scenarios of the same test
     */
    protected function assertGoldenPayloads(ScriptedTransport $transport, $run, $variant = '')
    {
        $name = $this->goldenFixtureName($variant);
        $firstCall = count($transport->calls);
        $firstWrite = count(IfirmaTestState::writes('ifirma_receipt'));

        $days = array(date('Y-m-d'));
        $threw = null;
        try {
            call_user_func($run);
        } catch (Exception $e) {
            $threw = $e->getMessage();
        }
        $days[] = date('Y-m-d');

        $record = array(
            'from' => $this->goldenSource(),
            'requests' => $this->goldenRequests(array_slice($transport->calls, $firstCall), $days),
            'stored' => $this->goldenStored(array_slice(IfirmaTestState::writes('ifirma_receipt'), $firstWrite), $days),
            'threw' => $threw,
        );

        $path = dirname(__DIR__) . '/fixtures/payloads/' . $name . '.json';
        if (getenv('IFIRMA_GOLDEN_RECORD') === '1') {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            $this->goldenRecorded[] = $name . '.json';

            return;
        }

        $this->assertFileExists($path, 'no fixture for this scenario; capture it with IFIRMA_GOLDEN_RECORD=1');
        $this->assertSame(
            json_decode(file_get_contents($path), true),
            $record,
            $name . '.json: the payloads differ from the captured ones'
        );
    }

    /**
     * Every test of a source test class either has a golden test of the same name, or is listed as
     * sending no request, so that a new source test cannot be left out unnoticed.
     *
     * @param string $sourceFile  the source test class file
     * @param array  $payloadFree test name => why the scenario sends no request payload
     */
    protected function assertEverySourceTestIsAccountedFor($sourceFile, array $payloadFree)
    {
        preg_match_all('/public function (test\w+)\(/', file_get_contents($sourceFile), $found);

        $this->assertNotEmpty($found[1]);
        foreach ($found[1] as $test) {
            $this->assertTrue(
                method_exists($this, $test) || isset($payloadFree[$test]),
                basename($sourceFile) . '::' . $test . ' has no golden test: add one, or list it as sending no request payload'
            );
        }
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();
        if ($this->goldenRecorded) {
            $this->markTestSkipped('recorded ' . implode(', ', $this->goldenRecorded));
        }
    }

    /**
     * @param string $variant
     * @return string e.g. create-receipt-success-stamps-reference
     */
    private function goldenFixtureName($variant)
    {
        $class = preg_replace('/GoldenTest$/', '', get_class($this));
        $test = preg_replace('/^test/', '', $this->getName(false));
        $name = $this->goldenKebab($class) . '-' . $this->goldenKebab($test);

        return $variant === '' ? $name : $name . '.' . $variant;
    }

    /**
     * @return string e.g. CreateReceiptTest::testSuccessStampsReference; for a scenario the source
     *                class does not have, the golden test itself and a note
     */
    private function goldenSource()
    {
        $source = preg_replace('/GoldenTest$/', 'Test', get_class($this));
        $test = $this->getName(false);
        $file = dirname((new ReflectionClass($this))->getFileName()) . '/' . $source . '.php';
        $declared = is_file($file) && preg_match('/public function ' . preg_quote($test, '/') . '\(/', file_get_contents($file));

        return $declared
            ? $source . '::' . $test
            : get_class($this) . '::' . $test . ' (not a scenario of ' . $source . ')';
    }

    /**
     * @param string $camel
     * @return string
     */
    private function goldenKebab($camel)
    {
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $camel));
    }

    /**
     * @param array    $calls  the transport's calls of the scenario
     * @param string[] $days   the dates today may have been during the scenario
     * @return array method, path and decoded body of every call that carried a body
     */
    private function goldenRequests(array $calls, array $days)
    {
        $requests = array();
        foreach ($calls as $call) {
            if ($call['body'] === '') {
                continue;
            }
            $body = json_decode($call['body'], true);
            $this->assertIsArray($body, $call['method'] . ' ' . $call['url'] . ' sent a body that is not a JSON object');
            $this->assertSame(
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $call['body'],
                $call['method'] . ' ' . $call['url'] . ': the body is not the compact JSON with unescaped unicode and slashes'
            );
            $requests[] = array(
                'method' => $call['method'],
                'path' => (string) parse_url($call['url'], PHP_URL_PATH),
                'body' => $this->goldenMaskToday($body, $days),
            );
        }

        return $requests;
    }

    /**
     * @param array    $writes the writes of the receipt in the scenario
     * @param string[] $days
     * @return array the decoded request_payload of every write
     */
    private function goldenStored(array $writes, array $days)
    {
        $stored = array();
        foreach ($writes as $write) {
            $raw = (string) $write['row']['request_payload'];
            $payload = json_decode($raw, true);
            if ($raw !== '') {
                $this->assertIsArray($payload, 'the stored request payload is not a JSON object');
                $this->assertSame(
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    $raw,
                    'the stored request payload is not the pretty JSON with unescaped unicode'
                );
            }
            $stored[] = $payload === null ? null : $this->goldenMaskToday($payload, $days);
        }

        return $stored;
    }

    /**
     * @param array    $node
     * @param string[] $days
     * @return array the node with the dates of issue and of sale that equal today read as {today}
     */
    private function goldenMaskToday(array $node, array $days)
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->goldenMaskToday($value, $days);
            } elseif (($key === 'DataWystawienia' || $key === 'DataSprzedazy') && in_array($value, $days, true)) {
                $node[$key] = '{today}';
            }
        }

        return $node;
    }
}
