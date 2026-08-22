<?php

declare(strict_types=1);

namespace App\Acl\Infrastructure\Repository;

use App\Acl\Domain\GroupMembership;
use App\Acl\Domain\Role;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\SubjectSet;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserRole;
use App\Shared\Domain\Clock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSubjectRepository implements SubjectRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private Clock $clock,
    ) {
    }

    public function subjectSetFor(Uuid $userId): SubjectSet
    {
        $groups = $this->groupsOf($userId);
        $groupIds = array_map(static fn (UserGroup $g): Uuid => $g->id(), $groups);

        // Effective roles = held directly + inherited through every group. Flattened here so
        // that nothing downstream has to remember groups carry roles.
        $roles = [];
        foreach ($this->rolesOf($userId) as $role) {
            $roles[$role->id()->toRfc4122()] = $role;
        }
        foreach ($groups as $group) {
            foreach ($group->roles() as $role) {
                $roles[$role->id()->toRfc4122()] = $role;
            }
        }

        return new SubjectSet(
            userId: $userId,
            groupIds: array_values($groupIds),
            roleIds: array_values(array_map(static fn (Role $r): Uuid => $r->id(), $roles)),
            roleNames: array_values(array_map(static fn (Role $r): string => $r->name(), $roles)),
        );
    }

    public function groupsOf(Uuid $userId): array
    {
        // The root alias must appear in the SELECT — DQL refuses to hydrate a joined entity
        // otherwise — so this selects memberships and unwraps them, rather than selecting
        // groups directly.
        /** @var list<GroupMembership> $memberships */
        $memberships = $this->em->createQueryBuilder()
            ->select('m', 'g', 'r')
            ->from(GroupMembership::class, 'm')
            ->join('m.group', 'g')
            // Fetch-join the group's roles: without it, resolving a subject set costs one
            // extra query per group on every single request.
            ->leftJoin('g.roles', 'r')
            ->where('m.userId = :userId')
            ->setParameter('userId', $userId->toRfc4122())
            ->getQuery()
            ->getResult();

        return array_map(static fn (GroupMembership $m): UserGroup => $m->group(), $memberships);
    }

    public function rolesOf(Uuid $userId): array
    {
        /** @var list<UserRole> $assignments */
        $assignments = $this->em->createQueryBuilder()
            ->select('ur', 'r')
            ->from(UserRole::class, 'ur')
            ->join('ur.role', 'r')
            ->where('ur.userId = :userId')
            ->setParameter('userId', $userId->toRfc4122())
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserRole $ur): Role => $ur->role(), $assignments);
    }

    public function assignRole(Uuid $userId, Role $role): void
    {
        if ($this->findAssignment($userId, $role) instanceof UserRole) {
            return;
        }

        $this->em->persist(new UserRole($userId, $role, $this->clock->now()));
    }

    public function revokeRole(Uuid $userId, Role $role): void
    {
        $assignment = $this->findAssignment($userId, $role);

        if ($assignment instanceof UserRole) {
            $this->em->remove($assignment);
        }
    }

    public function addToGroup(Uuid $userId, UserGroup $group): void
    {
        if ($this->findMembership($userId, $group) instanceof GroupMembership) {
            return;
        }

        $this->em->persist(new GroupMembership($userId, $group, $this->clock->now()));
    }

    public function removeFromGroup(Uuid $userId, UserGroup $group): void
    {
        $membership = $this->findMembership($userId, $group);

        if ($membership instanceof GroupMembership) {
            $this->em->remove($membership);
        }
    }

    public function memberIdsOf(UserGroup $group): array
    {
        /** @var list<array{userId: string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('m.userId')
            ->from(GroupMembership::class, 'm')
            ->where('m.group = :group')
            ->setParameter('group', $group)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): Uuid => Uuid::fromString($row['userId']), $rows);
    }

    private function findAssignment(Uuid $userId, Role $role): ?UserRole
    {
        return $this->em->getRepository(UserRole::class)
            ->findOneBy(['userId' => $userId, 'role' => $role]);
    }

    private function findMembership(Uuid $userId, UserGroup $group): ?GroupMembership
    {
        return $this->em->getRepository(GroupMembership::class)
            ->findOneBy(['userId' => $userId, 'group' => $group]);
    }
}
