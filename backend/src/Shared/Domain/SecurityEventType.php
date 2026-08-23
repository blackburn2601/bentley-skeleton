<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The security-relevant things that can happen.
 *
 * A closed enum rather than free-text: an audit log you cannot query by event type is a log
 * nobody queries. Adding a case is a deliberate act, which is the point — "what do we record?"
 * should be answerable by reading one file.
 *
 * Lives in Shared rather than in Audit because it appears in `AuditFacade::record()`'s
 * signature, and every context records events. **A type in a facade's signature is part of a
 * cross-context contract, so it must live somewhere every context may depend on** — otherwise
 * calling the facade requires importing the internals the facade exists to hide, and deptrac
 * rejects it (INV-02). Scalars or Shared types only.
 */
enum SecurityEventType: string
{
    // --- authentication
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case AccountLocked = 'account_locked';
    case LogoutSucceeded = 'logout_succeeded';
    case AllSessionsRevoked = 'all_sessions_revoked';

    // --- tokens
    case RefreshTokenRotated = 'refresh_token_rotated';

    /**
     * The one every security team wants alerting on: a refresh token was presented after it
     * had already been rotated, so either a client refreshed concurrently or a token was
     * stolen. The whole family is revoked either way.
     */
    case RefreshTokenReuse = 'refresh_token_reuse';

    // --- credentials
    case RegistrationCompleted = 'registration_completed';
    case EmailVerified = 'email_verified';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordChanged = 'password_changed';

    // --- MFA
    case MfaEnabled = 'mfa_enabled';
    case MfaDisabled = 'mfa_disabled';
    case MfaChallengeFailed = 'mfa_challenge_failed';

    // --- authorization
    case PermissionGranted = 'permission_granted';
    case PermissionRevoked = 'permission_revoked';
    case RoleAssigned = 'role_assigned';
    case RoleRevoked = 'role_revoked';
    case SuperAdminAccessUsed = 'super_admin_access_used';

    // --- data
    case AdminDataAccessed = 'admin_data_accessed';
    case GdprExportRequested = 'gdpr_export_requested';
    case GdprErasureRequested = 'gdpr_erasure_requested';

    /**
     * Should this event page someone?
     *
     * Kept on the enum so the answer travels with the event rather than living in a
     * dashboard someone has to remember to update.
     */
    /**
     * The wire values, for validating a type filter against.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isHighSeverity(): bool
    {
        return match ($this) {
            self::RefreshTokenReuse,
            self::AccountLocked,
            self::SuperAdminAccessUsed,
            self::GdprErasureRequested => true,
            default => false,
        };
    }
}
