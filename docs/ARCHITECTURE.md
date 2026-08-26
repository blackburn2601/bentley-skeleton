# Architecture

How the pieces fit and why they are arranged this way. Read `CLAUDE.md` and
`docs/INVARIANTS.md` first — this file explains the *shape*; those two tell you the rules.

---

## Contexts

`backend/src/` holds bounded contexts plus three non-context directories.

| Directory | Owns |
|---|---|
| `Account/` | authentication, admin-issued user provisioning and password reset, sessions and devices, token issuing and rotation |
| `Acl/` | the permission model, `PermissionResolver`, `AclCriteriaBuilder`, the permission catalog |
| `Audit/` | the append-only security event log, entity change history, GDPR export and erasure |
| `Platform/` | cross-cutting infrastructure: health checks, observability, rate limiting, request ids |
| `Shared/` | domain primitives every context may use — value objects, `Clock`, `IdGenerator` |
| `Api/` | **all** HTTP: controllers, request DTOs, response views, listeners, voters |
| `Maker/` | dev-time code generators |

Each context has `Domain/`, `Application/Service/` and `Infrastructure/`. Its HTTP surface
lives in `src/Api/<Context>/`, not inside the context — so the whole HTTP layer is one
directory you can review for authorization coverage in a single pass.

**A context may only reach another context through its `<Context>Facade`** (INV-02). `Api`
is exempt: controllers are the HTTP composition root and address contexts directly.

```mermaid
graph TD
    Api["src/Api<br/>controllers · DTOs · views · listeners"]
    Account["Account"]
    Acl["Acl"]
    Audit["Audit"]
    Platform["Platform"]
    Shared["Shared<br/>domain primitives"]

    Api --> Account
    Api --> Acl
    Api --> Audit
    Api --> Platform

    Account -.->|"AclFacade"| Acl
    Account -.->|"AuditFacade"| Audit
    Acl -.->|"AuditFacade"| Audit

    Account --> Shared
    Acl --> Shared
    Audit --> Shared
    Platform --> Shared
```

Dotted edges are facade calls — the only legal cross-context path.

---

## Layers

| Layer | Holds | May depend on |
|---|---|---|
| `Api` | controllers, request DTOs, response views, HTTP listeners, voters | `Application`, `Domain` |
| `Application` | service classes, ports (interfaces) | `Domain` |
| `Domain` | entities, value objects, enums, domain exceptions, repository *interfaces* | nothing |
| `Infrastructure` | Doctrine repositories, Redis / HTTP / JWT adapters | `Domain`, `Application` ports |

Enforced by `backend/deptrac.yaml`. The Domain layer's empty dependency list is the load-
bearing part: it is the only code here that survives a framework change.

---

## The life of a request

A worked example — `GET /api/v1/notes/{id}`, an endpoint requiring the object-level
permission `note.read`.

1. **FrankenPHP** receives the request and hands it to the Symfony kernel.
2. **`RequestIdSubscriber`** reads `X-Request-Id` or generates one. Everything downstream —
   logs, the audit row, the problem+json body — carries it.
3. **`TrustedProxy` handling** resolves the real client IP. Rate limiting and audit records
   are wrong without this.
4. **The authenticator** reads the `__Host-bentley_at` cookie, verifies the RS256 signature,
   and builds the security token from `sub` and `roles`. The token carries **no permission
   list** (ADR-0011).
5. **`RateLimitSubscriber`** applies the route's `#[RateLimit]` policy. Over the limit: 429
   problem+json with `Retry-After`, and the controller never runs.
6. **`#[IsGranted('note.read', subject: 'note')]`** triggers `AclVoter`, which calls
   `PermissionResolver::isGranted()`. Every endpoint has this attribute — a missing one is a
   build failure (INV-11).
7. **`PermissionResolver`** decides — see below.
8. **The controller** runs only if permitted. It maps the request DTO onto the service's
   parameters and calls it. Nothing else (INV-07).
9. **The Application service** does the work inside a transaction it owns (INV-06) and
   returns domain data. It has no idea it was reached over HTTP (INV-08).
10. **The response view** maps that result field by field. Entities are never serialized
    (INV-05).
11. **On any exception**, `ProblemJsonExceptionListener` maps it to RFC 9457 — the only place
    that knows about status codes (INV-17).

---

## How a permission decision is made

`PermissionResolver::isGranted($user, $permission, $resource)` evaluates tiers from most
specific to least. Within a tier, **deny beats allow**; if the tier is silent, it falls
through.

