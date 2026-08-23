<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Domain\User;

/**
 * The account that was just created.
 *
 * `passwordSetupEmailed` is here so the UI can tell the administrator what happens next —
 * without it, a form that returns silently looks like it did nothing.
 */
final readonly class CreateUserResponse
{
    private function __construct(
        public string $id,
        public string $email,
        public string $status,
        public bool $passwordSetupEmailed,
    ) {
    }

    public static function from(User $user): self
    {
        return new self($user->id()->toRfc4122(), $user->email(), $user->status()->value, true);
    }
}
