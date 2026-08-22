<!-- GENERATED FILE — DO NOT EDIT.
     Produced by `bin/console app:docs:generate` (make docs).
     Source of truth: the @responsibility docblock on each class in src/*/Application/Service/
     CI fails on any diff between this file and a fresh run (ADR-0016). -->

# Services

Every Application service, grouped by bounded context.

**Before writing a new service, look for the topic here.** If a service already owns
it, extend that one. If none does, `make service` generates a conforming skeleton.

## Account

| Service | Responsibility |
|---|---|
| `AccountFacade` | Exposes the Account context to other contexts as a single narrow surface. |
| `AssertPasswordAcceptableService` | Refuses a password that this system will not accept. |
| `AuthenticateUserService` | Decides whether a set of credentials identifies a user who may sign in. |
| `DescribeCurrentUserService` | Assembles the profile the signed-in user's own client needs. |
| `IssueSingleUseTokenService` | Mints a one-time secret for an email-delivered action. |
| `ListActiveSessionsService` | Lists the sessions a user currently has open. |
| `RefreshSessionService` | Exchanges a valid refresh token for a fresh session. |
| `RegisterUserService` | Creates an unverified account for a new email address. |
| `RequestPasswordResetService` | Emails a password-reset link to an address that has an account. |
| `ResetPasswordService` | Sets a new password from a valid reset token. |
| `RevokeAllSessionsService` | Revokes every refresh-token family belonging to one user. |
| `RotateRefreshTokenService` | Exchanges a refresh token for its successor, detecting reuse. |
| `SendAccountEmailService` | Sends the transactional emails the account lifecycle depends on. |
| `SignInService` | Turns valid credentials into an authenticated session. |
| `SignOutService` | Ends the session belonging to a presented refresh token. |
| `StartSessionService` | Opens a new refresh-token family for a user who has just authenticated. |
| `VerifyEmailService` | Confirms that a user controls the email address they registered. |

## Acl

| Service | Responsibility |
|---|---|
| `AclCache` | Caches permission decisions under a key that a grant change invalidates. |
| `AclFacade` | Exposes the Acl context to other contexts as a single narrow surface. |
| `AssignDefaultRoleService` | Gives a newly registered user the baseline role every account needs. |
| `EnsureBaselineRolesService` | Guarantees the roles the application cannot function without exist. |
| `PermissionResolver` | Decides whether a subject holds a permission on a resource. |
| `SyncPermissionsService` | Reconciles the database permission rows with the code-declared catalog. |

## Audit

| Service | Responsibility |
|---|---|
| `AuditFacade` | Exposes the Audit context to other contexts as a single narrow surface. |
| `ErasePersonalDataService` | Anonymises a person's account in response to an erasure request. |
| `ExportPersonalDataService` | Assembles everything this system holds about one person. |
| `PurgeExpiredDataService` | Removes data whose retention period has ended. |
| `RecordSecurityEventService` | Writes one immutable record of a security-relevant event. |

## Platform

| Service | Responsibility |
|---|---|
| `CheckReadinessService` | Reports whether every dependency this application needs is reachable. |
| `CollectMetricsService` | Gathers the application's metrics into Prometheus exposition format. |
