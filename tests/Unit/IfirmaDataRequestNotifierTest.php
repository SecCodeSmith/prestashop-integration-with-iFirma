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
 * IfirmaDataRequestNotifier: whom the data-change mails go to, in which language and with which
 * template, and what it answers when Mail does not send them. The subject and the variables belong
 * to the caller (IfirmaDataRequestService) and reach Mail::Send() as they are.
 */
class IfirmaDataRequestNotifierTest extends TestCase
{
    use IfirmaTestMailTrait;

    /** @var IfirmaDataRequestNotifier */
    private $notifier;

    /** @var Customer */
    private $customer;

    /** @var Order */
    private $order;

    /** @var string|null folder of the templates made by notifierWithTemplates(), removed afterwards */
    private $templateRoot;

    protected function setUp(): void
    {
        IfirmaTestState::resetAll();
        Configuration::$values['IFIRMA_SHOP_URL'] = 'https://www.Hialumed.pl/';

        $this->notifier = new IfirmaDataRequestNotifier();

        $this->customer = new Customer();
        $this->customer->id = 7;
        $this->customer->email = 'buyer@example.test';
        $this->customer->firstname = 'Anna';
        $this->customer->lastname = 'Nowak';

        $this->order = new Order();
        $this->order->id = 15;
        $this->order->id_customer = 7;
        $this->order->id_lang = 2;
        $this->order->id_shop = 1;
        $this->order->reference = 'ABCDEFGHI';
    }

    protected function tearDown(): void
    {
        if ($this->templateRoot !== null) {
            foreach (glob($this->templateRoot . '*/*') as $file) {
                unlink($file);
            }
            foreach (glob($this->templateRoot . '*', GLOB_ONLYDIR) as $dir) {
                rmdir($dir);
            }
            rmdir($this->templateRoot);
            $this->templateRoot = null;
        }
    }

    /**
     * A notifier that reads its templates from a folder made for the test.
     *
     * @param string[] $templates "<iso>/<template>" of the .html files to create
     * @return IfirmaDataRequestNotifier
     */
    private function notifierWithTemplates(array $templates)
    {
        $this->templateRoot = sys_get_temp_dir() . '/ifirma-notifier-' . uniqid('', true) . '/';
        foreach ($templates as $template) {
            $file = $this->templateRoot . $template . '.html';
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, '<p>template</p>');
        }

