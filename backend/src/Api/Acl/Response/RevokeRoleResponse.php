<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

use App\Acl\Domain\Role;

/**
 * The assignment that was just removed.
 */
final readonly class RevokeRoleResponse
{
    private function __construct(
        public string $userId,
        public string $role,
        public bool $revoked,
    ) {
    }

    public static function from(string $userId, Role $role): self
    {
        return new self($userId, $role->name(), true);
    }
}
