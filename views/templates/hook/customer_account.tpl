{*
 * iFirma module – "My receipts" block in the customer account home.
 * Feature 1: the registered user sees the documents fetched from iFirma.
 *}
<div class="col-lg-4 col-md-6 col-sm-6 col-xs-12" id="ifirma-account-block">
    <a class="ifirma-account-link" href="#ifirma-receipts-list" role="button"
       data-toggle="collapse" aria-expanded="false" aria-controls="ifirma-receipts-list">
        <span class="link-item">
            <i class="material-icons" aria-hidden="true">&#xE8B0;</i>
            {l s='My iFirma receipts' mod='ifirma'}
            <span class="badge badge-primary">{$ifirma_count|intval}</span>
        </span>
    </a>

    <ul class="collapse ifirma-receipts-list" id="ifirma-receipts-list">
        {foreach from=$ifirma_receipts item=receipt}
            <li>
                <a href="{$receipt.download_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">
                    <i class="material-icons" aria-hidden="true">&#xE2C4;</i>
                    {if $receipt.ifirma_number}{$receipt.ifirma_number|escape:'html':'UTF-8'}{else}{l s='Document' mod='ifirma'} #{$receipt.ifirma_id|escape:'html':'UTF-8'}{/if}
                </a>
                <span class="ifirma-badge ifirma-badge-{$receipt.status|escape:'html':'UTF-8'}">{$receipt.status|escape:'html':'UTF-8'}</span>
            </li>
        {/foreach}
    </ul>
</div>
