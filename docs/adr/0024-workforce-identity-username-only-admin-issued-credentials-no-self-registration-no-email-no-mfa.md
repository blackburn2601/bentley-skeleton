# 0024. Workforce identity: username-only, admin-issued credentials, no self-registration, no email, no MFA

- **Status:** accepted
- **Date:** 2026-08-24
- **Deciders:**
- **Supersedes:** 0010 (synchronous mail / Mailpit) — the mailer the skeleton no longer has

## Context

bentley-skeleton is a SaaS boilerplate, not an open web forum. Its first target deployments
are organisations whose users have **no email address** — supermarket staff, warehouse and
factory-floor operators, kiosk users. The current identity model assumes the opposite: `email`
is the `User` identity, the JWT claim, the login field, and the human-facing label, and a large
subsystem exists to support self-service around that email — public self-registration, email
verification, password reset by email (single-use tokens), a transactional mailer (Mailpit in
dev), and optional TOTP MFA.

None of that fits a workforce where the organisation, not the user, owns provisioning. There is
no address to verify, no inbox to send a reset link to, and no personal device to run an
authenticator app on. Keeping the email machinery "optional" was considered (see below) and
rejected as more code, not less: the `email` field is woven through ~15 call sites
(`PasswordPolicy`, the JWT claim, `AuthenticatedUser`, `AccountFacade::emailOf`, `ListUsersService`
search/ordering, `DescribeUserService`, `ExportPersonalDataService`, `User::anonymise`, audit
events), and making every one nullable-aware doubles the test surface for a feature nobody uses.

## Decision

Replace email with **`username`** as the user identity. Usernames are case-insensitive unique
(kept on the existing Postgres `citext` column type). Accounts are **created only by an
administrator**: there is no public registration endpoint and no self-service signup. The admin
creates a user with a **system-generated temporary password shown once** in the create response
(so it can be handed over out-of-band — printed, spoken, written on a slip); the user signs in
with `username + password` and may change their own password while signed in. An admin can reset
a user's password to a new generated temporary password (also shown once), which revokes that
user's sessions.

Remove, entirely: public registration, email verification, password reset by email, the mailer
(Mailpit, mailer config, templates, `SendAccountEmailService`), the single-use-token subsystem
(`SingleUseToken`, `TokenPurpose`, `IssueSingleUseTokenService`), and TOTP MFA
(`totpSecretEncrypted`, `enableMfa`/`disableMfa`, `mfaEnabled`). The HIBP breached-password check
is kept — it is not email-dependent and workforce passwords appear in breaches too.

The JWT carries a `username` claim instead of `email`. Access tokens minted before this change
lack `username`, so the authenticator rejects them after deploy — affected users are simply
logged out and sign in again. There is **no forced first-login password change**: the issued
temporary password is the user's password until they choose to change it.

## Consequences

### Positive

- One identity path, one login flow, one user model — no dual-mode nullable email spread across
  the codebase.
- Removes ~15 files of email/token/MFA machinery and one database table (`single_use_token`),
  shrinking the attack surface and the maintenance surface.
- No outbound email dependency in the stack (Mailpit, mailer DSN, sender config, templates),
  so the dev/prod topology is simpler and there is one fewer egress path to operate.
- Admin-issued credentials match the deployment reality: the organisation owns provisioning,
  and nobody waits on an email round trip.

### Negative

- **No self-service password recovery.** A user who forgets their password cannot recover it
  themselves; they must ask an administrator to reset it. This is the deliberate trade of the
  workforce model and is acceptable for the target deployments, but it is a real loss for any
  future deployment where users are self-managing.
- **No email-verified signal.** With no email there is nothing to verify, so `emailVerifiedAt`
  and `UserStatus::PendingVerification` go away. The "this account's contact channel is proven"
  property they represented does not exist in this model — the administrator is the trust anchor.
- **Username enumeration surface.** Unlike email (which a registration endpoint can hide behind
  anti-enumeration), a username is a public-ish handle and an admin create that reports a
  duplicate username tells a caller the handle is taken. We accept this because creation is
  admin-only and rate-limited; the login endpoint keeps the constant-time `DUMMY_HASH` so a
  failed login does not distinguish "no such user" from "wrong password".
