# Test fixtures

## detail-response.json

Response of `GET /iapi/{fakturaparagon|fakturakraj}/{id}.json` used by the duplicate
guard to confirm that a listed document belongs to a shop order (it searches the raw
JSON for the stamped order reference `PS-ORDER-{id_order}-{reference}`).

**Provenance:** iFirma does not document the shape of this response. No iFirma account
was available when this change was implemented (2026-11-01), so the fixture mirrors the
shape produced by the `test-stack` mock (`GET /iapi/{doc_type}/{id}.json`): the create
payload echoed back plus `Identyfikator`, `PelnyNumer`, `Zaplacono`, `Uwagi` and
`DodatkoweUwagi`.

**To replace it with a real capture:** issue a 1 PLN test document from a merchant
account with the module (so it carries the order reference), download
`https://www.ifirma.pl/iapi/<type>/<id>.json` with the module's signing scheme, redact
personal data (names, addresses, e-mail, NIP) and save it here. Keep the `Uwagi` /
`DodatkoweUwagi` values so `DuplicateMatcherTest::testConfirmAgainstRecordedDetailResponse`
still exercises the real nesting. If the real response does not carry the reference at
all, the matcher must be revisited (see design.md, Risks).

## payloads/

Golden request payloads of `IfirmaReceiptService::createReceipt()`, one file per scenario of
`CreateReceiptTest` and `PayloadBuilderTest` (refactor-srp-splits 5.1). The golden tests
`CreateReceiptGoldenTest` and `PayloadBuilderGoldenTest` run each scenario again and compare
what it sent with its file. A file is named after the golden test that reads it, in kebab case:
`CreateReceiptGoldenTest::testSuccessStampsReference` reads
`create-receipt-success-stamps-reference.json`, and a second scenario of the same test adds
`.<variant>`. A scenario the source test class lacks says so in its `from` key.

A file holds the scenario's `requests` (method, path and decoded body of every POST and PUT, oldest
first), `stored` (the decoded `request_payload` of every write of the receipt) and `threw` (the message
of the exception the call ended in, `null` when it returned). Today's date is written as `{today}`.

**Provenance:** captured on 2026-11-05 from `IfirmaReceiptService` as of commit `d708c46`, before
`createReceipt()` was split and the payload builder extracted (refactor-srp-splits 5.2 to 5.4). The
split must leave every file equal.

**To capture them again** after a change of the payload on purpose, run the two golden tests with
`IFIRMA_GOLDEN_RECORD=1` in the environment. They write the files, show as skipped and check nothing.
Review the diff of the files before committing it.
