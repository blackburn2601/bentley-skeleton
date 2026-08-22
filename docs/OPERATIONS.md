# Operations

Running this in production: deploy, roll back, restore, observe.

---

## Topology

One application image (ADR-0004): FrankenPHP serving the API and the built SPA. Postgres and
Redis alongside. TLS terminates at an ingress in front of the stack; the container speaks
plain HTTP on `:80` and publishes nothing else.

```
ingress (TLS) ──▶ app (FrankenPHP)  ──▶ db     (Postgres 18)
                                    └──▶ redis  (Redis 8)
```

`migrate` is a one-shot service gated before `app` starts. `pg-backup` is behind a profile
and runs on demand.

## What is different in production

`compose.prod.yaml` is an overlay, not a separate stack. It exists to reduce what a
compromised container can do:

| Setting | Why |
|---|---|
| `image:` pinned by **digest** | A tag is mutable — `:1.4.2` can point at different bytes tomorrow, so a rollback to a tag is not guaranteed to be a rollback |
| `read_only: true` | The filesystem is immutable at runtime; only `/app/var`, `/tmp`, `/data`, `/config` are tmpfs. This is why the cache is warmed at build time |
| `cap_drop: [ALL]` | `NET_BIND_SERVICE` is added back, and only because FrankenPHP binds `:80` as a non-root user |
| `no-new-privileges` | A setuid binary cannot escalate |
| Docker secrets | Passwords arrive as files, not environment variables — env leaks into `docker inspect`, crash dumps and child processes |
| Resource limits | One runaway container cannot starve the host |
| No published ports | Only the ingress is reachable |
| Separate DB roles | The app is **not** the schema owner — see below |

## Database roles

Two roles, deliberately:

- **owner** (`bentley_owner`) — owns the schema. Migrations run as this role.
- **application** (`bentley_app`) — DML only. It cannot alter the schema, and holds
  **INSERT only** on `security_event` (ADR-0012), so an attacker with application-level code
  execution still cannot rewrite the audit trail.

Grants live in the migration that creates each table. If you add a table the application must
write to, grant it explicitly; if you add an audit-like table, grant INSERT only.

---

## Deploy

```bash
export APP_IMAGE=ghcr.io/OWNER/bentley-skeleton@sha256:<digest>
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

The order is enforced by compose, not by you: `migrate` runs to completion, then `app`
starts, and `app` only becomes healthy once `/health/ready` reports database, cache and
migrations all up.

**Migrations must be backward compatible with the code currently running.** During any
rollout both versions exist at once. A destructive change is therefore two deploys:

1. Deploy code that no longer reads the column.
2. Deploy the migration that drops it.

Doing it in one deploy takes an outage for the duration of the rollout.

## Roll back

```bash
export APP_IMAGE=ghcr.io/OWNER/bentley-skeleton@sha256:<previous-digest>
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

This rolls back **code only**. Rolling a migration back is a separate, deliberate act
(`doctrine:migrations:migrate prev`) and is only safe if that migration was reversible —
which CI proves for every migration by running it up and then down on a scratch database.

If a migration is *not* safely reversible, the honest answer is roll forward with a fix.

## Backup and restore

```bash
docker compose -f compose.yaml -f compose.prod.yaml --profile backup run --rm pg-backup
```

**Practise the restore.** An untested backup is a hope, not a backup:

```bash
createdb -T template0 bentley_restore_test
pg_restore --dbname=bentley_restore_test --clean --if-exists ./backups/bentley-<stamp>.dump
psql -d bentley_restore_test -c 'SELECT count(*) FROM "user";'
```

Do this on a schedule, not after an incident. Restore-time surprises — a missing extension, a
role that does not exist on the target, an ownership mismatch — are all discoverable in
advance and none of them are discoverable from the dump alone.

## Rotating secrets

Files in `docker/secrets/`, never committed (`.gitignore` covers them).

- `app_secret` — rotating it invalidates signed/serialized values derived from it.
- `jwt_passphrase` — **rotating this invalidates every access token immediately.** Users are
  refreshed back in transparently by the SPA's single-flight refresh, provided the refresh
  cookies still validate.
- `db_password`, `db_owner_password` — rotate in Postgres first, then update the file, then
  recreate the containers.

---

## Observability

| Endpoint | Purpose |
|---|---|
| `/health/live` | Is the process wedged? Checks **nothing** else — see below |
| `/health/ready` | Should this replica receive traffic? Database, cache, migration state |
| `/metrics` | Prometheus. IP-restricted |

**Liveness must not check dependencies.** If it did, a brief database blip would make the
orchestrator conclude every container was broken and restart the whole fleet, turning a
recoverable dependency failure into a full outage. Liveness means "do not restart me";
readiness means "I can serve right now".

Logs are JSON on stdout — the container is the log, nothing is written to disk, which is what
makes the read-only root filesystem possible. Every line carries the request id, which also
appears in the `problem+json` error body the user saw and in the audit row. That is the thread
to pull when someone reports a failure: ask for the request id.

Sentry is enabled in production with PII scrubbing. OpenTelemetry is compiled into the image
only when built with `WITH_OTEL=1`, and is inert unless `OTEL_PHP_AUTOLOAD_ENABLED=true`.

---

## Branch protection

Configure on the default branch — this cannot be committed to the repository, so it is
recorded here:

- Require pull requests; no direct pushes.
- Require these checks: `ci-backend`, `ci-frontend`, `architecture`, `docs`, `security`,
  `codeql`, `e2e`.
- Require branches to be up to date before merging.
- Require review from `CODEOWNERS`.
- Dismiss stale approvals on new commits.
- Restrict who can push to tags.

## Runbook: common failures

| Symptom | Likely cause | Check |
|---|---|---|
| `app` never becomes healthy | Migrations pending | `curl /health/ready` — the `migrations` check names the count |
| 503 from every endpoint | Database unreachable | `/health/ready` `database` check; then `docker compose logs db` |
| Everyone rate-limited at once | `TRUSTED_PROXIES` wrong, so every client looks like the load balancer | Compare the IP in an audit row against the real client |
| Users logged out unexpectedly | Refresh-token reuse detection fired | `security_event` for `refresh_token_reuse` — concurrent refreshes, or a genuinely stolen token |
| `permission denied for table security_event` | Something tried to UPDATE or DELETE an audit row | Working as designed (ADR-0012); find the caller |
| Stale dependencies after a rebuild in dev | The anonymous `/app/vendor` volume was reused | `make down && make up` (the `up` target passes `--renew-anon-volumes`) |
