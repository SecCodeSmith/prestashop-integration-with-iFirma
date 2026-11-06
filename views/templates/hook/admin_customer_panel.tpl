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
 * iFirma module - admin customer page panel (hook displayAdminCustomers).
 * Server-rendered part: link state, local document counts and the pre-filled search form.
 * Everything that talks to iFirma runs afterwards from the inline script through the
 * AdminIfirma controller (check on load; count, search, link, unlink and register on click).
 * Values coming from iFirma are only ever written with jQuery .text(), never as HTML.
 *}
<link rel="stylesheet" href="{$ifirma_customer_css_url|escape:'html':'UTF-8'}" type="text/css" media="all">
<div class="col-12">

<div class="card ifirma-cp" id="ifirma-cp" data-id-customer="{$ifirma_customer_id|intval}">
    <div class="card-header">
        <h3 class="card-header-title">
            <i class="material-icons">receipt_long</i> {l s='iFirma' mod='ifirma'}
        </h3>
    </div>
    <div class="card-body">

    <div id="ifirma-cp-alert" class="alert" style="display:none;"></div>

    {if !$ifirma_customer_configured}
        <div class="alert alert-warning">
            {l s='The iFirma module is not configured. Set your login and API key in the module settings.' mod='ifirma'}
        </div>
    {/if}

    {* ---- contractor link state ---- *}
    <table class="table ifirma-cp-table" id="ifirma-cp-link">
        <tbody>
            <tr>
                <th>{l s='Identifier' mod='ifirma'}</th>
                <td><code id="ifirma-cp-identifier">{$ifirma_customer_link.identifier|escape:'html':'UTF-8'}</code></td>
            </tr>
            <tr>
                <th>{l s='Name' mod='ifirma'}</th>
                <td id="ifirma-cp-name">{if $ifirma_customer_link.linked}{$ifirma_customer_link.name|escape:'html':'UTF-8'}{/if}</td>
            </tr>
            <tr>
                <th>{l s='Link type' mod='ifirma'}</th>
                <td id="ifirma-cp-linktype">{if $ifirma_customer_link.linked}{$ifirma_customer_match_label|escape:'html':'UTF-8'}{else}{l s='Not linked to an existing contractor.' mod='ifirma'}{/if}</td>
            </tr>
            {if $ifirma_customer_configured}
            <tr>
                <th>{l s='In iFirma' mod='ifirma'}</th>
                <td id="ifirma-cp-check">{l s='Checking…' mod='ifirma'}</td>
            </tr>
            {/if}
        </tbody>
    </table>
    <p>
        <button type="button" class="btn btn-default btn-sm" id="ifirma-cp-unlink"{if !$ifirma_customer_link.linked} style="display:none;"{/if}{if !$ifirma_customer_configured} disabled="disabled"{/if}>
            {l s='Unlink' mod='ifirma'}
        </button>
    </p>

    {* ---- documents recorded by the module (no API call) ---- *}
    <h4>{l s='Documents recorded by the module' mod='ifirma'}</h4>
    <table class="table ifirma-cp-table" id="ifirma-cp-counts">
        <tbody>
            <tr><th>{l s='Issued' mod='ifirma'}</th><td>{$ifirma_customer_counts.issued|intval}</td></tr>
            <tr><th>{l s='Receipts' mod='ifirma'}</th><td>{$ifirma_customer_counts.by_type.receipt|intval}</td></tr>
            <tr><th>{l s='Domestic invoices' mod='ifirma'}</th><td>{$ifirma_customer_counts.by_type.invoice|intval}</td></tr>
            <tr><th>{l s='Other' mod='ifirma'}</th><td>{$ifirma_customer_counts.by_type.other|intval}</td></tr>
            <tr><th>{l s='Paid' mod='ifirma'}</th><td>{$ifirma_customer_counts.paid|intval}</td></tr>
            <tr><th>{l s='Held' mod='ifirma'}</th><td>{$ifirma_customer_counts.held|intval}</td></tr>
            <tr><th>{l s='Needs attention' mod='ifirma'}</th><td>{$ifirma_customer_counts.attention|intval}</td></tr>
        </tbody>
    </table>

    {if $ifirma_customer_configured}
        <p>
            <button type="button" class="btn btn-default" id="ifirma-cp-count">{l s='Count in iFirma' mod='ifirma'}</button>
            <button type="button" class="btn btn-default" id="ifirma-cp-toggle">{l s='Find or register a contractor' mod='ifirma'}</button>
        </p>
        <p id="ifirma-cp-remote" class="ifirma-cp-remote"></p>

        {* ---- contractor search and registration ---- *}
        <div id="ifirma-cp-tools" class="ifirma-cp-tools" style="display:none;">
            <h4>{l s='Find an existing contractor' mod='ifirma'}</h4>
            <p class="help-block text-muted">
                {l s='iFirma searches contractors by name, company or NIP. E-mail and phone only confirm the results.' mod='ifirma'}
            </p>
            <div class="row" id="ifirma-cp-search">
                <div class="col-md-4 form-group">
                    <label for="ifirma-cp-f-firstname">{l s='First name' mod='ifirma'}</label>
                    <input type="text" class="form-control" id="ifirma-cp-f-firstname" data-criterion="firstname" value="{$ifirma_customer_criteria.firstname|escape:'html':'UTF-8'}" maxlength="128">
                </div>
                <div class="col-md-4 form-group">
                    <label for="ifirma-cp-f-lastname">{l s='Last name' mod='ifirma'}</label>
                    <input type="text" class="form-control" id="ifirma-cp-f-lastname" data-criterion="lastname" value="{$ifirma_customer_criteria.lastname|escape:'html':'UTF-8'}" maxlength="128">
                </div>
                <div class="col-md-4 form-group">
                    <label for="ifirma-cp-f-company">{l s='Company' mod='ifirma'}</label>
                    <input type="text" class="form-control" id="ifirma-cp-f-company" data-criterion="company" value="{$ifirma_customer_criteria.company|escape:'html':'UTF-8'}" maxlength="255">
                </div>
                <div class="col-md-4 form-group">
                    <label for="ifirma-cp-f-nip">{l s='NIP' mod='ifirma'}</label>
                    <input type="text" class="form-control" id="ifirma-cp-f-nip" data-criterion="nip" value="{$ifirma_customer_criteria.nip|escape:'html':'UTF-8'}" maxlength="32">
                </div>
                <div class="col-md-4 form-group">
                    <label for="ifirma-cp-f-email">{l s='E-mail' mod='ifirma'}</label>
                    <input type="text" class="form-control" id="ifirma-cp-f-email" data-criterion="email" value="{$ifirma_customer_criteria.email|escape:'html':'UTF-8'}" maxlength="255">
                </div>
                <div class="col-md-4 form-group">
                    <label for="ifirma-cp-f-phone">{l s='Phone' mod='ifirma'}</label>
                    <input type="text" class="form-control" id="ifirma-cp-f-phone" data-criterion="phone" value="{$ifirma_customer_criteria.phone|escape:'html':'UTF-8'}" maxlength="64">
                </div>
            </div>
            <p>
                <button type="button" class="btn btn-primary" id="ifirma-cp-search-btn">{l s='Search' mod='ifirma'}</button>
            </p>
            <div id="ifirma-cp-results" class="table-responsive"></div>

            <h4>{l s='Register as a new contractor' mod='ifirma'}</h4>
            {if $ifirma_customer_can_register}
                <p>
                    <button type="button" class="btn btn-default" id="ifirma-cp-register">{l s='Register in iFirma' mod='ifirma'}</button>
                </p>
            {else}
                <p class="text-muted" id="ifirma-cp-register-reason">{$ifirma_customer_register_reason|escape:'html':'UTF-8'}</p>
            {/if}
        </div>
    {/if}

    </div>
