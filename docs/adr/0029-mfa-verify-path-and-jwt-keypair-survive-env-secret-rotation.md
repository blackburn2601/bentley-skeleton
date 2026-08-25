# 0029. MFA verify-path and JWT keypair survive env-secret rotation

- **Status:** accepted
- **Date:** 2026-08-25
- **Deciders:** Sebastian Wagner

## Context

ADR-0028 removed the committed `backend/.env` and introduced `bin/generate-env`, which writes
fresh per-machine `APP_SECRET`, `JWT_PASSPHRASE` and `TOTP_SECRET_KEY` whenever `backend/.env`
is (re)generated. Two of those secrets have stateful dependents that the generator did not
touch, and the omission broke both login and MFA in dev:

- **`JWT_PASSPHRASE` → the JWT keypair.** The RSA keypair in `backend/config/jwt/` (gitignored)
  is encrypted on disk with the passphrase. A regenerated `.env` carries a new passphrase, but
  the old keypair — encrypted with the previous one — stays put, and
  `lexik:jwt:generate-keypair --skip-if-exists` leaves it standing. Every login then fails with
  `JWTEncodeFailureException … bad decrypt … pkcs12 cipherfinal error` (a 500).
- **`TOTP_SECRET_KEY` → enrolled TOTP secrets.** Per ADR-0026, each user's TOTP secret is
  encrypted at rest with `TOTP_SECRET_KEY` via `SodiumSecretBox`. Rotating the key makes every
  already-enrolled secret undecryptable. `VerifyTwoFactorService` called
  `$this->encryptor->decrypt($secretEncrypted)` inline and did **not** catch
  `SecretDecryptionFailed`. Its anti-enumeration block returned an identical 401 for "no such
  user / no enrolled secret / wrong code" — but not for "the decrypt threw" — so a rotated key
  surfaced as a 500 `A stored secret could not be decrypted.` instead of a clean refusal.

A third failure compounded both: `backend/.env` itself went missing on a running dev machine.
Because it is gitignored (ADR-0028), `git status` showed clean, the `./backend:/app` bind mount
propagated the absence into the container, and `bootEnv()` threw `PathException` in
`autoload_runtime.php` *before* the Symfony kernel exists. With `display_errors=STDOUT` the
fatal rendered as raw `<br /><b>` HTML (`200 OK text/html`), and the SPA's `JSON.parse` failed
with `Unexpected token '<'`. That crash is operational (the file must exist for the app to
boot) and is not fixed in code here; the two stateful-rotation breaks above are.

The MFA tests (`TwoFactorFlowTest`, `VerifyTwoFactorControllerTest`) run against the fixed
zero key in `.env.test` with fixtures encrypted against that key, and CI regenerates both
`.env` and the keypair fresh on every run — so neither the passphrase/keypair mismatch nor the
rotated-TOTP-key verify path was covered.

## Decision

1. **`VerifyTwoFactorService` catches `SecretDecryptionFailed` and treats it as a wrong code.**
   The decrypt is wrapped in try/catch; on `SecretDecryptionFailed` it records the same
   `MfaChallengeFailed` audit event and throws `AccountException::invalidTwoFactorCode()`. A
   rotated/undecryptable secret is now byte-identical to a wrong code at the HTTP layer (401
   `application/problem+json`, "The authentication code is incorrect or has expired."), so the
   anti-enumeration invariant holds and no server-side condition is leaked to an unauthenticated
   caller. The audit event still records it — a sudden spike across many users is the
   operational signal that the encryption key rotated without re-enrolment.

2. **`bin/generate-env` removes a stale JWT keypair on the fresh path.** When it creates a new
   `backend/.env` (not on the idempotent "leave existing" branch), it `rm -f`s
   `backend/config/jwt/{private,public}.pem`. The next keypair generation then encrypts with
   the new passphrase. A developer's existing keypair is never touched when their `.env`
   already exists.

3. **`make up` regenerates the JWT keypair if it is missing.** The `up` recipe runs
   `lexik:jwt:generate-keypair --skip-if-exists` after `compose up --wait`. With the removal in
   (2), a `.env` regeneration followed by `make up` always ends with a passphrase and keypair
   that agree. `--skip-if-exists` makes it a no-op on an ordinary `make up`, so it only does
   work after the generator removed a stale keypair.

