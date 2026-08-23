<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Account\Application\AccountFacade;
use App\Acl\Domain\AclException;
use App\Acl\Domain\SubjectRepository;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Replaces the membership list of one group.
 *
 * Both the people joining and the people leaving have to be invalidated. Only invalidating the
 * new list would leave everyone removed still holding, in cache, the access the group gave
 * them — a revocation that silently did not happen, which is the failure mode ADR-0021 exists
 * to prevent.
 */
final readonly class SetGroupMembersService
{
    public function __construct(
        private UserGroupRepository $groups,
        private SubjectRepository $subjects,
        private AccountFacade $accounts,
        private InvalidateAclCachesService $invalidate,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $userIds
     */
    public function __invoke(Uuid $groupId, array $userIds, Uuid $changedBy): UserGroup
    {
        $group = $this->groups->findById($groupId);

        if (!$group instanceof UserGroup) {
            throw AclException::noSuchGroup();
        }

        $wanted = $this->resolve($userIds);
        $current = $this->currentMembers($group);

        $this->apply($group, $current, $wanted);
        $this->em->flush();

        // The union: everyone who joined, everyone who left, everyone who stayed. Invalidating
        // only the new list would leave everyone removed still holding, in cache, the access
        // the group gave them — a revocation that silently did not happen.
        $this->invalidate->forUsers(array_values($current + $wanted));

        $this->audit->record(SecurityEventType::RoleAssigned, $changedBy, [
            'action' => 'set_group_members',
            'group' => $group->name(),
            'memberCount' => \count($wanted),
        ]);

        return $group;
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, Uuid> keyed by canonical id, so the diff below is a key comparison
     */
    private function resolve(array $userIds): array
    {
        $wanted = [];

        foreach (array_unique($userIds) as $id) {
            $userId = Uuid::fromString($id);

            if (!$this->accounts->exists($userId)) {
                throw AclException::noSuchMember($id);
            }

            $wanted[$userId->toRfc4122()] = $userId;
        }

        return $wanted;
    }

    /** @return array<string, Uuid> */
    private function currentMembers(UserGroup $group): array
    {
        $current = [];

        foreach ($this->subjects->memberIdsOf($group) as $memberId) {
            $current[$memberId->toRfc4122()] = $memberId;
        }

        return $current;
    }

    /**
     * @param array<string, Uuid> $current
     * @param array<string, Uuid> $wanted
     */
    private function apply(UserGroup $group, array $current, array $wanted): void
    {
        foreach (array_diff_key($current, $wanted) as $memberId) {
            $this->subjects->removeFromGroup($memberId, $group);
        }

        foreach (array_diff_key($wanted, $current) as $memberId) {
            $this->subjects->addToGroup($memberId, $group);
        }
    }
}