- **The administrator is a trusted credential intermediary.** The admin who creates or resets a
  user sees the temporary password once. The model trusts that admin to hand it over and not
  retain it. This is the same trust boundary as Active Directory's "admin resets to a temp
  password", and it is why the temp password is shown once and never persisted or logged.
- **Destructive column drop.** `email`, `email_verified_at` and `totp_secret_encrypted` are
  dropped from `"user"`. In a real production deploy this is a two-deploy operation (stop
  reading the columns → deploy → drop them → deploy); the skeleton has no production data, so a
  single migration is used here and the caveat is recorded for whoever takes this to production.

## Alternatives rejected and why

- **Keep email as a nullable, optional field (mixed model).** Floor staff would be username-only
  while office staff could keep email for self-service reset. Rejected because it keeps the
  entire email subsystem *and* makes every one of the ~15 interwoven call sites nullable-aware
  (login falls back to username; reset must say "no email, can't reset"; `/me` renders username
  always and email sometimes). That is more code and more conditionals than removing email
  outright, for a feature the target deployments do not use. It also contradicts the stated goal
  of a simple username+password model.

- **Keep TOTP MFA as an optional second factor.** Rejected because the target users do not
  carry a personal device for an authenticator app (and policy often forbids it on the floor).
  A realistic workforce second factor — a hardware badge / RFID / smartcard reader at the
  terminal — is a different, larger feature and can be added later as another authenticator
  without designing for it now.

- **Keep public self-registration.** Rejected outright: this is a SaaS boilerplate, not an open
  forum. Accounts are organisation-issued. Self-registration was deleted along with its endpoint,
  request DTO, view, route and rate-limit policy.

- **Force a first-login password change (issue a `mustChangePassword` flag / limited session).**
  Rejected by the product owner for simplicity: the issued temporary password is simply the
  user's password until they choose to change it. The flag, its `UserStatus`/column, the
  `first-login` endpoint/view, and the must-change problem+json mapping were all removed from
  the design. The trade is that a user could keep using the admin-issued temp password
  indefinitely; acceptable for the target deployments.

- **Issue a "limited" change-password JWT claim instead of a normal session for flagged users.**
  Only relevant under the forced-change alternative, so moot once that is rejected.

## Reversal cost

**Moderate.** Undoing means re-adding `email` (citext, unique), `email_verified_at`,
`totp_secret_encrypted`, the `single_use_token` table, the mailer wiring, and the four deleted
services (registration, verification, request-reset, reset) plus `SendAccountEmailService` and
the email templates — most of which live in git history and can be restored. The data migration
is the expensive part: `email` was dropped and cannot be reconstructed from `username` alone
(the `down()` backfills a placeholder `@restored.invalid` address, not the original). Any
deployment that went to production on the username model would lose real email addresses on
reversal. The API contract change (JWT `email` claim → `username`; login/create/update response
shapes) is a client-breaking change both ways. Files: `User.php`, `UserStatus.php`,
`UserRepository.php`, `JwtAccessTokenIssuer.php`, `AuthenticatedUser.php`, the auth/account
controllers and request/response DTOs, `AccountFacade`, the Acl/Audit consumers, and the SPA
auth + admin views.

## Implemented by

- `backend/src/Account/Domain/User.php` — `username` identity, no email/MFA/verification.
- `backend/src/Account/Domain/UserStatus.php` — `PendingVerification` removed.
- `backend/src/Account/Application/Service/CreateUserService.php` — admin create with generated
  temp password.
- `backend/src/Account/Application/Service/ResetUserPasswordService.php` — admin reset.
- `backend/src/Account/Application/Service/ChangePasswordService.php` — self-service change.
- `backend/src/Account/Infrastructure/Security/JwtAccessTokenIssuer.php` — `username` claim.
- `backend/src/Account/Application/AccountFacade.php` — `usernameOf`.
- `backend/migrations/Version<timestamp>.php` — schema change.
- `frontend/src/views/SignInView.vue`, `frontend/src/views/admin/UsersListView.vue`,
  `frontend/src/views/admin/UserDetailView.vue` — username UI, temp-password dialogs.