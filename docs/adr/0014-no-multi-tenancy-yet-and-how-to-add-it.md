# 0014. No multi-tenancy yet, and how to add it later

- **Status:** accepted
- **Date:** 2026-08-22

## Context

Multi-tenancy is expensive to add late and wasteful to carry unused. Every query gains a
scope, every test gains a fixture dimension, and every ACL decision gains a term — whether or
not any project needs it.

## Decision

No tenancy in the skeleton. But the ACL is written so tenancy can be added as **one more
subject dimension** rather than a rewrite: `PermissionResolver` resolves a *subject set*
(user + groups + effective roles) once per request and evaluates tiers against it.

## Consequences

### Positive

- Single-tenant projects, which are most of them, carry no cost.
- The extension point is identified and documented rather than hoped for.

### Negative

- A project that discovers it needs tenancy still has a migration and a data backfill to do.
- The resolver's subject-set shape must not be "simplified" in a way that hard-codes a
  single dimension — that is the thing this ADR is protecting.

## How to add it

1. Add `tenant_id` to `acl_entry` and to the tenant-scoped entities.
2. Extend the subject set with the caller's tenant, and add a tenant term to each tier's
   match.
3. Add the same term to `AclCriteriaBuilder`, so collection filtering and single-item checks
   stay in agreement — the cross-check integration test will fail loudly if they do not.
4. Include `tenant_id` in the ACL cache key alongside `acl_version`.

## Alternatives rejected and why

- **Build tenancy now** — cost paid by every project, benefit to none yet.
- **Database-per-tenant** — strong isolation, but migrations, connection pooling and
  cross-tenant reporting all become substantially harder. That is a project-level decision,
  not a skeleton default.
- **Rely on ACL rows alone for isolation** — one missing grant becomes a cross-tenant leak.
  Real tenancy needs a scope that is enforced by default rather than granted explicitly.

## Reversal cost

**Not applicable** — this is a decision to defer. The cost of *acting* on it later is
moderate and is itemised above.

## Implemented by

- `backend/src/Acl/Application/PermissionResolver.php` (subject-set shape)
- `backend/src/Acl/Infrastructure/AclCriteriaBuilder.php`
