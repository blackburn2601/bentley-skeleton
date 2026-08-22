# 0011. Permissions are resolved server-side, never carried in the JWT

- **Status:** accepted
- **Date:** 2026-08-22

## Context

Embedding a user's permission list in the access token is a common optimisation: the token
is signed, so the API can authorize without touching the database.

It also means the token is a cached copy of an authorization decision, and caches go stale.
Revoking a permission would not take effect until the token expired.

## Decision

The access token carries `sub`, `jti`, `roles` and `perm_v` — the user's `acl_version` — and
no permission list. Permissions are resolved per request by `PermissionResolver`, memoized
for the request and cached in Redis under a key that includes `acl_version`. Any change to a
role, group membership or ACE bumps that user's `acl_version`.

## Consequences

### Positive

- Revocation takes effect on the very next request. No waiting out a TTL, no denylist.
- Cache invalidation is a version bump rather than a sweep, so it is concurrency-safe: a
  stale reader simply misses and recomputes.
- Tokens stay small; a per-object ACL could never fit in one anyway.

### Negative

- A cache lookup per request, and a database read on a miss.
- `acl_version` must be bumped on every mutation that could change an effective permission.
  Missing one is a real risk, so the mutations live behind Acl services rather than being
  scattered.

## Alternatives rejected and why

- **Permission list in the token** — the staleness problem above. With object-level grants it
  is also unbounded in size.
- **Short-lived tokens without `perm_v`** — reduces the stale window but does not close it,
  and forces a shorter TTL than anything else requires.
- **Token denylist checked per request** — reintroduces the per-request lookup that embedding
  permissions was meant to avoid, and adds a store to keep.

## Reversal cost

**Cheap technically, significant in behaviour.** Adding claims is easy; doing so silently
reintroduces the revocation delay this decision exists to remove.

## Implemented by

- `backend/src/Acl/Application/PermissionResolver.php`
- `backend/src/Account/` (token issuing), `acl_version` on `User`
