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
 * Delivers the e-mails of the customer data-change request: whom they go to, in which language and
 * with which template. What they say stays with IfirmaDataRequestService, which hands over the
 * translated subject and the template variables (so that every l() literal stays in its source file).
 *
 * Public contract:
 *  - merchantAddress(): IFIRMA_NOTIFY_EMAIL, else the shop address, '' when neither is set
 *  - sendToMerchant(to, Order, subject, vars): template data_request_received, in the language of the
 *    context, for the shop of the order
 *  - sendToCustomer(decision, Customer, Order, subject, vars): template data_request_approved|rejected, in
 *    the language of the order when the module ships that template for it, else English, else the
 *    language of the context; addressed to the customer by full name
 *  - both send methods answer false when Mail::Send() does not send the mail
 *
 * The service takes it as a constructor argument; its tests pass a subclass that records the calls.
 */
class IfirmaDataRequestNotifier
{
    /**
     * @return string the address that is told about new requests; empty when none is configured
     */
    public function merchantAddress()
    {
        $to = trim((string) Configuration::get('IFIRMA_NOTIFY_EMAIL'));
        if ($to === '') {
            $to = trim((string) Configuration::get('PS_SHOP_EMAIL'));
        }

        return $to;
    }

    /**
     * @param string $to address from merchantAddress()
     * @param Order $order the order the request is about
     * @param string $subject translated
     * @param array $vars '{placeholder}' => value, escaped by the caller
     * @return bool
     */
    public function sendToMerchant($to, Order $order, $subject, array $vars)
    {
        return (bool) Mail::Send(
            (int) Context::getContext()->language->id,
            'data_request_received',
            $subject,
            $vars,
            $to,
            null,
            null,
            null,
            null,
            null,
            $this->templateDir(),
            false,
            (int) $order->id_shop ?: null
        );
    }

    /**
     * @param string $decision approved|rejected
     * @param Customer $customer receives the mail
     * @param Order $order the order the request is about
     * @param string $subject translated
     * @param array $vars '{placeholder}' => value, escaped by the caller
     * @return bool
     */
    public function sendToCustomer($decision, Customer $customer, Order $order, $subject, array $vars)
    {
        $template = 'data_request_' . $decision;
        $dir = $this->templateDir();

        $idLang = (int) $order->id_lang;
        $iso = $idLang ? Language::getIsoById($idLang) : false;
        if (!$iso || !is_file($dir . $iso . '/' . $template . '.html')) {
            $idLang = 0;
            foreach (Language::getLanguages(false) as $lang) {
                if ($lang['iso_code'] === 'en') {
                    $idLang = (int) $lang['id_lang'];
                    break;
                }
            }
            if (!$idLang) {
                $idLang = (int) Context::getContext()->language->id;
            }
        }

        return (bool) Mail::Send(
            $idLang,
            $template,
            $subject,
            $vars,
            $customer->email,
            trim($customer->firstname . ' ' . $customer->lastname),
            null,
            null,
            null,
            null,
            $dir,
            false,
            (int) $order->id_shop ?: null
        );
    }

    /**
     * Protected so that a test can point the notifier at a folder of its own.
     *
     * @return string the folder of the mail templates, with a trailing slash, one sub folder per language iso code
     */
    protected function templateDir()
    {
        return _PS_MODULE_DIR_ . 'ifirma/mails/';
    }
}
