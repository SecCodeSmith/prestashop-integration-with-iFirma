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
 * Assertions on the calls the Mail stub recorded (Mail::$sent). For a PHPUnit TestCase.
 */
trait IfirmaTestMailTrait
{
    /** @return string the folder the module reads its mail templates from */
    private function mailDir()
    {
        return _PS_MODULE_DIR_ . 'ifirma/mails/';
    }

    /** @return array a recorded Mail::Send() call with its keys in a fixed order */
    private static function sortedMail(array $record)
    {
        ksort($record);
        ksort($record['templateVars']);

        return $record;
    }

    /**
     * Asserts one recorded Mail::Send() call, argument for argument and type for type, whatever the
     * order of the keys. The arguments that never change (sender, attachment, SMTP mode, template
     * folder, die flag, bcc, reply-to) are filled in here.
     *
     * @param array $expected keys as in Mail::$sent: idLang, template, subject, templateVars, to, toName, idShop
     * @param int $index
     */
    private function assertMailSent(array $expected, $index = 0)
    {
        $this->assertArrayHasKey($index, Mail::$sent, 'the mail was not sent');
        $expected += array(
            'from' => null,
            'fromName' => null,
            'fileAttachment' => null,
            'modeSmtp' => null,
            'templatePath' => $this->mailDir(),
            'die' => false,
            'bcc' => null,
            'replyTo' => null,
            'replyToName' => null,
        );
        $this->assertSame(self::sortedMail($expected), self::sortedMail(Mail::$sent[$index]));
    }
}
