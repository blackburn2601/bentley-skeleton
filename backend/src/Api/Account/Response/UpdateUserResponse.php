<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Domain\User;

/**
 * `emailVerified` is returned so the UI can say what just happened: a changed address is
 * unverified until its owner proves they can receive mail there.
 */
final readonly class UpdateUserResponse
{
    private function __construct(
        public string $id,
        public string $email,
        public bool $emailVerified,
    ) {
    }

    public static function from(User $user): self
    {
        return new self($user->id()->toRfc4122(), $user->email(), $user->isEmailVerified());
    }
}