4. **`TOTP_SECRET_KEY` rotation invalidates enrolled secrets; no automatic migration.**
   Rotating the active key makes every enrolled TOTP secret undecryptable; affected users must
   re-enrol (an admin clears the factor via `AdminResetTwoFactorService`, then the user enrols
   again against the current key). A re-encrypt migration is deliberately not built: it would
   require holding the old and new keys simultaneously and is disproportionate for a rotation
   that, in production, is a planned event (production provisions the key from Docker secrets,
   ADR-0028, and does not rotate it casually). The verify-path hardening in (1) ensures that
   when it does happen, users see a clean 401, not a 500, while they wait for re-enrolment.

## Consequences

### Positive

- The MFA verify path no longer 500s on an undecryptable secret; it returns the same 401 as a
  wrong code, preserving anti-enumeration and giving a clean failure to the SPA.
- `make up` is self-healing after a `.env` regeneration: passphrase and keypair stay in sync
  without a manual `make keys` step, closing the login-500 regression from ADR-0028.
- The audit log surfaces a key-rotation outage as a spike in `MfaChallengeFailed` events rather
  than as uncaught exceptions in the error log.
- A functional test now guards the undecryptable-secret path (`TwoFactorFlowTest`), which CI
  never exercised before.

### Negative

- The verify-path test must construct a `SodiumSecretBox` under a foreign key and write the
  ciphertext through reflection, which is more setup than the other verify tests.
- `make up` runs one extra console command on every invocation (`--skip-if-exists` → no-op when
  the keypair exists, but it is a process spawn).
- A `.env` deleted *while the container is already running* still crashes at `bootEnv` with
  raw HTML, because the fatal fires before the kernel. Recovery is `make env && make up`; the
  crash is not converted to JSON (that would mean changing `display_errors` / error handling
  before the kernel boots, out of scope). This is documented as a residual operational risk,
  not a code path.
- Direct `docker compose up` (bypassing `make up`) does not self-heal the keypair; the
  documented dev path is `make up`.

## Alternatives rejected and why

- **Leave the 500 as an operational signal for a rotated key.** Rejected: a 500 leaks a
  server-side condition to an unauthenticated caller and breaks the anti-enumeration invariant
  the verify path was explicitly designed for. The audit event provides the same signal without
  the leak.

- **Build a `TOTP_SECRET_KEY` re-encrypt migration that re-encrypts every enrolled secret under
  the new key.** Rejected: it requires the old and new keys to be present simultaneously, a
  migration command, and a story for users whose secret cannot be opened with the old key. The
  rotation is a dev-default side effect of `bin/generate-env` and a planned event in production;
  admin-reset-and-re-enrol is the simpler, honest answer. Revisit if rotation becomes routine.

- **Generate the JWT keypair from the dev container entrypoint instead of the `make up` recipe.**
  Rejected: it pushes keypair creation into the image/container lifecycle, mixing build and
  runtime concerns, and `make up` already has the container healthy (`--wait`) at the point the
  command runs. The recipe is the smaller, more observable place for it.

- **Catch `SecretDecryptionFailed` with a distinct audit event (e.g. `MfaSecretUndecryptable`).
  ** Rejected for now: a new `SecurityEventType` case adds schema/event surface for a condition
  that is already visible as a spike in `MfaChallengeFailed`. The identical-event choice keeps
  the byte-identical-response property end-to-end. Can be split out later if operators need to
  distinguish "wrong code" from "key rotation" in the event log.

## Reversal cost

Cheap. Reversing (1) is reverting the try/catch in `VerifyTwoFactorService` (a 500 returns);
reversing (2)/(3) is reverting the `rm -f` in `bin/generate-env` and the one line in the
`Makefile` `up` recipe. No schema change, no API contract change, no data migration. The only
stateful effect is the per-machine keypairs `make up` has regenerated, which a reversal simply
stops regenerating.

## Implemented by

- `backend/src/Account/Application/Service/VerifyTwoFactorService.php` — catches
  `SecretDecryptionFailed` → audit `MfaChallengeFailed` + `AccountException::invalidTwoFactorCode()`
- `backend/tests/Functional/Account/TwoFactorFlowTest.php` —
  `testVerifyWithAnUndecryptableSecretIsRefusedAs401Not500`
- `bin/generate-env` — removes a stale JWT keypair on the fresh-generation path
- `Makefile` — `up` recipe regenerates the JWT keypair (`--skip-if-exists`) after `compose up`