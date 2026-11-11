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
 * Given the module's configuration page, when a request reaches Ifirma::getContent(), then the module itself requires
 * the Configure permission and, for every action that changes state, a POST carrying the employee's back-office token
 * (spec receipt-access-control "Configuration page enforces the Configure permission itself" and "Configuration page
 * actions need POST and a valid token"). PrestaShop 9.1 lets an employee with the Module Manager's view right reach
 * getContent() without the module's Configure permission, so the page cannot rely on the core.
 */
class ConfigPageAuthTest extends TestCase
{
    use IfirmaWiringFixture;

    const TOKEN = 'admin-token-AdminModules';
    const CRON_TOKEN = 'cron-token-1';
    const REFUSAL = 'You do not have permission to configure this module.';

    protected function setUp(): void
    {
        $this->wiringSetUp();
        IfirmaContractorMap::link(5, 'KOWALSKI-01', 'import-nip', 'Jan Kowalski');
        $this->configurationBefore = $this->settingValues();
    }

    protected function tearDown(): void
    {
        Ifirma::$uploadValidator = null;
        unset($_FILES['IFIRMA_CONTRACTOR_CSV']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    /** @var array the stored settings before the request */
    private $configurationBefore;

    /** @return array the stored value of every setting a posted form can change, and the cron token */
    private function settingValues()
    {
        $values = array();
        foreach (array_keys(IfirmaSettings::fields()) as $key) {
            $values[$key] = Configuration::get($key);
        }
        $values['IFIRMA_CRON_TOKEN'] = Configuration::get('IFIRMA_CRON_TOKEN');

        return $values;
    }

    private function validSettingsPost()
    {
        return array(
            'submitIfirma' => '1',
            'IFIRMA_BASE_URL' => 'http://ifirma-mock:8001/iapi/',
            'IFIRMA_USER' => 'attacker@example.test',
            'IFIRMA_KEY_FAKTURA' => str_repeat('a', 64),
            'IFIRMA_KEY_ABONENT' => '',
            'IFIRMA_KEY_RACHUNEK' => '',
            'IFIRMA_DOC_TYPE' => 'fakturakraj',
            'IFIRMA_PAYMENT_METHOD' => 'PRZ',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'order',
            'IFIRMA_PAYMENT_METHOD_MAP' => '',
            'IFIRMA_PAYMENT_TYPE_TOKEN' => 'prz_faktura_kraj',
            'IFIRMA_DEFAULT_UNIT' => 'szt.',
            'IFIRMA_AUTO_CREATE_STATE' => '5',
            'IFIRMA_MARK_PAID_ON_CREATE' => '1',
            'IFIRMA_SEND_CHANNEL' => 'none',
            'IFIRMA_SEND_TEXT' => 'Thank you',
            'IFIRMA_SEND_COPY_MODE' => 'shop',
            'IFIRMA_SEND_COPY_EMPLOYEE' => '0',
            'IFIRMA_DUPLICATE_CHECK' => '0',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '30',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0,05',
            'IFIRMA_ALLOW_DATA_REQUEST' => '0',
            'IFIRMA_NOTIFY_EMAIL' => 'office@example.test',
            'IFIRMA_SYNC_INTERVAL_MIN' => '30',
            'IFIRMA_CRON_MAX_CALLS' => '20',
            'IFIRMA_CRON_MAX_ITEMS' => '15',
            'IFIRMA_RATE_MAX_PER_MIN' => '60',
            'IFIRMA_RATE_MAX_PER_DAY' => '12000',
        );
    }

    /**
     * @return array[] name => [the request values that name the action]
     */
    public static function stateChangingActions()
    {
        return array(
            'settings save' => array(array('submitIfirma' => '1')),
            'cron token regeneration' => array(array('submitIfirmaRegenerateCron' => '1')),
            'contractor import' => array(array('submitIfirmaImportContractors' => '1')),
            'contractor map reset' => array(array('submitIfirmaResetContractorMap' => '1', 'confirm' => '1')),
        );
    }

    /** @param array $request the values of the request, a settings post filled in for the settings action */
    private function send($method, array $request, $token = self::TOKEN)
    {
        if (isset($request['submitIfirma'])) {
            $request = $this->validSettingsPost() + $request;
        }
        if ($token !== null) {
            $request['token'] = $token;
        }
        $_SERVER['REQUEST_METHOD'] = $method;
        Tools::$values = $request;
        $this->csvUpload();

        return $this->module->getContent();
    }

    private function csvUpload()
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ifirma-csv');
        file_put_contents($tmp, "Identyfikator;Nazwa;NIP\nNEW-01;Nowy;5252248481\n");
        $_FILES['IFIRMA_CONTRACTOR_CSV'] = array('name' => 'k.csv', 'tmp_name' => $tmp, 'error' => 0, 'size' => 50);
        Ifirma::$uploadValidator = function () {
            return true;
        };
    }

    private function assertNothingChanged($message)
    {
        $this->assertSame($this->configurationBefore, $this->settingValues(), $message . ': a setting or the cron token changed');
        $this->assertCount(1, IfirmaTestState::objects('ifirma_contractor_map'), $message . ': the contractor map changed');
        $this->assertNotContains('TRUNCATE TABLE `ps_ifirma_contractor_map`', Db::getInstance()->executed, $message . ': the map was reset');
        $this->assertSame(array(), $this->t->calls);
        $smartyVars = Context::getContext()->smarty->vars;
        $this->assertNull(isset($smartyVars['ifirma_cm_report']) ? $smartyVars['ifirma_cm_report'] : null, $message . ': a contractor import ran');
    }

    // ---- without the Configure permission ------------------------------------------------------------------------

    public function testAnEmployeeWithoutTheConfigurePermissionSeesOnlyAGenericRefusal()
    {
        Module::$deniedPermissions = array('configure');

        $page = $this->send('GET', array());

        $this->assertStringContainsString(self::REFUSAL, $page);
        $this->assertNull(HelperForm::$lastForm, 'the settings form was built');
        $this->assertSame(array(), Module::$displayed, 'a panel was rendered');
    }

    public function testAnEmployeeWithoutTheConfigurePermissionDoesNotSeeTheCronTokenOrAddress()
    {
        Module::$deniedPermissions = array('configure');

        $page = $this->send('GET', array());

        $this->assertStringNotContainsString(self::CRON_TOKEN, $page);
        $this->assertStringNotContainsString('controller=cron', $page);
        $this->assertNull(HelperForm::$lastForm, 'the form with the cron token and address was built');
    }

    /**
     * @dataProvider stateChangingActions
     */
    public function testAnEmployeeWithoutTheConfigurePermissionCannotRunAnAction(array $action)
    {
        Module::$deniedPermissions = array('configure');

        $page = $this->send('POST', $action);

        $this->assertNothingChanged('denied employee, valid token');
        $this->assertStringContainsString(self::REFUSAL, $page);
    }

    public function testTheRefusalIsOnlyTheGenericErrorWithNothingElse()
    {
        Module::$deniedPermissions = array('configure');

        $page = $this->send('POST', array('submitIfirmaRegenerateCron' => '1'));

        $this->assertSame('<error>' . self::REFUSAL . '</error>', $page);
    }

    // ---- with the permission: method and token --------------------------------------------------------------------

    /**
     * @dataProvider stateChangingActions
     */
    public function testAGetRequestNamingAnActionChangesNothing(array $action)
    {
        $this->send('GET', $action);

        $this->assertNothingChanged('GET');
    }

    /**
     * @dataProvider stateChangingActions
     */
    public function testAPostWithoutATokenChangesNothingAndShowsAGenericError(array $action)
    {
        $page = $this->send('POST', $action, null);

        $this->assertNothingChanged('no token');
        $this->assertStringContainsString('<error>', $page);
    }

    /**
     * @dataProvider stateChangingActions
     */
    public function testAPostWithAWrongTokenChangesNothingAndShowsAGenericError(array $action)
    {
        $page = $this->send('POST', $action, 'forged');

        $this->assertNothingChanged('wrong token');
        $this->assertStringContainsString('<error>', $page);
    }

    public function testTheErrorForABadTokenSaysNothingAboutWhichCheckFailed()
    {
        $page = $this->send('POST', array('submitIfirmaRegenerateCron' => '1'), 'forged');

        $this->assertStringContainsString('<error>The request could not be verified. Reload the page and try again.</error>', $page);
        $this->assertStringNotContainsString('forged', $page);
        $this->assertStringNotContainsString(self::TOKEN, $page);
    }

    public function testAnOverrideOfTheMethodByARequestValueDoesNotMakeAGetAPost()
    {
        $this->send('GET', array('submitIfirmaRegenerateCron' => '1', '_method' => 'POST'));

        $this->assertNothingChanged('GET with _method');
    }

    // ---- PrestaShop 9: the core's own check of its randomised token ------------------------------------------------

    private function coreTokenManager($valid)
    {
        $manager = new \PrestaShopBundle\Security\Admin\UserTokenManager();
        $manager->valid = $valid;
        $container = new \PrestaShop\PrestaShop\Adapter\SymfonyContainer();
        $container->services[\PrestaShopBundle\Security\Admin\UserTokenManager::class] = $manager;
        \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$instance = $container;
    }

    public function testOnPrestaShop9ARequestTheCoreAcceptsRunsTheActionWhateverFormTheTokenHas()
    {
        $this->coreTokenManager(true);

        $this->send('POST', array('submitIfirmaRegenerateCron' => '1'), 'Ab1.Cd2.Ef3');

        $this->assertNotSame(self::CRON_TOKEN, Configuration::get('IFIRMA_CRON_TOKEN'));
    }

    public function testOnPrestaShop9ARequestTheCoreRefusesRunsNothingEvenWithTheFixedToken()
    {
        $this->coreTokenManager(false);

        $page = $this->send('POST', array('submitIfirmaRegenerateCron' => '1'), self::TOKEN);

        $this->assertNothingChanged('core refuses the token');
        $this->assertStringContainsString('<error>', $page);
    }

    public function testOnPrestaShop9ATokenTheCoreCannotReadIsRefusedAndNeverEndsInAnError()
    {
        $this->coreTokenManager(true);
        $services = \PrestaShop\PrestaShop\Adapter\SymfonyContainer::$instance->services;
        $services[\PrestaShopBundle\Security\Admin\UserTokenManager::class]->throws = new TypeError('token must be a string');

        $page = $this->send('POST', array('submitIfirmaRegenerateCron' => '1'), 'x');

        $this->assertNothingChanged('core throws on the token');
        $this->assertStringContainsString('<error>', $page);
    }

    public function testOnPrestaShop9ARequestWithoutATokenRunsNothingEvenWhenTheCoreIsAskedToAccept()
    {
        $this->coreTokenManager(true);

        $this->send('POST', array('submitIfirmaResetContractorMap' => '1', 'confirm' => '1'), null);

        $this->assertNothingChanged('no token');
    }

    // ---- an employee who may configure ---------------------------------------------------------------------------

    public function testAnEmployeeWhoMayConfigureStillSavesTheSettingsWithAPostedToken()
    {
        $page = $this->send('POST', array('submitIfirma' => '1'));

        $this->assertStringContainsString('<confirmation>', $page);
        $this->assertSame('attacker@example.test', Configuration::get('IFIRMA_USER'));
    }

    public function testAnEmployeeWhoMayConfigureStillOpensThePageWithTheFormAndTheCronToken()
    {
        $page = $this->send('GET', array());

        $this->assertNotNull(HelperForm::$lastForm);
        $this->assertStringNotContainsString(self::REFUSAL, $page);
    }

    public function testAnEmployeeWhoMayConfigureStillRegeneratesTheCronTokenWithAPostedToken()
    {
        $this->send('POST', array('submitIfirmaRegenerateCron' => '1'));

        $this->assertNotSame(self::CRON_TOKEN, Configuration::get('IFIRMA_CRON_TOKEN'));
    }

    public function testAnEmployeeWhoMayConfigureStillResetsTheContractorMapWithAPostedToken()
    {
        $this->send('POST', array('submitIfirmaResetContractorMap' => '1', 'confirm' => '1'));

        $this->assertContains('TRUNCATE TABLE `ps_ifirma_contractor_map`', Db::getInstance()->executed);
    }
}
