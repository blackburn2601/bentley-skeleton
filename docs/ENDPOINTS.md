<!-- GENERATED FILE — DO NOT EDIT.
     Produced by `bin/console app:docs:generate` (make docs).
     Source of truth: the compiled router plus controller attributes
     CI fails on any diff between this file and a fresh run (ADR-0016). -->

# Endpoints

Every application route, its required permission, and its request payload.

A **MISSING** permission means the endpoint is reachable without authorization. That is
a build failure (INV-11), so it should never appear here.

| Method | Path | Permission | Request DTO | Controller |
|---|---|---|---|---|
| DELETE | `/api/v1/account/mfa` | `account.update` | — | `DisableTwoFactorController` |
| POST | `/api/v1/account/mfa/confirm` | `account.update` | `ConfirmTwoFactorRequest` | `ConfirmTwoFactorController` |
| POST | `/api/v1/account/mfa/enrol` | `account.update` | — | `EnrolTwoFactorController` |
| GET | `/api/v1/admin/audit-events` | `audit.read` | — | `ListSecurityEventsController` |
| GET | `/api/v1/admin/groups` | `group.read` | — | `ListGroupsController` |
| POST | `/api/v1/admin/groups` | `group.create` | `CreateGroupRequest` | `CreateGroupController` |
| DELETE | `/api/v1/admin/groups/{id}` | `group.delete` | — | `DeleteGroupController` |
| PATCH | `/api/v1/admin/groups/{id}` | `group.update` | `UpdateGroupRequest` | `UpdateGroupController` |
| GET | `/api/v1/admin/groups/{id}/members` | `group.read` | — | `ListGroupMembersController` |
| PUT | `/api/v1/admin/groups/{id}/members` | `group.update` | `SetGroupMembersRequest` | `SetGroupMembersController` |
| PUT | `/api/v1/admin/groups/{id}/roles` | `group.update` | `SetGroupRolesRequest` | `SetGroupRolesController` |
| GET | `/api/v1/admin/permissions` | `permission.read` | — | `ListPermissionsController` |
| GET | `/api/v1/admin/roles` | `role.read` | — | `ListRolesController` |
| POST | `/api/v1/admin/roles` | `role.create` | `CreateRoleRequest` | `CreateRoleController` |
| DELETE | `/api/v1/admin/roles/{id}` | `role.delete` | — | `DeleteRoleController` |
| PATCH | `/api/v1/admin/roles/{id}` | `role.update` | `UpdateRoleRequest` | `UpdateRoleController` |
| PUT | `/api/v1/admin/roles/{id}/permissions` | `permission.grant` | `SetRolePermissionsRequest` | `SetRolePermissionsController` |
| GET | `/api/v1/admin/users` | `user.read` | — | `ListUsersController` |
| POST | `/api/v1/admin/users` | `user.create` | `CreateUserRequest` | `CreateUserController` |
| DELETE | `/api/v1/admin/users/{id}` | `user.delete` | — | `EraseUserController` |
| GET | `/api/v1/admin/users/{id}` | `user.read` | — | `DescribeUserController` |
| PATCH | `/api/v1/admin/users/{id}` | `user.update` | `UpdateUserRequest` | `UpdateUserController` |
| PUT | `/api/v1/admin/users/{id}/mfa/required` | `user.update` | `AdminRequireTwoFactorRequest` | `AdminRequireTwoFactorController` |
| POST | `/api/v1/admin/users/{id}/mfa/reset` | `user.update` | — | `AdminResetTwoFactorController` |
| POST | `/api/v1/admin/users/{id}/password` | `user.update` | — | `ResetUserPasswordController` |
| POST | `/api/v1/admin/users/{id}/roles` | `permission.grant` | `AssignRoleRequest` | `AssignRoleController` |
| DELETE | `/api/v1/admin/users/{id}/roles/{roleName}` | `permission.revoke` | — | `RevokeRoleController` |
| POST | `/api/v1/admin/users/{id}/sessions/revoke` | `user.update` | — | `RevokeUserSessionsController` |
| PATCH | `/api/v1/admin/users/{id}/status` | `user.update` | `ChangeUserStatusRequest` | `ChangeUserStatusController` |
| POST | `/api/v1/auth/change-password` | `account.update` | `ChangePasswordRequest` | `ChangePasswordController` |
| POST | `/api/v1/auth/login` | _public_ | `LoginRequest` | `LoginController` |
| POST | `/api/v1/auth/logout` | _public_ | — | `LogoutController` |
| POST | `/api/v1/auth/logout-all` | `account.update` | — | `LogoutAllController` |
| GET | `/api/v1/auth/me` | `account.read` | — | `MeController` |
| POST | `/api/v1/auth/mfa/recovery/verify` | `MFA_PENDING` | `UseTwoFactorRecoveryRequest` | `UseTwoFactorRecoveryController` |
| POST | `/api/v1/auth/mfa/verify` | `MFA_PENDING` | `VerifyTwoFactorRequest` | `VerifyTwoFactorController` |
| POST | `/api/v1/auth/refresh` | _public_ | — | `RefreshController` |
| GET | `/api/v1/auth/sessions` | `account.read` | — | `ListSessionsController` |
| DELETE | `/api/v1/me` | `account.delete` | — | `EraseMyAccountController` |
| POST | `/api/v1/me/export` | `account.export` | — | `ExportMyDataController` |
| GET | `/health/live` | _public_ | — | `LivenessController` |
| GET | `/health/ready` | _public_ | — | `ReadinessController` |
| GET | `/metrics` | _public_ | — | `MetricsController` |
