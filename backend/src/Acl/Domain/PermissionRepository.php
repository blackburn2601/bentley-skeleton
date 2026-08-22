<?php

declare(strict_types=1);

namespace App\Acl\Domain;

interface PermissionRepository
{
    public function findByName(string $name): ?Permission;

    /** @return list<Permission> */
    public function findAll(): array;

    /** @return list<string> */
    public function findAllNames(): array;

    public function save(Permission $permission): void;
}
