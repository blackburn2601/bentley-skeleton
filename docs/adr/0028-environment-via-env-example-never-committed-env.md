# 0028. Environment via .env.example, never a committed .env

- **Status:** accepted
- **Date:** 2026-08-25
- **Deciders:** Sebastian Wagner

## Context

The skeleton shipped `backend/.env` committed to git, with secret-shaped dev values inside
it: `APP_SECRET` (the HMAC key for signed URLs and CSRF), `JWT_PASSPHRASE` (the passphrase
on the JWT signing keypair), and `TOTP_SECRET_KEY` (the libsodium key that encrypts users'
TOTP secrets at rest, ADR-0026). The JWT keypair files themselves were already gitignored
and never committed, so `JWT_PASSPHRASE` alone was worthless — but `APP_SECRET` and
`TOTP_SECRET_KEY` were live keys sitting in a public repository.

The owner's position was unambiguous: **no `.env` file is ever committed**. The real
environment file is per-machine — generated locally for dev, generated in CI for the run, and
provisioned from Docker secrets in production. The committed file is a template,
`.env.example`, that documents every variable the app expects with the non-secret dev
defaults filled in and the secret slots left empty.

This is the industry-standard `.env.example` pattern, but it is not free here: four CI
workflows, `make up` and `bin/new-project` all boot the Symfony console and assumed a
`backend/.env` was already on disk. `.env.test` is loaded *after* `.env` (its own header says
so) and does not redefine `JWT_PASSPHRASE`, `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `LOCK_DSN`
or the CORS/egress/OTEL settings — so the test environment still needs a `.env` to exist.
Each of those call sites gets one idempotent generate step.

## Decision

1. **`backend/.env.example` is the committed template.** Every non-secret dev default stays
   (database/redis URLs, TTLs, CORS, trusted proxies, OTEL flags, the JWT key *paths* —
   paths, not secrets). The three secret slots are emptied: `APP_SECRET=`, `JWT_PASSPHRASE=`,
   `TOTP_SECRET_KEY=`, each with a comment naming the generator that fills it and the
   production source.

2. **`backend/.env` is gitignored and removed from the tree.** An anchored `/.env` rule in
   `backend/.gitignore` ignores only the file named `.env` — `.env.example` and `.env.test`
   stay tracked.

3. **`bin/generate-env` generates the real `.env` per machine.** Idempotent: if
   `backend/.env` exists it is left untouched (a developer's or operator's set values are
   never clobbered); otherwise it copies `.env.example` and writes fresh secrets with
   `openssl` — `APP_SECRET` 16 bytes hex, `JWT_PASSPHRASE` 32 bytes hex, `TOTP_SECRET_KEY`
   32 bytes base64 (the exact `SODIUM_CRYPTO_SECRETBOX_KEYBYTES` SodiumSecretBox decodes).
   It needs only `openssl` + `perl`, no PHP, no container.

4. **`make env` wraps `bin/generate-env`; `make up` depends on `env`**, so out-of-the-box
   `git clone && make up` still works on a fresh checkout.

5. **CI runs `bin/generate-env` after checkout (and `composer install` where applicable)**,
   before any step that boots the kernel: `ci-backend` (static-analysis and tests jobs),
   `architecture`, `docs`, `e2e`, and the `image` job of `security`. That last one is easy to
   miss: the `trivy image` job does not run the app, but it *builds* the production image, and
   the Dockerfile runs `cache:warmup --env=prod` at build time — which boots the kernel and
   needs a `.env` in the build context. The other `security` jobs (gitleaks, composer-audit,
   semgrep, trivy filesystem) genuinely never boot the app and need no `.env`. In `e2e` the
   host generates `.env` and the `./backend` bind mount brings it into the container; in the
   `image` job the `COPY backend/ /app/` step bakes the generated `.env` into the image, as
   the committed `.env` did before.

6. **Production does not run `generate-env`.** It provisions the secrets as real environment
   variables or Docker secrets (the existing A05 control in `docs/SECURITY.md`, "Secrets as
   Docker secrets, not environment variables", remains the production control and is
   unchanged). `.env.example` is documentation of what production must supply.

7. **`bin/new-project` reseeds `.env` for the new project.** The rename loop already rewrites
   `bentley`→`<short>` inside the tracked `.env.example` (DATABASE_URL, CORS, etc.);
   `new-project` now deletes any stale local `.env` and runs `generate-env` so the new
   project's `.env` carries the new identity and fresh secrets, then regenerates the JWT
   keypair from the fresh passphrase.

8. **`.env.test` stays committed.** It is a test fixture whose values are visible non-secret
   literals (a fixed zero TOTP key so SodiumSecretBox constructs, a fixed `APP_SECRET`, a
   separate database URL). Tests must stay reproducible across machines, so it is not
   generated.

## Consequences

### Positive

- No secrets of any kind live in git. A fresh clone has none until `generate-env` writes
  them, and each machine gets its own values.
- gitleaks stays green by construction rather than by suppression: `.env.example` has empty
  secret slots, so there is nothing to flag in the current tree.
- The dev story is unchanged — `git clone && make up` generates `.env` on the first `up`.
- Production's Docker-secret control (ADR/SECURITY A05) is the authoritative source and is not
  touched; `.env.example` is only its documentation.

### Negative

- One `bin/generate-env` step in four CI workflows. Mechanical, but it is a new moving part
  that must keep running or those workflows fail to boot the kernel.
- The historical dev secrets remain in git history on public GitHub. Removing a file from the
  tree does not un-leak it. They are **neutralised, not removed**: once every environment
  generates fresh secrets, the old `APP_SECRET`/`JWT_PASSPHRASE`/`TOTP_SECRET_KEY` are no
  longer the active key — they sign and decrypt nothing. `APP_SECRET` and `JWT_PASSPHRASE`
  are hex and sit below gitleaks' `generic-api-key` entropy threshold, so they were never
  flagged. `TOTP_SECRET_KEY` is base64 and was pinned at its introducing commit
  (`7214e858…`, in `.gitleaksignore`) so a rotated real key is never silently covered. A full
  history rewrite (`git filter-repo --path backend/.env --invert-paths`) would rewrite every
  SHA and force-push public `main`; it is not worth that cost for already-neutralised dev
  values and is deliberately not done.

## Alternatives rejected and why

- **Keep `backend/.env` committed with only non-secret defaults, secrets in `.env.local`.**
  Violates the owner's hard constraint — *no* `.env` committed, ever. `.env.local` is already
  gitignored and is the right place for a developer's local overrides, but it cannot be the
  *only* home of the secret slots, because CI and a fresh clone start with no `.env.local`
  either, and `.env.test` depends on `.env` existing. Rejected.

- **Move all dev defaults into `config/packages/dev/*.yaml` instead of a template.** Removes
  the env file entirely, but is a large refactor of every `%env(VAR)%` reference across
  config and services, and it conflates non-secret defaults with the secret slots that still
  need a per-machine source. The `.env.example` pattern is the smaller, conventional change.
  Rejected as disproportionate.

- **Purge the historical `.env` from git history.** `git filter-repo --path backend/.env
  --invert-paths` rewrites every commit SHA and requires a force-push to public `main`, with
  every downstream clone needing a reset. The historical values are dev-only and are
  neutralised by rotation (see Consequences). The cost is real and the benefit is cosmetic
  on already-dead keys. Deferred, not rejected outright — it remains the owner's call if the
  repository's visibility or ownership changes.

## Reversal cost

Cheap. Reversing is `git revert` of the rename plus the four workflow edits; the
`.gitignore` rule re-allows `backend/.env`, and the historical file is recoverable from git
at any commit before this one. No data migration, no schema change, no API contract change.
The only stateful piece is the per-machine `.env` files `generate-env` has written, which a
reversal simply stops generating.

## Implemented by

- `backend/.env.example` — the committed template (renamed from `backend/.env`, secret slots
  emptied)
- `backend/.gitignore` — `/.env` rule ignoring the generated file
- `bin/generate-env` — idempotent per-machine generator
- `Makefile` — `env` target, `up: env` prerequisite
- `bin/new-project` — regenerates `.env` for the reseeded project
- `.github/workflows/ci-backend.yml` — `generate-env` in static-analysis and tests jobs
- `.github/workflows/architecture.yml` — `generate-env` before `cache:warmup`
- `.github/workflows/docs.yml` — `generate-env` before `app:docs:generate --check`
- `.github/workflows/e2e.yml` — `generate-env` on the host before `docker compose up`
- `.github/workflows/security.yml` — `generate-env` before the production image build (the
  `trivy image` job), so build-time `cache:warmup` has a `.env`
- `.gitleaksignore` — pinned historical `TOTP_SECRET_KEY` at `7214e858…` (unchanged, from
  ADR-0026)