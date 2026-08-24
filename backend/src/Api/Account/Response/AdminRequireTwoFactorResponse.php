<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Domain\User;

/**
 * The MFA requirement an administrator just set on one user (ADR-0026).
 *
 * Reflects only the admin-enforced requirement, not whether the user has enrolled a factor —
 * those are independent, and `mfaApplies()` is their union at login time. The admin UI shows
 * this toggle alongside the reset action.
 */
final readonly class AdminRequireTwoFactorResponse
{
    public function __construct(
        public string $id,
        public bool $mfaRequired,
    ) {
    }

    public static function from(User $user): self
    {
        return new self($user->id()->toRfc4122(), $user->isMfaRequired());
    }
}
