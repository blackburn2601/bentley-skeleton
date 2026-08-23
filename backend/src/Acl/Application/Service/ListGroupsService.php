<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\Role;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;

/**
 * @responsibility Lists the groups this application defines.
 *
 * Member counts come from the subject repository rather than a mapped association: membership
 * is stored by user id, deliberately, so that Acl does not have to know what a user is.
 */
final readonly class ListGroupsService
{
    public function __construct(
        private UserGroupRepository $groups,
        private SubjectRepository $subjects,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, description: string|null, roles: list<string>, memberCount: int}>
     */
    public function __invoke(): array
    {
        return array_map(fn (UserGroup $group): array => [
            'id' => $group->id()->toRfc4122(),
            'name' => $group->name(),
            'description' => $group->description(),
            'roles' => array_map(static fn (Role $role): string => $role->name(), $group->roles()),
            'memberCount' => \count($this->subjects->memberIdsOf($group)),
        ], $this->groups->findAll());
    }
}
