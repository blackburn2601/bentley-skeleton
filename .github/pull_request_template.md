## What this changes, and why

<!-- The why matters more than the what; the diff already shows the what. -->

## Checklist

- [ ] `make check` is green
- [ ] `make docs` produces no diff
- [ ] New endpoints declare `#[IsGranted]` with the right permission
- [ ] New endpoints have a functional test asserting an unpermitted caller is refused
- [ ] New services declare a one-sentence `@responsibility` with no "and"

## Security

<!-- Delete any line that does not apply, but read all of them first. -->

- [ ] This changes who can access what — an ADR is included
- [ ] This adds or changes a permission — the catalog and `app:acl:sync-permissions` are updated
- [ ] This adds an endpoint that takes an object id — the IDOR case is covered by a test
- [ ] This touches authentication, tokens or sessions — an ADR is included
- [ ] This adds an outbound HTTP call — it goes through the SSRF-guarded client
- [ ] This adds a field to a response — it is on a response view, not a serialized entity
- [ ] None of the above

## ADR

- [ ] An ADR is included
- [ ] Not needed — this change carries no decision (apply the `no-adr-needed` label)
