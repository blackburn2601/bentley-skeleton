# `bentley-skeleton` — headless Symfony + Vue boilerplate

## Context

This repo (`/Users/sebbo/Applications/Github/bentley-skeleton`) holds **`bentley-skeleton`**: a reusable,
security-hardened GitHub template repo that every upcoming project starts from — a headless
PHP/Symfony API on PostgreSQL, a Vue.js SPA, Docker Compose deployment, and GitHub Actions for tests
and source-code security.

Two goals shape this beyond "a working app":

1. **AI-resumable.** A different AI session with *zero* prior context must be able to read a small,
   fixed set of files and then extend the system correctly — knowing not just *what* exists but *why*
   it was built that way. Documentation and ADRs are therefore first-class deliverables with
   CI-enforced freshness, not an afterthought.
2. **Strict source code.** API endpoints contain no logic; they call service classes. Each service
   class owns exactly **one semantic topic** (a PDF service does nothing about string normalization).
   This is enforced by tooling, not by convention — an agent that violates it gets a red pipeline
   with a message telling it which rule it broke.

Local toolchain verified: PHP 8.5.6, Composer 2.9.8, Node 26.7.0, Docker 29.5.3 (no `symfony` CLI —
scaffolding uses `composer create-project`).

### Decisions taken

| Area | Decision |
|---|---|
| Name | `bentley-skeleton`; composer `bentley/skeleton`, npm `@bentley/skeleton-frontend`, compose project `bentley`, image `ghcr.io/<org>/bentley-skeleton`. PHP root namespace stays `App\` (Symfony convention; per-project renaming is churn — ADR-0015) |
| API layer | Plain Symfony one-action controllers + request/response DTOs (`#[MapRequestPayload]`), OpenAPI via NelmioApiDocBundle. **No API Platform.** |
| Code architecture | 4 layers (`Api` → `Application` → `Domain` ← `Infrastructure`), thin controllers, single-topic `final readonly` services, enforced by deptrac + phpat + custom PHPStan rules |
| Auth | LexikJWT short-lived access token + opaque **rotating refresh token** (hashed in Postgres), both in HttpOnly/Secure/SameSite cookies; device/session list + revoke-all |
| Authorization | **Full per-object ACL table** (users ↔ groups ↔ roles ↔ permissions + object-scoped ACEs) behind Symfony Voters |
| Docs | `CLAUDE.md`/`AGENTS.md` entry point, `docs/ARCHITECTURE.md`, `docs/INVARIANTS.md`, MADR ADR log, `docs/cookbook/` recipes, generated `docs/SERVICES.md` + endpoint/permission inventories, freshness enforced in CI |
| Extras included | Ops & observability; Compliance & audit |
| Extras excluded | Async/Messenger + Scheduler; API hardening & DX kit (idempotency keys, webhooks, uploads/S3, cursor-pagination helpers, devcontainer) |

### Assumptions / deliberate deviations (flagging, not changing scope)

1. **Mailer is included minimally.** Email verification, password reset and invites cannot work
   without it. `symfony/mailer` + Mailpit in dev, sending **synchronously** (no Messenger worker).
2. **A consistent error contract is included** (RFC 9457 `application/problem+json`) plus the
   `/api/v1` prefix — a headless API needs one error shape. The rest of the "API hardening" group
   stays out.
3. **Symfony 7.4 LTS on PHP 8.5** (bugfixes to Nov 2028, security to Nov 2029), chosen over the
   current Symfony 8.1.5 because 8.1 is a standard release (~14 months of support) and a skeleton
   that seeds every future project should not need a major upgrade within the year. The cost is
   real and is recorded in ADR-0005: some bundles now ship `^8.0`-only majors, and
   `damienharper/auditor-bundle ^7.x` is one of them — see assumption 8.
4. **FrankenPHP** runtime (Caddy-based, HTTP/3, worker mode) instead of nginx + php-fpm: one
   container, same image dev and prod (ADR-0004).
5. **No multi-tenancy** (per-object ACL without tenancy). The resolver is written so a tenant scope
   can be added as one more subject dimension (ADR-0014).
6. **A small admin UI is included** in the SPA — a per-object ACL is unusable without screens for
   users, groups, roles, permissions and ACEs.
