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

require_once _PS_MODULE_DIR_ . 'ifirma/classes/IfirmaHttpOutput.php';

/** Thrown by the recording header sink to stop a controller before it echoes the document and exits. */
class IfirmaTestHeadersSent extends RuntimeException
{
}

/**
 * IfirmaHttpOutput::pdf() and the two controllers that stream a PDF with it: the front receipt
 * controller and AdminIfirmaController::ajaxProcessDownloadPdf().
 *
 * Under PHPUnit the native header() fails ("headers already sent") and exit would end the run, so
 * pdf() sends its header lines through IfirmaHttpOutput::useHeaderSink(). Both controllers end in
 * exit right after pdf(): their tests stop them with an exception thrown by the sink after the
 * fourth header, so the document is not echoed and exit is not reached.
 */
class HttpOutputTest extends TestCase
{
    use IfirmaWiringFixture;

    /** The document of the mocked iFirma API: 30 bytes, binary and multibyte (fewer characters). */
    const PDF = "%PDF-1.4\n%\xC4\x85\xFF\x00\nfake body\n%%EOF";

    /** @var string[] header lines the recording sink received, in order */
    private $lines = array();

    /** @var int[] length of the pending output when each header line was sent */
    private $pendingAtHeader = array();

    /** @var int output buffer level of the test itself (PHPUnit's own buffer) */
    private $baseLevel = 0;

    /** @var bool true while the code under test runs: an exit() then ends the whole PHPUnit process */
    private static $running = false;

    public static function setUpBeforeClass(): void
    {
        // An exit() reached by the code under test would end the PHPUnit run with status 0 and no
        // summary; the shutdown function turns that into a failed run.
        register_shutdown_function(function () {
            if (self::$running) {
                fwrite(STDERR, "\nHttpOutputTest: the code under test reached exit(), which ends the whole run.\n");
                exit(1);
            }
        });
    }

    protected function setUp(): void
    {
        $this->wiringSetUp();
        $this->baseLevel = ob_get_level();
        $this->recordHeaders(0);
    }

    protected function tearDown(): void
    {
        // a failing test may leave the buffer level it opened
        while (ob_get_level() > $this->baseLevel) {
            ob_end_clean();
        }
    }

    // ---- helpers ----------------------------------------------------------------------------

    /**
     * Installs the sink that records the header lines and the length of the pending output at each.
     * A $stopAfter above 0 makes it throw IfirmaTestHeadersSent after that many lines.
     */
    private function recordHeaders($stopAfter)
    {
        $this->lines = array();
        $this->pendingAtHeader = array();
        $lines = &$this->lines;
        $pending = &$this->pendingAtHeader;
        IfirmaHttpOutput::useHeaderSink(function ($line) use (&$lines, &$pending, $stopAfter) {
            $lines[] = $line;
            $pending[] = ob_get_length();
            if (count($lines) === $stopAfter) {
                throw new IfirmaTestHeadersSent();
            }
        });
    }

    /**
     * Runs $call on an output buffer level of its own that already holds $pending: pdf() closes the
     * top level, and that must not be PHPUnit's.
     */
    private function withOwnBuffer(callable $call, $pending = '')
    {
        ob_start();
        echo $pending;
        self::$running = true;
        try {
            $call();
        } finally {
            self::$running = false;
        }
    }

    private function expectedHeaders($filename)
    {
        return array(
            'Content-Type: application/pdf',
            'Content-Disposition: inline; filename="' . $filename . '"',
            'Content-Length: 30',
            'Cache-Control: private, must-revalidate',
        );
    }

    // ---- IfirmaHttpOutput::pdf() --------------------------------------------------------------

    public function testSendsTheFourPdfHeadersInOrderAndThenTheDocument()
    {
        $this->withOwnBuffer(function () {
            IfirmaHttpOutput::pdf(self::PDF, 'ifirma-7_2026.pdf');
        });

        // the length is a byte count
        $this->assertSame($this->expectedHeaders('ifirma-7_2026.pdf'), $this->lines);
        $this->expectOutputString(self::PDF);
    }

    public function testTheHeadersGoOutBeforeAnyOfTheDocument()
    {
        $this->withOwnBuffer(function () {
            IfirmaHttpOutput::pdf(self::PDF, 'ifirma-7_2026.pdf');
        });

        $this->assertSame(array(0, 0, 0, 0), $this->pendingAtHeader);
        $this->expectOutputString(self::PDF);
    }

