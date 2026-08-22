<!-- GENERATED FILE — DO NOT EDIT.
     Produced by `bin/console app:docs:generate` (make docs).
     Source of truth: the constants on App\Acl\Domain\PermissionCatalog
     CI fails on any diff between this file and a fresh run (ADR-0016). -->

# Permissions

Every permission this application knows about, grouped by resource.

Declared in code, synced into the database with `bin/console app:acl:sync-permissions`.
**Never insert a permission row by hand** — it would exist in one environment and not
another, with nothing to tell you. See [cookbook/add-permission.md](cookbook/add-permission.md).

## account

| Permission | Constant |
|---|---|
| `account.delete` | `PermissionCatalog::ACCOUNT_DELETE` |
| `account.export` | `PermissionCatalog::ACCOUNT_EXPORT` |
| `account.read` | `PermissionCatalog::ACCOUNT_READ` |
| `account.update` | `PermissionCatalog::ACCOUNT_UPDATE` |

## audit

| Permission | Constant |
|---|---|
| `audit.export` | `PermissionCatalog::AUDIT_EXPORT` |
| `audit.read` | `PermissionCatalog::AUDIT_READ` |

## group

| Permission | Constant |
|---|---|
| `group.create` | `PermissionCatalog::GROUP_CREATE` |
| `group.delete` | `PermissionCatalog::GROUP_DELETE` |
| `group.read` | `PermissionCatalog::GROUP_READ` |
| `group.update` | `PermissionCatalog::GROUP_UPDATE` |

## permission

| Permission | Constant |
|---|---|
| `permission.explain` | `PermissionCatalog::PERMISSION_EXPLAIN` |
| `permission.grant` | `PermissionCatalog::PERMISSION_GRANT` |
| `permission.read` | `PermissionCatalog::PERMISSION_READ` |
| `permission.revoke` | `PermissionCatalog::PERMISSION_REVOKE` |

## role

| Permission | Constant |
|---|---|
| `role.create` | `PermissionCatalog::ROLE_CREATE` |
| `role.delete` | `PermissionCatalog::ROLE_DELETE` |
| `role.read` | `PermissionCatalog::ROLE_READ` |
| `role.update` | `PermissionCatalog::ROLE_UPDATE` |

## user

| Permission | Constant |
|---|---|
| `user.create` | `PermissionCatalog::USER_CREATE` |
| `user.delete` | `PermissionCatalog::USER_DELETE` |
| `user.impersonate` | `PermissionCatalog::USER_IMPERSONATE` |
| `user.read` | `PermissionCatalog::USER_READ` |
| `user.update` | `PermissionCatalog::USER_UPDATE` |