7. `symfony/acl-bundle` is **not** used: legacy (MaskBuilder, caps at Symfony `^7.0`, no collection
   filtering). The ACL is a purpose-built domain (~600 LOC) — ADR-0003.
8. **Entity-change auditing uses the `damienharper/auditor` core library, not `auditor-bundle`**
   (ADR-0017). Every `auditor-bundle ^7.x` release requires `symfony/framework-bundle ^8.0`, so it
   cannot install on 7.4 at all; the newest bundle that can is `^6.3`, and that one drags
   `symfony/twig-bundle`, `twig/extra-bundle`, `twig/intl-extra`, `symfony/asset` and
   `symfony/translation` into a headless API purely for an HTML audit viewer nothing renders. The
   core library carries none of that. The DI wiring the bundle would have done —
   `AuditConfiguration`, `DoctrineProvider`, storage/auditing services, Doctrine subscribers — is
   written by hand in `src/Audit/Infrastructure/` (one provider class plus service config).
9. **TypeScript 6, not 7** (ADR-0018). TypeScript 7 is the Go port and no longer exports
   `./lib/tsc`, which `vue-tsc` resolves at startup — `vue-tsc -b` dies with
   `ERR_PACKAGE_PATH_NOT_EXPORTED` on TS 7.0.2. `vue-tsc` has no TS 7 release (latest 3.3.11, no
   `next` tag), so SFC type-checking is only possible on TS 6 today. Verified empirically, not
   assumed. Revisit when Volar supports `tsgo`; the bump is a one-line constraint change.

---

## Stack (pinned, versions verified on Packagist/npm)

**Backend** — PHP 8.5 · Symfony 7.4 LTS · Doctrine ORM 3 + Migrations · PostgreSQL 18 · Redis 8
· `lexik/jwt-authentication-bundle ^3.2` · `nelmio/cors-bundle ^2.6` · `nelmio/api-doc-bundle ^5.11`
· `symfony/rate-limiter` · `symfony/monolog-bundle ^4` · `damienharper/auditor ^3.4` (ADR-0017)
· `stof/doctrine-extensions-bundle` · `predis/predis ^3` · `sentry/sentry-symfony ^5.12`
· `open-telemetry/opentelemetry-auto-symfony ^1.4`
· dev: `phpunit/phpunit ^13` · `phpstan/phpstan ^2.2` (+ symfony/doctrine/strict extensions)
· `phpat/phpat ^0.12` · `deptrac/deptrac ^4.7` (note: `qossmic/deptrac` is abandoned/renamed)
· `ergebnis/phpstan-rules ^2.13` · `phpmd/phpmd ^2.15` · `friendsofphp/php-cs-fixer ^3.95`
· `rector/rector ^2.6` · `zenstruck/foundry ^2.12` · `dama/doctrine-test-bundle ^8.6`
· `roave/security-advisories:dev-latest`

**Frontend** — Vue 3.5 · Vite 8 · TypeScript 6 · vue-router 5 · Pinia 4 · ESLint 10 + Prettier
· Vitest 4 · Playwright 1.62 · a fetch wrapper (no axios).

---

## Repository layout

```
bentley-skeleton/
├── CLAUDE.md                # entry point for AI sessions (AGENTS.md = same content)
├── README.md                # entry point for humans
├── backend/
│   ├── src/
│   │   ├── Api/             # HTTP only: controllers, request DTOs, response views, listeners
│   │   ├── Account/         # bounded context: registration, verification, reset, MFA, sessions
│   │   ├── Acl/             # bounded context: permission model, resolver, criteria builder
│   │   ├── Audit/           # bounded context: security events, GDPR export/erase
│   │   ├── Platform/        # cross-cutting: health, observability, rate limiting, request-id, mail
│   │   ├── Shared/          # Domain primitives shared by contexts (VOs, Clock, IdGenerator)
│   │   └── Maker/           # custom makers: make:api-endpoint, make:service, make:adr
│   │        …each context: Domain/ · Application/Service/ · Infrastructure/ (+ Api slice in src/Api)
│   ├── config/, migrations/, tests/{Unit,Integration,Functional,Architecture}
│   ├── deptrac.yaml, phpstan.neon, phpmd.xml, .php-cs-fixer.dist.php, rector.php
├── frontend/src/{api,stores,router,views,components,composables}
├── docker/{frankenphp,postgres,redis}/…
├── compose.yaml · compose.prod.yaml
├── docs/
│   ├── ARCHITECTURE.md · INVARIANTS.md · SECURITY.md · OPERATIONS.md · GLOSSARY.md
│   ├── SERVICES.md · ENDPOINTS.md · PERMISSIONS.md        # ← generated, CI-checked
│   ├── adr/{README.md, 0001-…md, …, template.md}
│   └── cookbook/{add-endpoint,add-service,add-entity-with-acl,add-permission,
│                 add-rate-limit-policy,add-migration,add-frontend-view,add-adr}.md
├── .github/workflows/{ci-backend,ci-frontend,architecture,docs,e2e,security,codeql}.yml
└── Makefile
```

