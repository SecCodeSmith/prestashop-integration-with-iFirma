{*
 * SCM iFirma integration module for PrestaShop.
 *
 * @author    SCM Jakub Berechowski <admin@seccodesmith.pl>
 * @copyright 2026 SCM Jakub Berechowski
 * @license   SecCodeSmith Commercial License v1.3
 * SPDX-License-Identifier: LicenseRef-SecCodeSmith-Commercial-1.2
 * See the LICENCE file distributed with this module for the full terms.
 *}

{extends file=$layout}

{block name='content'}
<section id="ifirma-data-request" class="card card-block">
  <h1 class="h2">{l s='Change invoice data' mod='ifirma'}</h1>
  <p>{l s='Order' mod='ifirma'}: <strong>{$ifirma_order->reference|escape:'html':'UTF-8'}</strong></p>

  {if $ifirma_errors}
    <div class="alert alert-danger" role="alert">
      <ul>
        {foreach from=$ifirma_errors item=ifirma_error}
          <li>{$ifirma_error|escape:'html':'UTF-8'}</li>
        {/foreach}
      </ul>
    </div>
  {/if}

  {if $ifirma_pending}
    <div class="alert alert-info" role="status">
      {l s='Your change request is awaiting review by the shop. The document will be issued after it is resolved.' mod='ifirma'}
    </div>
    <dl>
      {foreach from=$ifirma_pending.requested_data key=ifirma_field item=ifirma_value}
        {if $ifirma_value !== '' && $ifirma_value !== false && $ifirma_value !== 0}
          <dt>{$ifirma_field|escape:'html':'UTF-8'}</dt>
          <dd>{$ifirma_value|escape:'html':'UTF-8'}</dd>
        {/if}
      {/foreach}
    </dl>
    <form method="post" action="{$ifirma_form_action|escape:'html':'UTF-8'}">
      <input type="hidden" name="token" value="{$ifirma_token|escape:'html':'UTF-8'}">
      <input type="hidden" name="id_order" value="{$ifirma_order->id|intval}">
      <button type="submit" name="cancelIfirmaDataRequest" value="1" class="btn btn-secondary">
        {l s='Cancel request' mod='ifirma'}
      </button>
    </form>
  {elseif !$ifirma_eligibility.allowed}
    <div class="alert alert-warning" role="alert">
      {$ifirma_reason_message|escape:'html':'UTF-8'}
    </div>
  {else}
    <form method="post" action="{$ifirma_form_action|escape:'html':'UTF-8'}" class="form-horizontal">
      <input type="hidden" name="token" value="{$ifirma_token|escape:'html':'UTF-8'}">
      <input type="hidden" name="id_order" value="{$ifirma_order->id|intval}">

      {include file='module:ifirma/views/templates/front/_datarequest_fields.tpl'}

      <button type="submit" name="submitIfirmaDataRequest" value="1" class="btn btn-primary">
        {l s='Send request' mod='ifirma'}
      </button>
    </form>
  {/if}

  <p><a href="{$ifirma_order_link|escape:'html':'UTF-8'}">{l s='Back to the order' mod='ifirma'}</a></p>
</section>
{/block}
