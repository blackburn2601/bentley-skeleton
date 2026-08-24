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
| `ChangePasswordService` | Changes the signed-in user's password after verifying the current one. |
| `ChangeUserStatusService` | Moves a user account to a new administrative status. |
| `CreateUserService` | Creates a user account with a system-generated temporary password. |
| `DescribeCurrentUserService` | Assembles the profile the signed-in user's own client needs. |
| `DescribeUserService` | Assembles the administrative profile of one user account. |
| `ListActiveSessionsService` | Lists the sessions a user currently has open. |
| `ListUsersService` | Lists the user accounts a caller is permitted to read. |
| `RefreshSessionService` | Exchanges a valid refresh token for a fresh session. |
| `ResetUserPasswordService` | Resets a user's password to a new system-generated temporary password. |
| `RevokeAllSessionsService` | Revokes every refresh-token family belonging to one user. |
| `RevokeUserSessionsService` | Ends every session belonging to one user at an administrator's request. |
| `RotateRefreshTokenService` | Exchanges a refresh token for its successor, detecting reuse. |
| `SignInService` | Turns valid credentials into an authenticated session. |
| `SignOutService` | Ends the session belonging to a presented refresh token. |
| `StartSessionService` | Opens a new refresh-token family for a user who has just authenticated. |
| `UpdateUserService` | Applies an administrator's edit to one user's username. |

## Acl

| Service | Responsibility |
|---|---|
| `AclCache` | Caches permission decisions under a key that a grant change invalidates. |
| `AclFacade` | Exposes the Acl context to other contexts as a single narrow surface. |
| `AssignDefaultRoleService` | Gives a newly registered user the baseline role every account needs. |
| `AssignRoleService` | Gives a user a role. |
| `CreateGroupService` | Creates a named group. |
| `CreateRoleService` | Creates a named role. |
| `DeleteGroupService` | Removes a group that no longer applies. |
| `DeleteRoleService` | Removes a role that is safe to delete. |
| `EnsureBaselineRolesService` | Guarantees the roles the application cannot function without exist. |
| `InvalidateAclCachesService` | Invalidates the cached authorization decisions belonging to a set of users. |
| `ListGroupMembersService` | Lists the users who belong to one group. |
| `ListGroupsService` | Lists the groups this application defines. |
| `ListPermissionsService` | Lists the permissions this application declares. |
| `ListRolesService` | Lists the roles this application defines. |
| `PermissionResolver` | Decides whether a subject holds a permission on a resource. |
| `RevokeRoleService` | Takes a role away from a user. |
| `SetGroupMembersService` | Replaces the membership list of one group. |
| `SetGroupRolesService` | Replaces the set of roles carried by one group. |
| `SetRolePermissionsService` | Replaces the permission set carried by one role. |
| `SyncPermissionsService` | Reconciles the database permission rows with the code-declared catalog. |
| `UpdateGroupService` | Applies an administrator's edits to one group's descriptive fields. |
| `UpdateRoleService` | Applies an administrator's edits to one role's description. |

## Audit

| Service | Responsibility |
|---|---|
| `AuditFacade` | Exposes the Audit context to other contexts as a single narrow surface. |
| `ErasePersonalDataService` | Anonymises a person's account in response to an erasure request. |
| `EraseUserService` | Anonymises another person's account at an administrator's request. |
| `ExportPersonalDataService` | Assembles everything this system holds about one person. |
| `ListSecurityEventsService` | Lists the recorded security events matching a filter. |
| `PurgeExpiredDataService` | Removes data whose retention period has ended. |
| `RecordSecurityEventService` | Writes one immutable record of a security-relevant event. |

## Platform

| Service | Responsibility |
|---|---|
| `CheckReadinessService` | Reports whether every dependency this application needs is reachable. |
| `CollectMetricsService` | Gathers the application's metrics into Prometheus exposition format. |
