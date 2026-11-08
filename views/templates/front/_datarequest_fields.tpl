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
 * The invoice-address fields shared by the account form (datarequest.tpl) and the guest form
 * (guestrequest.tpl), so the two cannot drift. Needs $ifirma_values, $ifirma_field_errors, $ifirma_countries.
 *}
<div class="form-group">
  <label>
    <input type="checkbox" name="want_invoice_for_company" value="1"{if $ifirma_values.want_invoice_for_company} checked{/if}>
    {l s='Invoice for a company' mod='ifirma'}
  </label>
</div>

{foreach from=['company', 'vat_number', 'firstname', 'lastname', 'address1', 'address2', 'postcode', 'city'] item=ifirma_name}
  <div class="form-group{if isset($ifirma_field_errors[$ifirma_name])} has-error{/if}">
    <label for="ifirma-{$ifirma_name|escape:'html':'UTF-8'}">
      {if $ifirma_name == 'company'}{l s='Company name' mod='ifirma'}
      {elseif $ifirma_name == 'vat_number'}{l s='VAT number (NIP)' mod='ifirma'}
      {elseif $ifirma_name == 'firstname'}{l s='First name' mod='ifirma'}
      {elseif $ifirma_name == 'lastname'}{l s='Last name' mod='ifirma'}
      {elseif $ifirma_name == 'address1'}{l s='Address' mod='ifirma'}
      {elseif $ifirma_name == 'address2'}{l s='Address (line 2)' mod='ifirma'}
      {elseif $ifirma_name == 'postcode'}{l s='Postcode' mod='ifirma'}
      {else}{l s='City' mod='ifirma'}{/if}
    </label>
    <input type="text" class="form-control" id="ifirma-{$ifirma_name|escape:'html':'UTF-8'}" name="{$ifirma_name|escape:'html':'UTF-8'}" value="{$ifirma_values[$ifirma_name]|escape:'html':'UTF-8'}">
    {if isset($ifirma_field_errors[$ifirma_name])}
      <span class="help-block text-danger">{$ifirma_field_errors[$ifirma_name]|escape:'html':'UTF-8'}</span>
    {/if}
  </div>
{/foreach}

<div class="form-group{if isset($ifirma_field_errors.id_country)} has-error{/if}">
  <label for="ifirma-id_country">{l s='Country' mod='ifirma'}</label>
  <select class="form-control" id="ifirma-id_country" name="id_country">
    {foreach from=$ifirma_countries item=ifirma_country}
      <option value="{$ifirma_country.id_country|intval}"{if $ifirma_country.id_country == $ifirma_values.id_country} selected{/if}>{$ifirma_country.name|escape:'html':'UTF-8'}</option>
    {/foreach}
  </select>
  {if isset($ifirma_field_errors.id_country)}
    <span class="help-block text-danger">{$ifirma_field_errors.id_country|escape:'html':'UTF-8'}</span>
  {/if}
</div>

<div class="form-group{if isset($ifirma_field_errors.customer_note)} has-error{/if}">
  <label for="ifirma-customer_note">{l s='Note for the shop (optional)' mod='ifirma'}</label>
  <textarea class="form-control" id="ifirma-customer_note" name="customer_note" rows="3" maxlength="1000">{$ifirma_values.customer_note|escape:'html':'UTF-8'}</textarea>
  {if isset($ifirma_field_errors.customer_note)}
    <span class="help-block text-danger">{$ifirma_field_errors.customer_note|escape:'html':'UTF-8'}</span>
  {/if}
</div>
