<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl\Double;

use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use Symfony\Component\Uid\Uuid;

final class StubRoleRepository implements RoleRepository
{
    /** @var array<string, list<string>> role id => permission names */
    private array $grants = [];

    /** @var array<string, Role> */
    private array $roles = [];

    public function withRole(Role $role, string ...$permissions): void
    {
        $this->roles[$role->id()->toRfc4122()] = $role;
        $this->grants[$role->id()->toRfc4122()] = array_values($permissions);
    }

    public function anyGrants(array $roleIds, string $permissionName): bool
    {
        return array_any($roleIds, fn (Uuid $roleId): bool => \in_array($permissionName, $this->grants[$roleId->toRfc4122()] ?? [], true));
    }

    public function findByName(string $name): ?Role
    {
        foreach ($this->roles as $role) {
            if ($role->name() === $name) {
                return $role;
            }
        }

        return null;
    }

    public function findById(Uuid $id): ?Role
    {
        return $this->roles[$id->toRfc4122()] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->roles);
    }

    public function save(Role $role): void
    {
        $this->roles[$role->id()->toRfc4122()] = $role;
    }

    public function remove(Role $role): void
    {
        unset($this->roles[$role->id()->toRfc4122()]);
    }
}
