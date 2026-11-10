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

class Mail
{
    /** @var array[] one assoc array per Send() call (keys = parameter names) */
    public static $sent = [];
    /** @var bool value returned by Send() */
    public static $result = true;
    /** @var Throwable|null thrown by Send(), as the core does when the mail transport fails */
    public static $throw = null;

    public static function reset()
    {
        self::$sent = [];
        self::$result = true;
        self::$throw = null;
    }

    public static function Send(
        $idLang,
        $template,
        $subject,
        $templateVars,
        $to,
        $toName = null,
        $from = null,
        $fromName = null,
        $fileAttachment = null,
        $modeSmtp = null,
        $templatePath = _PS_MAIL_DIR_,
        $die = false,
        $idShop = null,
        $bcc = null,
        $replyTo = null,
        $replyToName = null
    ) {
        if (self::$throw !== null) {
            throw self::$throw;
        }
        self::$sent[] = [
            'idLang' => $idLang, 'template' => $template, 'subject' => $subject,
            'templateVars' => $templateVars, 'to' => $to, 'toName' => $toName,
            'from' => $from, 'fromName' => $fromName, 'fileAttachment' => $fileAttachment,
            'modeSmtp' => $modeSmtp, 'templatePath' => $templatePath, 'die' => $die,
            'idShop' => $idShop, 'bcc' => $bcc, 'replyTo' => $replyTo, 'replyToName' => $replyToName,
        ];

        return self::$result;
    }
}
