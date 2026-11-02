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
 * iFirma module - accounting-document block on the customer's order detail page.
 * Consumes only $ifirma_view (see classes/IfirmaCustomerView.php); renders exactly one state.
 *}
<div class="box ifirma-order-detail ifirma-state-{$ifirma_view.state|escape:'html':'UTF-8'}">
    <h3 class="page-subheading">{l s='Accounting document (iFirma)' mod='ifirma'}</h3>

    {if $ifirma_view.state == 'issued'}
        {assign var='doc' value=$ifirma_view.document}
        <p>
            <strong>{$doc.number|escape:'html':'UTF-8'}</strong>
            {if $doc.paid}
                <span class="ifirma-badge ifirma-badge-paid">{l s='Paid' mod='ifirma'}</span>
            {else}
                <span class="ifirma-badge ifirma-badge-unpaid">{l s='Unpaid' mod='ifirma'}</span>
            {/if}
            {if $doc.is_external}
                <span class="ifirma-badge ifirma-badge-external">{l s='Issued outside the shop (imported from iFirma)' mod='ifirma'}</span>
            {/if}
        </p>
        <p>{l s='Issue date' mod='ifirma'}: {$doc.issue_date|escape:'html':'UTF-8'}</p>
        <p>
            {if $doc.sent}
                {l s='Sent on' mod='ifirma'} {$doc.sent_at|escape:'html':'UTF-8'}
            {else}
                {l s='Not yet sent' mod='ifirma'}
            {/if}
        </p>
        {if $doc.download_url}
            <a class="btn btn-primary" href="{$doc.download_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
                <i class="material-icons" aria-hidden="true">&#xE2C4;</i>
                {l s='Download receipt (PDF)' mod='ifirma'}
            </a>
        {/if}
    {elseif $ifirma_view.state == 'missing'}
        <p>{l s='This document is no longer available. Please contact the shop.' mod='ifirma'}</p>
    {elseif $ifirma_view.state == 'verifying'}
        <p>{l s='The shop is verifying the accounting document for this order.' mod='ifirma'}</p>
    {elseif $ifirma_view.state == 'held'}
        <p>{l s='The document is on hold pending your request to change the billing data.' mod='ifirma'}</p>
        {if $ifirma_view.pending_request}
            <p>{l s='Request submitted on' mod='ifirma'} {$ifirma_view.pending_request.date_add|escape:'html':'UTF-8'}</p>
        {/if}
        <a class="btn btn-secondary" href="{$ifirma_view.cancel_url|escape:'html':'UTF-8'}">{l s='Cancel request' mod='ifirma'}</a>
    {else}
        <p>{l s='No accounting document has been issued for this order yet.' mod='ifirma'}</p>
        {if $ifirma_view.trigger_state_name}
            <p>{l s='It will be issued when the order reaches the status:' mod='ifirma'} <strong>{$ifirma_view.trigger_state_name|escape:'html':'UTF-8'}</strong></p>
        {/if}
        {if $ifirma_view.can_request_change}
            <a class="btn btn-secondary" href="{$ifirma_view.request_url|escape:'html':'UTF-8'}">{l s='Request a change to billing data' mod='ifirma'}</a>
        {/if}
    {/if}

    {if $ifirma_view.contact_shop}
        <p class="ifirma-contact-shop">{l s='To change the document, please contact the shop.' mod='ifirma'}</p>
    {/if}
</div>
