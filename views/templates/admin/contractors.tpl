{**
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 *}
<div class="panel" id="ifirma-contractors">
    <div class="panel-heading"><i class="icon-users"></i> {l s='iFirma contractors' mod='ifirma'}</div>

    <p>{l s='Customers linked to an existing iFirma contractor reuse it on every document instead of getting a new PS-C contractor.' mod='ifirma'}</p>

    <table class="table" id="ifirma-contractor-counts">
        <thead>
            <tr>
                <th>{l s='Match type' mod='ifirma'}</th>
                <th>{l s='Linked customers' mod='ifirma'}</th>
            </tr>
        </thead>
        <tbody>
            {foreach from=$ifirma_cm_counts key=ifirma_cm_type item=ifirma_cm_n}
                <tr>
                    <td>{$ifirma_cm_type|escape:'html':'UTF-8'}</td>
                    <td>{$ifirma_cm_n|intval}</td>
                </tr>
            {foreachelse}
                <tr>
                    <td colspan="2" class="text-muted">{l s='No customer is linked yet.' mod='ifirma'}</td>
                </tr>
            {/foreach}
        </tbody>
    </table>

    {if $ifirma_cm_errors}
        <div class="alert alert-danger">
            {foreach from=$ifirma_cm_errors item=ifirma_cm_error}
                <div>{$ifirma_cm_error|escape:'html':'UTF-8'}</div>
            {/foreach}
        </div>
    {/if}

    {if $ifirma_cm_report}
        <div class="alert alert-success" id="ifirma-contractor-report">
            <p><strong>{l s='Import finished.' mod='ifirma'}</strong>
                {l s='Rows read' mod='ifirma'}: {$ifirma_cm_report.rows|intval};
                {l s='Linked' mod='ifirma'}: {$ifirma_cm_report.linked|intval};
                {l s='Unmatched' mod='ifirma'}: {$ifirma_cm_report.unmatched|intval};
                {l s='Kept existing link' mod='ifirma'}: {$ifirma_cm_report.skipped_existing|intval}.</p>
            {if $ifirma_cm_report.by}
                <ul>
                    {foreach from=$ifirma_cm_report.by key=ifirma_cm_type item=ifirma_cm_n}
                        <li>{$ifirma_cm_type|escape:'html':'UTF-8'}: {$ifirma_cm_n|intval}</li>
                    {/foreach}
                </ul>
            {/if}
            {if $ifirma_cm_report.unmatched_rows}
                <p>{l s='First unmatched identifiers' mod='ifirma'}: {$ifirma_cm_report.unmatched_text|escape:'html':'UTF-8'}</p>
            {/if}
        </div>
    {/if}

    <form method="post" action="{$ifirma_cm_action|escape:'html':'UTF-8'}" enctype="multipart/form-data" class="form-horizontal">
        <input type="hidden" name="token" value="{$ifirma_cm_token|escape:'html':'UTF-8'}">
        <div class="form-group">
            <label class="control-label col-lg-3" for="IFIRMA_CONTRACTOR_CSV">{l s='iFirma contractor export (CSV)' mod='ifirma'}</label>
            <div class="col-lg-9">
                <input type="file" name="IFIRMA_CONTRACTOR_CSV" id="IFIRMA_CONTRACTOR_CSV" accept=".csv,.txt,text/csv">
                <p class="help-block">{l s='Export from iFirma: Contractors, export to CSV. Required column: Identyfikator; used for matching: Nazwa, NIP, E-mail, Kod pocztowy. Maximum 5 MB.' mod='ifirma'}</p>
            </div>
        </div>
        <button type="submit" name="submitIfirmaImportContractors" value="1" class="btn btn-default">
            <i class="icon-upload"></i> {l s='Import contractors' mod='ifirma'}
        </button>
    </form>

    <form method="post" action="{$ifirma_cm_action|escape:'html':'UTF-8'}" style="margin-top:15px;"
          onsubmit="return window.confirm('{l s='Forget all customer to contractor links?' mod='ifirma' js=1}');">
        <input type="hidden" name="token" value="{$ifirma_cm_token|escape:'html':'UTF-8'}">
        <input type="hidden" name="confirm" value="1">
        <button type="submit" name="submitIfirmaResetContractorMap" value="1" class="btn btn-default">
            <i class="icon-trash"></i> {l s='Forget all links' mod='ifirma'}
        </button>
    </form>
</div>
