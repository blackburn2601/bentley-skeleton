# bentley-skeleton

A headless Symfony 7.4 API with a Vue 3 SPA, built to be extended by people and by AI
sessions that have no prior context. **You are probably one of those. Read this file
completely before writing anything.**

The architecture is enforced by tooling, not by convention. You do not have to remember the
rules — you have to run `make check` and read what it tells you. Every rule failure names the
invariant it broke and what to do instead.

---

## Read in this order

1. **This file** — what the repo is and how to work in it.
2. **`docs/INVARIANTS.md`** — the rules, why each exists, and the check that enforces it.
   This is the highest-value file in the repo if you are new.
3. **The recipe for what you are doing** — `docs/cookbook/`. Follow it literally; it is
   written so that following it produces conforming code.
4. **`docs/ARCHITECTURE.md`** — the module map and a full request trace, when you need to
   understand *why* the pieces are arranged this way.
5. **`docs/adr/README.md`** — every significant decision, with the alternatives that were
   rejected and why. Read the relevant ADR *before* proposing to change a decision.

Generated inventories — use these to find existing code instead of writing a second copy:

| File | Answers |
|---|---|
| `docs/SERVICES.md` | "Does a service already own this topic?" |
| `docs/ENDPOINTS.md` | "What routes exist, and what permission does each need?" |
| `docs/PERMISSIONS.md` | "What permissions exist?" |

---

## Commands

`make help` lists everything. The ones you will actually use:

```
make up                 start the dev stack (API :8080, SPA :5173)
make migrate fixtures   apply migrations, load the demo dataset
make check              everything CI runs except e2e — run this before you say you are done
make fix                apply every safe automatic fix (cs-fixer, then Rector)
make stan               PHPStan at max, including the architecture rules
make arch               deptrac, PHPMD and the architecture tests
make proof              prove the rules still fire (negative and positive controls)
make docs               regenerate the inventories after changing code
make docs-check         fail if any inventory is stale (what CI runs)
```

Generators — **use these, do not hand-write a slice**:

```
make endpoint           a full conforming endpoint (controller, DTOs, view, service, test)
make service            a single-topic Application service and its unit test
                        Both prompt for what you omit. You have no terminal, so pass it all:
                          make service CONTEXT=Account NAME=RotateToken WHY="One sentence, no 'and'"
                          make endpoint CONTEXT=Account NAME=ListNotes METHOD=GET \
                            ROUTE=/api/v1/notes PERMISSION=note.read WHY="One sentence"
make adr TITLE="..."    the next architecture decision record
```

---

## The layering

| Layer | Holds | May depend on |
|---|---|---|
| `Api` | controllers, request DTOs, response views, HTTP listeners, voters | `Application`, `Domain` |
| `Application` | **service classes**, ports (interfaces) | `Domain` |
| `Domain` | entities, value objects, enums, domain exceptions, repository *interfaces* | nothing |
| `Infrastructure` | Doctrine repositories, Redis/HTTP/JWT adapters | `Domain`, `Application` ports |

Bounded contexts live in `backend/src/<Context>/` — `Account`, `Acl`, `Audit`, `Platform`,
plus `Shared` for domain primitives. **A context may only use another context through that
context's `<Context>Facade`.** `src/Api` is exempt: controllers are the HTTP composition
root.

---

## Never do this

Each of these fails the build. They are listed because they are the things that get written
by reflex.

- **Never put logic in a controller.** A DTO in, one `#[IsGranted]`, one service call, one
  response view out. No `EntityManagerInterface`, no repository, no `if` on business state.
- **Never write an endpoint without `#[IsGranted]`.** If it is genuinely public, say so with
  `#[IsGranted('PUBLIC_ACCESS')]`. A missing attribute is a silently public endpoint.
- **Never give a service two topics.** The `@responsibility` sentence must not contain
  "and". If it does, write two services.
- **Never import `Request`, `Response` or `HttpException` below the Api layer.**
- **Never serialize an entity into a response.** Map it onto a response view, field by field.
- **Never generate an interface per service.** Interfaces are for real port boundaries — a
  second implementation, a clock, an HTTP egress. One implementer means no
  interface.
- **Never put a permission list in the JWT.** Permissions are resolved server-side so that
  revocation takes effect on the next request.
- **Never call another context's services directly.** Use its facade.
- **Never add a `@phpstan-ignore` or a baseline to make a rule go away.** If a rule is wrong,
  fix the rule and say so in the commit; `make proof` guards against rules that cannot be
  satisfied.
- **Never edit a generated file** (`docs/SERVICES.md`, `docs/ENDPOINTS.md`,
  `docs/PERMISSIONS.md`, `docs/adr/README.md`). Change the code and run `make docs`.

---

## If you are adding X, follow recipe Y

| You are adding | Follow |
|---|---|
| An API endpoint | `docs/cookbook/add-endpoint.md` ← start here; this is the canonical one |
| A service | `docs/cookbook/add-service.md` |
| An entity with object-level permissions | `docs/cookbook/add-entity-with-acl.md` |
| A permission | `docs/cookbook/add-permission.md` |
| A rate-limit policy | `docs/cookbook/add-rate-limit-policy.md` |
| A database change | `docs/cookbook/add-migration.md` |
| A screen in the SPA | `docs/cookbook/add-frontend-view.md` |
| A decision worth recording | `docs/cookbook/add-adr.md` |

---

## When you change something sensitive, write an ADR

Touching `src/Acl`, `src/Account`, `config/packages/security.yaml`, `deptrac*.yaml` or
`compose*.yaml` requires an ADR (`make adr TITLE="..."`) or the `no-adr-needed` label. The
pre-commit hook reminds you; `.github/workflows/docs.yml` enforces it.

This is not bureaucracy. The reason a decision was made is the single hardest thing to
recover later, and it is exactly what the next session will need.

---

## Definition of done

1. `make check` is green.
2. `make docs-check` passes.
3. New endpoints have a functional test that asserts an unpermitted caller is refused.
4. Anything security-relevant has an ADR.
