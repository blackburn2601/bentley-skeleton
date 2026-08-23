<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\Permission;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;

/**
 * @responsibility Lists the roles this application defines.
 *
 * Not ACL-filtered, and not paginated. Roles are a small, closed, application-wide set — there
 * are three in the demo dataset — so `role.read` is the whole question, and a caller who holds
 * it sees all of them.
 */
final readonly class ListRolesService
{
    public function __construct(private RoleRepository $roles)
    {
    }

    /**
     * @return list<array{id: string, name: string, description: string|null, permissions: list<string>}>
     */
    public function __invoke(): array
    {
        return array_map(static fn (Role $role): array => [
            'id' => $role->id()->toRfc4122(),
            'name' => $role->name(),
            'description' => $role->description(),
            'permissions' => array_map(
                static fn (Permission $permission): string => $permission->name(),
                $role->permissions(),
            ),
        ], $this->roles->findAll());
    }
}
