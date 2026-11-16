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
 * iFirma module - "My invoices" page. Consumes only $ifirma_account (see
 * classes/IfirmaCustomerView.php::forAccount()). No script: every link works without JavaScript.
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='My invoices' mod='ifirma'}
{/block}

{block name='page_content'}
<section id="ifirma-documents">
    {if $ifirma_account.receipts}
    <ul class="ifirma-receipts-list">
        {foreach from=$ifirma_account.receipts item=receipt}
            <li>
                <span class="ifirma-document-number">
                    {if $receipt.download_url}
                    <a href="{$receipt.download_url|escape:'html':'UTF-8'}" rel="noopener">
                        <i class="material-icons" aria-hidden="true">&#xE2C4;</i>
                        {$receipt.number|escape:'html':'UTF-8'}
                    </a>
                    {else}
                        <i class="material-icons" aria-hidden="true">&#xE2C4;</i>
                        {$receipt.number|escape:'html':'UTF-8'}
                    {/if}
                </span>
                <span class="ifirma-document-date">{$receipt.issue_date|escape:'html':'UTF-8'}</span>
                <span class="ifirma-document-badges">
                    <span class="ifirma-badge ifirma-badge-{$receipt.status|escape:'html':'UTF-8'}">{$receipt.status_label|escape:'html':'UTF-8'}</span>
                    {if $receipt.is_external}
                        <span class="ifirma-badge ifirma-badge-external">{l s='Issued outside the shop (imported from iFirma)' mod='ifirma'}</span>
                    {/if}
                </span>
            </li>
        {/foreach}
    </ul>
    {/if}

    {if $ifirma_account.pending_count > 0}
        <p class="ifirma-pending-count">
            {l s='Orders with a pending billing data change request:' mod='ifirma'} {$ifirma_account.pending_count|intval}
        </p>
    {/if}

    {if !$ifirma_account.receipts && $ifirma_account.pending_count < 1}
        <p class="ifirma-no-documents">{l s='You have no invoices yet.' mod='ifirma'}</p>
    {/if}
</section>
{/block}