    public function testDiscardsTheOutputBufferedSoFar()
    {
        $this->withOwnBuffer(function () {
            IfirmaHttpOutput::pdf(self::PDF, 'ifirma-7_2026.pdf');
        }, 'half-rendered page');

        // Exactly the level of the call is closed, and its content is dropped, not flushed.
        $this->assertSame($this->baseLevel, ob_get_level());
        // the four headers went out with nothing pending: the discard came before them
        $this->assertSame(array(0, 0, 0, 0), $this->pendingAtHeader);
        $this->expectOutputString(self::PDF);
    }

    public function testTheNativeHeaderIsTheDefaultSinkAndNullRestoresIt()
    {
        $sink = new ReflectionProperty('IfirmaHttpOutput', 'sendHeader');
        $sink->setAccessible(true);
        // what a request runs with: nothing in production installs another sink
        $this->assertSame('header', $sink->getDefaultValue());
        $this->assertNotSame('header', $sink->getValue(), 'setUp() installed the recording sink');

        IfirmaHttpOutput::useHeaderSink(null);
        $this->assertSame('header', $sink->getValue());

        $this->recordHeaders(0);
        IfirmaHttpOutput::useHeaderSink();
        $this->assertSame('header', $sink->getValue());

        // what every setUp() of the suite does
        $this->recordHeaders(0);
        IfirmaTestState::resetAll();
        $this->assertSame('header', $sink->getValue());
    }

    // ---- the two download controllers ---------------------------------------------------------

    /** An issued receipt for order 15 and the PDF that the mocked iFirma API returns for it. */
    private function prepareDownload($number, $id)
    {
        Db::getInstance()->whenGetValue('/WHERE security_token = "([^"]*)"/', function ($sql, $m) {
            foreach (IfirmaTestState::objects('ifirma_receipt') as $rowId => $row) {
                if ($row['security_token'] === $m[1]) {
                    return $rowId;
                }
            }

            return false;
        });
        $this->makeReceipt(array('ifirma_number' => $number, 'ifirma_id' => $id));
        $this->t->queue('GET', '#\.pdf#', array('body' => self::PDF));
    }

    /** Runs a download request and stops it at the fourth header, before the echo and the exit. */
    private function runUntilTheFourthHeader(callable $request)
    {
        $this->expectOutputString('');
        $this->recordHeaders(4);
        try {
            $this->withOwnBuffer($request);
        } catch (IfirmaTestHeadersSent $stop) {
            return;
        }
        $this->fail('The download ended without sending four headers.');
    }

    /**
     * The file name of the front controller sanitises the number, or the id when there is no
     * number; the admin controller sanitises the number only and sends the id as it is.
     */
    public function filenames()
    {
        return array(
            'the number, every character outside 0-9 A-Z a-z . _ - replaced' => array(
                'FV 7/2026-A.b_c', '77', array('front' => 'ifirma-FV_7_2026-A.b_c.pdf', 'admin' => 'ifirma-FV_7_2026-A.b_c.pdf'),
            ),
            'no number: the id, sanitised by the front controller only' => array(
                '', 'A/7', array('front' => 'ifirma-A_7.pdf', 'admin' => 'ifirma-A/7.pdf'),
            ),
        );
    }

    /**
     * @dataProvider filenames
     */
    public function testFrontControllerSendsThePdfHeadersOfTheOwnersReceipt($number, $id, array $filenames)
    {
        $this->prepareDownload($number, $id);
        Tools::$values = array('token' => 'tok123');
        $controller = IfirmaTestModuleLoader::loadFrontController('receipt');

        $this->runUntilTheFourthHeader(function () use ($controller) {
            $controller->initContent();
        });

        $this->assertSame($this->expectedHeaders($filenames['front']), $this->lines);
    }

    /**
     * @dataProvider filenames
     */
    public function testAdminControllerSendsThePdfHeadersOfTheOrdersReceipt($number, $id, array $filenames)
    {
        $this->prepareDownload($number, $id);
        Tools::$values = array('id_order' => 15);
        $controller = IfirmaTestModuleLoader::loadAdminController();

        $this->runUntilTheFourthHeader(function () use ($controller) {
            $controller->ajaxProcessDownloadPdf();
        });

        $this->assertSame($this->expectedHeaders($filenames['admin']), $this->lines);
    }
}
