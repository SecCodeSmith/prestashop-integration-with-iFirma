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

require_once _PS_MODULE_DIR_ . 'ifirma/controllers/front/documents.php';

/** The documents controller on a core that offers the "Your account" breadcrumb link. */
class IfirmaTestDocumentsController extends IfirmaDocumentsModuleFrontController
{
    protected function addMyAccountToBreadcrumb()
    {
        return array('title' => 'Your account', 'url' => 'https://shop.test/my-account');
    }
}

/**
 * Change fix-my-invoices-page-and-native-invoice-link, capability customer-receipt-visibility, scenario to test:
 *
 * "My invoices page access"
 * - Not logged in: testAVisitorWhoIsNotLoggedInIsSentToTheLoginAndSeesNoDocument
 * - Another customer's documents never listed: testOnlyTheLoggedInCustomersDocumentsAreRead,
 *   testAnIdCustomerInTheRequestIsIgnored
 * - Read-only page: testThePageChangesNothingAndCallsNoIfirmaApi
 * - Untrusted document data: testTheTemplateEscapesEveryValueItPrints
 *
 * "My invoices page"
 * - Documents listed with links, Document awaiting KSeF acceptance, Document without a download token: the rule
 *   itself is pinned in CustomerViewTest (link decision tests); here testTheControllerHandsTheAccountViewToTheTemplate
 *   shows the rows reach the template and testTheTemplateOffersALinkOnlyWhenTheRowHasOne the template side
 * - Empty page: testTheTemplateShowsAMessageWhenThereIsNothingToList
 *
 * "Account entry works without theme scripts": testTheTemplateUsesNoScriptToggle
 *
 * Breadcrumb: testTheBreadcrumbLeadsFromYourAccountToMyInvoices, testTheBreadcrumbSkipsYourAccountWhenCoreHasNone
 */
class DocumentsFrontTest extends TestCase
{
    use IfirmaWiringFixture;

    const OWN = 5;

    protected function setUp(): void
    {
        $this->wiringSetUp();
        Tools::reset();
        // the fixture's own scripted queries answer first; this page is tested against its own
        Db::reset();
        Db::getInstance()->whenExecuteS('/FROM `\w*ifirma_receipt`\s+WHERE id_customer = (\d+)/', function ($sql, $m) {
            return (int) $m[1] === self::OWN
                ? array(array('ifirma_id' => 9, 'ifirma_number' => '9/2026', 'status' => 'created', 'security_token' => 'tokown', 'issue_date' => '2026-11-01'))
                : array(array('ifirma_id' => 8, 'ifirma_number' => 'FOREIGN/1', 'status' => 'created', 'security_token' => 'tokforeign', 'issue_date' => '2026-11-01'));
        });
        Db::getInstance()->whenGetValue('/FROM `\w*ifirma_data_request`\s+WHERE id_customer = (\d+)/', function ($sql, $m) {
            return (int) $m[1] === self::OWN ? 2 : 7;
        });
    }

    protected function tearDown(): void
    {
        Tools::reset();
    }

    private function open($logged = true)
    {
        Context::getContext()->customer->logged = $logged;
        $controller = new IfirmaTestDocumentsController();
        $controller->initContent();

        return $controller;
    }

    private function account()
    {
        return Context::getContext()->smarty->vars['ifirma_account'];
    }

    private function template($name)
    {
        return file_get_contents(dirname(__DIR__, 2) . '/views/templates/' . $name);
    }

    public function testAVisitorWhoIsNotLoggedInIsSentToTheLoginAndSeesNoDocument()
    {
        try {
            $this->open(false);
            $this->fail('the visitor should have been redirected');
        } catch (IfirmaTestRedirect $redirect) {
            $this->assertStringContainsString('/authentication?back=', $redirect->getMessage());
            $this->assertStringContainsString('/module/ifirma/documents', urldecode($redirect->getMessage()));
        }
        $this->assertArrayNotHasKey('ifirma_account', Context::getContext()->smarty->vars);
    }

    public function testTheControllerHandsTheAccountViewToTheTemplate()
    {
        $controller = $this->open();

        $this->assertSame('module:ifirma/views/templates/front/documents.tpl', $controller->template);
        $this->assertSame('9/2026', $this->account()['receipts'][0]['number']);
        $this->assertSame(2, $this->account()['pending_count']);
        $this->assertSame('https://shop.test/module/ifirma/documents?', $this->account()['page_url']);
    }