---

## Phase 0 — Scaffolding & naming

- `composer create-project symfony/skeleton backend "7.4.*"`; `npm create vite@latest frontend -- --template vue-ts`.
- Name applied everywhere: composer/npm package names, compose project name, image name, README,
  `docs/`, JWT key paths, cookie prefix (`__Host-bentley_at` / `__Host-bentley_rt`).
- `Makefile`: `up down sh migrate fixtures test lint fix stan arch docs e2e new-project NAME=…`.
  `new-project` rewrites package/compose/image names + README title, generates a fresh per-machine
  `backend/.env` (via `bin/generate-env`) and the JWT keypair, resets the ADR log to the skeleton
  ADRs, and prints a "first 5 things to decide" list.
- `.editorconfig`, `.gitattributes`, git hooks (`core.hooksPath`) → cs-fixer + phpstan on staged PHP,
  eslint on staged TS, ADR-reminder hook on staged `src/Acl|src/Security` changes.

## Phase 1 — Architecture contract & enforcement (do this before feature code)

**Layers** (deptrac, `backend/deptrac.yaml`), allowed direction only:

| Layer | Contents | May depend on |
|---|---|---|
| `Api` | controllers, request DTOs, response views, HTTP listeners, voters | `Application`, `Domain` |
| `Application` | **service classes** (use cases + domain services), ports (interfaces) | `Domain` |
| `Domain` | entities, VOs, enums, domain exceptions, repository *interfaces* | — (only `Shared`) |
| `Infrastructure` | Doctrine repositories, mailer/redis/http/JWT adapters | `Domain`, `Application` ports |

Cross-context rule: a context's `Application` may **not** call another context's services directly —
only that context's declared facade service (`src/<Context>/Application/<Context>Facade.php`).
Adding a cross-context edge requires an ADR (CI reminds).

**Hard rules for controllers** (`Api`):
- one `final class` per endpoint with a single `__invoke()`; ≤ 60 LOC; cyclomatic complexity ≤ 3;
- forbidden: `EntityManagerInterface`, any `*Repository`, any `Doctrine\*`, any `if` on business
  state, direct entity serialization;
- allowed: DTO in, `#[IsGranted]`, one service call, one response view out.

**Hard rules for services** (`Application`):
- `final readonly class`, constructor injection only, no static/mutable state;
- **one semantic topic per class**, expressed as a mandatory one-sentence class docblock
  `@responsibility` — a service must be nameable in one sentence with no "and";
- no HTTP knowledge (`Request`, `Response`, `Session`, `HttpException` are forbidden imports);
- no `Doctrine\ORM\QueryBuilder` outside `Infrastructure` (single documented exception:
  `Acl\Infrastructure\AclCriteriaBuilder`);
- transactions owned here (`$this->tx->wrapInTransaction(...)`), never in controllers/repositories;
- interfaces only where there is a second implementation or a real port boundary — **no ceremonial
  interface-per-class** (stated explicitly so agents stop generating them);
- domain exceptions only; HTTP mapping happens once, in the problem+json listener.

