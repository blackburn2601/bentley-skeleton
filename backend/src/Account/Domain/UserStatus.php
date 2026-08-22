<?php

declare(strict_types=1);

namespace App\Account\Domain;

/**
 * Where an account is in its lifecycle.
 *
 * Distinct from "locked": lockout is temporary and automatic (too many failed logins),
 * status is deliberate and administrative. Conflating them means an admin cannot tell a
 * brute-force attempt from a disciplinary suspension.
 */
enum UserStatus: string
{
    /** Registered, email not yet verified. Cannot log in. */
    case PendingVerification = 'pending_verification';

    case Active = 'active';

    /** Suspended by an administrator. Cannot log in; may be reinstated. */
    case Suspended = 'suspended';

    /** GDPR erasure has anonymised this row. Terminal. */
    case Anonymised = 'anonymised';

    public function canAuthenticate(): bool
    {
        return self::Active === $this;
    }
}
