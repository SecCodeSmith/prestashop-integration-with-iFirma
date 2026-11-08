# Upgrading

What to do when you upload a newer module over an installed one.

[Back to README](../README.md)

## Upgrading from 1.0.x

Upload the 1.1.0 module over the existing one and run the upgrade from the
Module Manager. `upgrade/upgrade-1.1.0.php` adds the new `ps_ifirma_receipt`
columns (each only if it is not already present), creates
`ps_ifirma_data_request` if missing, and sets every new configuration key that
has no value yet. It never overwrites an existing setting and is idempotent, so
running it again is harmless. Existing receipts keep their data and get
`source = module` from the column default.

After upgrading, review these settings: the **delivery channel**
(`IFIRMA_SEND_CHANNEL`, default `shop_mail`; set it to `none` to keep the 1.0
behaviour of not sending anything), the **data-request switch**
(`IFIRMA_ALLOW_DATA_REQUEST`, default on) and the **cron token / schedule**
(`IFIRMA_CRON_TOKEN` is generated automatically; point your scheduler at the
synchronisation URL at the interval set in `IFIRMA_SYNC_INTERVAL_MIN`).

## Upgrading to 1.2.0

Upload the 1.2.0 module over the existing one and run the upgrade from the Module Manager.
There is no schema or configuration change. `upgrade/upgrade-1.2.0.php` registers the
`displayAdminCustomers` hook, which shows the iFirma panel on the back-office customer page, and
unregisters the legacy `displayAdminOrder` hook.

1.2.0 drops PrestaShop 1.7: the minimum version is 8.0.0. Shops still on 1.7 stay on 1.1.x.

## Upgrading to 1.4.0

Upload the 1.4.0 module over the existing one and run the upgrade from the Module Manager.
There is no schema or configuration change. `upgrade/upgrade-1.4.0.php` registers the three
order-grid hooks (`actionOrderGridDefinitionModifier`, `actionOrderGridQueryBuilderModifier` and
`actionOrderGridPresenterModifier`) that add the iFirma column to the Orders list, and it can be run
more than once. PrestaShop runs an upgrade file only when its version is not above the module's, so
this release must be published as a `minor` version bump: a patch release would leave the hooks
unregistered on upgraded shops.

## Upgrading to 1.4.1

No upgrade step is needed and there is no schema or configuration change: the release only sped up the Orders list query.

## Upgrading to 1.5.0

Upload the 1.5.0 module over the existing one and run the upgrade from the Module Manager.
`upgrade/upgrade-1.5.0.php` makes these changes and can be run more than once; a second run changes nothing.

- **Tab.** The iFirma tab becomes a menu entry, *Orders > iFirma documents* (*Dokumenty iFirma* in a Polish back office). It is the same tab row as
  before, updated in place, so its id and its permission rows (`ps_access`) are kept. A shop without an
  Orders menu keeps the tab hidden, as before.
- **Hook.** `actionAdminControllerSetMedia` is registered, once, when it is missing.
- **Schema.** The receipt table gets the `last_download_at` column (`DATETIME NULL`, the time of the last
  PDF download) and the index `idx_date_upd` on `date_upd`. Each is added only if it is not there yet.
- **Configuration.** `IFIRMA_AUTH_FAILURE` is created empty when it has no value yet.

This release must be published as a `minor` version bump, as 1.4.0 was: PrestaShop runs an upgrade file
only when its version is not above the module's, so a patch release would leave the tab and the hook
unchanged on upgraded shops.

**Behaviour change: check the permissions after the upgrade.** Employees of profiles without the View
permission on the iFirma tab no longer see the iFirma panel on the order page, the iFirma column on the
Orders list or the iFirma panel on the customer page. The panels come back when an administrator grants
the permissions on *Team > Permissions > Orders > iFirma documents*: View lets an employee see the
documents, Add lets them issue a document, and Edit lets them manage it (retry, send again). On the same
page, under *Modules*, the profile also needs the iFirma module's own View permission: PrestaShop runs a
module's back-office hooks only for profiles that may view the module, so without it the panels stay hidden
whatever the tab permissions say.

## Upgrading to 1.6.0

**Behaviour change: PDF file names.** The PDF of a document is now named `<shop-domain>-<number>.pdf`
instead of `ifirma-<number>.pdf`, both when it is downloaded (by the customer or in the back office) and
when it is attached to the e-mail of the shop-mail delivery. The shop domain is the host of the shop
webpage set in the module settings (lower case, without a leading `www.`), or the shop's own domain when
none is set; every character outside `0-9 A-Z a-z . _ -` becomes `_`. For example, document `FV 7/2026` of
the shop `https://www.Hialumed.pl/` is `hialumed.pl-FV_7_2026.pdf`. Mail filters, scripts or archive
rules that match the old `ifirma-` name must be changed.
