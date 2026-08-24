<?php

declare(strict_types=1);

namespace App\Api\Security;

/**
 * Where a caller sits in the two-step authentication flow (ADR-0026).
 *
 * Read off the signed `mfa` claim by {@see AuthenticatedUser}, never derived from a cookie or
 * a database flag — the token is the single statement of how the caller authenticated. A
 * non-MFA caller (floor user, or a session that never enrolled) is Verified too: "verified"
 * here means "free to act", which is true once there is no pending second factor.
 */
enum MfaStage: string
{
    /** The second factor is pending; the caller may reach only the verify endpoints. */
    case Pending = 'pending';

    /** No second factor is owed — the caller is free to act (MFA completed, or never owed). */
    case Verified = 'verified';
}
