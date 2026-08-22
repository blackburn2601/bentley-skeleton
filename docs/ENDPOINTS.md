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
| POST | `/api/v1/auth/login` | _public_ | `LoginRequest` | `LoginController` |
| POST | `/api/v1/auth/logout` | _public_ | — | `LogoutController` |
| POST | `/api/v1/auth/logout-all` | `account.update` | — | `LogoutAllController` |
| GET | `/api/v1/auth/me` | `account.read` | — | `MeController` |
| POST | `/api/v1/auth/password/forgot` | _public_ | `ForgotPasswordRequest` | `ForgotPasswordController` |
| POST | `/api/v1/auth/password/reset` | _public_ | `ResetPasswordRequest` | `ResetPasswordController` |
| POST | `/api/v1/auth/refresh` | _public_ | — | `RefreshController` |
| POST | `/api/v1/auth/register` | _public_ | `RegisterRequest` | `RegisterController` |
| POST | `/api/v1/auth/verify-email` | _public_ | `TokenRequest` | `VerifyEmailController` |
| GET | `/health/live` | _public_ | — | `LivenessController` |
| GET | `/health/ready` | _public_ | — | `ReadinessController` |
