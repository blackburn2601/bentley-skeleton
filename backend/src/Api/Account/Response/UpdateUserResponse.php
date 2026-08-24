<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Domain\User;

/**
 * The username an administrator just set on one user.
 */
final readonly class UpdateUserResponse
{
    private function __construct(
        public string $id,
        public string $username,
    ) {
    }

    public static function from(User $user): self
    {
        return new self($user->id()->toRfc4122(), $user->username());
    }
}
