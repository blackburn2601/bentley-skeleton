<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Domain\AclException;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Removes a group that no longer applies.
 *
 * Members lose everything the group carried, so their caches are invalidated before the row
 * goes — afterwards `group_membership` has cascaded and the member list is unrecoverable.
 */
final readonly class DeleteGroupService
{
    public function __construct(
        private UserGroupRepository $groups,
        private InvalidateAclCachesService $invalidate,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(Uuid $groupId, Uuid $deletedBy): string
    {
        $group = $this->groups->findById($groupId);

        if (!$group instanceof UserGroup) {
            throw AclException::noSuchGroup();
        }

        $name = $group->name();

        $this->invalidate->forGroup($group);

        $this->groups->remove($group);
        $this->em->flush();

        $this->audit->record(SecurityEventType::PermissionRevoked, $deletedBy, [
            'action' => 'delete_group',
            'group' => $name,
        ]);

        return $name;
    }
}
