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
 * Every file of tests/fixtures/payloads must belong to a golden test.
 *
 * IfirmaGoldenPayloads::goldenFixtureName() names a fixture after its golden test:
 * the class without its GoldenTest suffix and the method without its test prefix, both
 * in kebab case, then an optional .variant. This test reads that rule in reverse: every
 * split of the stem into a class part and a method part is a candidate, and a fixture
 * matches when tests/Unit holds that golden class with that public method. A fixture
 * with no match is an orphan (its test was renamed or deleted) and fails the run.
 *
 * The mapping is worked out from the file names and reflection, not from a registry that
 * the golden tests fill while they run, so the result does not depend on test order or on
 * which tests a --filter selected.
 */
class GoldenPayloadFixturesTest extends TestCase
{
    public function testEveryPayloadFixtureBelongsToAGoldenTest()
    {
        $files = glob(dirname(__DIR__) . '/fixtures/payloads/*.json');
        $this->assertNotEmpty($files, 'no payload fixtures found in tests/fixtures/payloads');

        $orphans = [];
        $mapped = 0;
        foreach ($files as $file) {
            if ($this->hasGoldenTest(basename($file, '.json'))) {
                ++$mapped;
            } else {
                $orphans[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $orphans,
            'fixtures without a golden test: ' . implode(', ', $orphans) . ' (rename or delete them)'
        );
        $this->assertSame(
            count($files),
            $mapped,
            'fixtures mapped to a golden test: ' . $mapped . ' of ' . count($files)
        );
    }

    /**
     * @param string $stem file name without .json, e.g. create-receipt-success-stamps-reference.company
     * @return bool whether some split of the stem names a public method of an existing golden test class
     */
    private function hasGoldenTest($stem)
    {
        $dot = strpos($stem, '.');
        $name = $dot === false ? $stem : substr($stem, 0, $dot);
        $parts = explode('-', $name);

        for ($i = 1; $i < count($parts); ++$i) {
            $class = $this->pascal(array_slice($parts, 0, $i)) . 'GoldenTest';
            $method = 'test' . $this->pascal(array_slice($parts, $i));
            $file = __DIR__ . '/' . $class . '.php';
            if (!is_file($file)) {
                continue;
            }
            require_once $file;
            if (!class_exists($class, false)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->hasMethod($method) && $reflection->getMethod($method)->isPublic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $parts kebab-case words
     * @return string PascalCase
     */
    private function pascal(array $parts)
    {
        return implode('', array_map('ucfirst', $parts));
    }
}
