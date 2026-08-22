<!-- GENERATED FILE — DO NOT EDIT.
     Produced by `bin/console app:docs:generate` (make docs).
     Source of truth: the @responsibility docblock on each class in src/*/Application/Service/
     CI fails on any diff between this file and a fresh run (ADR-0016). -->

# Services

Every Application service, grouped by bounded context.

**Before writing a new service, look for the topic here.** If a service already owns
it, extend that one. If none does, `make service` generates a conforming skeleton.

## Acl

| Service | Responsibility |
|---|---|
| `AclCache` | Caches permission decisions under a key that a grant change invalidates. |
| `AclFacade` | Exposes the Acl context to other contexts as a single narrow surface. |
| `PermissionResolver` | Decides whether a subject holds a permission on a resource. |
| `SyncPermissionsService` | Reconciles the database permission rows with the code-declared catalog. |

## Platform

| Service | Responsibility |
|---|---|
| `CheckReadinessService` | Reports whether every dependency this application needs is reachable. |
