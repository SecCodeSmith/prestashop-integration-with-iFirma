{*
 * iFirma module – receipt block on the customer's order detail page.
 * Feature 1: download the document that was issued in iFirma for this order.
 *}
<div class="box ifirma-order-detail">
    <h3 class="page-subheading">{l s='Accounting document (iFirma)' mod='ifirma'}</h3>

    <p>
        {if $ifirma_receipt.ifirma_number}
            <strong>{$ifirma_receipt.ifirma_number|escape:'html':'UTF-8'}</strong>
        {else}
            <strong>{l s='Document' mod='ifirma'} #{$ifirma_receipt.ifirma_id|escape:'html':'UTF-8'}</strong>
        {/if}
        <span class="ifirma-badge ifirma-badge-{$ifirma_receipt.status|escape:'html':'UTF-8'}">{$ifirma_receipt.status|escape:'html':'UTF-8'}</span>
    </p>

    <a class="btn btn-primary" href="{$ifirma_receipt.download_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
        <i class="material-icons" aria-hidden="true">&#xE2C4;</i>
        {l s='Download receipt (PDF)' mod='ifirma'}
    </a>
</div>
