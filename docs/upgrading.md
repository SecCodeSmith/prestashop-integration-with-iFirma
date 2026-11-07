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
