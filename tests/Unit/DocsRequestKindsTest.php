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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaRequestKind.php';

/**
 * docs/configuration.md says what each kind of request covers, so an owner reading the "Module requests" box finds every
 * kind explained (add-api-usage-delivery-retry-status-filter-and-ci-fix 3.10).
 */
class DocsRequestKindsTest extends TestCase
{
    public function kinds()
    {
        $cases = array();
        foreach (IfirmaRequestKind::all() as $kind) {
            $cases[$kind] = array($kind);
        }

        return $cases;
    }

    /**
     * @dataProvider kinds
     */
    public function testEveryKindOfRequestIsNamedInTheConfigurationDocs($kind)
    {
        $docs = file_get_contents(dirname(__DIR__, 2) . '/docs/configuration.md');

        $this->assertStringContainsString('| `' . $kind . '` (', $docs);
    }
}
