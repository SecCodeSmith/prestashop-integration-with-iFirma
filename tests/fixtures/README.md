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
