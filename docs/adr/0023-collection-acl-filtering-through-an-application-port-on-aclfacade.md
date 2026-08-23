# 0023. Collection ACL filtering through an Application port exposed on `AclFacade`

- **Status:** accepted
- **Date:** 2026-08-23

## Context

`AclCriteriaBuilder` pushes a permission check into SQL so a list shows exactly what its owner
may see. Its docblock is emphatic about why that matters: filtering in PHP after the query
"looks fine in development" while page 1 quietly returns three of twenty rows and the total
count is a lie. `AclConsistencyTest` asserts it can never disagree with `PermissionResolver`.

It was also unreachable.

`AclCriteriaBuilder` lives in `src/Acl/Infrastructure/`. `deptrac.yaml` declares
`Application: [Domain]` and `Api: [Application, Domain]`. Neither an Application service nor a
controller may depend on Infrastructure (INV-01), so **no collection endpoint could legally
call it from anywhere an endpoint could plausibly live.** `config/services.yaml` recorded that
nothing consumed it yet, and nothing could have.

`docs/cookbook/add-endpoint.md` nonetheless instructed the reader to write, inside a service:

```php
$this->aclCriteria->apply($qb, 'n', 'note.read');
```

That line was wrong twice: it crosses a layer boundary deptrac forbids, and it is missing the
`Uuid $userId` argument the real signature requires. The recipe had never been executed — which
is the strongest possible argument for `make proof`, and a reminder that a documented example
is not a tested one.

Every list endpoint in the admin API is blocked on this.

## Decision

Declare the port in the Application layer and let Infrastructure implement it:

```
App\Acl\Application\AclQueryFilter      interface: apply(QueryBuilder, alias, permission, userId)
App\Acl\Infrastructure\AclCriteriaBuilder   implements it — no logic change
App\Acl\Application\AclFacade::filterToVisible(...)   delegates to the port
```

Other contexts reach it through `AclFacade::filterToVisible()`, which is their only legal door
into Acl (INV-02).

## Consequences

### Positive

- Collection endpoints become possible without changing a single deptrac rule. Every edge is
  already legal: Infrastructure → Application (the config comment explicitly blesses "ports
  declared in Application, implemented here"), Application → Application within a context, and
  `Account\Application\Service\* → AclFacade` which `RegisterUserService` already does.
- `AclCriteriaBuilder` keeps its DQL in Infrastructure, where persistence detail belongs.
- One door. `grep -rn filterToVisible src/` lists every collection that is ACL-filtered, and by
  omission every collection that is not.
- The count query and the row query provably share a predicate, because both are built from the
  same `QueryBuilder` after the same call.

### Negative

- `Doctrine\ORM\QueryBuilder` now appears in an Application-layer signature. This is a real
  leak of persistence vocabulary into a layer that nominally does not know about it.
- The facade grows a method that is not about authorization decisions but about query
  construction, which stretches "a single narrow surface".
- `AclFacade` gains a fifth constructor dependency.

## Alternatives rejected and why

- **Widen `deptrac.yaml` to `Application: [Domain, Infrastructure]`** — the single most
  damaging change available here. It legalises this call and every future shortcut at once, and
  INV-01 stops meaning anything. A rule relaxed to admit one legitimate case admits all the
  illegitimate ones silently.
- **Filter inside `DoctrineUserRepository`** — Account's Infrastructure would depend on Acl's
  Infrastructure, which is a context violation (INV-02) with no facade in between. It also
  hard-codes one permission into a repository that has other callers.
- **Put `AclCriteriaBuilder` in Application** — it writes DQL against `acl_entry` and reads
  Doctrine metadata to find the root entity. That is Infrastructure work, and moving it would
  put persistence detail in the layer this architecture most wants kept clean.
- **A separate `AclCollectionFacade`** — avoids widening `AclFacade`, but INV-12 forbids
  interfaces and indirection that exist only for tidiness, and a second facade would make
  "which door do I use?" a question again.

The port itself is not an INV-12 violation: it is a genuine boundary with a Doctrine adapter
behind it, exactly the case INV-12 carves out ("a second implementation, a clock, a mailer, an
HTTP egress"). It is not one interface per service.

## Reversal cost

**Cheap.** Delete the interface, drop the facade method, and put the call back — but there is
nowhere legal to put it back to, which is the point.

## Implemented by

- `backend/src/Acl/Application/AclQueryFilter.php`
- `backend/src/Acl/Infrastructure/AclCriteriaBuilder.php`
- `backend/src/Acl/Application/AclFacade.php` (`filterToVisible`)
- `docs/cookbook/add-endpoint.md` (the corrected recipe)
