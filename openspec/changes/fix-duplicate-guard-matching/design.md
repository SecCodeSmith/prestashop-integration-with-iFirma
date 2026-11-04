# Design

## Context

See proposal.md "Why" for the motivation. Current state that shapes the approach:

- `IfirmaDuplicateGuard::check()` lists `faktury.json` (contractor identifier, then NIP, then unfiltered within `IFIRMA_DUPLICATE_WINDOW_DAYS`), hands the rows to the pure `IfirmaDuplicateMatcher::selectCandidates()` (draft, `Rodzaj`, currency, `Brutto` within tolerance), fetches up to `max_detail_calls` (10) details newest first and adopts the first whose raw JSON contains the order reference. Anything left is written as `duplicate_suspected` with `IfirmaDuplicateMatcher::summarize()` rows.
- List rows carry `KontrahentNazwa`, `IdentyfikatorKontrahenta` and `NIPKontrahenta` (documented row shape, mirrored by the mock) but no sale date and no positions. The detail JSON carries `DataSprzedazy`, `FormatDatySprzedazy`, `Pozycje[]` (`NazwaPelna`, `Ilosc`, `CenaJednostkowa`, ...) and `Kontrahent` (see `tests/fixtures/detail-response.json`).
- `IfirmaReceiptService::buildInvoicePayload()` is the only place that knows what the module sends: sale date = order `date_add` day, positions = order detail lines (`product_name` truncated to 500, `product_quantity`) plus a translated shipping line, buyer name = company for a business buyer, otherwise `firstname lastname`.
- The guard owns only an `IfirmaApiClient`; it does not hold the receipt service. The matcher must stay I/O free (unit-tested without transport).
- The admin panel reads `duplicate_candidates` JSON and renders a fixed column set; `linkCandidate()` rebuilds a list row from the stored summary.
- `IFIRMA_DUPLICATE_*` settings, the query sequence and the paging rules are covered by the existing spec and must keep working.

## Goals / Non-Goals

**Goals:**
- Drop rows of other buyers before any detail call.
- Decide "suspected" only after the detail shows the same sale date and the same product lines, or when the detail could not be read.
- Compare against exactly what the module would send for the order, so a module-created document always matches its own order.
- Keep the matcher pure and the guard's outcome contract (`IfirmaGuardResult`) unchanged for the pipeline.

**Non-Goals:**
- Changing the list query sequence, window or paging.
- A configurable sale-date tolerance or fuzzy product matching.
- Re-evaluating orders already stored as `duplicate_suspected`; the admin re-runs Create, which runs the guard again.
- Schema changes.

## Decisions

### D1. Buyer match on list rows, before the detail step

**Choice.** A new pure `IfirmaDuplicateMatcher::filterByBuyer(array $rows, array $buyer)` keeps a row when `IdentyfikatorKontrahenta` equals `$buyer['identifier']`, or the digits of `NIPKontrahenta` equal `$buyer['nip']` (both non-empty), or the normalised `KontrahentNazwa` is one of `$buyer['names']`. The guard applies it to the rows of the query that returned data, before `selectCandidates()`, and tags each kept row with the match reason (`identifier`, `nip`, `name`) for the summary. `selectCandidates()` itself is unchanged.