</div>

{if $ifirma_customer_configured}
<script type="text/javascript">
(function () {
    var tries = 0;

    function boot() {
        if (window.jQuery) {
            init(window.jQuery);
        } else if (tries++ < 100) {
            setTimeout(boot, 100);
        }
    }

    function init($) {
        var $panel = $('#ifirma-cp');
        if (!$panel.length) {
            return;
        }
        var cfg = {
            url: "{$ifirma_customer_ajax_url|escape:'javascript':'UTF-8'}",
            id: {$ifirma_customer_id|intval},
            labels: JSON.parse("{$ifirma_customer_match_labels_json|escape:'javascript':'UTF-8'}")
        };
        var t = {
            error: "{l s='Error' mod='ifirma' js=1}",
            failed: "{l s='Request failed. Check the browser console and PrestaShop logs.' mod='ifirma' js=1}",
            checking: "{l s='Checking…' mod='ifirma' js=1}",
            notLinked: "{l s='Not linked to an existing contractor.' mod='ifirma' js=1}",
            counting: "{l s='Counting…' mod='ifirma' js=1}",
            searching: "{l s='Searching…' mod='ifirma' js=1}",
            linking: "{l s='Linking…' mod='ifirma' js=1}",
            unlinking: "{l s='Unlinking…' mod='ifirma' js=1}",
            registering: "{l s='Registering…' mod='ifirma' js=1}",
            link: "{l s='Link' mod='ifirma' js=1}",
            receipts: "{l s='Receipts' mod='ifirma' js=1}",
            invoices: "{l s='Domestic invoices' mod='ifirma' js=1}",
            other: "{l s='Other' mod='ifirma' js=1}",
            confirmLink: "{l s='Link this customer to the selected contractor? The next document for this customer will update the name, NIP, address and e-mail of that contractor in iFirma with the shop data.' mod='ifirma' js=1}",
            confirmUnlink: "{l s='Remove the link between this customer and the iFirma contractor? Nothing is changed in iFirma.' mod='ifirma' js=1}",
            cols: [
                "{l s='Identifier' mod='ifirma' js=1}",
                "{l s='Name' mod='ifirma' js=1}",
                "{l s='NIP' mod='ifirma' js=1}",
                "{l s='E-mail' mod='ifirma' js=1}",
                "{l s='Phone' mod='ifirma' js=1}",
                "{l s='Postcode' mod='ifirma' js=1}",
                "{l s='City' mod='ifirma' js=1}",
                "{l s='Matches' mod='ifirma' js=1}",
                ""
            ],
            flags: [
                ['nip', "{l s='NIP' mod='ifirma' js=1}"],
                ['email', "{l s='e-mail' mod='ifirma' js=1}"],
                ['phone', "{l s='phone' mod='ifirma' js=1}"],
                ['name', "{l s='name' mod='ifirma' js=1}"]
            ]
        };

        function showAlert(type, message) {
            $('#ifirma-cp-alert')
                .removeClass('alert-success alert-danger alert-info alert-warning')
                .addClass('alert-' + type)
                .text(message)
                .show();
        }

        function messageOf(resp) {
            return (resp && resp.message) ? String(resp.message) : t.error;
        }

        /**
         * POSTs one AdminIfirma action for this customer. The buttons stay disabled while it runs.
         * onDone receives every parsed JSON envelope (success or not), onFail transport failures.
         */
        function call(action, payload, $btn, busyLabel, onDone, onFail) {
            var data = $.extend({ id_customer: cfg.id }, payload || null);
            var label = null;
            if ($btn) {
                label = $btn.text();
                $btn.prop('disabled', true);
                if (busyLabel) {
                    $btn.text(busyLabel);
                }
            }
            $.ajax({
                url: cfg.url + '&action=' + action + '&ajax=1',
                type: 'POST',
                dataType: 'json',
                data: data
            }).done(function (resp) {
                onDone(resp || null);
            }).fail(function () {
                if (onFail) {
                    onFail();
                } else {
                    showAlert('danger', t.failed);
                }
            }).always(function () {
                if ($btn) {
                    $btn.prop('disabled', false).text(label);
                }
            });
        }

        function renderLink(state) {
            if (!state) {
                return;
            }
            var linked = !!state.linked;
            $('#ifirma-cp-identifier').text(state.identifier);
            $('#ifirma-cp-name').text(linked ? state.name : '');
            $('#ifirma-cp-linktype').text(linked ? (cfg.labels[state.matched_by] || state.matched_by) : t.notLinked);
            $('#ifirma-cp-unlink').toggle(linked);
        }

        function openTools(attention) {
            $('#ifirma-cp-tools').show();
            if (attention) {
                $('#ifirma-cp-tools').addClass('ifirma-cp-attention');
            }
        }

        function runCheck() {
            var $c = $('#ifirma-cp-check')
                .removeClass('ifirma-cp-ok ifirma-cp-warn ifirma-cp-err')
                .text(t.checking);
            call('CustomerCheck', null, null, '', function (resp) {
                var check = resp && resp.data ? resp.data.check : null;
                if (!check) {
                    $c.addClass('ifirma-cp-err').text(messageOf(resp));
                    return;
                }
                $c.text(check.label || messageOf(resp));
                if (check.state === 'registered') {
                    $c.addClass('ifirma-cp-ok');
                } else if (check.state === 'missing') {
                    $c.addClass('ifirma-cp-warn');
                    openTools(true);
                } else {
                    $c.addClass('ifirma-cp-err');
                }
            }, function () {
                $c.addClass('ifirma-cp-err').text(t.failed);
            });
        }

        function renderResults(search) {
            var $box = $('#ifirma-cp-results').empty();
            var rows = (search && search.rows) ? search.rows : [];
            if (!rows.length) {
                return;
            }
            var $head = $('<tr>');
            $.each(t.cols, function (i, label) {
                $head.append($('<th>').text(label));
            });
            var $body = $('<tbody>');
            $.each(rows, function (i, row) {
                var $tr = $('<tr>');
                $.each(['identifier', 'name', 'nip', 'email', 'phone', 'postcode', 'city'], function (j, key) {
                    $tr.append($('<td>').text(row[key] === null || row[key] === undefined ? '' : String(row[key])));
                });
                var $flags = $('<td>');
                var flags = row.flags || null;
                $.each(t.flags, function (j, pair) {
                    if (flags && flags[pair[0]]) {
                        $flags.append($('<span class="badge badge-success label label-success ifirma-cp-flag">').text(pair[1])).append(' ');
                    }
                });
                $tr.append($flags);
                $tr.append($('<td>').append(
                    $('<button type="button" class="btn btn-default btn-sm ifirma-cp-link">')
                        .attr('data-identifier', row.identifier === null || row.identifier === undefined ? '' : String(row.identifier))
                        .text(t.link)
                ));
                $body.append($tr);
            });
            $box.append($('<table class="table ifirma-cp-table">').append($('<thead>').append($head)).append($body));
        }

        function criteria() {
            var data = { };
            $('#ifirma-cp-search input[data-criterion]').each(function () {
                data['criteria[' + $(this).attr('data-criterion') + ']'] = $(this).val();
            });
            return data;
        }

        function runSearch() {
            var $btn = $('#ifirma-cp-search-btn');
            if ($btn.prop('disabled')) {
                return;
            }
            call('CustomerSearch', criteria(), $btn, t.searching, function (resp) {
                showAlert(resp && resp.success ? 'info' : 'danger', messageOf(resp));
                renderResults(resp && resp.data ? resp.data.search : null);
            });
        }

        $('#ifirma-cp-toggle').on('click', function (e) {
            e.preventDefault();
            $('#ifirma-cp-tools').toggle();
        });

        $('#ifirma-cp-count').on('click', function (e) {
            e.preventDefault();
            call('CustomerCountRemote', null, $(this), t.counting, function (resp) {
                showAlert(resp && resp.success ? 'info' : 'danger', messageOf(resp));
                var $out = $('#ifirma-cp-remote').text('');
                var count = resp && resp.data ? resp.data.count : null;
                if (count && count.ok && count.by_type) {
                    $out.text(
                        t.receipts + ': ' + parseInt(count.by_type.receipt || 0, 10) + ', '
                        + t.invoices + ': ' + parseInt(count.by_type.invoice || 0, 10) + ', '
                        + t.other + ': ' + parseInt(count.by_type.other || 0, 10)
                    );
                }
            });
        });

        $('#ifirma-cp-search').on('keydown', 'input', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                runSearch();
            }
        });
        $('#ifirma-cp-search-btn').on('click', function (e) {
            e.preventDefault();
            runSearch();
        });

        $panel.on('click', '.ifirma-cp-link', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var identifier = $btn.attr('data-identifier');
            if (!window.confirm(identifier + "\n\n" + t.confirmLink)) {
                return;
            }
            call('CustomerLink', { identifier: identifier }, $btn, t.linking, function (resp) {
                showAlert(resp && resp.success ? 'success' : 'danger', messageOf(resp));
                if (resp && resp.data && resp.data.link_state) {
                    renderLink(resp.data.link_state);
                }
                if (resp && resp.success) {
                    runCheck();
                }
            });
        });

        $('#ifirma-cp-unlink').on('click', function (e) {
            e.preventDefault();
            if (!window.confirm(t.confirmUnlink)) {
                return;
            }
            call('CustomerUnlink', null, $(this), t.unlinking, function (resp) {
                showAlert(resp && resp.success ? 'success' : 'danger', messageOf(resp));
                if (resp && resp.data && resp.data.link_state) {
                    renderLink(resp.data.link_state);
                }
                if (resp && resp.success) {
                    runCheck();
                }
            });
        });

        $('#ifirma-cp-register').on('click', function (e) {
            e.preventDefault();
            call('CustomerRegister', null, $(this), t.registering, function (resp) {
                showAlert(resp && resp.success ? 'success' : 'danger', messageOf(resp));
                if (resp && resp.data && resp.data.link_state) {
                    renderLink(resp.data.link_state);
                }
                if (resp && resp.success) {
                    runCheck();
                }
            });
        });

        // the one registration check per page open
        runCheck();
    }

    boot();
})();
</script>
{/if}
</div>
