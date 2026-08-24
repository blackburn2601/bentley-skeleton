<?php

declare(strict_types=1);

namespace App\Account\Application;

/**
 * The scalar projection of an account that was just created or reset, plus the one-time
 * temporary password that must leave the service exactly once and never be persisted.
 *
 * Carrying the plaintext in a typed container — rather than returning it from a method that
 * also returns the entity — makes it impossible to silently drop or log: every caller has to
 * acknowledge it is handling a secret. The projection is scalars only (like IssuedSession),
 * so this stays a value and not a service that would muddy docs/SERVICES.md.
 */
final readonly class CreatedUser
{
    public function __construct(
        public string $userId,
        public string $username,
        public string $status,
        public string $temporaryPassword,
    ) {
    }
}
