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
 * add-document-pdf-store 3.3: saving the setting "Keep PDF copies (days)" as 0 deletes every stored copy in the same
 * request, and nothing else on the configuration page does. Driven through Ifirma::getContent() like SettingsSaveTest.
 */
class PdfCopyClearOnSaveTest extends TestCase
{
    use IfirmaWiringFixture;
    use IfirmaPdfCopyDb;

    const TOKEN = 'admin-token-AdminModules';
    const DELETE_FAILED = 'The settings were saved, but the stored PDF copies could not be deleted. Save the settings again.';
    const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->scriptPdfCopyDb();
        foreach (array(1, 2, 3) as $idReceipt) {
            $this->seedPdfCopy($idReceipt, '%PDF-' . $idReceipt, self::FINGERPRINT, '2026-11-10 00:00:00');
        }
        Configuration::updateValue('IFIRMA_PDF_COPY_DAYS', 30);
    }

    protected function tearDown(): void
    {
        IfirmaApiClient::setDefaultTransport(null);
        Module::$deniedPermissions = array();
    }

    private function post(array $changes = array(), $token = self::TOKEN)
    {
        $request = array(
            'submitIfirma' => '1',
            'IFIRMA_USER' => 'firma@example.test',
            'IFIRMA_PAYMENT_METHOD' => 'PRZ',
            'IFIRMA_PAYMENT_METHOD_MODE' => 'order',
            'IFIRMA_DUPLICATE_WINDOW_DAYS' => '30',
            'IFIRMA_DUPLICATE_AMOUNT_TOLERANCE' => '0',
            'IFIRMA_SYNC_INTERVAL_MIN' => '15',
            'IFIRMA_CRON_MAX_CALLS' => '10',
            'IFIRMA_CRON_MAX_ITEMS' => '10',
            'IFIRMA_RATE_MAX_PER_MIN' => '60',
            'IFIRMA_RATE_MAX_PER_DAY' => '12000',
            'IFIRMA_PDF_COPY_DAYS' => '30',
        );
        if ($token !== null) {
            $request['token'] = $token;
        }
        Tools::$values = $changes + $request;

        return $this->module->getContent();
    }

    public function testSavingZeroDeletesEveryStoredCopy()
    {
        $page = $this->post(array('IFIRMA_PDF_COPY_DAYS' => '0'));

        $this->assertStringStartsWith('<confirmation>Settings updated.</confirmation>', $page);
        $this->assertSame(0, $this->pdfCopyRowCount());
        $this->assertStringNotContainsString(self::DELETE_FAILED, $page);
    }

    public function testASavedZeroWhoseDeleteFailsSaysSoAndKeepsTheSetting()
    {
        $this->refusePdfCopyDeletes();

        $page = $this->post(array('IFIRMA_PDF_COPY_DAYS' => '0'));

        $this->assertStringStartsWith('<confirmation>Settings updated.</confirmation>', $page);
        $this->assertStringContainsString('<error>' . self::DELETE_FAILED . '</error>', $page);
        $this->assertSame(0, Configuration::get('IFIRMA_PDF_COPY_DAYS'));
        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testSavingAPositiveNumberKeepsTheStoredCopies()
    {
        $page = $this->post(array('IFIRMA_PDF_COPY_DAYS' => '30'));

        $this->assertStringStartsWith('<confirmation>Settings updated.</confirmation>', $page);
        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testASaveRefusedByValidationDeletesNothing()
    {
        $page = $this->post(array('IFIRMA_PDF_COPY_DAYS' => 'abc'));

        $this->assertStringStartsWith('<error>', $page);
        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testAnotherRefusedFieldDeletesNothingEvenWhenTheDaysAreZero()
    {
        $page = $this->post(array('IFIRMA_PDF_COPY_DAYS' => '0', 'IFIRMA_DUPLICATE_WINDOW_DAYS' => 'ten'));

        $this->assertStringStartsWith('<error>', $page);
        $this->assertSame(3, $this->pdfCopyRowCount());
        $this->assertSame(30, Configuration::get('IFIRMA_PDF_COPY_DAYS'));
    }

    public function testAPostWithoutATokenDeletesNothing()
    {
        $this->post(array('IFIRMA_PDF_COPY_DAYS' => '0'), null);

        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testAPostWithAWrongTokenDeletesNothing()
    {
        $this->post(array('IFIRMA_PDF_COPY_DAYS' => '0'), 'forged');

        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testAnEmployeeWithoutTheConfigurePermissionDeletesNothing()
    {
        Module::$deniedPermissions = array('configure');

        $this->post(array('IFIRMA_PDF_COPY_DAYS' => '0'));

        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testOtherActionsOfThePageDeleteNothing()
    {
        Configuration::updateValue('IFIRMA_PDF_COPY_DAYS', 0);

        $this->post(array('submitIfirma' => null, 'submitIfirmaRegenerateCron' => '1'));
        $this->post(array('submitIfirma' => null));

        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    /** @return mixed the value the settings form shows for the days field */
    private function formDays()
    {
        Tools::$values = array();
        $this->module->getContent();

        return HelperForm::$lastHelper->fields_value['IFIRMA_PDF_COPY_DAYS'];
    }

    public function testTheFormShowsTheDefaultWhenTheSettingIsMissing()
    {
        Configuration::deleteByName('IFIRMA_PDF_COPY_DAYS');

        $this->assertSame(30, $this->formDays());
    }

    /**
     * @return array[] name => array(stored, shown)
     */
    public function storedDays()
    {
        return array('storing off' => array('0', 0), 'ninety days' => array('90', 90));
    }

    /**
     * @dataProvider storedDays
     */
    public function testTheFormShowsTheStoredDays($stored, $shown)
    {
        Configuration::updateValue('IFIRMA_PDF_COPY_DAYS', $stored);

        $this->assertSame($shown, $this->formDays());
    }

    public function testSavingTheFormAsItWasShownForAMissingSettingKeepsStoringAndTheCopies()
    {
        Configuration::deleteByName('IFIRMA_PDF_COPY_DAYS');
        $shown = $this->formDays();

        $page = $this->post(array('IFIRMA_PDF_COPY_DAYS' => (string) $shown));

        $this->assertStringStartsWith('<confirmation>Settings updated.</confirmation>', $page);
        $this->assertSame(30, Configuration::get('IFIRMA_PDF_COPY_DAYS'));
        $this->assertSame(3, $this->pdfCopyRowCount());
    }

    public function testTheModuleClassHoldsNoSqlOfThePdfCopyTable()
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/ifirma.php');

        $this->assertStringNotContainsString('ifirma_pdf_copy', $source);
    }
}
