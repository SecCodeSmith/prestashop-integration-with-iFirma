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

This release must be published as a `minor` version bump: PrestaShop runs `upgrade/upgrade-1.8.0.php` only when the module's version is above the one installed. The script adds six settings when they are missing and changes no row of any table: nothing becomes queued or awaiting KSeF retroactively, and a document issued before the upgrade is delivered as it was.

New settings (all editable on the settings page except the two internal counters):

| Setting | Default | Meaning |
|---|---|---|
| `IFIRMA_RATE_MAX_PER_DAY` | `14000` | The day cap of the local rate guard, 1 to 15,000 (iFirma's own limit); see [Rate limits](configuration.md#rate-limits). |
| `IFIRMA_KSEF_SUBMITTER` | `module` | Who submits invoices to KSeF: `module` or `ifirma`. Choose `ifirma` when your iFirma account submits API invoices to KSeF on its own. |
| `IFIRMA_CRON_MAX_ITEMS` | `10` | How many queued orders, KSeF submissions and deliveries each cron job handles per run, 1 to 50. |
| `IFIRMA_RATE_DAY_WINDOW`, `IFIRMA_RATE_BACKOFF_UNTIL`, `IFIRMA_REMOTE_LIMIT` | empty | Internal: the day counter, the end of a back-off after iFirma's limit answer, and the last reading of the day limit from iFirma. |

**Behaviour change: the cron endpoint does more.** `/module/ifirma/cron` now issues queued orders, retries deferred KSeF submissions, synchronises statuses and delivers documents accepted by KSeF, in that order and under a run lock. Its JSON keeps its existing keys and gains `limit`, `queue`, `ksef`, `delivery`, `rate_limited` and `rate_limited_window`. Call it every 1 to 5 minutes: queued orders and documents that wait for KSeF are processed there. See [Cron jobs](configuration.md#cron-jobs).

**Behaviour change: orders are queued first.** Every order that reaches the trigger status gets a record in the state `queued` before any request to iFirma, and an issuance refused by the rate limit stays queued instead of failing or leaving no trace. See [Queued issuance](usage.md#queued-issuance).

**Behaviour change in KSeF modes `b2b` and `all`: delivery after acceptance.** An invoice that goes to KSeF is no longer e-mailed to the customer at issuance. Its delivery state is `awaiting_ksef`, the customer's order page shows no download link, and the cron delivers it after KSeF accepted it (**Send again** delivers at once). Receipts, invoices to private buyers in `b2b` mode and every document in mode `off` are delivered at issuance as before. Check that the cron endpoint is called regularly before you upgrade, or B2B invoices are not delivered. See [KSeF](usage.md#ksef-e-invoicing).

**Downgrading.** The extra configuration keys are harmless in 1.7.0. A record in the state `queued` or with the delivery state `awaiting_ksef` is unknown to the older version: before a downgrade, issue the queued orders (bulk **Retry issuance**) and deliver the waiting documents (**Send again**).

## Upgrading to 1.10.0

**When the upgrade script runs.** The module version in the files stays at 1.9.1 until the Release workflow raises it. `upgrade/upgrade-1.10.0.php` (the four receipt columns below) runs when the 1.10.0 release is installed over an older version. Until then, files deployed from the repository work with the old version number: the columns are added on first use (when a receipt is written, or when the Orders list or the documents list is opened).

**Behaviour change: the cron has a 45-second time budget and a longer answer.** A cron run no longer starts a record after 45 seconds; what is left waits for the next run, and the status sync is skipped when the budget is already used up when its turn comes. The cron now also retries failed deliveries as its last job. The JSON answer gains `delivery_retry` (the retry job), `usage` (the module's own request counts), `time_budget_exhausted` (0 or 1) and, as before, `limit`, `rate_limited_window`; a script that parses the answer must accept the extra keys. See [Cron jobs](configuration.md#cron-jobs). Call the endpoint every 1 to 5 minutes; the example the configuration page prints says every 15 minutes, and a retry due after 15 minutes then starts up to 15 minutes late.

**BREAKING for integrations: a download refused by the module's request limit answers 429 instead of 502.** When the module's own request limit (the minute cap, the day cap or a running back-off) refuses a PDF download before it is sent to iFirma, the customer download (`/module/ifirma/receipt`) and the back office download now answer HTTP 429 with a `Retry-After` header (seconds) and a short plain-text message. Before this release they answered HTTP 502. The customer message is generic ("Too many requests at the moment. Please try again shortly.") and contains no count or cap; the back office message names the refusing window. A download that was sent to iFirma and failed there, a wrong token, another customer's document and the pause after a rejected key keep their answers (502, 404, 404, 502). Monitors, link checkers or scripts that treat only 502 as "temporarily unavailable" must treat 429 with `Retry-After` the same way. See [HTTP 429 on a PDF download because of the module's request limit](troubleshooting.md#http-429-on-a-pdf-download-because-of-the-modules-request-limit).

**Customer PDF downloads have their own request budget (security fix).** All customers together can send at most 20 PDF downloads per calendar minute and 2,000 per calendar day to iFirma; the module's shared request limit is no longer used up by downloads. A download over the budget answers HTTP 429 with `Retry-After: 60` like the other limit answers. The budget needs no setting and no upgrade step: three internal rows (`IFIRMA_DL_CUSTOMER_MINUTE`, `IFIRMA_DL_CUSTOMER_DAY`, `IFIRMA_DL_EMPLOYEE_MINUTE`, `IFIRMA_DL_EMPLOYEE_DAY`) are created on first use. See [Download limits](usage.md#download-limits).

**The minute cap now holds for every 60 seconds (security fix).** The module used to count its requests per calendar minute, so 80 requests at 10:00:59 and 80 more at 10:01:00 were all sent, above iFirma's limit of 100 per minute. It now counts the last 60 seconds: after a full window it waits until those requests are older than 60 seconds (up to 61 seconds), not until the next calendar minute. `IFIRMA_RATE_WINDOW` keeps its name and takes a new value format; no upgrade step is needed. A counter written by an earlier version is read as requests of the last second of its calendar minute, so right after the upgrade the module is as careful as it was, never less.

**Register on the customer page now needs the Issue level (security fix, behaviour change).** Registering a customer's contractor in iFirma from the back-office customer page needs the module's Issue (Add) permission, like Register contractor on the order page, on top of the Customers edit permission. Before this release the View level was enough, so an employee who may only view iFirma data could create a contractor in iFirma. Profiles that hold View and the Customers edit permission but not Issue lose the button (the panel says why) and get a 403 answer if they call the action. Link and Unlink only change the shop's local link and stay at View. See [Permissions](configuration.md#permissions).

**BREAKING for scripts: back-office actions run only for POST (security fix).** Every action of the AdminIfirma controller that changes state or sends a request to iFirma (`CreateReceipt`, `IssueAnyway`, `LinkCandidate`, `RegisterContractor`, `IssueFromList`, `UpdateStatus`, `ResendDocument`, `SendToKsef`, `RefreshStatus`, `ApproveRequest`, `RejectRequest`, `CustomerLink`, `CustomerUnlink`, `CustomerRegister`, `IssueFeedSeen` and `BulkRun`) now answers HTTP 405 with the failure envelope and the text "This action must be sent with POST." to any other request method, and changes nothing. Before this release only the core's token check protected them, and that check is skipped when the shop runs with the security token switched off, so a link or an image tag could trigger an action. The module's own buttons, bulk runs and the Orders list already send POST and are not affected. A script or a bookmark that called such an action with GET must send a POST with the back-office token. `DownloadPdf`, `IssueFeed`, `CustomerCheck`, `CustomerSearch` and `CustomerCountRemote` only read and keep accepting GET. See ["This action must be sent with POST."](troubleshooting.md#this-action-must-be-sent-with-post-http-405).

**Send again is refused for 30 seconds after a delivery (behaviour change).** A manual **Send again**, single or in a bulk run, of a document whose delivery was recorded as sent less than 30 seconds earlier sends nothing and answers "This document was sent a moment ago. Wait before sending it again."; a bulk run reports the document as skipped and goes on. A failed delivery can still be sent again at once. There is no setting. See [Automatic retry of a failed delivery](usage.md#automatic-retry-of-a-failed-delivery).

**A customer can download at most 30 PDFs per day, and the receipt table gets two more columns (security fix).** One customer can send at most 30 PDF downloads to iFirma per calendar day, all their documents together; the 31st answers HTTP 429 with `Retry-After: 60` like the other limit answers. The count needs two columns on the receipt table, added by the upgrade (or by the first write of a receipt, if the files were deployed before the upgrade ran): `download_day` (`DATE NULL`, the day the count belongs to) and `download_count` (`INT(11) UNSIGNED NOT NULL DEFAULT 0`, the downloads of this document that reached iFirma that day). No row is written: every document starts at 0. There is no setting. See [Download limits](usage.md#download-limits).

**A customer's order page sends at most one list call per customer and interval (security fix, behaviour change).** The order page used to read every page of the issue date for each order a customer opened, so a customer with many orders on a busy day could use up the request limit that issuing documents shares. Now the page sends one list call per customer and interval that reads the first page of the issue date of the opened document (100 documents) and refreshes that document and the customer's other documents of the date. Orders of other issue dates opened within the interval show the last known state; the cron keeps them current. Only the opened document can be marked as missing from the page, and only when the page has fewer than 100 documents; any other document that is not among the rows keeps its stored state. All customers together can start at most 10 such calls per calendar minute and 1,000 per calendar day; over that budget the page shows the last known state and sends nothing. The budget needs no setting: two internal rows (`IFIRMA_SYNC_CUSTOMER_MINUTE`, `IFIRMA_SYNC_CUSTOMER_DAY`) are created on first use. The receipt table gets one more column, `sync_attempt_at` (`DATETIME NULL`, the time an order page of the customer last started a refresh, kept on the customer's first issued record and empty on the others), added by the upgrade (or by the first write of a receipt, if the files were deployed before the upgrade ran). No row is written: every document starts with no attempt time. See [Monitoring](usage.md#monitoring).

**The limit box shows the last reading until you click.** The iFirma request limit box on the settings page no longer reads the limit
from iFirma every time the page is opened (that was one request per view). It shows the last stored reading with its time (the
cron refreshes it when the `abonent` key is set) and a button, **Check the remaining iFirma limit**, that reads a fresh figure on request. The
new **Module requests** box shows the module's own requests per kind; its counters start at zero after the upgrade. See
[Module requests](configuration.md#module-requests).

**Two columns for the delivery retry; BREAKING behaviour: a failed delivery can now be sent later by the cron.** The upgrade adds two columns to the receipt table: `delivery_attempts` (`INT(11) UNSIGNED NOT NULL DEFAULT 0`, the automatic attempts used) and `delivery_retry_at` (`DATETIME NULL`, when the next attempt is due). A delivery that fails for a temporary reason is now retried by the [cron endpoint](configuration.md#cron-jobs), at most three attempts in all, the second 15 minutes and the third 2 hours after the previous failure, as the last job of a run. Before this release a failed delivery stayed failed until an employee used **Send again**; a shop whose automations or staff rely on that (for example, a customer who must not get the e-mail after a manual check) has to set the delivery channel to `none` or know that the cron may deliver. There is no new setting. A permanent failure and a failure with an unknown outcome are never retried. See [Automatic retry of a failed delivery](usage.md#automatic-retry-of-a-failed-delivery).

**Deliveries that failed before the upgrade are not retried.** Their `delivery_attempts` is 0 and `delivery_retry_at` is empty, so the cron leaves them alone, and the panel, the tooltip and the list say "No automatic attempt is scheduled". Use **Send again** for them. If the two columns cannot be added by the upgrade, the Orders list and the documents list add them when they are opened; the Orders page keeps working in the meantime and only the retry line of the tooltip is missing.

**The configuration page needs the module's Configure permission on every PrestaShop version.** PrestaShop 9.1 lets an employee who may only view the Module Manager open a module's configuration page. From this release the module checks its own **Configure** permission (Advanced Parameters > Team > Permissions, under Modules) on every request to its settings page, so an employee without it sees only a notice that they may not configure the module. Saving the settings, regenerating the cron token, checking the limit and the two contractor actions also run only for a POST that carries the back-office token of the employee; the page's own buttons already send it. See [Permissions](configuration.md#permissions).
