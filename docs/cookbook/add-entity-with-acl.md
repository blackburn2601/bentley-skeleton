# Recipe: add an entity with object-level permissions

## 1. The entity

Create it under `src/<Context>/Domain/`. Non-negotiables:

```php
#[ORM\Entity]
#[ORM\Table(name: 'note')]
class Note implements AclParentAware
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    public function __construct(private Folder $folder, private string $title)
    {
        $this->id = Uuid::v7();          // UUIDv7, generated here, never by the DB (INV-14)
    }

    public function getAclParent(): ?object
    {
        return $this->folder;            // permission inheritance: a grant on the folder
    }                                    // covers the notes inside it
}
```

`getAclParent()` is the inheritance hook. Returning `null` means the object inherits nothing
and only object-level or class-level grants apply. Returning the folder means a grant on the
folder is checked as tier 2 — see the tier diagram in [ARCHITECTURE.md](../ARCHITECTURE.md).

Keep the entity in `Domain/`, which depends on nothing (INV-01). The repository *interface*
belongs there too; the Doctrine implementation goes in `Infrastructure/`.

## 2. If this is a new context

Creating `src/Billing/` is an architecture change, and three registrations must happen or the
new code is **silently unenforced**:

1. `deptrac-context.yaml` — a `Billing` layer **and** a `BillingFacade` layer, plus a ruleset
   entry.
2. `config/packages/doctrine.yaml` — a mapping (`auto_mapping` is deliberately off).
3. `src/Billing/Application/BillingFacade.php` — the front door other contexts use.

`EnforcementCoverageTest` fails if you miss any of them (INV-03). That test exists because
forgetting produces a *green* build that enforces nothing.

Also write an ADR: a new bounded context is a decision.

## 3. Permissions

Declare them in `PermissionCatalog` and sync — see [add-permission.md](add-permission.md).

## 4. Migration

See [add-migration.md](add-migration.md). Object-level grants need no schema of their own:
`acl_entry` is generic, keyed by `resource_class` and `resource_id`.

## 5. Endpoints

One per action, via [add-endpoint.md](add-endpoint.md). For every collection endpoint, filter
with `AclCriteriaBuilder` rather than in PHP — anything else breaks pagination and lets the
list disagree with a single-item check.

## 6. Test the decision matrix

For a new resource with inheritance, cover the combinations that actually break:

- allow at object level → granted
- deny at object level, allow at class level → **denied** (specific beats general)
- allow on the parent folder, nothing on the note → granted via inheritance
- deny on the note, allow on the folder → denied (the more specific tier wins)
- expired ACE → ignored
- grant via group, and via role → granted
- no entry anywhere → denied

Then the cross-check: assert `AclCriteriaBuilder` returns exactly the set
`PermissionResolver` allows, for the same user and permission. The list and the item check
disagreeing is *the* classic bug in per-object ACLs.

## Checklist

- [ ] UUIDv7 primary key, generated in the constructor
- [ ] `AclParentAware` implemented (even if it returns `null` — make the choice explicit)
- [ ] Entity in `Domain/`, repository interface in `Domain/`, implementation in `Infrastructure/`
- [ ] New context? deptrac layers + facade + Doctrine mapping + an ADR
- [ ] Permissions declared and synced
- [ ] Migration applies **and** reverts
- [ ] Decision-matrix tests, including inheritance and expiry
- [ ] Resolver/criteria cross-check test
- [ ] `make check` green, `make docs` no diff
