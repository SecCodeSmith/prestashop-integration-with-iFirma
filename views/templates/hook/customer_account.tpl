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
 * iFirma module - "My receipts" block in the customer account home.
 * Consumes only $ifirma_account (see classes/IfirmaCustomerView.php::forAccount()).
 *}
{if $ifirma_account.visible}
<div class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="ifirma-account-block">
    <a class="ifirma-account-link" href="#ifirma-receipts-list" role="button"
       data-toggle="collapse" aria-expanded="false" aria-controls="ifirma-receipts-list">
        <span class="link-item">
            <i class="material-icons" aria-hidden="true">&#xE8B0;</i>
            {l s='My iFirma receipts' mod='ifirma'}
            <span class="badge badge-primary">{$ifirma_account.receipts|count|intval}</span>
        </span>
    </a>

    <ul class="collapse ifirma-receipts-list" id="ifirma-receipts-list">
        {foreach from=$ifirma_account.receipts item=receipt}
            <li>
                {if $receipt.download_url}
                <a href="{$receipt.download_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
                    <i class="material-icons" aria-hidden="true">&#xE2C4;</i>
                    {$receipt.number|escape:'html':'UTF-8'}
                </a>
                {else}
                    <i class="material-icons" aria-hidden="true">&#xE2C4;</i>
                    {$receipt.number|escape:'html':'UTF-8'}
                {/if}
                <span class="ifirma-badge ifirma-badge-{$receipt.status|escape:'html':'UTF-8'}">{$receipt.status_label|escape:'html':'UTF-8'}</span>
                {if $receipt.is_external}
                    <span class="ifirma-badge ifirma-badge-external">{l s='Issued outside the shop (imported from iFirma)' mod='ifirma'}</span>
                {/if}
            </li>
        {/foreach}
        {if $ifirma_account.pending_count > 0}
            <li class="ifirma-pending-count">
                {l s='Orders with a pending billing data change request:' mod='ifirma'} {$ifirma_account.pending_count|intval}
            </li>
        {/if}
    </ul>
</div>
{/if}
