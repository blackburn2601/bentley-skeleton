# 0026. Optional TOTP MFA with authenticator app, superseding the no-MFA workforce floor

- **Status:** accepted
- **Date:** 2026-08-24
- **Deciders:**
- **Supersedes:** 0024 (workforce identity, no MFA) — only its "no MFA" half. 0024's
  username-only, admin-issued, no-self-registration, no-email model stands untouched.

## Context

ADR-0024 removed TOTP MFA from the skeleton. Its reasoning was sound for the *floor* —
workforce operators at supermarket/warehouse/kiosk terminals do not carry a personal device
for an authenticator app, and policy often forbids one on the floor. But the skeleton is a
boilerplate, and most of its real target deployments are offices where every user *does*
have a phone. Removing MFA entirely left those deployments with a weaker auth model than a
SaaS boilerplate should ship with, and the "no MFA" decision was never about MFA being
*undesirable* — only about it not fitting *one* persona.

The same day 0024 landed, the product owner asked for MFA back, scoped to the personas it
does fit: optional, self-enrollable, with an admin able to enforce it per user. This record
reverses only 0024's MFA rejection; everything else in 0024 (username identity, admin-issued
credentials, no self-registration, no email) remains.

The second factor is TOTP (RFC 6238) via an authenticator app such as Microsoft
Authenticator or Google Authenticator. It is free: verification is a server-side HMAC
computation, with no SMS gateway and no mail round trip. The skeleton already has the
infrastructure this needs — a signed JWT, an append-only audit log, a per-user
`aclVersion`, session revocation — so the design reuses all of it and adds the minimum.

## Decision

Add **optional TOTP MFA** as a second authentication factor.

- **Enrollment is two-way.** A user self-enrolls from their own account screen
  (`account.update`), and an administrator can require MFA for a user or reset a user's MFA
  (`user.update`). Enrollment always happens *inside* an already-authenticated session —
  never forced at login, because a "enroll your device now" prompt at login is a phishing
  primitive (the phisher enrolls their own device).
- **MFA is optional per user.** A user with no enrolled secret and no admin requirement
  logs in exactly as before; 0024's floor persona is untouched. `User::mfaApplies()` is
  `mfaRequired || totpSecretEncrypted !== null`.
- **The half-authenticated state is a short-lived "challenge" JWT**, minted into the
  existing access-token cookie slot, with `mfa: 'pending'` and `roles: []`. No fourth
  cookie, no database flag, and **no refresh token** pre-MFA — a stolen refresh cookie
  would otherwise bypass MFA for 30 days. A new `MfaStageVoter` denies every attribute
  except `MFA_PENDING` while the stage is pending, so a pending token can reach only the two
  verify endpoints. Existing controllers need no change; the deny is global.
- **MFA-verified is a signed claim, not a role or a flag.** A verified access token carries
  `amr: ['totp']` (RFC 8257 authentication-method reference) and `mfa: 'verified'`;
  `AuthenticatedUser::mfaVerified()` reads it. The claim is signed (unforgeable) and
  defaults to `[]` for pre-MFA tokens, so existing tokens stay valid.
- **Admin enforcement blocks rather than force-enrolls.** `mfa_required` on `user` set by
  an admin; a login where MFA is required but no secret is enrolled returns a distinct
  `mfa_required_not_enrolled` problem, rather than silently enrolling a device at login.
- **Recovery codes** ship in this first cut (10, SHA-256 hashed, single-use, shown once).
  **"Remember this device" is deferred** — a long-lived trust cookie is a real security
  tradeoff and is out of scope here.
- The TOTP secret is encrypted at rest with libsodium (`crypto_secretbox`), keyed by a new
  `TOTP_SECRET_KEY` env var; recovery codes are hashed, not encrypted (verify-then-burn,
  like the refresh token).

## Consequences

### Positive

- Office deployments get a second factor for free; floor deployments are unaffected.
- Reuses the signed-JWT, audit, `aclVersion`, and session-revocation infrastructure already
  in the skeleton — no new auth primitive, no new cookie, no new DB flag on the happy path.
- MFA verified/failed/recovery-used/reset are all audited (recovery use and admin reset are
  high severity), so the security trail matches the existing model.
- `amr` as a claim (not a role) keeps permissions server-side-resolved (ADR-0011) and keeps
  the token the single statement of *how* the caller authenticated.

### Negative

- **A second database table and three columns.** `mfa_recovery_code` plus
  `totp_secret_encrypted`, `totp_secret_encrypted_provisional` and `mfa_required` on
  `"user"`, and `amr` on `refresh_token`. The migration reverses 0024's drop of
  `totp_secret_encrypted`.
