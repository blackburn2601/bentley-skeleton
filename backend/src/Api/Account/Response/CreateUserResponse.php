<?php

declare(strict_types=1);

namespace App\Api\Account\Response;

use App\Account\Application\CreatedUser;

/**
 * The account that was just created, with the one-time temporary password.
 *
 * The temporary password is in the body exactly once so the administrator can hand it over
 * out-of-band. It is never persisted, never logged, and never appears again — the SPA must
 * show it now or lose it (ADR-0024).
 */
final readonly class CreateUserResponse
{
    private function __construct(
        public string $id,
        public string $username,
        public string $status,
        public string $temporaryPassword,
    ) {
    }

    public static function from(CreatedUser $created): self
    {
        return new self(
            $created->userId,
            $created->username,
            $created->status,
            $created->temporaryPassword,
        );
    }
}