```mermaid
flowchart TD
    A["isGranted(user, permission, resource)"] --> B["Resolve subject set once per request:<br/>user + groups + effective roles"]
    B --> C{"ROLE_SUPER_ADMIN?"}
    C -->|yes| SA["granted<br/>(and audited)"]
    C -->|no| T1["Tier 1: (class, object id)"]
    T1 --> D1{"any deny?"}
    D1 -->|yes| DENY["denied"]
    D1 -->|no| A1{"any allow?"}
    A1 -->|yes| GRANT["granted"]
    A1 -->|no| T2["Tier 2: each ACL parent<br/>via getAclParent()"]
    T2 --> D2{"any deny?"}
    D2 -->|yes| DENY
    D2 -->|no| A2{"any allow?"}
    A2 -->|yes| GRANT
    A2 -->|no| T3["Tier 3: (class, NULL)<br/>class-level grant"]
    T3 --> D3{"any deny?"}
    D3 -->|yes| DENY
    D3 -->|no| A3{"any allow?"}
    A3 -->|yes| GRANT
    A3 -->|no| RBAC{"permission via<br/>any effective role?"}
    RBAC -->|yes| GRANT
    RBAC -->|no| DENY
```

Two properties matter more than the algorithm itself:

- **`explain()`** returns the winning entry and the tier it came from. Per-object ACLs are
  usually abandoned because nobody can answer "why can't this user do this?" in production.
- **`AclCriteriaBuilder`** pushes exactly these rules into SQL for collection endpoints, so a
  list cannot show something a single-item check would refuse. An integration test asserts
  the two can never disagree — that is the classic bug in this design.

Results are memoized per request and cached in Redis under a key containing the user's
`acl_version`. Any role, group or ACE change bumps that version, so a grant takes effect on
the next request with no invalidation sweep and no re-login (ADR-0011).

---

## Login

```mermaid
sequenceDiagram
    participant SPA
    participant API
    participant DB as Postgres
    participant R as Redis

    SPA->>API: POST /api/v1/auth/login
    API->>R: rate limit "login" (IP + username)
    alt over limit
        API-->>SPA: 429 problem+json + Retry-After
    end
    API->>DB: load user by citext username
    API->>API: argon2id verify (constant work even if user is absent)
    alt bad credentials
        API->>DB: failed_login_count++, maybe locked_until
        API->>DB: SecurityEvent(login_failed)
        API-->>SPA: 401 problem+json (no hint whether the account exists)
    end
    API->>DB: store refresh token HASH (new family)
    API->>DB: SecurityEvent(login_succeeded)
    API-->>SPA: 204 + Set-Cookie __Host-bentley_at, bentley_rt
```

The anti-enumeration property is deliberate: the same work and the same response shape
whether or not the account exists.

## Refresh, and what happens when a token is stolen

```mermaid
sequenceDiagram
    participant SPA
    participant API
    participant DB as Postgres

    SPA->>API: POST /api/v1/auth/refresh (cookie + CSRF double-submit)
    API->>DB: look up hash of presented token
    alt unknown
        API-->>SPA: 401
    else already rotated (reuse!)
        API->>DB: revoke the ENTIRE family
        API->>DB: SecurityEvent(refresh_token_reuse)
        API-->>SPA: 401 — every session in that family is now dead
    else valid
        API->>DB: mark used, issue successor in the same family
        API-->>SPA: 204 + new access and refresh cookies
    end
```

Rotation is what makes theft *detectable*. Whichever party refreshes second presents a token
that has already been used, and the family dies — so a stolen refresh token cannot be used
quietly for its full lifetime.

---

## Frontend

`frontend/src/` mirrors the backend's strictness: views hold no business logic, API calls
live in `src/api/`, one module per topic.

- **`api/client.ts`** — a fetch wrapper. Sends cookies, adds the CSRF double-submit header,
  turns `problem+json` into typed errors, and on a 401 performs a **single-flight** refresh
  and replays the original request. Single-flight matters: without it, ten concurrent 401s
  become ten refreshes, nine of which present an already-rotated token and trip reuse
  detection, logging the user out.
- **`stores/auth.ts`** — Pinia store holding the user, roles and permissions from `/me`.
- **`usePermission()` / `v-can`** — **UI hiding only, never an authorization boundary**
  (INV-16). The browser is not a trust boundary; a hidden button is still a reachable
  endpoint.

---

## Generated inventories

Do not edit these — change the code and run `make docs`. CI runs `make docs-check`, which
fails naming any file the generator would rewrite (ADR-0016).

| File | Answers |
|---|---|
| [`SERVICES.md`](SERVICES.md) | Does a service already own this topic? |
| [`ENDPOINTS.md`](ENDPOINTS.md) | What routes exist, with which permission and rate-limit policy? |
| [`PERMISSIONS.md`](PERMISSIONS.md) | What permissions exist? |
| [`adr/README.md`](adr/README.md) | Every decision, with status |
