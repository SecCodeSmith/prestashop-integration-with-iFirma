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
<section id="ifirma-guest-request" class="card card-block">
  <h1 class="h2">{l s='Change invoice data' mod='ifirma'}</h1>

  {if $ifirma_errors}
    <div class="alert alert-danger" role="alert">
      <ul>
        {foreach from=$ifirma_errors item=ifirma_error}
          <li>{$ifirma_error|escape:'html':'UTF-8'}</li>
        {/foreach}
      </ul>
    </div>
  {/if}

  {if $ifirma_notice}
    <div class="alert alert-info" role="status">{$ifirma_notice|escape:'html':'UTF-8'}</div>
  {/if}

  {if $ifirma_step == 'disabled'}
    <div class="alert alert-warning" role="alert">
      {$ifirma_reason_message|escape:'html':'UTF-8'}
    </div>

  {elseif $ifirma_step == 'done'}
    <p>{l s='Order' mod='ifirma'}: <strong>{$ifirma_done_reference|escape:'html':'UTF-8'}</strong></p>
    <div class="alert alert-success" role="status">
      {if $ifirma_outcome == 'cancelled'}
        {l s='Your change request has been cancelled.' mod='ifirma'}
      {else}
        {l s='Your change request has been received. We will inform you by e-mail about the decision.' mod='ifirma'}
      {/if}
    </div>

  {elseif $ifirma_step == 'verify'}
    <p>{l s='Enter the six-digit code from the e-mail. It is valid for 15 minutes.' mod='ifirma'}</p>
    <form method="post" action="{$ifirma_form_action|escape:'html':'UTF-8'}" class="form-horizontal">
      <input type="hidden" name="token" value="{$ifirma_token|escape:'html':'UTF-8'}">
      <input type="hidden" name="reference" value="{$ifirma_reference|escape:'html':'UTF-8'}">
      <input type="hidden" name="email" value="{$ifirma_email|escape:'html':'UTF-8'}">
      <div class="form-group">
        <label for="ifirma-code">{l s='Code' mod='ifirma'}</label>
        <input type="text" class="form-control" id="ifirma-code" name="code" value="" inputmode="numeric" maxlength="6" autocomplete="one-time-code">
      </div>
      <button type="submit" name="verifyIfirmaGuest" value="1" class="btn btn-primary">
        {l s='Verify the code' mod='ifirma'}
      </button>
    </form>
    <form method="post" action="{$ifirma_form_action|escape:'html':'UTF-8'}">
      <input type="hidden" name="token" value="{$ifirma_token|escape:'html':'UTF-8'}">
      <input type="hidden" name="reference" value="{$ifirma_reference|escape:'html':'UTF-8'}">
      <input type="hidden" name="email" value="{$ifirma_email|escape:'html':'UTF-8'}">
      <button type="submit" name="identifyIfirmaGuest" value="1" class="btn btn-link">
        {l s='Send a new code' mod='ifirma'}
      </button>
    </form>

  {elseif $ifirma_step == 'form'}
    <p>{l s='Order' mod='ifirma'}: <strong>{$ifirma_order->reference|escape:'html':'UTF-8'}</strong></p>
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

  {else}
    <p>{l s='Enter the order reference and the e-mail address used for the order. We will send a code to that address.' mod='ifirma'}</p>
    <form method="post" action="{$ifirma_form_action|escape:'html':'UTF-8'}" class="form-horizontal">
      <input type="hidden" name="token" value="{$ifirma_token|escape:'html':'UTF-8'}">
      <div class="form-group">
        <label for="ifirma-reference">{l s='Order reference' mod='ifirma'}</label>
        <input type="text" class="form-control" id="ifirma-reference" name="reference" value="{$ifirma_reference|escape:'html':'UTF-8'}" maxlength="64">
      </div>
      <div class="form-group">
        <label for="ifirma-email">{l s='E-mail address' mod='ifirma'}</label>
        <input type="email" class="form-control" id="ifirma-email" name="email" value="{$ifirma_email|escape:'html':'UTF-8'}" maxlength="255">
      </div>
      <button type="submit" name="identifyIfirmaGuest" value="1" class="btn btn-primary">
        {l s='Send the code' mod='ifirma'}
      </button>
    </form>
  {/if}
</section>
{/block}
