# Recipe: add an API endpoint

The canonical recipe. Follow it literally — it is written so that following it produces code
that passes every check. **Do not hand-write the files.** The generator exists because
producing five conforming files by hand is exactly where shortcuts get taken.

Worked example: `GET /api/v1/notes`, listing notes the caller is allowed to read.

---

## 1. Generate the slice

```bash
make endpoint
```

You will be asked for five things. The third and fifth are the ones that matter:

| Prompt | Example | Notes |
|---|---|---|
| Bounded context | `Platform` | Must already exist. A new context is an architecture change — see [add-entity-with-acl.md](add-entity-with-acl.md). |
| Endpoint name | `ListNotes` | PascalCase, no `Controller` suffix. |
| HTTP method | `GET` | |
| Path | `/api/v1/notes` | Always under `/api/v1`. |
| Permission | `note.read` | `PUBLIC_ACCESS` **only** if genuinely public. |
| Responsibility | `Lists the notes a caller is permitted to read` | One sentence, **no "and"** (INV-10). |

Five files appear:

```
src/Api/Platform/ListNotesController.php          # route, #[IsGranted], one service call
src/Api/Platform/Request/ListNotesRequest.php     # request payload + validation
src/Api/Platform/Response/ListNotesResponse.php   # the exact fields clients see
src/Platform/Application/Service/ListNotesService.php
tests/Functional/Platform/ListNotesControllerTest.php
```

---

## 2. Fill them in, in this order

### 2a. The service — it owns the logic

`ListNotesService::__invoke()` is where the work goes. It **must not** take the request DTO:
that class lives in the Api layer, and Application may not depend on Api (deptrac enforces
this). Take scalars and value objects; the controller does the mapping.

For a collection endpoint, filter through the ACL rather than filtering in PHP:

```php
$qb = $this->notes->createQueryBuilder('n');
$this->aclCriteria->apply($qb, 'n', 'note.read');
```

This is not an optimisation. Fetching rows and filtering afterwards breaks pagination —
page 1 returns three rows because seven were filtered out — and it lets the list disagree
with a single-item permission check.

### 2b. The request DTO

Add the fields and their `#[Assert\*]` constraints. Validation lives **here**, never in the
controller and never in the service: `#[MapRequestPayload]` turns a violation into a 422
problem+json before the controller runs, so the service is entitled to assume valid input.

### 2c. The response view

Map the service result field by field in `from()`. **Never return an entity** (INV-05). This
is what stops a new column silently becoming public.

### 2d. The controller

Usually already correct. It should read as: DTO in, one service call, one view out. If you
are adding an `if` here, the condition belongs in the service.

---

## 3. Declare the permission

If the permission is new, add it to the catalog and sync — see
[add-permission.md](add-permission.md). Grants are code-declared so they are diffable in git
and survive a redeploy. **Never insert permission rows by hand.**

---

## 4. Finish the test

The generated test already asserts an anonymous caller gets 401. Two cases are marked
incomplete, and they are the ones that catch real bugs:

```php
public function testItRejectsACallerWithoutThePermission(): void
{
    // Log in as a user who does NOT hold note.read, request another user's note,
    // and assert 403 — or 404 if this resource hides existence. Both are fine;
    // pick one per resource and be consistent.
}
```

The second is the IDOR case: **user A requesting user B's id**. It is the single most
valuable test in a system with object-level permissions, because it is the failure that
looks correct in code review.

---

## 5. Verify

```bash
make check        # cs-fixer, PHPStan max, deptrac, PHPMD, tests
make docs         # regenerates docs/ENDPOINTS.md — commit the result
```

---

## What will catch you if you drift

| Mistake | What fails | Message |
|---|---|---|
| `EntityManagerInterface` in the controller | PHPStan | `bentley.noDoctrineInController` |
| Forgot `#[IsGranted]` | PHPStan + a router-walking functional test | `bentley.controllerMustHaveIsGranted` |
| Controller does the work itself | PHPStan | `bentley.controllerMustDelegateToService` |
| Service not `final readonly` | PHPStan | `bentley.serviceMustBeFinalReadonly` |
| `@responsibility` contains "and" | PHPStan | `bentley.serviceHasTwoTopics` |
| `Request` imported in the service | PHPStan | `bentley.noHttpInApplicationLayer` |
| Controller grew a branch | PHPMD | cyclomatic complexity > 3 |
| Called another context directly | deptrac | `must not depend on` |
| Forgot `make docs` | `docs.yml` | fails on diff |

Every one of these is proven to fire by `make proof`, which also proves a conforming slice
produces **no** errors. If you believe a rule is wrong, say so in the commit and change the
rule — do not add `@phpstan-ignore`.

---

## Checklist

- [ ] Generated with `make endpoint`, not by hand
- [ ] Service takes scalars/VOs, not the request DTO
- [ ] Collection endpoints filter via `AclCriteriaBuilder`, not in PHP
- [ ] Response view lists every field explicitly
- [ ] Permission declared in `PermissionCatalog` and synced
- [ ] Functional test covers: anonymous, wrong-permission, IDOR, happy path
- [ ] `make check` green
- [ ] `make docs` produces no diff
