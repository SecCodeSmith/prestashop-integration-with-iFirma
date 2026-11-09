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
 * iFirma module - admin order panel.
 * Sections: status header, document table, alerts (errors, duplicate candidates, hold),
 * action buttons, billing data change requests (approve / reject) and request history.
 *}
{assign var='ifirma_badge' value='default'}
{if $ifirma_state == 'issued'}{assign var='ifirma_badge' value='info'}{/if}
{if $ifirma_state == 'paid'}{assign var='ifirma_badge' value='success'}{/if}
{if $ifirma_state == 'error' || $ifirma_state == 'missing'}{assign var='ifirma_badge' value='danger'}{/if}
{if $ifirma_state == 'unknown' || $ifirma_state == 'suspected' || $ifirma_state == 'held' || $ifirma_state == 'queued'}{assign var='ifirma_badge' value='warning'}{/if}

<div class="card mt-2" id="ifirma-panel">
    <div class="card-header">
        <h3 class="card-header-title">
            <i class="material-icons">receipt_long</i> {l s='iFirma' mod='ifirma'}
            {if $ifirma_configured}
                <span id="ifirma-status" class="badge badge-{if $ifirma_badge == 'default'}secondary{else}{$ifirma_badge|escape:'html':'UTF-8'}{/if}" data-state="{$ifirma_state|escape:'html':'UTF-8'}">{$ifirma_state_label|escape:'html':'UTF-8'}</span>
            {/if}
        </h3>
    </div>
    <div class="card-body">

    {if !$ifirma_configured}
        <div class="alert alert-warning">
            {l s='The iFirma module is not configured. Set your login and API key in the module settings.' mod='ifirma'}
        </div>
    {else}
        <div id="ifirma-alert" class="alert" style="display:none;"></div>

        <div id="ifirma-state">
            {* ---- document table ---- *}
            {if $ifirma_is_issued}
                <table class="table" id="ifirma-document">
                    <tbody>
                        <tr>
                            <th style="width:35%">{l s='Document number' mod='ifirma'}</th>
                            <td>
                                <span id="ifirma-number">{$ifirma_receipt->ifirma_number|default:$ifirma_receipt->ifirma_id|escape:'html':'UTF-8'}</span>
                                <span class="text-muted">({l s='ID' mod='ifirma'}: {$ifirma_receipt->ifirma_id|escape:'html':'UTF-8'})</span>
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='Document type' mod='ifirma'}</th>
                            <td>{$ifirma_receipt->document_type|escape:'html':'UTF-8'}</td>
                        </tr>
                        {if $ifirma_payment_method}
                        <tr>
                            <th>{l s='Payment method (SposobZaplaty)' mod='ifirma'}</th>
                            <td>{$ifirma_payment_method|escape:'html':'UTF-8'}</td>
                        </tr>
                        {/if}
                        {if $ifirma_contractor_note}
                        <tr>
                            <th>{l s='Contractor' mod='ifirma'}</th>
                            <td>{$ifirma_contractor_note|escape:'html':'UTF-8'}</td>
                        </tr>
                        {/if}
                        {if $ifirma_contractor_map}
                        <tr>
                            <th>{l s='Linked contractor' mod='ifirma'}</th>
                            <td>{$ifirma_contractor_map.identifier|escape:'html':'UTF-8'}{if $ifirma_contractor_map.name} ({$ifirma_contractor_map.name|escape:'html':'UTF-8'}){/if}
                                <span class="text-muted">- {l s='matched by' mod='ifirma'}: {$ifirma_contractor_map.matched_by_label|escape:'html':'UTF-8'}</span></td>
                        </tr>
                        {/if}
                        <tr>
                            <th>{l s='Issue date' mod='ifirma'}</th>
                            <td>{$ifirma_receipt->issue_date|default:'-'|escape:'html':'UTF-8'}</td>
                        </tr>
                        <tr>
                            <th>{l s='Source' mod='ifirma'}</th>
                            <td>
                                {if $ifirma_is_external}
                                    <span class="label label-warning">{l s='External document (issued outside the shop)' mod='ifirma'}</span>
                                {else}
                                    {l s='Issued by the module' mod='ifirma'}
                                {/if}
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='Paid in iFirma / gross' mod='ifirma'}</th>
                            <td>
                                {if $ifirma_receipt->remote_paid_amount !== null && $ifirma_receipt->remote_paid_amount !== ''}{$ifirma_receipt->remote_paid_amount|string_format:'%.2f'|escape:'html':'UTF-8'}{else}-{/if}
                                / {$ifirma_gross|string_format:'%.2f'|escape:'html':'UTF-8'}
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='Sent' mod='ifirma'}</th>
                            <td>
                                {if $ifirma_receipt->remote_sent}{l s='Marked as sent in iFirma' mod='ifirma'}{else}{l s='Not marked as sent in iFirma' mod='ifirma'}{/if}
                                {if $ifirma_receipt->remote_draft} - {l s='Draft' mod='ifirma'}{/if}
                                <br><span class="text-muted" id="ifirma-delivery">{$ifirma_delivery_text|escape:'html':'UTF-8'}</span>
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='KSeF' mod='ifirma'}</th>
                            <td id="ifirma-ksef">{$ifirma_ksef_text|escape:'html':'UTF-8'}</td>
                        </tr>
                        {if $ifirma_receipt->linked_document}
                        <tr>
                            <th>{l s='Linked document' mod='ifirma'}</th>
                            <td>{$ifirma_receipt->linked_document|escape:'html':'UTF-8'}</td>
                        </tr>
                        {/if}
                        <tr>
                            <th>{l s='Last synchronised' mod='ifirma'}</th>
                            <td>
                                <span id="ifirma-last-sync">{$ifirma_receipt->last_sync_at|default:'-'|escape:'html':'UTF-8'}</span>
                                {if $ifirma_receipt->sync_error}
                                    <span class="text-danger">{$ifirma_receipt->sync_error|escape:'html':'UTF-8'}</span>
                                {/if}
                            </td>
                        </tr>
                    </tbody>
                </table>

                {if $ifirma_receipt->remote_missing}
                    <div class="alert alert-danger">
                        {l s='This document was not found in iFirma any more. Check iFirma before issuing anything again.' mod='ifirma'}
                    </div>
                {/if}
                {if $ifirma_receipt && ($ifirma_request_payload || $ifirma_response_payload)}
                    <details class="ifirma-diagnostics" style="margin-top:10px;">
                        <summary>{l s='Diagnostics: last iFirma request and response' mod='ifirma'} <span class="text-muted">(v{$ifirma_module_version|escape:'html':'UTF-8'}, {$ifirma_build_stamp|escape:'html':'UTF-8'})</span></summary>
                        <p class="text-muted">{l s='Copy this when reporting a problem. API keys are never included.' mod='ifirma'}</p>
                        {if $ifirma_contractor_note}<p><strong>{l s='Contractor step' mod='ifirma'}:</strong> {$ifirma_contractor_note|escape:'html':'UTF-8'}</p>{/if}
                        {if $ifirma_contractor_map}<p><strong>{l s='Linked contractor' mod='ifirma'}:</strong> {$ifirma_contractor_map.identifier|escape:'html':'UTF-8'} ({$ifirma_contractor_map.matched_by_label|escape:'html':'UTF-8'})</p>{/if}
                        {if $ifirma_receipt->last_error}<p><strong>{l s='Last error' mod='ifirma'}:</strong> {$ifirma_receipt->last_error|escape:'html':'UTF-8'}</p>{/if}
                        <strong>{l s='Request sent' mod='ifirma'}</strong>
                        <pre style="max-height:240px;overflow:auto;font-size:11px;">{$ifirma_request_payload|escape:'html':'UTF-8'}</pre>
                        <strong>{l s='Response received' mod='ifirma'}</strong>
                        <pre style="max-height:240px;overflow:auto;font-size:11px;">{$ifirma_response_payload|escape:'html':'UTF-8'}</pre>
                    </details>
                {/if}
            {else}
                {* ---- alerts for a document that is not issued ---- *}
                {if $ifirma_state == 'error' || $ifirma_state == 'unknown'}
                    {if $ifirma_receipt && $ifirma_receipt->last_error}
                        <div class="alert alert-danger">
                            {l s='Last attempt failed:' mod='ifirma'} {$ifirma_receipt->last_error|escape:'html':'UTF-8'}
                        </div>
                    {/if}
                    {if $ifirma_state == 'unknown'}
                        <div class="alert alert-warning">
                            {l s='iFirma did not confirm the result. A document may exist already - check iFirma before issuing it again.' mod='ifirma'}
                        </div>
                    {/if}
                {/if}
                {if $ifirma_receipt && ($ifirma_request_payload || $ifirma_response_payload)}
                    <details class="ifirma-diagnostics" style="margin-top:10px;">
                        <summary>{l s='Diagnostics: last iFirma request and response' mod='ifirma'} <span class="text-muted">(v{$ifirma_module_version|escape:'html':'UTF-8'}, {$ifirma_build_stamp|escape:'html':'UTF-8'})</span></summary>
                        <p class="text-muted">{l s='Copy this when reporting a problem. API keys are never included.' mod='ifirma'}</p>
                        {if $ifirma_contractor_note}<p><strong>{l s='Contractor step' mod='ifirma'}:</strong> {$ifirma_contractor_note|escape:'html':'UTF-8'}</p>{/if}
                        {if $ifirma_contractor_map}<p><strong>{l s='Linked contractor' mod='ifirma'}:</strong> {$ifirma_contractor_map.identifier|escape:'html':'UTF-8'} ({$ifirma_contractor_map.matched_by_label|escape:'html':'UTF-8'})</p>{/if}
                        {if $ifirma_receipt->last_error}<p><strong>{l s='Last error' mod='ifirma'}:</strong> {$ifirma_receipt->last_error|escape:'html':'UTF-8'}</p>{/if}
                        <strong>{l s='Request sent' mod='ifirma'}</strong>
                        <pre style="max-height:240px;overflow:auto;font-size:11px;">{$ifirma_request_payload|escape:'html':'UTF-8'}</pre>
                        <strong>{l s='Response received' mod='ifirma'}</strong>
                        <pre style="max-height:240px;overflow:auto;font-size:11px;">{$ifirma_response_payload|escape:'html':'UTF-8'}</pre>
                    </details>
                {/if}

                {if $ifirma_state == 'queued'}
                    <div class="alert alert-warning" id="ifirma-queued-notice">
                        {l s='This order is queued for issuance. The cron endpoint issues it, or use Create below.' mod='ifirma'}
                        {if $ifirma_receipt && $ifirma_receipt->last_error}<br>{$ifirma_receipt->last_error|escape:'html':'UTF-8'}{/if}
                    </div>
                {/if}

                {if $ifirma_state == 'held'}
                    <div class="alert alert-warning" id="ifirma-held-notice">
                        {l s='Issuing is on hold until the customer billing data change request below is resolved.' mod='ifirma'}
                    </div>
                {/if}

                {if $ifirma_state == 'suspected'}
                    <div class="alert alert-warning">
                        {l s='iFirma already contains documents that look like this order. Link one of them or issue a new document anyway.' mod='ifirma'}
                    </div>
                    {if $ifirma_candidates}
                    <table class="table" id="ifirma-candidates">
                        <thead>
                            <tr>
                                <th>{l s='Document number' mod='ifirma'}</th>
                                <th>{l s='Issue date' mod='ifirma'}</th>
                                <th>{l s='Sale date' mod='ifirma'}</th>
                                <th>{l s='Gross' mod='ifirma'}</th>
                                <th>{l s='Paid' mod='ifirma'}</th>
                                <th>{l s='Contractor' mod='ifirma'}</th>
                                <th>{l s='Type' mod='ifirma'}</th>
                                <th>{l s='Sent' mod='ifirma'}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$ifirma_candidates item=candidate}
                            <tr>
                                <td>{$candidate.number|escape:'html':'UTF-8'}</td>
                                <td>{$candidate.date|escape:'html':'UTF-8'}</td>
                                <td>{$candidate.sale_date|default:''|escape:'html':'UTF-8'}</td>
                                <td>{$candidate.gross|string_format:'%.2f'|escape:'html':'UTF-8'} {$candidate.currency|escape:'html':'UTF-8'}</td>
                                <td>{$candidate.paid|string_format:'%.2f'|escape:'html':'UTF-8'}</td>
                                <td>{$candidate.contractor|escape:'html':'UTF-8'}</td>
                                <td>{$candidate.rodzaj|escape:'html':'UTF-8'}</td>
                                <td>{if $candidate.sent}{l s='Yes' mod='ifirma'}{else}{l s='No' mod='ifirma'}{/if}</td>
                                <td>
                                    {if $ifirma_can_issue}
                                    <button type="button" class="btn btn-default btn-sm ifirma-link-candidate"
                                            data-id-order="{$ifirma_id_order|intval}"
                                            data-faktura-id="{$candidate.id|escape:'html':'UTF-8'}">
                                        <i class="icon-link"></i> {l s='Link this document' mod='ifirma'}
                                    </button>
                                    {/if}
                                </td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                    {/if}
                {/if}

                {if $ifirma_resolution}
                    <p class="text-muted" id="ifirma-resolution">
                        {l s='Resolved by an employee:' mod='ifirma'}
                        {$ifirma_resolution.action|escape:'html':'UTF-8'}
                        {if isset($ifirma_resolution.at)}({$ifirma_resolution.at|escape:'html':'UTF-8'}){/if}
                    </p>
                {/if}

                {if !$ifirma_receipt || $ifirma_state == 'not_issued'}
                    <p class="text-muted">{l s='No iFirma document has been issued for this order yet.' mod='ifirma'}</p>
                {/if}
            {/if}

            {* ---- actions ---- *}
            <div class="ifirma-actions">
                {if $ifirma_can_create && $ifirma_can_issue}
                    <button type="button" class="btn btn-primary btn-lg"
                            id="ifirma-create-receipt"
                            data-id-order="{$ifirma_id_order|intval}">
                        <i class="icon-plus-sign"></i> {l s='Create Receipt' mod='ifirma'}
                    </button>
                {/if}

                {if $ifirma_can_issue_anyway && $ifirma_can_issue}
                    <button type="button" class="btn btn-warning"
                            id="ifirma-issue-anyway"
                            data-id-order="{$ifirma_id_order|intval}">
                        <i class="icon-warning-sign"></i> {l s='Issue anyway' mod='ifirma'}
                    </button>
                {/if}

                {if $ifirma_is_issued}
                    <a class="btn btn-default"
                       href="{$ifirma_ajax_url|escape:'html':'UTF-8'}&amp;action=DownloadPdf&amp;ajax=1&amp;id_order={$ifirma_id_order|intval}"
                       target="_blank" rel="noopener">
                        <i class="icon-download"></i> {l s='Download PDF' mod='ifirma'}
                    </a>

                    {if $ifirma_can_manage}
                    <button type="button" class="btn btn-primary"
                            id="ifirma-update-status"
                            data-id-order="{$ifirma_id_order|intval}">
                        <i class="icon-refresh"></i> {l s='Update status in iFirma (register payment)' mod='ifirma'}
                    </button>
                    {/if}

                    {if $ifirma_can_manage && $ifirma_ksef_waiting}
                    <span class="text-warning" id="ifirma-resend-warning">{l s='Not yet accepted in KSeF.' mod='ifirma'}</span>
                    {/if}
                    {if $ifirma_can_manage}
                    <button type="button" class="btn btn-default"
                            id="ifirma-resend"
                            data-id-order="{$ifirma_id_order|intval}">
                        <i class="icon-envelope"></i> {l s='Send again' mod='ifirma'}
                    </button>
                    {/if}

                    {if $ifirma_can_manage}
                    <button type="button" class="btn btn-default"
                            id="ifirma-refresh"
                            data-id-order="{$ifirma_id_order|intval}">
                        <i class="icon-cloud-download"></i> {l s='Refresh from iFirma' mod='ifirma'}
                    </button>
                    {/if}

                    {if $ifirma_can_manage && !$ifirma_receipt->ksef_number && $ifirma_receipt->ksef_status != 'PRZYJETA'}
                    <button type="button" class="btn btn-default"
                            id="ifirma-ksef-send"
                            data-id-order="{$ifirma_id_order|intval}">
                        <i class="icon-upload"></i> {l s='Send to KSeF' mod='ifirma'}
                    </button>
                    {/if}
                {/if}

                {if $ifirma_configured}
                    {if $ifirma_can_issue}
                    <button type="button" class="btn btn-default"
                            id="ifirma-register-contractor"
                            data-id-order="{$ifirma_id_order|intval}">
                        <i class="icon-user"></i> {l s='Register contractor in iFirma' mod='ifirma'}
                    </button>
                    {/if}
                {/if}
            </div>
        </div>

        {* ---- billing data change request ---- *}
        {if $ifirma_pending_request}
            <hr>
            <div id="ifirma-request-panel">
                <h4>{l s='Billing data change request' mod='ifirma'} #{$ifirma_pending_request.id|intval}</h4>
                <p class="text-muted">
                    {l s='Request submitted on' mod='ifirma'} {$ifirma_pending_request.date_add|escape:'html':'UTF-8'}
                </p>
                {if $ifirma_pending_request.customer_note}
                    <p><strong>{l s='Note for the shop (optional)' mod='ifirma'}:</strong> {$ifirma_pending_request.customer_note|escape:'html':'UTF-8'}</p>
                {/if}

                {if $ifirma_request_diff}
                <table class="table" id="ifirma-request-diff">
                    <thead>
                        <tr>
                            <th>{l s='Field' mod='ifirma'}</th>
                            <th>{l s='Current' mod='ifirma'}</th>
                            <th>{l s='Requested' mod='ifirma'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$ifirma_request_diff item=change}
                        <tr>
                            <td>{$change.field|escape:'html':'UTF-8'}</td>
                            <td>{$change.current|escape:'html':'UTF-8'}</td>
                            <td><strong>{$change.requested|escape:'html':'UTF-8'}</strong></td>
                        </tr>
                        {/foreach}
                    </tbody>
                </table>
                {/if}

                <div class="form-horizontal" id="ifirma-request-form">
                    {foreach from=$ifirma_request_fields item=field}
                    <div class="form-group">
                        <label class="control-label col-lg-3" for="ifirma-req-{$field.name|escape:'html':'UTF-8'}">{$field.label|escape:'html':'UTF-8'}</label>
                        <div class="col-lg-6">
                            {if $field.type == 'checkbox'}
                                <input type="checkbox" class="ifirma-req-field" data-type="checkbox"
                                       id="ifirma-req-{$field.name|escape:'html':'UTF-8'}"
                                       data-field="{$field.name|escape:'html':'UTF-8'}" value="1"{if $field.value} checked="checked"{/if}>
                            {else}
                                <input type="text" class="form-control ifirma-req-field" data-type="text"
                                       id="ifirma-req-{$field.name|escape:'html':'UTF-8'}"
                                       data-field="{$field.name|escape:'html':'UTF-8'}"
                                       value="{$field.value|escape:'html':'UTF-8'}">
                            {/if}
                        </div>
                    </div>
                    {/foreach}
                    <div class="form-group">
                        <label class="control-label col-lg-3" for="ifirma-admin-note">{l s='Note to the customer (optional)' mod='ifirma'}</label>
                        <div class="col-lg-6">
                            <textarea class="form-control" id="ifirma-admin-note" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                {if $ifirma_can_manage}
                <button type="button" class="btn btn-success" id="ifirma-approve-request"
                        data-id-request="{$ifirma_pending_request.id|intval}">
                    <i class="icon-check"></i> {l s='Approve' mod='ifirma'}
                </button>
                <button type="button" class="btn btn-danger" id="ifirma-reject-request"
                        data-id-request="{$ifirma_pending_request.id|intval}">
                    <i class="icon-remove"></i> {l s='Reject' mod='ifirma'}
                </button>
                {/if}
            </div>
        {/if}

        {if $ifirma_request_history}
            <hr>
            <div id="ifirma-request-history">
                <h4>{l s='Request history' mod='ifirma'}</h4>
                <ul class="list-unstyled">
                    {foreach from=$ifirma_request_history item=entry}
                    <li>
                        <span class="label label-default">{$entry.status_label|escape:'html':'UTF-8'}</span>
                        #{$entry.id|intval} - {$entry.date_add|escape:'html':'UTF-8'}
                        {if $entry.date_resolved}
                            &rarr; {$entry.date_resolved|escape:'html':'UTF-8'}
                            {if $entry.employee}({$entry.employee|escape:'html':'UTF-8'}){/if}
                        {/if}
                        {if $entry.admin_note}
                            <br><span class="text-muted">{l s='Admin note:' mod='ifirma'} {$entry.admin_note|escape:'html':'UTF-8'}</span>
                        {/if}
                        {if isset($entry.accepted) && $entry.accepted}
                        <table class="table ifirma-request-accepted">
                            <thead>
                                <tr>
                                    <th>{l s='Field' mod='ifirma'}</th>
                                    <th>{l s='Current' mod='ifirma'}</th>
                                    <th>{l s='Requested' mod='ifirma'}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach from=$entry.accepted item=change}
                                <tr>
                                    <td>{$change.field|escape:'html':'UTF-8'}</td>
                                    <td>{$change.current|escape:'html':'UTF-8'}</td>
                                    <td><strong>{$change.requested|escape:'html':'UTF-8'}</strong></td>
                                </tr>
                                {/foreach}
                            </tbody>
                        </table>
                        {/if}
                    </li>
                    {/foreach}
                </ul>
            </div>
        {/if}
    {/if}
    </div>
