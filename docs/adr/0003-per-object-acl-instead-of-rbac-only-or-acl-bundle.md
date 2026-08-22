# 0003. A purpose-built per-object ACL, not RBAC-only and not `symfony/acl-bundle`

- **Status:** accepted
- **Date:** 2026-08-22

## Context

Projects seeded from this skeleton need to answer "may this user do this to *this specific
object*?" — not merely "does this user have the editor role?". Role-only authorization
handles that by growing ever more specific roles until the role list is a permission list
with worse ergonomics.

## Decision

A first-class `acl_entry` table keyed by (subject type, subject id, resource class, resource
id, permission, effect), where a NULL `resource_id` means a class-level grant. Decisions are
made tier by tier — most specific first, deny beating allow within a tier — with RBAC as the
final fallback. A `PermissionResolver::explain()` method reports which entry won and why.

## Consequences

### Positive

- Object-level sharing is a row, not a schema change.
- `explain()` makes "why can/can't X do Y?" answerable in production, which is what usually
  kills homegrown ACLs.
- Collection filtering pushes the same rules into SQL via `AclCriteriaBuilder`, so lists and
  single-item checks cannot disagree — and an integration test asserts they never do.

### Negative

- Roughly 600 lines of authorization code we own and must test. Mitigated by an exhaustive
  decision-matrix test.
- Every permission check touches `acl_entry` unless cached. Mitigated by per-request
  memoization plus a Redis cache keyed on the user's `acl_version`.

## Alternatives rejected and why

- **RBAC only** — the role explosion described above. It also cannot express "this document,
  this user" without inventing a role per document.
- **`symfony/acl-bundle`** — legacy: bitmask `MaskBuilder` semantics, capped at Symfony
  `^7.0`, and no collection filtering, which is the requirement that actually forces the
  design. Adopting it would mean writing the SQL filtering ourselves anyway, against a schema
  we did not choose.
- **Policy classes per entity (Laravel-style gates)** — fine for logic, but object-level
  grants would still need somewhere to live, and filtering a list still means hand-written
  SQL per entity.
- **Casbin / OPA** — a second policy language and a second deployment to operate, for a
  model this one expresses directly.

## Reversal cost

**Expensive.** Grants live in application tables; moving to another model means migrating
data, not just code. This is the decision in this repo most worth reading before changing.

## Implemented by

- `backend/src/Acl/`
- `docs/cookbook/add-entity-with-acl.md`, `docs/cookbook/add-permission.md`
