# Recipe: add a permission

Permissions are **code-declared**. They live in a catalog, are synced into the database by a
command, and are therefore diffable in git and survive a redeploy. Never `INSERT` a
permission row by hand — it will exist in one environment and not another, and nothing will
tell you.

## 1. Declare it

`backend/src/Acl/Domain/PermissionCatalog.php`:

```php
public const NOTE_READ   = 'note.read';
public const NOTE_UPDATE = 'note.update';
public const NOTE_DELETE = 'note.delete';
```

Naming: `<resource>.<verb>`, lowercase, singular resource. `note.read`, not `notes.read` or
`READ_NOTE`. The generated `docs/PERMISSIONS.md` is grouped on the prefix, so consistency
here is what makes that document readable.

## 2. Sync

```bash
make sh
bin/console app:acl:sync-permissions
```

The command is idempotent: it inserts what is missing and reports what exists in the database
but is no longer in the catalog. It does **not** delete — removing a permission that still
has grants is a decision, not a side effect.

## 3. Use it

On an endpoint:

```php
#[IsGranted(PermissionCatalog::NOTE_READ, subject: 'note')]
```

With `subject:` it is an object-level check and the resolver walks the tiers
(object → ACL parents → class-level → RBAC). Without it, it is a class-level check.

For a collection:

```php
$this->aclCriteria->apply($qb, 'n', PermissionCatalog::NOTE_READ);
```

## 4. Grant it

Grants are data, not code: through the admin API (`/api/v1/admin/...`) or in fixtures for the
demo dataset. A grant may target a user, a group or a role, and may be scoped to one object
or to the whole class (`resource_id IS NULL`).

## 5. Verify

```bash
make check
make docs      # regenerates docs/PERMISSIONS.md; commit it
```

Use the explain endpoint when a decision surprises you — it reports which entry won and at
which tier:

```
GET /api/v1/admin/permissions/explain?user=<id>&permission=note.read&resource=<id>
```

## Checklist

- [ ] Declared in `PermissionCatalog`, named `<resource>.<verb>`
- [ ] Synced with `app:acl:sync-permissions`
- [ ] Endpoint uses the constant, not a string literal
- [ ] Collection endpoints filter through `AclCriteriaBuilder`
- [ ] `make docs` no diff