**Enforcement** (all in CI, all with actionable failure messages):
- `deptrac/deptrac` layer + context rulesets;
- `phpat/phpat` rules inside PHPStan (naming, finality, forbidden dependencies, "controllers must
  depend on ≥1 Application service");
- PHPStan level max + `ergebnis/phpstan-rules` + 5 custom rules in `tests/Architecture/PhpStanRules/`:
  `NoDoctrineInControllerRule`, `NoHttpInApplicationLayerRule`, `ServiceMustBeFinalReadonlyRule`,
  `ServiceMustDeclareResponsibilityRule`, `ControllerMustHaveIsGrantedRule`;
- PHPMD for LOC/complexity limits on `Api`; php-cs-fixer; Rector (dry-run in CI).
- **Makers** (`src/Maker/`): `make:api-endpoint`, `make:service`, `make:entity-with-acl`, `make:adr`
  generate a complete conforming slice (controller + DTOs + service + tests + docs stub). An agent
  following the cookbook literally cannot produce a non-conforming slice.

## Phase 2 — Documentation & ADR system (built for a context-free AI session)

**Build order:** this phase is split in two. The *static* contract docs — `CLAUDE.md`/`AGENTS.md`,
`ARCHITECTURE.md`, `INVARIANTS.md`, the ADR log and the cookbook — are written straight after Phase 1
and **before** any feature code, because they are the contract the rest of the build follows. The
*generated* inventories and their CI freshness gate land after Phase 9, since a generator has nothing
to read until the services, endpoints and permissions exist. Phase 3 (Docker) is brought forward
ahead of both so Phases 4–7 have a live Postgres and Redis to test against.

**`CLAUDE.md` (+ identical `AGENTS.md`)** — short and imperative, the only file an agent must read
first. Contains: what this repo is; the read-in-this-order list (`CLAUDE.md` → `docs/ARCHITECTURE.md`
→ `docs/INVARIANTS.md` → the relevant `docs/cookbook/*.md` → `docs/adr/README.md`); the `make`
commands; the layering table; the "never do this" list; and "if you are adding X, follow recipe Y".

**`docs/ARCHITECTURE.md`** — module/context map, the layer table, a full request lifecycle trace
(HTTP → authenticator → voter/ACL → controller → service → repository → response), mermaid sequence
diagrams for login/refresh/ACL-check, and the generated inventories linked below.

**`docs/INVARIANTS.md`** — the highest-value artifact for a context-free agent: a numbered list
`INV-01 … INV-nn`, each row = *rule · why it exists · the automated check that enforces it · the
failure message you will see*. Example: `INV-07 Controllers contain no business logic — keeps the
system testable and the ACL centralized — enforced by NoDoctrineInControllerRule + PHPMD LOC limit`.

**ADR log** — `docs/adr/` in MADR 4 format; each ADR has Context, Decision, **Consequences**,
**Alternatives rejected and why**, **Reversal cost**, and links to the code that implements it.
Written as part of this build, not later:

| ADR | Subject |
|---|---|
| 0001 | Headless API, plain Symfony + DTOs instead of API Platform |
| 0002 | JWT access + rotating opaque refresh token in `__Host-` cookies |
| 0003 | Per-object ACL table instead of RBAC-only or `symfony/acl-bundle` |
| 0004 | FrankenPHP instead of nginx + php-fpm |
| 0005 | Symfony 7.4 LTS on PHP 8.5 rather than 8.1 — LTS lifetime vs. `^8.0`-only bundle majors |
| 0006 | Layered architecture, thin controllers, single-topic services |
| 0007 | RFC 9457 problem+json as the single error contract |
| 0008 | Rate-limit policy set and storage (Redis) |
| 0009 | PostgreSQL only — no separate search/queue infrastructure |
| 0010 | Synchronous mail, no Messenger in the skeleton |
| 0011 | Permissions resolved server-side, never carried in the JWT |
| 0012 | Append-only audit log with INSERT-only DB grants |
| 0013 | UUIDv7 primary keys |
| 0014 | No multi-tenancy yet, and how to add it |
| 0015 | Keep `App\` namespace; project identity via package/compose/image names |
| 0016 | Documentation-as-code: generated inventories + CI freshness gate |
| 0017 | `damienharper/auditor` core library instead of `auditor-bundle` (Symfony 7.4 support, no Twig in a headless API) |
| 0018 | TypeScript 6 until `vue-tsc` supports the TypeScript 7 Go port |

**Cookbook** — `docs/cookbook/*.md`, each a numbered, copy-pasteable recipe: which maker to run,
which files appear, what to add to them in order, which tests to write, which CI checks will catch a
mistake, and a final checklist. `add-endpoint.md` is the canonical one and doubles as the worked
example in `ARCHITECTURE.md`.

**Generated, CI-checked docs** — `bin/console app:docs:generate` writes:
- `docs/SERVICES.md` — every `Application` service with its `@responsibility` sentence and its
  context. This is how a fresh agent finds the *existing* service for a topic instead of writing a
  duplicate (directly serving your one-topic rule).
- `docs/ENDPOINTS.md` — route, method, required permission, request/response DTO, rate-limit policy.
- `docs/PERMISSIONS.md` — the code-declared permission catalog.
- `docs/adr/README.md` — ADR index table with status.
CI job `docs.yml` re-runs the generator and **fails on any diff**, plus markdown lint, link check and
ADR numbering/format check. So the docs cannot rot.

**ADR gate** — a PR touching `src/Acl`, `src/Account`, `config/packages/security.yaml`, `deptrac.yaml`
or `compose*.yaml` must add/modify an ADR or carry the `no-adr-needed` label.

## Phase 3 — Docker Compose (dev + prod)

**`compose.yaml` (dev):** `app` (FrankenPHP, bind-mounted source, Xdebug opt-in), `db`
(postgres:18-alpine + `citext`, healthcheck), `redis` (redis:8-alpine, appendonly), `mailpit`,
`node` (Vite dev server, HMR, proxies `/api` → `app`).

**`docker/frankenphp/Dockerfile`** — multi-stage: (1) composer deps with cache mount,
(2) `npm ci && npm run build` → SPA into `public/`, (3) runtime `dunglas/frankenphp:php8.5` with
opcache preload + JIT, `pdo_pgsql`/`redis`/`intl`/`opentelemetry`, non-root user,
`HEALTHCHECK /health/ready`, read-only-rootfs friendly (tmpfs cache, logs to stdout).
`ext-opentelemetry` comes from `pecl install` behind a `WITH_OTEL` build arg:
`opentelemetry-auto-symfony` hard-requires the extension, so it must be present at
composer-install time no matter what the runtime env flag says.

**`compose.prod.yaml`** — no bind mounts, pinned image digests, `read_only: true`, `cap_drop: [ALL]`,
`no-new-privileges`, resource limits, Docker secrets (DB password, JWT passphrase), a one-shot
`migrate` service gated before `app`, optional nightly `pg-backup` sidecar. App DB user is **not**
the schema owner; migrations run as owner. `docs/OPERATIONS.md`: TLS options, rollout, rollback,
backup/restore drill, log/metric endpoints.

## Phase 4 — Identity model & authentication

Entities (UUIDv7 PKs, `citext` email, Timestampable/SoftDeleteable):
`User` (argon2id hash, status enum, `email_verified_at`, encrypted `totp_secret`,
`failed_login_count`, `locked_until`, `password_changed_at`, `acl_version`), `UserGroup` +
membership, `Role`, `Permission`, `role_permission`, `user_role`, `group_role`,
`RefreshToken` (`token_hash`, `family_id`, `parent_id`, device/ip/ua, `revoked_at`, `replaced_by`),
`EmailVerificationToken`, `PasswordResetToken` (hashed, single-use, short TTL), `SecurityEvent`.

Endpoints `/api/v1/auth/*`: `register`, `verify-email`, `login`, `mfa/verify`, `refresh`, `logout`,
`logout-all`, `password/{forgot,reset,change}`, `me`, `sessions` (list/revoke),
`mfa/{setup,enable,disable}`, `mfa/recovery-codes`. Each = one controller + one `Account` service.

Tokens: access JWT 10 min, RS256, claims `sub`/`jti`/`roles`/`perm_v` (= `acl_version`), **no
permission list in the token** so revocation is immediate (ADR-0011). Refresh: 256-bit opaque, 30 d,
rotated every use, **reuse detection** revokes the whole family and logs `refresh_token_reuse`. Both
in `__Host-` HttpOnly/Secure/SameSite=Strict cookies with scoped paths; double-submit CSRF on
refresh/logout. Bearer-header mode behind a config flag for machine clients.

Hardening: argon2id (64 MB / 4), password policy ≥ 12 chars + strength scoring + HIBP k-anonymity
check (opt-out flag), per-account lockout with exponential backoff, constant-work anti-enumeration
responses, TOTP MFA + recovery codes, forced re-auth for sensitive operations.

## Phase 5 — ACL (per-object) — the core of the skeleton

**`acl_entry`:** `subject_type` (`user|group|role`), `subject_id`, `resource_class`,
`resource_id` (**NULL = class-level**), `permission_id`, `effect` (`allow|deny`), `expires_at`,
`granted_by`, `created_at`. Unique `(subject_type, subject_id, resource_class, resource_id,
permission_id)`; indexes on `(resource_class, resource_id, permission_id)` and `(subject_type, subject_id)`.

**Decision algorithm** (`Acl\Application\PermissionResolver::isGranted($user, $permission, ?$resource)`),
tier by tier, deterministic:
1. Subject set once per request: user + groups + effective roles.
2. Tiers, most specific first: `(class, objectId)` → each ancestor via `AclParentAware::getAclParent()`
   (e.g. Document → Folder) → `(class, NULL)`.
3. Per tier: any `deny` → **denied**; else any `allow` → **granted**; else next tier.
4. Fallback: RBAC — permission via any effective role → **granted**.
5. Default **denied**. `ROLE_SUPER_ADMIN` short-circuits and is itself audited.

`PermissionResolver::explain()` returns the winning ACE + tier → powers an admin "why can/can't X do
Y?" endpoint. Debuggability is what usually kills per-object ACLs.

**Collection filtering** — `Acl\Infrastructure\AclCriteriaBuilder::apply($qb, $alias, $permission)`
pushes the check into SQL: `EXISTS (allow …) AND NOT EXISTS (deny …)` over `acl_entry` for the
subject set, matching `resource_id = alias.id OR resource_id IS NULL`; class-level RBAC grants
short-circuit to no subquery. Documented escape hatch for hot paths: a denormalized `acl_effective`
projection maintained by an event subscriber — designed, not enabled.

**Caching** — per-request memoization + Redis keyed by `user.acl_version`; any role/group/ACE
mutation bumps `acl_version` (no invalidation sweeps, concurrency-safe).

**Integration** — one `AclVoter` bridging `#[IsGranted('invoice.update', subject: 'invoice')]`;
`#[IsGranted]` mandatory on every endpoint, enforced by `ControllerMustHaveIsGrantedRule` *and* a
functional test that walks the router.

**Admin API** `/api/v1/admin/*` (itself permission-guarded): users, groups, roles, permissions,
memberships, ACE grant/revoke, permission explain, effective-permission preview. The permission
catalog is code-declared (`PermissionCatalog` constants) and synced by
`bin/console app:acl:sync-permissions`, so grants are diffable in git and survive redeploys.

## Phase 6 — API layer, OWASP hardening, rate limiting

- One-action controllers; `#[MapRequestPayload]` DTOs + Validator; explicit response views (entities
  are never serialized directly — no accidental field leaks).
- `ProblemJsonExceptionListener` → RFC 9457 (`type`, `title`, `status`, `detail`, `instance`,
  `errors[]`, `requestId`); validation → 422; authz → 403, with a documented 404-on-no-read-permission
  policy per resource to avoid existence disclosure.
- Security headers subscriber: HSTS, `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-*-Policy`, nonce-based CSP for SPA and API
  docs; CORS via nelmio with an explicit origin allowlist + credentials (never `*`).
- SSRF guard: decorated HttpClient with egress allowlist, private-IP and redirect blocking.
- Payload limits (body size, JSON depth, array size), strict `Content-Type`, no form-encoded bodies.
- **Rate limiting** (`symfony/rate-limiter`, Redis) via `#[RateLimit('login')]` + subscriber; emits
  `X-RateLimit-{Limit,Remaining,Reset}` and 429 problem+json with `Retry-After`:

  | policy | limit |
  |---|---|
  | `login` | 5 / 15 min per (IP + email), sliding |
  | `password_reset`, `verify_resend` | 3 / h per email, 10 / h per IP |
  | `register` | 10 / h per IP |
  | `refresh` | 30 / h per user |
  | `api_user` | token bucket 120 / min, burst 60, per user |
  | `api_anon` | 30 / min per IP |
  | `admin_write` | 60 / min per user |

  Trusted-proxy config so client IPs are real; per-route overrides; all policies in one config file
  and listed in generated `docs/ENDPOINTS.md`.
- `docs/SECURITY.md`: threat model + OWASP Top 10 (2021) mapping table — control **and the test that
  proves it** — A01 ACL/voters/IDOR suite, A02 argon2id + hashed tokens, A03 Doctrine binding,
  A04 rate limits + lockout, A05 hardened images + headers, A06 CI dependency scans, A07 MFA +
  rotation + reuse detection, A08 pinned digests + lockfiles + SBOM, A09 security event log,
  A10 SSRF guard. Plus API Security Top 10 notes (BOLA/BOPLA ↔ ACL + response views).

## Phase 7 — Compliance & audit, ops & observability

- `SecurityEvent` append-only log (DB grant: INSERT only) for login success/failure, lockout, token
  reuse, permission/role/ACE change, MFA change, admin data access, GDPR actions — with actor, IP,
  UA, request id, JSONB payload; retention command.
- `damienharper/auditor` (core library, wired by hand in `src/Audit/Infrastructure/` — ADR-0017) for
  Doctrine entity change history; SoftDeleteable + Timestampable.
- GDPR: `POST /api/v1/me/export`, `DELETE /api/v1/me` (erase/anonymize with a documented retention
  exception list), `app:gdpr:purge`, data-inventory table in `docs/SECURITY.md`.
- Observability: Monolog JSON to stdout, `RequestIdSubscriber` (`X-Request-Id` in/out, propagated to
  logs, problem+json and audit rows), Sentry (release + user context, PII scrubbing), OpenTelemetry
  auto-instrumentation behind an env flag, `/health/live`, `/health/ready` (DB + Redis + migrations
  current), `/metrics` (Prometheus, IP-restricted) with request/latency/rate-limit/auth-failure
  counters.

## Phase 8 — Vue SPA

- `src/api/client.ts`: fetch wrapper — cookie credentials, CSRF double-submit, problem+json → typed
  errors, **single-flight** 401 → refresh → replay, hard logout on refresh failure.
- Pinia `authStore` (`user`, `roles`, `permissions` from `/me`), router guards, `usePermission()`
  composable + `v-can` directive (UI hiding only — never the authorization boundary; documented as
  an invariant).
- Views: login (+MFA), register, verify-email, forgot/reset password, profile, active sessions/devices,
  admin screens for users/groups/roles/permissions and the ACE editor with the "explain" panel.
- ESLint 10 + Prettier + `vue-tsc`, Vitest, Playwright. Frontend mirrors the strictness rule: views
  contain no business logic, API calls live in `src/api/*`, one module per topic.

## Phase 9 — Tests

- **Architecture** (`tests/Architecture/`): deptrac, phpat, custom PHPStan rules, plus a test
  asserting every route has `#[IsGranted]` and every `Application` service has an `@responsibility`.
- Unit: password policy, token hashing/rotation, rate-limit policies, **ACL decision matrix** as
  exhaustive table tests (subject type × tier × allow/deny × inheritance × expiry).
- Integration (Postgres + Redis, `dama/doctrine-test-bundle` rollback, `zenstruck/foundry`
  factories): repositories, and a cross-check test asserting `AclCriteriaBuilder` and
  `PermissionResolver` can never disagree (the classic per-object-ACL bug).
- Functional: full auth flows, refresh reuse detection, lockout, an **IDOR regression suite** (user A
  attempts every route with user B's ids), rate-limit 429s, problem+json shape, security-header
  snapshot.
- E2E (Playwright): login → MFA → admin grants an object permission → the affected user's access
  changes **without re-login**.
- Coverage gate: `src/Acl` + `src/Account` ≥ 90 %, global ≥ 80 %.

## Phase 10 — GitHub Actions

Actions pinned by SHA, `permissions: contents: read` default, concurrency groups, dependency caches.

- `ci-backend.yml` — `composer validate --strict`, cs-fixer dry-run, PHPStan max, Rector dry-run,
  `doctrine:schema:validate`, migrations **up and down** on a scratch DB, PHPUnit (postgres:18,
  redis:8) with coverage gate.
- `architecture.yml` — deptrac, phpat, PHPMD, `tests/Architecture` (separate job so a layering
  violation reports as such).
- `docs.yml` — `app:docs:generate` + **fail on diff**, markdown lint, link check, ADR format/numbering
  check, ADR-required gate on sensitive paths.
- `ci-frontend.yml` — `npm ci`, ESLint, `vue-tsc`, Vitest, production build with size budget.
- `e2e.yml` — build images, `docker compose up`, Playwright, upload traces on failure.
- `security.yml` — `composer audit`, `npm audit --audit-level=high`, **Semgrep** (`p/php`,
  `p/javascript`, `p/owasp-top-ten`, `p/secrets`), **Trivy** fs + image (fail HIGH/CRITICAL,
  `.trivyignore` entries carry expiry dates), **gitleaks**, **Syft** SBOM artifact, license policy;
  nightly **OWASP ZAP baseline** against the compose stack.
- `codeql.yml` — `javascript-typescript` + `actions` (PHP has no CodeQL analyzer; Semgrep covers it).
- `dependabot.yml` (composer, npm, docker, actions; grouped minor/patch), `CODEOWNERS`, PR template
  with security + ADR checklist, `SECURITY.md`, branch protection documented in `docs/OPERATIONS.md`.

---

## Additional features worth having (answering your last question)

**Included above** because they are cheap now and painful later: refresh-token reuse detection, TOTP
MFA, session/device management, HIBP password check, ACL `explain()`, code-declared permission
catalog, request-id correlation, live/ready split, migration up+down CI check, least-privilege DB
users, RFC 9457 errors, SBOM, ZAP baseline, IDOR regression suite, generated service/endpoint/
permission inventories, custom makers.

**Deliberately left out, easy to bolt on** (say the word): Messenger + worker + Scheduler; uploads
with MIME sniffing + S3/MinIO; idempotency keys; signed outbound webhooks; cursor pagination + filter
DSL; feature flags; i18n of API errors; `openapi-typescript` client generation; devcontainer;
Passkeys/WebAuthn; OIDC/SSO; admin impersonation with audit; multi-tenancy; Grafana/Loki profile;
Terraform/Ansible targets.

---

## Verification (how we prove it works)

1. `make up` → stack healthy; `curl localhost/health/ready` → 200 with db/redis/migrations checks.
2. `make migrate fixtures` → seeded admin, normal user, groups/roles/permissions, sample object ACEs.
3. Auth walkthrough with cookie jars: register → verify (link from Mailpit API) → login → `/me` →
   refresh (assert rotation) → replay old refresh token (assert family revoked + security event
   logged) → logout-all.
4. ACL walkthrough: user B reads user A's object → 403/404; admin grants an object-level ACE → same
   request → 200 **without re-login**; collection endpoint returns exactly what `PermissionResolver`
   allows (asserted automatically by the cross-check test).
5. Rate limiting: 6 rapid bad logins → 429 + `Retry-After`; headers present on normal requests.
6. `make lint stan arch docs test` and `make e2e` green locally.
7. **Strictness proof**: deliberately add a `EntityManagerInterface` to a controller, a service with
   two topics, and a cross-context service call — confirm `architecture.yml` fails with three
   distinct, readable messages.
8. **Docs-freshness proof**: add a service without regenerating docs → `docs.yml` fails on diff.
9. **Cold-start proof** (the real acceptance test for your AI goal): a fresh AI session, given only
   the repo, must add a new `GET /api/v1/notes` slice with an object-level permission by following
   `CLAUDE.md` → `docs/cookbook/add-endpoint.md`, and land it green with no extra guidance. If it
   can't, the docs — not the agent — get fixed.
10. Push a branch → all seven workflows green; then a scratch branch with a vulnerable dependency and
    a hardcoded secret to confirm `security.yml` actually fails.
