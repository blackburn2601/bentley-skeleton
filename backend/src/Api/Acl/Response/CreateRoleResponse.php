<?php

declare(strict_types=1);

namespace App\Api\Acl\Response;

use App\Acl\Domain\Permission;
use App\Acl\Domain\Role;

/**
 * One role, as an administrator's client needs it.
 */
final readonly class CreateRoleResponse
{
    /**
     * @param list<string> $permissions
     */
    private function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public array $permissions,
    ) {
    }

    public static function from(Role $role): self
    {
        return new self(
            $role->id()->toRfc4122(),
            $role->name(),
            $role->description(),
            array_map(static fn (Permission $p): string => $p->name(), $role->permissions()),
        );
    }
}
