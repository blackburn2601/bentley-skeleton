<?php

declare(strict_types=1);

namespace App\Tests\Unit\Acl\Double;

use App\Acl\Domain\Role;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\SubjectSet;
use App\Acl\Domain\UserGroup;
use Symfony\Component\Uid\Uuid;

final class StubSubjectRepository implements SubjectRepository
{
    /** @var array<string, SubjectSet> */
    private array $sets = [];

    public function define(SubjectSet $set): void
    {
        $this->sets[$set->userId->toRfc4122()] = $set;
    }

    public function subjectSetFor(Uuid $userId): SubjectSet
    {
        return $this->sets[$userId->toRfc4122()] ?? new SubjectSet($userId);
    }

    public function groupsOf(Uuid $userId): array
    {
        return [];
    }

    public function rolesOf(Uuid $userId): array
    {
        return [];
    }

    public function assignRole(Uuid $userId, Role $role): void
    {
    }

    public function revokeRole(Uuid $userId, Role $role): void
    {
    }

    public function addToGroup(Uuid $userId, UserGroup $group): void
    {
    }

    public function removeFromGroup(Uuid $userId, UserGroup $group): void
    {
    }

    public function memberIdsOf(UserGroup $group): array
    {
        return [];
    }
}
