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

## Upgrading to 1.8.0

This release must be published as a `minor` version bump: PrestaShop runs `upgrade/upgrade-1.8.0.php` only when the module's version is above the one installed. The script adds five settings when they are missing and changes no row of any table: nothing becomes queued or awaiting KSeF retroactively, and a document issued before the upgrade is delivered as it was.

New settings (all editable on the settings page except the two internal counters):

| Setting | Default | Meaning |
|---|---|---|
| `IFIRMA_RATE_MAX_PER_DAY` | `14000` | The day cap of the local rate guard, 1 to 15,000 (iFirma's own limit); see [Rate limits](configuration.md#rate-limits). |
| `IFIRMA_KSEF_SUBMITTER` | `module` | Who submits invoices to KSeF: `module` or `ifirma`. Choose `ifirma` when your iFirma account submits API invoices to KSeF on its own. |
| `IFIRMA_CRON_MAX_ITEMS` | `10` | How many queued orders, KSeF submissions and deliveries each cron job handles per run, 1 to 50. |
| `IFIRMA_RATE_DAY_WINDOW`, `IFIRMA_RATE_BACKOFF_UNTIL` | empty | Internal: the day counter and the end of a back-off after iFirma's limit answer. |

**Behaviour change: the cron endpoint does more.** `/module/ifirma/cron` now issues queued orders, retries deferred KSeF submissions, synchronises statuses and delivers documents accepted by KSeF, in that order and under a run lock. Its JSON keeps its existing keys and gains `queue`, `ksef`, `delivery`, `rate_limited` and `rate_limited_window`. Call it every 1 to 5 minutes: queued orders and documents that wait for KSeF are processed there. See [Cron jobs](configuration.md#cron-jobs).

**Behaviour change: orders are queued first.** Every order that reaches the trigger status gets a record in the state `queued` before any request to iFirma, and an issuance refused by the rate limit stays queued instead of failing or leaving no trace. See [Queued issuance](usage.md#queued-issuance).

**Behaviour change in KSeF modes `b2b` and `all`: delivery after acceptance.** An invoice that goes to KSeF is no longer e-mailed to the customer at issuance. Its delivery state is `awaiting_ksef`, the customer's order page shows no download link, and the cron delivers it after KSeF accepted it (**Send again** delivers at once). Receipts, invoices to private buyers in `b2b` mode and every document in mode `off` are delivered at issuance as before. Check that the cron endpoint is called regularly before you upgrade, or B2B invoices are not delivered. See [KSeF](usage.md#ksef-e-invoicing).

**Downgrading.** The extra configuration keys are harmless in 1.7.0. A record in the state `queued` or with the delivery state `awaiting_ksef` is unknown to the older version: before a downgrade, issue the queued orders (bulk **Retry issuance**) and deliver the waiting documents (**Send again**).
