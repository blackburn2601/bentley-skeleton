# 0025. Search users by id fragment via a narrow TEXT() DQL cast

- **Status:** accepted
- **Date:** 2026-08-24
- **Deciders:** Sebastian Wagner

## Context

The admin user list now renders the account id as its first column, so an operator can read
and copy it without opening the record. Having put it on screen, the obvious next move is to
search by it — and the ids that reach an operator arrive from places that carry no username
at all: a log line, an audit row, a support ticket, a `requestId` correlation.

Two properties of the data decide how that search has to work.

**The id is a `uuid` column, not text.** PostgreSQL stores it in a 128-bit binary type. `LIKE`
against it is a type error, not an empty result, and DQL has no `CAST` of its own — so there
was no way to express the query at all without adding something.

**A prefix match would be quietly useless.** These are UUIDv7 ids (ADR-0013), whose leading
48 bits are a timestamp. Accounts created in the same moment share their opening characters:
all three demo fixtures begin `01a0346c-0`. A prefix search would return all of them and look
like it had worked. The entropy is in the tail, so the match has to be a substring anywhere in
the canonical text — which is also why the list column abbreviates to the tail rather than the
head.

## Decision

`GET /api/v1/admin/users?q=` matches the username **or** any substring of the account id's
canonical UUID text. The id side is made matchable by a single custom DQL function, `TEXT()`,
which emits `CAST(expr AS TEXT)` and nothing else.

## Consequences

### Positive

- An id from a log line, an audit row or a support ticket finds its account directly. That is
  the path an operator actually walks, and it previously dead-ended.
- One search box, one parameter. The alternative — a separate `id=` filter — would have made
  the caller classify their own search term before typing it.
- The ACL still decides what is visible. The search predicate is ANDed with
  `AclFacade::filterToVisible()` (ADR-0023), so a denied row stays denied even when the caller
  knows its exact id. There is a test asserting precisely that.

### Negative

- **`TEXT()` is PostgreSQL-shaped.** `CAST(x AS TEXT)` is standard SQL and portable in
  principle, but the lowercase canonical rendering this relies on is Postgres behaviour. This
  repository targets Postgres (the schema uses `citext` and `JSONB` already), so the constraint
  is not new — but it is one more thing pinning us there.
- **The id side cannot use an index.** A leading-wildcard `LIKE` on a cast expression is a
  sequential scan. It is bounded by the ACL predicate and by the `Assert\Length(max: 254)` cap
  on `q`, and the user table is small by nature, but this is the first search in the codebase
  that cannot be served by an index. If the table ever grows past that assumption, the fix is a
  `pg_trgm` GIN index on `CAST(id AS TEXT)`, not a rewrite of the query.
- One custom DQL function now has to be carried and registered.

## Alternatives rejected and why

- **Match the id only when `q` parses as a complete UUID.** No cast, no scan, an index hit. It
  fails the actual request: the column shows an abbreviated id, so the value most likely to be
  pasted back is a fragment. This would silently return nothing for the commonest input.
- **A general-purpose `CAST(expr AS type)` DQL function.** More reusable, and wrong here: the
  target type would be a caller-supplied string interpolated into SQL, which is the one place a
  bound parameter cannot protect it. `TEXT()` takes an expression and has no type argument, so
  there is nothing to inject.
- **`beberlei/DoctrineExtensions`.** A dependency, its upgrades, and dozens of functions to
  obtain one. The function below is thirty lines.
- **Store the id redundantly as a `varchar` alongside the `uuid`.** Indexable and fast, at the
  cost of two columns that can disagree about the identity of a row. A denormalisation that can
  drift is a poor trade for a search on a table this size.
- **Filter in PHP after fetching the page.** The mistake `ListUsersService` is written to
  avoid: it returns short pages with a total that is a lie, and makes the list disagree with a
  direct fetch of the same row.

## Reversal cost

**Cheap.** Delete `TextCastFunction`, drop the `dql.string_functions` block from
`config/packages/doctrine.yaml`, and restore the single-column `WHERE` in `ListUsersService`.
No schema change, no migration, no API contract change — `q` stays one optional string, so a
client that only ever sent usernames sees no difference.

## Implemented by

- `backend/src/Shared/Infrastructure/Doctrine/TextCastFunction.php`
- `backend/src/Account/Application/Service/ListUsersService.php`
- `backend/src/Api/Account/Request/ListUsersRequest.php`
- `backend/config/packages/doctrine.yaml`
- `backend/tests/Functional/Account/ListUsersControllerTest.php`
- `frontend/src/views/admin/UsersListView.vue`