        return new class($this->templateRoot) extends IfirmaDataRequestNotifier {
            /** @var string */
            private $root;

            public function __construct($root)
            {
                $this->root = $root;
            }

            protected function templateDir()
            {
                return $this->root;
            }
        };
    }

    /** @return array variables as the service hands them over: already escaped, so left alone here */
    private function vars()
    {
        return array(
            '{firstname}' => 'Anna &amp; Co',
            '{order_reference}' => 'ABCDEFGHI',
            '{order_id}' => 15,
            '{note}' => "line 1\nline 2 -&gt; <kept>",
        );
    }

    /** @return array the variables as the notifier hands them to Mail::Send(): plus the shop link of the setting */
    private function varsWithTheLink()
    {
        return $this->vars() + array('{ifirma_shop_link}' => 'https://www.Hialumed.pl/');
    }

    /** @return array[] id_shop of the order => shop id handed to the mailer */
    public function shopIds()
    {
        return array(
            'shop 3' => array(3, 3),
            'shop 1' => array(1, 1),
            'no shop' => array(0, null),
            'shop not set' => array(null, null),
        );
    }

    // ---- the address of the merchant -------------------------------------------------------------

    /** @return array[] configured address, shop address => address that is told */
    public function merchantAddresses()
    {
        return array(
            'configured address' => array('merchant@example.test', 'shop@example.test', 'merchant@example.test'),
            'configured address is trimmed' => array("  merchant@example.test\t", 'shop@example.test', 'merchant@example.test'),
            'blank configured address falls back to the shop address' => array('   ', 'shop@example.test', 'shop@example.test'),
            'no configured address' => array(null, 'shop@example.test', 'shop@example.test'),
            'shop address is trimmed' => array('', ' shop@example.test ', 'shop@example.test'),
            'blank shop address' => array('', ' ', ''),
            'no shop address' => array(null, null, ''),
        );
    }

    /**
     * @dataProvider merchantAddresses
     */
    public function testMerchantAddressIsTheNotifyAddressOrElseTheShopAddress($configured, $shop, $expected)
    {
        if ($configured === null) {
            Configuration::deleteByName('IFIRMA_NOTIFY_EMAIL');
        } else {
            Configuration::updateValue('IFIRMA_NOTIFY_EMAIL', $configured);
        }
        Configuration::updateValue('PS_SHOP_EMAIL', $shop);

        $this->assertSame($expected, $this->notifier->merchantAddress());
    }

    // ---- the mail to the merchant ------------------------------------------------------------------

    public function testMerchantMailIsSentWithEverythingTheTemplateNeeds()
    {
        $sent = $this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars());

        $this->assertTrue($sent);
        $this->assertCount(1, Mail::$sent);
        $this->assertMailSent(array(
            'idLang' => 1,
            'template' => 'data_request_received',
            'subject' => 'The subject',
            'templateVars' => $this->varsWithTheLink(),
            'to' => 'merchant@example.test',
            'toName' => null,
            'idShop' => 1,
        ));
    }

    public function testEveryMailCarriesTheConfiguredShopLinkForTheTemplates()
    {
        $this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars());
        $this->notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars());
        $this->notifier->sendToCustomer('rejected', $this->customer, $this->order, 'The subject', $this->vars());

        $this->assertCount(3, Mail::$sent);
        foreach (Mail::$sent as $mail) {
            $this->assertSame('https://www.Hialumed.pl/', $mail['templateVars']['{ifirma_shop_link}']);
        }
    }

    public function testTheShopLinkFallsBackToTheShopAddressWhenNoneIsConfigured()
    {
        Configuration::$values['IFIRMA_SHOP_URL'] = '';
        IfirmaShopLink::useFallback(function () {
            return 'https://shop.example.test/';
        });

        try {
            $this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars());
            $this->notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars());
        } finally {
            IfirmaShopLink::useFallback(null);
        }

        $this->assertSame('https://shop.example.test/', Mail::$sent[0]['templateVars']['{ifirma_shop_link}']);
        $this->assertSame('https://shop.example.test/', Mail::$sent[1]['templateVars']['{ifirma_shop_link}']);
    }

    public function testMerchantMailGoesOutInTheLanguageOfTheContext()
    {
        Context::getContext()->language->id = 9;

        $this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars());

        // not the language of the order, which is 2
        $this->assertSame(9, Mail::$sent[0]['idLang']);
    }

    /**
     * @dataProvider shopIds
     */
    public function testMerchantMailIsSentForTheShopOfTheOrder($idShop, $expected)
    {
        $this->order->id_shop = $idShop;

        $this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars());

        $this->assertSame($expected, Mail::$sent[0]['idShop']);
    }

    public function testMerchantMailIsSentFromTheTemplateFolderOfTheNotifier()
    {
        $notifier = $this->notifierWithTemplates(array('en/data_request_received'));

        $notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars());

        $this->assertSame($this->templateRoot, Mail::$sent[0]['templatePath']);
    }

    public function testMerchantMailReportsWhetherTheMailWentOut()
    {
        Mail::$result = false;
        $this->assertFalse($this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars()));
        $this->assertCount(1, Mail::$sent);

        // core may answer with something that is not a boolean
        Mail::$result = 0;
        $this->assertFalse($this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars()));
        Mail::$result = 1;
        $this->assertTrue($this->notifier->sendToMerchant('merchant@example.test', $this->order, 'The subject', $this->vars()));
        $this->assertCount(3, Mail::$sent);
    }

    // ---- the mail to the customer --------------------------------------------------------------------

    public function testApprovalMailIsSentWithEverythingTheTemplateNeeds()
    {
        $sent = $this->notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars());

        $this->assertTrue($sent);
        $this->assertCount(1, Mail::$sent);
        $this->assertMailSent(array(
            'idLang' => 2,
            'template' => 'data_request_approved',
            'subject' => 'The subject',
            'templateVars' => $this->varsWithTheLink(),
            'to' => 'buyer@example.test',
            'toName' => 'Anna Nowak',
            'idShop' => 1,
        ));
    }

    public function testRejectionMailIsSentWithEverythingTheTemplateNeeds()
    {
        $sent = $this->notifier->sendToCustomer('rejected', $this->customer, $this->order, 'The subject', $this->vars());

        $this->assertTrue($sent);
        $this->assertCount(1, Mail::$sent);
        $this->assertMailSent(array(
            'idLang' => 2,
            'template' => 'data_request_rejected',
            'subject' => 'The subject',
            'templateVars' => $this->varsWithTheLink(),
            'to' => 'buyer@example.test',
            'toName' => 'Anna Nowak',
            'idShop' => 1,
        ));
    }

    /** @return array[] first name, last name => name the mail is addressed to */
    public function customerNames()
    {
        return array(
            'full name' => array('Anna', 'Nowak', 'Anna Nowak'),
            'no last name' => array('Anna', '', 'Anna'),
            'no first name' => array('', 'Nowak', 'Nowak'),
            'no name' => array('', '', ''),
            'the name is not escaped, it is no part of the body' => array('<i>Ann</i>', "O'Neil", "<i>Ann</i> O'Neil"),
        );
    }

    /**
     * @dataProvider customerNames
     */
    public function testCustomerMailIsAddressedToTheTrimmedFullName($first, $last, $expected)
    {
        $this->customer->firstname = $first;
        $this->customer->lastname = $last;

        $this->notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars());

        $this->assertSame($expected, Mail::$sent[0]['toName']);
        $this->assertSame('buyer@example.test', Mail::$sent[0]['to']);
    }

    /**
     * @return array[] label => [languages installed, id_lang of the order, language the mail goes out in]
     */
    public function customerMailLanguages()
    {
        $en = array('id_lang' => 1, 'iso_code' => 'en', 'name' => 'English');
        $pl = array('id_lang' => 2, 'iso_code' => 'pl', 'name' => 'Polski');
        $de = array('id_lang' => 3, 'iso_code' => 'de', 'name' => 'Deutsch');

        return array(
            'language of the order, which has templates' => array(array($en, $pl), 2, 2),
            'english order' => array(array($en, $pl), 1, 1),
            'language of the order has no template folder' => array(array($en, $pl, $de), 3, 1),
            'language of the order is not installed' => array(array($en, $pl), 5, 1),
            'order without a language' => array(array($en, $pl), 0, 1),
            'english is installed under another id' => array(array($pl, array('id_lang' => 7, 'iso_code' => 'en', 'name' => 'English')), 5, 7),
            // every installed language is searched, not just the active ones
            'english is installed but switched off' => array(array($pl, array('id_lang' => 8, 'iso_code' => 'en', 'name' => 'English', 'active' => 0)), 5, 8),
            'the first english wins' => array(array($pl, array('id_lang' => 4, 'iso_code' => 'en', 'name' => 'English'), array('id_lang' => 5, 'iso_code' => 'en', 'name' => 'English (UK)')), 6, 4),
            'no english: the language of the context' => array(array($pl, $de), 5, 9),
        );
    }

    /**
     * @dataProvider customerMailLanguages
     */
    public function testCustomerMailFallsBackToEnglishThenToTheContextLanguage(array $languages, $orderLang, $expected)
    {
        Language::$languages = $languages;
        Context::getContext()->language->id = 9;
        $this->order->id_lang = $orderLang;

        foreach (array('approved', 'rejected') as $n => $decision) {
            $this->assertTrue($this->notifier->sendToCustomer($decision, $this->customer, $this->order, 'The subject', $this->vars()));

            $this->assertSame($expected, Mail::$sent[$n]['idLang'], $decision);
        }
    }

    public function testACustomerMailGoesOutInTheLanguageOfTheOrderOnlyWhenThatLanguageHasThatTemplate()
    {
        // 'xx' has the approval template but not the rejection one; English has both
        $notifier = $this->notifierWithTemplates(array('xx/data_request_approved', 'en/data_request_approved', 'en/data_request_rejected'));
        Language::$languages[] = array('id_lang' => 3, 'iso_code' => 'xx', 'name' => 'Xx');
        $this->order->id_lang = 3;

        $notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars());
        $notifier->sendToCustomer('rejected', $this->customer, $this->order, 'The subject', $this->vars());

        $this->assertSame(3, Mail::$sent[0]['idLang']);
        $this->assertSame(1, Mail::$sent[1]['idLang']);
        // the templates are looked up, and handed to Mail, in the folder the notifier works with
        $this->assertSame($this->templateRoot, Mail::$sent[0]['templatePath']);
        $this->assertSame($this->templateRoot, Mail::$sent[1]['templatePath']);
    }

    /**
     * @dataProvider shopIds
     */
    public function testCustomerMailIsSentForTheShopOfTheOrder($idShop, $expected)
    {
        $this->order->id_shop = $idShop;

        $this->notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars());

        $this->assertSame($expected, Mail::$sent[0]['idShop']);
    }

    public function testCustomerMailReportsWhetherTheMailWentOut()
    {
        Mail::$result = false;
        $this->assertFalse($this->notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars()));
        $this->assertCount(1, Mail::$sent);

        // core may answer with something that is not a boolean
        Mail::$result = 0;
        $this->assertFalse($this->notifier->sendToCustomer('rejected', $this->customer, $this->order, 'The subject', $this->vars()));
        Mail::$result = 1;
        $this->assertTrue($this->notifier->sendToCustomer('approved', $this->customer, $this->order, 'The subject', $this->vars()));
        $this->assertCount(3, Mail::$sent);
    }

    // ---- the code mail of the guest page ------------------------------------------------------------

    public function testTheCodeMailIsSentWithTheCodeTemplateAndTheShopLink()
    {
        $vars = array('{firstname}' => 'Anna', '{order_reference}' => 'ABCDEFGHI', '{code}' => '012345', '{minutes}' => '15');

        $sent = $this->notifier->sendCode($this->customer, $this->order, 'The subject', $vars);

        $this->assertTrue($sent);
        $this->assertCount(1, Mail::$sent);
        $this->assertMailSent(array(
            'idLang' => 2,
            'template' => 'data_request_code',
            'subject' => 'The subject',
            'templateVars' => $vars + array('{ifirma_shop_link}' => 'https://www.Hialumed.pl/'),
            'to' => 'buyer@example.test',
            'toName' => 'Anna Nowak',
            'idShop' => 1,
        ));
    }

    /**
     * @dataProvider customerMailLanguages
     */
    public function testTheCodeMailFallsBackToEnglishThenToTheContextLanguage(array $languages, $orderLang, $expected)
    {
        Language::$languages = $languages;
        Context::getContext()->language->id = 9;
        $this->order->id_lang = $orderLang;

        $this->assertTrue($this->notifier->sendCode($this->customer, $this->order, 'The subject', array()));

        $this->assertSame($expected, Mail::$sent[0]['idLang']);
    }

    public function testTheCodeMailReportsWhetherTheMailWentOut()
    {
        Mail::$result = false;

        $this->assertFalse($this->notifier->sendCode($this->customer, $this->order, 'The subject', array()));
    }

    public function testTheCodeTemplateCarriesNoLinkWithTheCode()
    {
        foreach (array('en', 'pl') as $iso) {
            foreach (array('html', 'txt') as $ext) {
                $body = file_get_contents($this->mailDir() . $iso . '/data_request_code.' . $ext);
                $this->assertStringContainsString('{code}', $body);
                $this->assertStringContainsString('{minutes}', $body);
                $this->assertStringContainsString('{order_reference}', $body);
                // the only links are the shop link: the code is never part of an address
                $this->assertDoesNotMatchRegularExpression('/(?:https?:\/\/|href=")[^\s"]*\{code\}/', $body);
                $this->assertDoesNotMatchRegularExpression('/\{ifirma_shop_link\}[^\s"]*\{code\}/', $body);
            }
        }
    }

    // ---- the templates ---------------------------------------------------------------------------

    public function testEveryMailTemplateIsShippedInEnglishAndPolish()
    {
        // English is the fallback of the customer mails, so it has to exist for every template
        foreach (array('en', 'pl') as $iso) {
            foreach (array('data_request_received', 'data_request_approved', 'data_request_rejected') as $template) {
                $this->assertFileExists($this->mailDir() . $iso . '/' . $template . '.html');
                $this->assertFileExists($this->mailDir() . $iso . '/' . $template . '.txt');
            }
        }
    }
}