**Why.** The row already carries the buyer fields, so the check costs no request, and it removes the bulk of the false positives (other customers' documents returned by the unfiltered query) before the detail loop, which is capped at ten calls and burns the daily API limit. Keeping it as a separate pure step leaves the existing `selectCandidates()` tests valid and makes each rule testable alone.

**Alternatives.** Adding the buyer to `selectCandidates()` as a fifth parameter: rejected, every existing call and test changes for no gain. Dropping the unfiltered query instead: rejected, it is what catches documents created by iFirma's own integrator under a different identifier, and the spec keeps it.

### D2. What identifies the buyer

**Choice.** The guard builds `$buyer` once per run: `identifier` from the existing `contractorFilter()` (mapping or `PS-C{id}`), `nip` from the existing `orderNip()` (digits), `names` from a new static `IfirmaReceiptService::buyerNames(Order)` returning the normalised company name (when present) and the normalised `firstname lastname` of the invoice address. Normalisation (`IfirmaDuplicateMatcher::normalizeText()`): cast to string, trim, collapse runs of whitespace to one space, `mb_strtolower` UTF-8. Diacritics are kept.

**Why.** Identifier and NIP are exact keys and cover module-created and mapped documents. The name is the last resort for documents created by hand or by the integrator; accepting both the company and the person name avoids a miss when the integrator used the person for a business address. Keeping diacritics avoids collapsing distinct Polish names; the identifier and NIP paths already cover the common cases.

**Alternatives.** Comparing the detail's `Kontrahent` block instead: rejected, it needs a detail call per row, which is what D1 avoids. Using the approved data-request override for the name: rejected, the guard does not receive it and the invoice address is what both the module and the integrator see.

### D3. Sale date source and comparison

**Choice.** New static `IfirmaReceiptService::orderSaleDate(Order)` returns `date('Y-m-d', strtotime($order->date_add))` (today when `date_add` is empty) and `buildInvoicePayload()` uses it for `DataSprzedazy`, so the guard and the payload cannot drift. `IfirmaDuplicateMatcher::saleDateMatches(array $detail, $saleDate)` compares the first ten characters of `DataSprzedazy` with the order sale date; when `FormatDatySprzedazy` is `MSC` it compares the first seven (`Y-m`). A missing or empty `DataSprzedazy` returns `null` (unknown).

**Why.** The module stamps the order date as the sale date, so a document the module created earlier for this order (the primary duplicate case: an `unknown` outcome followed by a retry) always matches. Day precision is what the merchant asked for. The monthly format exists in iFirma and would otherwise never match.

**Alternatives.** A configurable tolerance in days: deferred (see Risks); it would need a new setting and the request was explicit about the sale date.

### D4. Product line comparison

**Choice.** New static `IfirmaReceiptService::orderProductLines(Order)` returns `array(array('name' => truncate(product_name, 500), 'quantity' => (float) product_quantity), ...)` from `getOrderDetailList()`; `buildInvoicePayload()` builds its product positions from it. `IfirmaDuplicateMatcher::positionsCover(array $positions, array $lines)` walks the order lines and, for each, consumes the first not-yet-used position whose normalised `NazwaPelna` equals the normalised line name and whose `(float) Ilosc` equals the quantity (compared with a 0.001 epsilon). Every line must be consumed; unconsumed positions are ignored. An order with no product lines is covered by any position list. A detail without a `Pozycje` array returns `null` (unknown).

**Why.** The shipping line is translated and the integrator names it differently, so requiring it would miss real duplicates; ignoring extra positions tolerates shipping, fees and rounding lines while the gross match already bounds the total. Unit price is not compared: the gross is already within tolerance and an integrator may send net prices (`LiczOd` `NET`). Consuming positions one-to-one (multiset) makes two lines of the same product require two positions.

**Alternatives.** Comparing position sets both ways: rejected because of the shipping line. Comparing product references or ids: rejected, iFirma positions carry no product id.

### D5. Verification loop and unverifiable candidates

**Choice.** In `check()`, after `filterByBuyer()` and `selectCandidates()`, the detail loop keeps its order (newest first, `max_detail_calls`). For each candidate: detail fetch failed -> keep (unverified, error recorded); reference found -> adopt immediately (unchanged); otherwise `verifyDetail()` returns `matched` true/false/null and `sale_date`; `false` drops the candidate, `true` and `null` keep it. Candidates beyond the cap are kept unverified. If nothing survives the outcome is `PROCEED` (no record written); otherwise `markSuspected()` receives the surviving rows, each carrying `_sale_date` and `_matched_by` for `summarize()`.

**Why.** The guard exists to prevent a second document; when it cannot read the detail it must not assume there is no duplicate. With the buyer filter in place an unverifiable candidate is already the same buyer with the same amount, so keeping it is the conservative choice and matches the existing `testDetailFailureStillSuspected` expectation. Writing no record on `PROCEED` keeps today's behaviour.

**Alternatives.** Dropping unverified candidates: rejected, a transient 500 would then let a true duplicate through.

### D6. Candidate summary and admin panel

**Choice.** `summarize()` adds `sale_date` (string, `''` when unknown) and `matched_by` (`identifier`, `nip`, `name`, or `''`). The admin panel table gains a "Sale date" column after "Issue date", rendering `''` for older records (Smarty `{$candidate.sale_date|default:''}`), with the Polish translation added to `translations/pl.php` (`TranslationCoverageTest` enforces it). `linkCandidate()` keeps rebuilding the row from the existing keys and ignores the new ones.

**Why.** The sale date is the field the admin needs to decide between Link and Issue anyway, and the match reason explains why the row was kept, which is exactly the question the merchant raised.

### D7. Test stack and fixtures

**Choice.** The mock's `POST /__mock/documents` accepts `sale_date` (-> `DataSprzedazy`, default: `issue_date` when given, else today) and `sale_date_format` (-> `FormatDatySprzedazy`, default `DZN`); the detail endpoint already echoes the stored payload, so no change there. Smoke scenario A in `tests/smoke/run.php` seeds its external document with the order's contractor identifier, sale date and product positions so it is still suspected, and gains a sibling check that a same-amount document of another contractor is not. Unit-test row fixtures (`DuplicateGuardTest`, `IssuancePipelineTest`) gain `IdentyfikatorKontrahenta` = `PS-C5`, and detail bodies gain `DataSprzedazy` and `Pozycje` matching the stub order (`details` on the `Order` stub).

**Why.** Without a seedable sale date every seeded document would be unverified and the smoke test could not exercise the new negative path. The fixtures must describe the buyer, otherwise every existing suspected/adopt test fails on the buyer filter rather than on what it tests.

## Risks / Trade-offs

- [External documents named differently are no longer flagged] -> They are still adopted when they carry the order reference; README keeps the caveat about not running iFirma's own integrator alongside the module; the admin can still see them in iFirma.
- [Integrator documents whose sale date is the payment date, not the order date, are not flagged] -> Accepted for this change at the merchant's request; a `IFIRMA_DUPLICATE_SALE_DATE_TOLERANCE_DAYS` setting can be added later without changing the query sequence or the candidate record format.
- [Name match misses punctuation variants (`Sp. z o.o.` vs `Sp. z o. o.`)] -> Identifier and NIP cover business buyers; the name path is a last resort, and a miss means a document is created rather than an order blocked.
- [Detail verification depends on the live detail shape] -> `DataSprzedazy` and `Pozycje` are documented request fields and are present in the recorded live response (`tests/fixtures/detail-response.json`); a missing field keeps the candidate (D5) rather than creating a document.
- [Orders already stored as `duplicate_suspected` are not re-evaluated automatically] -> Create Receipt on the order re-runs the guard with the new rules; README troubleshooting says so.

## Migration Plan

1. Ship as a module update (no schema change, no new configuration key).
2. After update, open each order blocked as `duplicate_suspected` and click **Create Receipt**; the guard re-runs with the new rules and either proceeds, adopts or lists the real candidates.
3. Rollback: reinstall the previous module version; `duplicate_candidates` records written by the new version carry two extra keys the old template ignores.
