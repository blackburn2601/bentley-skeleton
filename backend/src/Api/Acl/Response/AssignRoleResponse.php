<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

use App\Acl\Domain\Role;

/**
 * The assignment that was just made.
 */
final readonly class AssignRoleResponse
{
    private function __construct(
        public string $userId,
        public string $roleId,
        public string $role,
    ) {
    }

    public static function from(string $userId, Role $role): self
    {
        return new self($userId, $role->id()->toRfc4122(), $role->name());
    }
}
