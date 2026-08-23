# 0021. One service owns ACL cache invalidation for every admin mutation

- **Status:** accepted
- **Date:** 2026-08-23

## Context

ADR-0011 made revocation immediate by resolving permissions server-side and caching them under
a key containing the user's `acl_version`. That guarantee has a precondition, stated plainly in
ADR-0011's own "Negative" section:

> `acl_version` must be bumped on every mutation that could change an effective permission.
> Missing one is a real risk.

The admin API adds roughly fourteen services that mutate a grant. Every one of them must bump
the right set of users, and the failure mode is **silent**: the write succeeds, the response is
200, the audit event is written, and the affected user keeps their old access until the Redis
entry expires. Nothing logs, nothing throws, and the bug is invisible until someone notices
that a revocation did not take. INV-13 exists because of exactly this.

Three things made a naive per-service `bumpAll([$userId])` insufficient:

1. **Fan-out.** Attaching a permission to a *role* affects everyone holding it — directly, and
   transitively through every group carrying it. `SubjectRepository` could answer neither
   question: it had `memberIdsOf(UserGroup)` but nothing for a role.
2. **Ordering.** Deleting a role must bump its holders *before* the cascade removes the
   `user_role` rows. Afterwards, the holder list no longer exists.
3. **Same-request staleness.** `AclCache` memoises decisions per request. `forgetRequestScope()`
   was written for this — its docblock says "an admin granting a permission and then rendering
   the result would otherwise see the pre-grant answer" — and it had **zero callers**. Every
   mutation endpoint that returns post-mutation state hits that bug.

## Decision

One `Acl\Application\Service\InvalidateAclCachesService`, called as the last step of every
mutating admin service. It exposes the three shapes a change actually takes — `forUsers(list)`,
`forGroup(UserGroup)`, `forRole(Role)` — and owns the fan-out behind them.

`SubjectRepository` gains `userIdsWithRole(Role): list<Uuid>`, returning direct holders plus
members of every group carrying the role.

Every bump also calls `AclCache::forgetRequestScope()`.

The fan-out is deliberately over-inclusive rather than clever: bumping a version too often
costs one cache miss; bumping too rarely is a security hole.

## Consequences

### Positive

- The hard part is written once and unit-tested once, instead of fourteen times.
- `grep -rn InvalidateAclCachesService src/` answers "which mutations invalidate correctly?".
- `forgetRequestScope()` finally has a caller, closing a documented bug that had no reporter.
- The transitive case — a permission attached to a role carried by a group — is handled by
  default rather than by whoever remembers it. That is the case a per-service `bumpAll` gets
  wrong most often, because the direct holders are the visible ones.

### Negative

- It is called explicitly, so it can still be forgotten. This is mitigated by review and by the
  end-to-end test, not prevented by the type system.
- `forRole()` on a widely held role bumps a large set of users, invalidating caches for people
  whose effective permissions did not change. Correct, and occasionally expensive.
- `userIdsWithRole()` is two queries rather than one; DQL has no `UNION`. Acceptable because
  role edits are rare.

## Alternatives rejected and why

- **`bumpAll()` inline in each service** — the status quo extended. Fourteen chances to forget,
  and the two subtle cases (role fan-out, bump-before-cascade) are exactly the ones a
  copy-pasted line gets wrong.
- **A Doctrine `postFlush` listener** detecting changes to `UserRole`, `GroupMembership` and
  `AclEntry` — attractive because it cannot be forgotten, rejected for three reasons: it
  cannot reliably observe many-to-many changes to `role.permissions`; bumping writes to `user`
  inside a flush, which risks recursion; and it hides a security-critical action behind
  framework magic, where the next reader has no way to know it happens.
  `AssignDefaultRoleService` already set the explicit-call precedent.
- **Bumping every user on any grant change** — always correct, trivially simple, and it
  invalidates the entire cache on every administrative edit.
- **Dropping the cache entirely on mutation** — the same objection, plus it discards the
  version-key design that makes invalidation concurrency-safe in the first place.

## Reversal cost

**Cheap to remove, expensive to get wrong.** Deleting the service means re-scattering the calls;
the risk is not the code but the guarantee, which fails silently.

## Implemented by

- `backend/src/Acl/Application/Service/InvalidateAclCachesService.php`
- `backend/src/Acl/Domain/SubjectRepository.php` (`userIdsWithRole`)
- `backend/src/Acl/Application/AclCache.php` (`forgetRequestScope`)
- `backend/tests/Unit/Acl/InvalidateAclCachesServiceTest.php`
