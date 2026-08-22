<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use Symfony\Component\Uid\Uuid;

interface RoleRepository
{
    public function findByName(string $name): ?Role;

    public function findById(Uuid $id): ?Role;

    /** @return list<Role> */
    public function findAll(): array;

    /**
     * Does any of these roles carry this permission? The RBAC fallback tier.
     *
     * @param list<Uuid> $roleIds
     */
    public function anyGrants(array $roleIds, string $permissionName): bool;

    public function save(Role $role): void;

    public function remove(Role $role): void;
}