- **A new secret in config.** `TOTP_SECRET_KEY` must be provisioned and rotated with the
  same care as the JWT keys; losing it invalidates every enrolled secret (users re-enroll).
  The container fails fast if it is missing or too short.
- **No "remember this device".** Users re-verify on every login. This is acceptable for the
  target deployments and is the safer default; a trust cookie can be added later behind its
  own ADR.
- **The half-authenticated window is 120 s.** A challenge JWT that is not completed expires;
  the user re-enters credentials. Kept short on purpose.
- **Admin-required-but-unenrolled is a dead end the user cannot self-resolve.** The user
  cannot enroll at login (the block), so an admin who sets `mfa_required` must also ensure
  the user enrolls, or the user is locked out until an admin resets the requirement. This is
  the deliberate trade against force-enroll-at-login.

## Alternatives rejected and why

- **Force-enroll at login when `mfa_required` but no secret.** Rejected: a "set up your
  authenticator now" prompt at login is the phishing flow an attacker runs against a
  victim. Enrollment must happen inside an authenticated session. The admin who requires
  MFA owns ensuring enrollment, which is why `mfa_required_not_enrolled` is a distinct,
  admin-actionable error rather than a silent enrollment.

- **A fourth cookie / a DB flag for the pending state.** Rejected: a separate
  "mfa_pending" cookie or a `mfa_pending_until` column is a new credential-bearing artifact
  and a new revocation surface. The challenge JWT reuses the access-cookie slot and is
  stateless (ADR-0002/0011), and expiring it needs no cleanup — it just stops verifying.

- **Issue a refresh token before MFA.** Rejected outright: a refresh token valid for 30
  days, handed out before the second factor is checked, lets anyone who steals the refresh
  cookie skip MFA for the token's lifetime. Refresh is issued only after MFA verifies.

- **Carry `amr` as a Symfony role (`ROLE_MFA_VERIFIED`).** Rejected: roles in this skeleton
  are ACL role names, resolved server-side, and `getRoles()` is a framework-level concern.
  `amr` is *how* the caller authenticated, not *what* they may do — a claim is the right
  vehicle, and it keeps the token self-describing without a DB read.

- **WebAuthn / hardware badge instead of TOTP.** This is 0024's "realistic workforce second
  factor" and remains the right answer for the floor. It is a larger feature (device
  enrollment, attestation, a second authenticator type) and can be added later as another
  `amr` value without redesigning this one. TOTP is the 80% case for the office persona.

- **Mandatory MFA for everyone.** Rejected: it re-breaks the floor persona 0024 protected.
  Optional-with-admin-enforce gives each deployment the policy it wants.

## Reversal cost

**Cheap.** The feature is opt-in per user: a user with no secret and no `mfa_required` bit
hits none of the new code on login. Removing it means dropping the three columns and the
`mfa_recovery_code` table (the inverse of this migration), removing the `amr`/`mfa` claim
handling (default `[]` keeps old tokens valid already), and deleting the eight services and
their endpoints. No data is irrecoverable: the TOTP secret is the only user data added and
it is reproducible by re-enrollment. The API contract change (new endpoints, new
`mfaRequired` login-response field) is additive, so reverting it is client-safe. Files:
`User.php`, `RefreshToken.php`, `JwtAccessTokenIssuer.php`, `AuthenticatedUser.php`,
`MfaStageVoter.php`, the eight `*TwoFactor*Service` classes and their controllers, the
migration, and the SPA `MfaVerifyView` + account/admin enrollment UI.

## Implemented by

- `docs/adr/0026-…md` — this record.
- `backend/src/Account/Domain/User.php` — `totpSecretEncrypted`,
  `totpSecretEncryptedProvisional`, `mfaRequired`, `mfaApplies()`.
- `backend/src/Shared/Domain/SecretEncryptor.php` +
  `backend/src/Shared/Infrastructure/Security/SodiumSecretBox.php` — secret encryption.
- `backend/migrations/Version<timestamp>.php` — schema (re-adds `totp_secret_encrypted`).
- `backend/src/Shared/Domain/SecurityEventType.php` — MFA audit events.
- `backend/src/Account/Infrastructure/Security/JwtAccessTokenIssuer.php` — `amr`/`mfa`
  claims, `issueChallenge()`.
- `backend/src/Api/Security/AuthenticatedUser.php` + `MfaStageVoter.php` — pending stage.
- `backend/src/Account/Application/Service/*TwoFactor*Service.php` — the eight services.
- `backend/src/Api/Account/*TwoFactor*Controller.php` + `AuthCookies` — the endpoints.
- `frontend/src/views/MfaVerifyView.vue` + `frontend/src/views/AccountView.vue` +
  `frontend/src/views/admin/UserDetailView.vue` — the UI.