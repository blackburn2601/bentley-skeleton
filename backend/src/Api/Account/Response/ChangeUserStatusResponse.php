<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Domain\User;

final readonly class ChangeUserStatusResponse
{
    private function __construct(
        public string $id,
        public string $status,
    ) {
    }

    public static function from(User $user): self
    {
        return new self($user->id()->toRfc4122(), $user->status()->value);
    }
}
