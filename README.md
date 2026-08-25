# bentley-skeleton

A security-hardened template for headless **Symfony 7.4 + Vue 3** applications: JWT
authentication with rotating refresh tokens, a per-object ACL, Docker Compose for development
and production, and a CI pipeline that enforces the architecture rather than describing it.

It is built to be extended by people **and by AI sessions with no prior context**. That goal
shapes everything else: the rules are machine-enforced, the decisions are written down with
their rejected alternatives, and the inventories that answer "does this already exist?" are
generated from the code and checked for freshness in CI.

> **Working in this repo?** Read [`CLAUDE.md`](CLAUDE.md) first — it is the entry point for
> humans and agents alike, and it is short.

---

## Quick start

```bash
make hooks                    # install the git hooks
make up                       # start the stack
make migrate fixtures         # schema + demo dataset
```

| | |
|---|---|
| API | <http://localhost:8080> |
| SPA | <http://localhost:5173> |
| API docs | <http://localhost:8080/api/doc> |

Then `make check` to run everything CI runs.

## Starting a real project from this template

```bash
make new-project NAME=acme-api
```

Rewrites every package, compose and image name, generates a fresh per-machine
`backend/.env` (`APP_SECRET`, JWT passphrase, TOTP key — via `bin/generate-env`; the file is
gitignored and never committed, see ADR-0028), regenerates the JWT keypair, and prints the
five things you must decide before shipping. It does **not** decide them for you — none of
them have a safe default.

---

## What is in the box

**Authentication** — argon2id, RS256 access tokens (10 min), opaque refresh tokens rotated
on every use with reuse detection that revokes the whole family, admin-issued username
credentials with one-time temporary passwords, per-account lockout with exponential
backoff, HIBP k-anonymity password checks, session and device management. Everything in
`__Host-` cookies.

**Authorization** — a real per-object ACL: users, groups, roles, permissions and
object-scoped entries, resolved most-specific-first with deny beating allow. Collection
endpoints are filtered in SQL by the same rules, and an integration test asserts the list and
the single-item check can never disagree. `explain()` answers "why can't this user do this?"
in production.

**Hardening** — RFC 9457 `problem+json` errors, security headers with a nonce-based CSP, a
CORS allowlist, an SSRF-guarded HTTP client, payload limits, and seven named rate-limit
policies backed by Redis.

**Compliance** — an append-only security event log with INSERT-only database grants, entity
change history, GDPR export and erasure.

**Operations** — one FrankenPHP image for development and production, read-only rootfs,
dropped capabilities, Docker secrets, gated migrations, JSON logs, request-id correlation,
Sentry, optional OpenTelemetry, liveness and readiness probes, Prometheus metrics.

---

## Why the pipeline is strict

The architecture is enforced by tooling, not convention: deptrac for layer and context
direction, custom PHPStan rules for the shape of controllers and services, PHPMD for size and
complexity. Break a rule and the build fails with a message naming the invariant and the fix.

`make proof` demonstrates this both ways — it plants a deliberate violation of each rule and
asserts every one fires, then generates a fully conforming slice and asserts it produces no
errors at all. A rule that cannot be satisfied is a bug in the rule.

`make endpoint` generates a complete conforming slice, so the correct path is also the
fastest one.

---

## Documentation

| | |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | Entry point. Start here. (`AGENTS.md` is identical.) |
| [`docs/INVARIANTS.md`](docs/INVARIANTS.md) | The rules, why each exists, and what enforces it |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Context map, request lifecycle, ACL decision flow |
| [`docs/adr/`](docs/adr/) | Every significant decision, with rejected alternatives |
| [`docs/cookbook/`](docs/cookbook/) | Copy-pasteable recipes for common changes |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Threat model and OWASP mapping, each control with its test |
| [`docs/OPERATIONS.md`](docs/OPERATIONS.md) | Deploy, roll back, restore, observe |

---

## Requirements

Docker is all you need to run it. To work on the code outside containers: PHP 8.5,
Composer 2, Node 26.

## License

Proprietary — adjust before publishing.
