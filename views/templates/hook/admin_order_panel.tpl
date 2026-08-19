{*
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.2
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 *}

{*
 * iFirma module – admin order panel.
 * Feature 3: "Create Receipt" button.  Feature 2: "Update status" button.
 * Feature 1: link to fetch the issued PDF back from iFirma.
 *}
<div class="panel" id="ifirma-panel">
    <div class="panel-heading">
        <i class="icon-file-text-o"></i> {l s='iFirma' mod='ifirma'}
    </div>

    {if !$ifirma_configured}
        <div class="alert alert-warning">
            {l s='The iFirma module is not configured. Set your login and API key in the module settings.' mod='ifirma'}
        </div>
    {else}
        <div id="ifirma-alert" class="alert" style="display:none;"></div>

        <div id="ifirma-state">
            {if $ifirma_is_issued}
                <table class="table">
                    <tbody>
                        <tr>
                            <th style="width:35%">{l s='Document number' mod='ifirma'}</th>
                            <td>
                                <span id="ifirma-number">{$ifirma_receipt->ifirma_number|default:$ifirma_receipt->ifirma_id|escape:'html':'UTF-8'}</span>
                                <span class="text-muted">(ID: {$ifirma_receipt->ifirma_id|escape:'html':'UTF-8'})</span>
                            </td>
                        </tr>
                        <tr>
                            <th>{l s='Status' mod='ifirma'}</th>
                            <td>
                                <span id="ifirma-status" class="label {if $ifirma_receipt->status == 'paid'}label-success{else}label-info{/if}">
                                    {$ifirma_receipt->status|escape:'html':'UTF-8'}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <a class="btn btn-default"
                   href="{$ifirma_ajax_url|escape:'html':'UTF-8'}&action=DownloadPdf&ajax=1&id_order={$ifirma_id_order|intval}"
                   target="_blank" rel="noopener">
                    <i class="icon-download"></i> {l s='Download PDF' mod='ifirma'}
                </a>

                <button type="button" class="btn btn-primary"
                        id="ifirma-update-status"
                        data-id-order="{$ifirma_id_order|intval}">
                    <i class="icon-refresh"></i> {l s='Update status in iFirma (register payment)' mod='ifirma'}
                </button>
            {else}
                {if $ifirma_receipt && $ifirma_receipt->last_error}
                    <div class="alert alert-danger">
                        {l s='Last attempt failed:' mod='ifirma'} {$ifirma_receipt->last_error|escape:'html':'UTF-8'}
                    </div>
                {/if}
                <p class="text-muted">{l s='No iFirma document has been issued for this order yet.' mod='ifirma'}</p>

                <button type="button" class="btn btn-primary btn-lg"
                        id="ifirma-create-receipt"
                        data-id-order="{$ifirma_id_order|intval}">
                    <i class="icon-plus-sign"></i> {l s='Create Receipt' mod='ifirma'}
                </button>
            {/if}
        </div>
    {/if}
</div>

<script type="text/javascript">
(function () {
    var ifirmaAjaxUrl = "{$ifirma_ajax_url|escape:'javascript':'UTF-8'}";

    function showAlert(type, message) {
        var $box = $('#ifirma-alert');
        $box.removeClass('alert-success alert-danger alert-info')
            .addClass('alert-' + type)
            .html(message)
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

    $(document).on('click', '#ifirma-create-receipt', function (e) {
        e.preventDefault();
        var $btn = $(this);
        busy($btn, true, "{l s='Issuing…' mod='ifirma' js=1}");

        $.ajax({
            url: ifirmaAjaxUrl + '&action=CreateReceipt&ajax=1',
            type: 'POST',
            dataType: 'json',
            data: { id_order: $btn.data('id-order') }
        }).done(function (resp) {
            if (resp && resp.success) {
                showAlert('success', resp.message);
                setTimeout(function () { window.location.reload(); }, 900);
            } else {
                showAlert('danger', (resp && resp.message) ? resp.message : 'Error');
                busy($btn, false);
            }
        }).fail(function () {
            showAlert('danger', "{l s='Request failed. Check the browser console and PrestaShop logs.' mod='ifirma' js=1}");
            busy($btn, false);
        });
    });

    $(document).on('click', '#ifirma-update-status', function (e) {
        e.preventDefault();
        var $btn = $(this);
        busy($btn, true, "{l s='Updating…' mod='ifirma' js=1}");

        $.ajax({
            url: ifirmaAjaxUrl + '&action=UpdateStatus&ajax=1',
            type: 'POST',
            dataType: 'json',
            data: { id_order: $btn.data('id-order') }
        }).done(function (resp) {
            if (resp && resp.success) {
                showAlert('success', resp.message);
                $('#ifirma-status').removeClass('label-info').addClass('label-success').text(resp.receipt.status);
                busy($btn, false);
            } else {
                showAlert('danger', (resp && resp.message) ? resp.message : 'Error');
                busy($btn, false);
            }
        }).fail(function () {
            showAlert('danger', "{l s='Request failed. Check the browser console and PrestaShop logs.' mod='ifirma' js=1}");
            busy($btn, false);
        });
    });
})();
</script>
