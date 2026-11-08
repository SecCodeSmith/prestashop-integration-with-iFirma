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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The web address of the shop as the merchant wants it shown: the link in the e-mails and the
 * prefix of the PDF file names.
 *
 * url(): the setting IFIRMA_SHOP_URL (trimmed) when it has a value, else the base URL of the shop
 * of the context ('' when there is no context or no shop). Mail::Send() overwrites {shop_url} with
 * its own value, which is why the templates use the module's own placeholder {ifirma_shop_link}.
 *
 * fileNamePrefix(): the host of url() (with its port, if any) in lower case without a leading "www.", every character outside
 * 0-9A-Za-z._- replaced by an underscore; "ifirma" when no host can be read.
 */
class IfirmaShopLink
{
    /** @var callable|null returns the fallback address; null reads the shop of the context */
    private static $fallback = null;

    /**
     * Testability seam: where url() gets the address when the setting is empty.
     *
     * @param callable|null $fallback returns the address; null restores the context lookup
     */
    public static function useFallback($fallback = null)
    {
        self::$fallback = $fallback;
    }

    /**
     * @return string the address of the shop; '' when there is none
     */
    public static function url()
    {
        $url = trim((string) Configuration::get('IFIRMA_SHOP_URL'));
        if ($url !== '') {
            return $url;
        }

        return trim((string) call_user_func(self::$fallback === null ? array(__CLASS__, 'contextUrl') : self::$fallback));
    }

    /**
     * @return string the prefix of the PDF file names, only 0-9A-Za-z._-
     */
    public static function fileNamePrefix()
    {
        $url = self::url();
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return 'ifirma';
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        $host = strtolower($host);
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        $host = preg_replace('/[^0-9A-Za-z._-]/', '_', $host);

        return $host === '' ? 'ifirma' : $host;
    }

    /**
     * The name of a PDF download or e-mail attachment: <fileNamePrefix()>-<name>.pdf, every character of
     * the name outside 0-9A-Za-z._- replaced by an underscore. The one place that builds it, so the
     * downloads and the attachment always carry the same name.
     *
     * @param string $name the document number or, when there is none, the identifier (raw)
     * @return string
     */
    public static function pdfFileName($name)
    {
        return self::fileNamePrefix() . '-' . preg_replace('/[^0-9A-Za-z._-]/', '_', (string) $name) . '.pdf';
    }

    /**
     * @return string the base URL of the shop of the context; '' when there is no context or shop
     */
    public static function contextUrl()
    {
        $context = Context::getContext();
        if (!is_object($context) || !isset($context->shop) || !is_object($context->shop) || !method_exists($context->shop, 'getBaseURL')) {
            return '';
        }

        return (string) $context->shop->getBaseURL(true, true);
    }
}