    public function testOnlyTheLoggedInCustomersDocumentsAreRead()
    {
        $this->open();

        $numbers = array_column($this->account()['receipts'], 'number');
        $this->assertSame(array('9/2026'), $numbers);
    }

    public function testAnIdCustomerInTheRequestIsIgnored()
    {
        Tools::$values = array('id_customer' => '99', 'id' => '99');

        $this->open();

        $this->assertSame(array('9/2026'), array_column($this->account()['receipts'], 'number'));
        $this->assertSame(2, $this->account()['pending_count']);
    }

    public function testThePageChangesNothingAndCallsNoIfirmaApi()
    {
        $this->open();

        $this->assertSame(array(), Db::getInstance()->executed, 'no statement that writes');
        $this->assertSame(array(), Db::getInstance()->inserts);
        $this->assertSame(array(), Db::getInstance()->updates);
        $this->assertSame(array(), Db::getInstance()->deletes);
        $this->assertSame(array(), $this->t->calls, 'no request to iFirma');
    }

    public function testAFailedDocumentQueryShowsAnEmptyPageInsteadOfAnError()
    {
        Db::reset();
        Db::getInstance()->whenExecuteS('/ifirma_receipt/', false);

        $this->open();

        $this->assertSame(array(), $this->account()['receipts']);
    }

    public function testTheBreadcrumbLeadsFromYourAccountToMyInvoices()
    {
        $controller = new IfirmaTestDocumentsController();

        $breadcrumb = $controller->getBreadcrumbLinks();

        $this->assertSame(array('Home', 'Your account', 'My invoices'), array_column($breadcrumb['links'], 'title'));
        $this->assertSame('My invoices', $breadcrumb['title']);
    }

    public function testTheBreadcrumbSkipsYourAccountWhenCoreHasNone()
    {
        $controller = new IfirmaDocumentsModuleFrontController();

        $breadcrumb = $controller->getBreadcrumbLinks();

        $this->assertSame(array('Home', 'My invoices'), array_column($breadcrumb['links'], 'title'));
    }

    public function testTheTemplateUsesNoScriptToggle()
    {
        $tpl = $this->template('front/documents.tpl');

        $this->assertStringContainsString("extends file='customer/page.tpl'", $tpl);
        foreach (array('data-toggle', 'data-bs-toggle', 'collapse', '<script', 'target="_blank"') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $tpl);
        }
    }

    public function testTheTemplateEscapesEveryValueItPrints()
    {
        $tpl = $this->template('front/documents.tpl');
        preg_match_all('/\{\$([a-z_.\[\]\']+)(\|[^}]*)?\}/i', $tpl, $printed, PREG_SET_ORDER);

        $unescaped = array();
        foreach ($printed as $hit) {
            $isCount = isset($hit[2]) && strpos($hit[2], 'intval') !== false;
            $isEscaped = isset($hit[2]) && strpos($hit[2], "escape:'html':'UTF-8'") !== false;
            if (!$isCount && !$isEscaped) {
                $unescaped[] = $hit[0];
            }
        }

        $this->assertNotEmpty($printed);
        $this->assertSame(array(), $unescaped);
        $this->assertStringContainsString('{$receipt.number|escape:\'html\':\'UTF-8\'}', $tpl);
        $this->assertStringContainsString('{$receipt.status_label|escape:\'html\':\'UTF-8\'}', $tpl);
    }

    public function testTheTemplateGroupsTheStatusAndExternalBadgesInOneSpan()
    {
        $tpl = $this->template('front/documents.tpl');

        $this->assertMatchesRegularExpression(
            '/<span class="ifirma-document-badges">\s*<span class="ifirma-badge ifirma-badge-\{\$receipt\.status.*?ifirma-badge-external.*?<\/span>\s*\{\/if\}\s*<\/span>/s',
            $tpl
        );
    }

    public function testTheTemplateOffersALinkOnlyWhenTheRowHasOne()
    {
        $tpl = $this->template('front/documents.tpl');

        $this->assertMatchesRegularExpression('/\{if \$receipt\.download_url\}.*?<a href="\{\$receipt\.download_url\|escape/s', $tpl);
    }

    public function testTheTemplateShowsAMessageWhenThereIsNothingToList()
    {
        $tpl = $this->template('front/documents.tpl');

        $this->assertStringContainsString("{l s='You have no invoices yet.' mod='ifirma'}", $tpl);
        $this->assertStringContainsString("{l s='My invoices' mod='ifirma'}", $tpl);
    }
}