</div>

<script type="text/javascript">
(function () {
    var ifirmaAjaxUrl = "{$ifirma_ajax_url|escape:'javascript':'UTF-8'}";

    function showAlert(type, message) {
        var $box = $('#ifirma-alert');
        $box.removeClass('alert-success alert-danger alert-info')
            .addClass('alert-' + type)
            .text(message)
            .show();
    }

    function busy($btn, isBusy, busyLabel) {
        if (isBusy) {
            $btn.data('label', $btn.html()).prop('disabled', true)
                .html('<i class="icon-refresh icon-spin"></i> ' + busyLabel);
        } else {
            $btn.prop('disabled', false).html($btn.data('label'));
        }
    }

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 900);
    }

    /**
     * POSTs an action; reloadOnSuccess reloads the page so the panel is rendered from fresh data.
     */
    function run($btn, action, payload, busyLabel, reloadOnSuccess) {
        busy($btn, true, busyLabel);
        $.ajax({
            url: ifirmaAjaxUrl + '&action=' + action + '&ajax=1',
            type: 'POST',
            dataType: 'json',
            data: payload
        }).done(function (resp) {
            if (resp && resp.success) {
                showAlert('success', resp.message);
                if (reloadOnSuccess) {
                    reloadSoon();
                } else {
                    busy($btn, false);
                }
            } else {
                showAlert('danger', (resp && resp.message) ? resp.message : "{l s='Error' mod='ifirma' js=1}");
                busy($btn, false);
                if (resp && resp.data && resp.data.outcome) {
                    reloadSoon();
                }
            }
        }).fail(function () {
            showAlert('danger', "{l s='Request failed. Check the browser console and PrestaShop logs.' mod='ifirma' js=1}");
            busy($btn, false);
        });
    }

    $(document).on('click', '#ifirma-create-receipt', function (e) {
        e.preventDefault();
        var $btn = $(this);
        run($btn, 'CreateReceipt', { id_order: $btn.data('id-order') }, "{l s='Issuing…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '#ifirma-issue-anyway', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if (!window.confirm("{l s='Issue a new document although a similar one exists in iFirma?' mod='ifirma' js=1}")) {
            return;
        }
        run($btn, 'IssueAnyway', { id_order: $btn.data('id-order') }, "{l s='Issuing…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '.ifirma-link-candidate', function (e) {
        e.preventDefault();
        var $btn = $(this);
        run($btn, 'LinkCandidate', {
            id_order: $btn.data('id-order'),
            faktura_id: $btn.data('faktura-id')
        }, "{l s='Linking…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '#ifirma-update-status', function (e) {
        e.preventDefault();
        var $btn = $(this);
        run($btn, 'UpdateStatus', { id_order: $btn.data('id-order') }, "{l s='Updating…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '#ifirma-resend', function (e) {
        e.preventDefault();
        var $btn = $(this);
        run($btn, 'ResendDocument', { id_order: $btn.data('id-order') }, "{l s='Sending…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '#ifirma-refresh', function (e) {
        e.preventDefault();
        var $btn = $(this);
        run($btn, 'RefreshStatus', { id_order: $btn.data('id-order') }, "{l s='Refreshing…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '#ifirma-ksef-send', function (e) {
        e.preventDefault();
        var $btn = $(this);
        if (!window.confirm("{l s='A submission to KSeF cannot be withdrawn. Send the document now?' mod='ifirma' js=1}")) {
            return;
        }
        run($btn, 'SendToKsef', { id_order: $btn.data('id-order') }, "{l s='Sending…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '#ifirma-register-contractor', function (e) {
        e.preventDefault();
        var $btn = $(this);
        run($btn, 'RegisterContractor', { id_order: $btn.data('id-order') }, "{l s='Registering…' mod='ifirma' js=1}", false);
    });

    $(document).on('click', '#ifirma-approve-request', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var data = {};
        $('#ifirma-request-form .ifirma-req-field').each(function () {
            var $f = $(this);
            data[$f.data('field')] = $f.data('type') === 'checkbox' ? ($f.is(':checked') ? '1' : '0') : $f.val();
        });
        run($btn, 'ApproveRequest', {
            id_request: $btn.data('id-request'),
            data: data,
            admin_note: $('#ifirma-admin-note').val()
        }, "{l s='Approving…' mod='ifirma' js=1}", true);
    });

    $(document).on('click', '#ifirma-reject-request', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var reason = window.prompt("{l s='Reason for rejection (sent to the customer):' mod='ifirma' js=1}", '');
        if (reason === null || $.trim(reason) === '') {
            showAlert('danger', "{l s='A reason is required to reject the request.' mod='ifirma' js=1}");
            return;
        }
        run($btn, 'RejectRequest', {
            id_request: $btn.data('id-request'),
            reason: reason
        }, "{l s='Rejecting…' mod='ifirma' js=1}", true);
    });
})();
</script>
