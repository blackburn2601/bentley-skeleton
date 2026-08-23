<?php

declare(strict_types=1);

namespace App\Acl\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Reads and writes groups.
 *
 * Separate from SubjectRepository, which answers "what does this user belong to?" during a
 * permission check. This one answers "what groups exist?", which only administration asks.
 * Keeping them apart means the hot path never grows a method it does not need.
 */
interface UserGroupRepository
{
    public function findById(Uuid $id): ?UserGroup;

    public function findByName(string $name): ?UserGroup;

    /** @return list<UserGroup> */
    public function findAll(): array;

    public function save(UserGroup $group): void;

    public function remove(UserGroup $group): void;
}
