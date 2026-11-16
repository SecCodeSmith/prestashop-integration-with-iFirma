{*
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 *}

{*
 * iFirma module - "My invoices" entry of the Hummingbird account menu (also used on its my-account page). Same
 * markup as the core modules' overrides in themes/hummingbird/modules/. Plain link, no theme script. Consumes only
 * $ifirma_account (see classes/IfirmaCustomerView.php::forAccount()).
 *}
{if $ifirma_account.visible}
<a class="account-menu__link{if $urls.current_url === $ifirma_account.page_url} account-menu__link--active{/if}" id="ifirma-account-block" href="{$ifirma_account.page_url|escape:'html':'UTF-8'}"{if $urls.current_url === $ifirma_account.page_url} aria-current="page"{/if}>
    <i class="account-menu__icon material-icons" aria-hidden="true">&#xE8B0;</i>
    {l s='My invoices' mod='ifirma'}
</a>
{/if}
