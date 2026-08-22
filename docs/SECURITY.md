# Security

The threat model, the controls, and — for each one — **the test that proves it works**.

A control with no test is a claim. The right-hand column is the part that keeps this document
honest: if a row has no test, that is a gap, not an oversight to tidy up later.

---

## What this system is protecting

| Asset | Why someone wants it |
|---|---|
| Credentials | Reused elsewhere. A password dump is worth more off this system than on it |
| Session tokens | Immediate account access, no credentials needed |
| The permission model | Escalation. A grant is worth more than any single record |
| Personal data | Regulatory exposure and direct harm to the person |
| The audit trail | Its absence. Whoever gets in wants no record of it |

## Who we assume is attacking

- **Anonymous internet.** Credential stuffing, enumeration, scraping, SSRF probes.
- **A registered user.** The most important one. They are authenticated, so every control
  except authorization has already let them through — this is what the ACL and the IDOR suite
  exist for.
- **A user whose credentials were stolen.** Detection and blast radius, not prevention.
- **A compromised dependency.** Assumed to run our code. Hence read-only containers, dropped
  capabilities and an audit log the application cannot rewrite.

Explicitly **out of scope**: a hostile database administrator, physical access, and a
compromised CI runner. Those need controls this template cannot provide.

---

## OWASP Top 10 (2021)

### A01 Broken Access Control

The one that matters most here, and the reason for the per-object ACL.

| Control | Test |
|---|---|
| Per-object ACL, deny-by-default, tiered resolution | `tests/Unit/Acl/PermissionResolverTest.php` — 23 cases across every tier, subject type, expiry and precedence rule |
| Deny beats allow within a tier, and never depends on row order | `testDenyBeatsAllowWithinTheSameTier` — mutation-checked: inverting the precedence fails it |
| Every endpoint declares a permission | `ControllerMustHaveIsGrantedRule` + a router-walking functional test. A missing attribute is a build failure |
| Collection endpoints cannot leak what a direct fetch would refuse | The resolver/criteria cross-check integration test |
| IDOR — user A reaching user B's ids | The IDOR regression suite |
| The SPA's permission list is advisory only | INV-16; the IDOR suite calls endpoints directly, ignoring the UI |

`AclCriteriaBuilder` **refuses** rather than approximating when an entity inherits
permissions: returning rows that ignore inheritance would make a list disagree with a direct
fetch, which is the failure this whole design exists to prevent.

### A02 Cryptographic Failures

| Control | Test |
|---|---|
| argon2id, 64 MiB / 4 passes | `config/packages/security.yaml`; upgraded on login via `needsRehash` |
| Refresh, verification and reset tokens stored **hashed only** | `TokenHash`; the schema has no plaintext column to store one in |
| Access tokens RS256 — only the issuer holds the private key | `JwtAccessTokenIssuer` |
| Cookies `__Host-`, `HttpOnly`, `Secure`, `SameSite=Strict` | Header snapshot test |
| TOTP secrets encrypted at rest, not hashed — verification needs them back | `User::$totpSecretEncrypted` |

### A03 Injection

| Control | Test |
|---|---|
| All queries via Doctrine with bound parameters; no string-concatenated SQL | `NoDoctrineInControllerRule` keeps queries out of the Api layer entirely |
| Request bodies bound to typed DTOs and validated before a controller runs | `#[MapRequestPayload]` + Validator; violations become 422 |
| Entities never serialized into responses | INV-05; every response is an explicit view class |
| Log injection via `X-Request-Id` | `RequestIdSubscriber` accepts `[A-Za-z0-9._-]{1,64}` and generates a fresh id otherwise |

### A04 Insecure Design

| Control | Test |
|---|---|
| Rate limits on every credential-touching endpoint | Six rapid bad logins → 429 + `Retry-After` (verified) |
| `login` keyed on IP **and** email | Verified: a different email from the same IP is not blocked; a case variant of the same email is |
| Lockout with exponential backoff, capped | `AuthenticateUserService`; the cap stops it becoming a denial of service against the account's owner |
| Anti-enumeration on register, login and password reset | Identical responses; login verifies against a dummy argon2id hash when the account does not exist, so timing matches |
| Refresh-token reuse detection | Verified: replaying a rotated token revokes the family, and the successor dies too |

### A05 Security Misconfiguration

