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
    case Active = 'active';

    /** Suspended by an administrator. Cannot log in; may be reinstated. */
    case Suspended = 'suspended';

    /** GDPR erasure has anonymised this row. Terminal. */
    case Anonymised = 'anonymised';

    /**
     * The wire values, for validating a status filter against.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function canAuthenticate(): bool
    {
        return self::Active === $this;
    }
}
