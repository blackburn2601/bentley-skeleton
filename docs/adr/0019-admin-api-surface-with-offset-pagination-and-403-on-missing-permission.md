# 0019. Admin API surface, with offset pagination and 403 on a missing permission

- **Status:** accepted
- **Date:** 2026-08-23

## Context

The ACL was built before anything could reach it. `User`, `Role`, `UserGroup`,
`GroupMembership`, `UserRole`, `AclEntry` and `SecurityEvent` all exist, with repositories, a
tier-walking resolver and SQL-level collection filtering — but `docs/ENDPOINTS.md` listed
fifteen routes, all of them authentication, `/me` or a health probe. Fifteen of the twenty-three
permissions in `PermissionCatalog` were declared and enforced by no controller.

Two docblocks in the codebase already promised this surface. `AclFacade` says granting and
revoking "goes through the admin services"; `AuditFacade` says reading the trail is "an
administrative action behind `audit.read`, served by the Audit context's own endpoints". Both
described code that did not exist. `docs/INVARIANTS.md` INV-13 went further and claimed
enforcement by "the E2E test that grants a permission and asserts access changes without
logout" — a test nobody could have written, because there was no way to grant anything over
the API.

Adding twenty-six endpoints at once means the conventions they share have to be decided once,
deliberately, rather than settled twenty-six times by whoever generates the next slice. Two of
those conventions had no precedent anywhere in the codebase: **no controller read a query
parameter at all**, so there was no pagination or filtering convention to copy, and
ADR-0001 explicitly put pagination out of scope for the skeleton.

## Decision

An admin API under `/api/v1/admin/`, one endpoint per action, each generated with
`make endpoint` and each guarded by a permission already in `PermissionCatalog`. Within it:

- **Offset pagination.** List endpoints accept `?page=1&perPage=25` and return
  `{items, page, perPage, total}`. `perPage` is capped at 100. The shared half lives in
  `App\Api\Shared\Request\PageRequest`; endpoint-specific filters are declared by the
  concrete subclass.
- **403, never 404, when a permission is missing.** The cookbook requires picking one policy
  per resource; administrative resources do not hide their existence from an operator.
- **Set replacement over add/remove pairs.** Membership, role and permission assignment are
  `PUT` of the whole collection.
- **Every collection returns the envelope, including small fixed ones.** Roles and
  permissions could have returned a bare `{items}`, but then a client needs two response
  shapes and a rule for which endpoint uses which. They return `page: 1`, `perPage: <total>`
  and the real `total` instead, so the SPA has one `Paginated<T>` type with no special cases.

## Consequences

### Positive

- One paging contract for the whole API: a client that learns `?page=2&perPage=50` once knows
  every collection.
- One response shape across the whole API, so `usePaginatedResource` and `DataTablePagination`
  work for every list screen without an exception branch.
- Offset matches the repositories that already exist — `UserRepository::findAllPaginated`
  and `SecurityEventRepository::findRecent` both take an offset — so no repository is
  rewritten to serve the convention.
- `LIMIT` is applied after `AclCriteriaBuilder` has filtered in SQL, so a page of twenty-five
  is twenty-five rows the caller may actually see, not twenty-five rows minus the ones removed
  afterwards.
- Set replacement halves the endpoint count and matches the UI, which submits a whole
  multi-select rather than a diff.
- The permission catalog needed no additions: every one of the twenty-six endpoints uses a
  name that was already declared, which is evidence the catalog was designed for this surface.

### Negative

- Offset pagination drifts under concurrent writes: a row inserted while an operator pages
  can push an item onto a page they already passed. Acceptable for administration; it would
  not be for an activity feed.
- `COUNT(*)` on every list request. Fine at administrative table sizes, wrong at millions of
  rows.
- 403 tells an unauthorized caller that the id they guessed exists. That is the deliberate
  trade: the alternative confuses "you may not" with "it is gone" for the operator too.
- Set replacement is last-write-wins. Two administrators editing one group's members
  concurrently silently lose one set of changes.
- **A caller holding only an object-level grant cannot list at all.** The class-level
  `#[IsGranted('user.read')]` on a collection controller is an entry gate: it asks the RBAC and
  class-level question, and an object-scoped ACE does not answer it. So the fixtures'
  `viewer@`, who holds one object-level allow on `editor@`'s record and — as the fixture
  comment says — "no class-level access to users at all", gets 403 on `GET /admin/users` while
  `GET /admin/users/{id}` for that one record succeeds.

  This is the existing voter's behaviour, not something this ADR introduces, and it is
  consistent: the ACL predicate narrows *within* a permission the caller already holds
  generally, rather than granting entry on its own. It is recorded here because it looks like a
  bug the first time you meet it, and because it means `AclCriteriaBuilder`'s object-level
  support serves callers who hold the class-level permission *and* have specific rows denied —
  which is exactly `editor@`, who sees two of three users because of an explicit deny.

## Alternatives rejected and why

- **Cursor pagination** — correct under concurrent writes, but the admin UI shows numbered
  pages and a total, which a cursor cannot give without a separate count anyway. It would
  also have meant rewriting two repositories that already take an offset.
- **404 instead of 403** — right for tenant data, where existence is itself a secret. Here it
  would mean an operator who forgot to grant themselves `group.read` sees "no such group"
  and goes looking for a data problem. Revisit when multi-tenancy lands (ADR-0014).
- **`PATCH` with add/remove operation lists** — expressive and concurrency-safe, but it
  doubles the request vocabulary and every client has to compute a diff the server then has
  to validate.
- **A generic `/api/v1/admin/{resource}` CRUD controller** — fewer files, but it defeats every
  rule in this repository at once: one `#[IsGranted]` per endpoint stops being checkable,
  response views stop being explicit, and `docs/ENDPOINTS.md` stops describing anything.

## Reversal cost

**Moderate.** The envelope shape is a published API contract: changing it means touching every
list endpoint's response view, every `src/api/admin/*.ts` module in the SPA, and any client
built against it. The 403/404 policy is cheaper — it is one branch per endpoint and its
functional test.

## Implemented by

- `backend/src/Api/Shared/Request/PageRequest.php`
- `backend/src/Api/*/` (admin controllers, requests and response views)
- `docs/ENDPOINTS.md` (generated)
