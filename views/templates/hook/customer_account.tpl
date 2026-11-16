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
 * iFirma module - "My invoices" tile in the customer account home of the Classic theme (and any theme that
 * styles the anchor as the grid column, like the core modules do). A plain link to the "My invoices" page: it needs
 * no theme script. Consumes only $ifirma_account (see classes/IfirmaCustomerView.php::forAccount()).
 *}
{if $ifirma_account.visible}
<a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="ifirma-account-block" href="{$ifirma_account.page_url|escape:'html':'UTF-8'}">
    <span class="link-item">
        <i class="material-icons" aria-hidden="true">&#xE8B0;</i>
        {l s='My invoices' mod='ifirma'}
    </span>
</a>
{/if}