| Control | Test |
|---|---|
| Security headers on every response | Header snapshot test |
| CSP `default-src 'none'` on API responses | Same |
| CORS allow-list, never `*`, credentials enabled | `config/packages/nelmio_cors.yaml` |
| Production container: read-only rootfs, `cap_drop: ALL`, `no-new-privileges`, non-root | `compose.prod.yaml` |
| Secrets as Docker secrets, not environment variables | Env leaks into `docker inspect`, crash dumps and child processes |
| Errors never leak internals | Unclassified exceptions become a bare 500; the detail is logged against the request id |

### A06 Vulnerable and Outdated Components

| Control | Test |
|---|---|
| `roave/security-advisories` blocks known-vulnerable installs at composer time | `composer.json` |
| `composer audit`, `npm audit`, Trivy, gitleaks, Semgrep | `.github/workflows/security.yml`, nightly plus per-PR |
| Dependabot, grouped minor/patch | `.github/dependabot.yml` |
| SBOM published per build | Syft, in `security.yml` |

### A07 Identification and Authentication Failures

| Control | Test |
|---|---|
| TOTP MFA with recovery codes | Account context |
| Rotation with reuse detection | Verified end to end |
| Password policy: length and breach checks, no composition rules | `PasswordPolicy` unit tests |
| HIBP via k-anonymity — the password never leaves the process | `HibpBreachedPasswordChecker`; only a 5-character SHA-1 prefix is sent |
| Breach check fails **open** | Deliberate: a third party's outage must not become an outage of registration |
| Password change ends every session | `ResetPasswordService` revokes all refresh tokens |

### A08 Software and Data Integrity Failures

| Control | Test |
|---|---|
| Production images pinned by **digest**, not tag | `compose.prod.yaml`; a tag is mutable, so a rollback to one is not guaranteed to be a rollback |
| Lockfiles committed; CI installs from them | `composer.lock`, `package-lock.json` |
| GitHub Actions pinned by SHA | `.github/workflows/` |

### A09 Security Logging and Monitoring Failures

| Control | Test |
|---|---|
| Append-only `security_event` log | INSERT-only grant; an integration test asserts UPDATE is refused |
| Every auth event recorded with actor, IP, user agent, request id | Verified: register, verify, login success and failure, rotation and reuse all present |
| Audit writes flush immediately | Found the hard way — persisted-but-unflushed events were being silently dropped |
| Request id correlates log, error body and audit row | `RequestIdSubscriber` |
| High-severity events flagged on the event type itself | `SecurityEventType::isHighSeverity()` |

### A10 Server-Side Request Forgery

| Control | Test |
|---|---|
| Egress allow-list | `SsrfGuardedHttpClient`, decorating the default client so it cannot be bypassed by forgetting to use it |
| Resolve-then-check against private, loopback and link-local ranges | A hostname check alone is useless: any domain can point an A record at `169.254.169.254` |
| Redirects not followed | A permitted host could otherwise bounce us to a forbidden one after the check passed |
| Only `http` and `https` | `file://` and `gopher://` are the classic escalation from "fetch a URL" to "read a file" |

---

## API Security Top 10 notes

**BOLA / BOPLA** (object and property level authorization) are the two that a generic Top 10
under-weights and that matter most for a JSON API:

- **BOLA** is A01 above. Every object-level check goes through `PermissionResolver`, and the
  IDOR suite exists precisely to catch the endpoint that forgot.
- **BOPLA** is INV-05. Entities are never serialized; each response view lists its fields
  explicitly, so adding a column cannot silently start publishing it.

---

## Data inventory

| Data | Where | Retention |
|---|---|---|
| Email address | `user.email` (citext) | Until erasure; replaced with a placeholder on anonymisation |
| Password hash | `user.password_hash` | Until erasure; cleared on anonymisation |
| TOTP secret | `user.totp_secret_encrypted` | Until MFA is disabled or the account is erased |
| Session metadata (IP, user agent) | `refresh_token` | Until the token expires or is revoked |
| Security events | `security_event` | Retention policy; append-only, never edited |
| Audit entity history | auditor tables | Retention policy |

GDPR export and erasure are in the Audit context. Erasure **anonymises** rather than deletes,
because the security event log must survive the account it describes — that is the point of an
audit trail, and the retention exception is documented rather than implicit.

---

## Reporting a vulnerability

See `.github/SECURITY.md`.
