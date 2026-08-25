# 0027. Free-text search over the audit log

- **Status:** accepted
- **Date:** 2026-08-25
- **Deciders:** Sebastian Wagner

## Context

The admin Audit-Protokoll screen offered a single text field bound to the `type` query
parameter. `type` is a closed enum (`SecurityEventType`) validated server-side against its
wire values, so a free-text entry like `login` — the obvious thing an operator types when
looking for login activity — failed `#[Assert\Choice]` and returned 422
"The request body did not pass validation." The screen looked broken to the person using it.

An audit log is read by people holding fragments, not whole values: a type prefix from a
log line, an IP from a support ticket, a request id pasted from a problem+json body, an
actor id quoted from another audit row. The question the screen has to answer is "does any
of this appear in any event?", not "is this an exact event type?".

## Decision

Add a `q` query parameter to `GET /api/v1/admin/audit-events` that matches a case-
insensitive substring of the event **type**, **actor id**, **IP address** and **request id**,
ORed together. The existing exact `type` filter stays for programmatic callers that want
one event class.

The match is a substring anywhere, not a prefix. The id side uses the existing
`TEXT()` DQL function (ADR-0025 introduced it to cast the binary `uuid` column) and is
lowered on both ends, because PostgreSQL writes the canonical form lowercase while an id
pasted from elsewhere may arrive uppercased.

## Consequences

### Positive

- An operator typing `login` finds `login_succeeded` and `login_failed`. The screen now
  does what it appeared to do.
- One field answers the four kinds of fragment an operator actually holds, instead of four
  fields nobody would fill in combination.
- The `type` exact filter remains, so a caller that wants a single event class is not
  forced into a fuzzy match.

### Negative

- The id side cannot use an index: `TEXT(e.actorId) LIKE '%…%'` is a full scan of the cast.
  Acceptable because the audit table is append-only and bounded by retention, and the
  caller already holds `audit.read` (a class-level permission, not per-object — ADR-0012).
- `LIKE '%q%'` patterns are wildcard-injectable: a `%` in the search term becomes a
  wildcard. Deliberately not escaped, because the term is operator-controlled, length-
  capped at 254, and an over-broad match is a usability annoyance rather than a correctness
  or security fault on an admin-only endpoint.

## Alternatives rejected and why

- **Replace the text field with a `<select>` of the enum wire values.** Eliminates the 422
  by construction, but it answers only the type question. An operator with an IP or a
  request id is no closer to an answer, and the original report was "I searched for
  `login`" — a dropdown of `login_succeeded` / `login_failed` would have made them pick
  each separately rather than find both at once. Rejected as solving the wrong problem.

- **Prefix match on `type` only.** `login` → `login_succeeded`, `login_failed`. Cheaper
  (indexable via the existing `idx_security_event_type`) and fixes the reported case, but
  it leaves IP, request id and actor id unsearchable — exactly the fragments the audit log
  exists to correlate. The cost saving is on a bounded table; the coverage loss is permanent.
  Rejected.

- **Full-text search (Postgres `tsvector` / `tsquery`).** The right tool for natural-
  language prose, which the audit columns are not: enum wire values, uuids, IPs and hex
  request ids have no tokenisation worth ranking. The added index and query machinery
  would buy nothing over `LIKE` on columns that are short and few. Rejected as over-
  engineering.

## Reversal cost

Cheap. Removing `q` is deleting one DTO field, one service parameter, one repository
clause and the frontend search field — no data migration, no schema change, and `type`
still works. The `applyFilter` helper in `DoctrineSecurityEventRepository` was extracted as
part of this change and stays useful with or without `q`.

## Implemented by

- `backend/src/Api/Audit/Request/ListSecurityEventsRequest.php` — `q` field + validation
- `backend/src/Audit/Application/Service/ListSecurityEventsService.php` — query threaded through
- `backend/src/Audit/Domain/SecurityEventRepository.php` — `findRecent` / `countRecent` signatures
- `backend/src/Audit/Infrastructure/Repository/DoctrineSecurityEventRepository.php` — `applyFilter`, the `LIKE` predicate
- `backend/src/Api/Audit/ListSecurityEventsController.php` — passes `q`
- `frontend/src/views/admin/AuditLogView.vue` — search field bound to `q`
- `backend/tests/Functional/Audit/ListSecurityEventsControllerTest.php` — search cases