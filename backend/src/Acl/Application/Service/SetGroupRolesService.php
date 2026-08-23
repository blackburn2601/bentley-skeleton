<?php

declare(strict_types=1);

namespace App\Acl\Application\Service;

use App\Acl\Application\AclFacade;
use App\Acl\Domain\AclException;
use App\Acl\Domain\Role;
use App\Acl\Domain\RoleRepository;
use App\Acl\Domain\UserGroup;
use App\Acl\Domain\UserGroupRepository;
use App\Audit\Application\AuditFacade;
use App\Shared\Domain\SecurityEventType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @responsibility Replaces the set of roles carried by one group.
 *
 * Carries the same escalation ceiling as SetRolePermissionsService, for the same reason: every
 * member inherits whatever is attached here, so a caller who could attach ROLE_SUPER_ADMIN to
 * a group they belong to would be promoting themselves by a different route.
 */
final readonly class SetGroupRolesService
{
    public function __construct(
        private UserGroupRepository $groups,
        private RoleRepository $roles,
        private AclFacade $acl,
        private InvalidateAclCachesService $invalidate,
        private AuditFacade $audit,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<string> $roleNames
     */
    public function __invoke(Uuid $groupId, array $roleNames, Uuid $changedBy): UserGroup
    {
        $group = $this->groups->findById($groupId);

        if (!$group instanceof UserGroup) {
            throw AclException::noSuchGroup();
        }

        $wanted = [];
        foreach (array_unique($roleNames) as $name) {
            $role = $this->roles->findByName($name);

            if (!$role instanceof Role) {
                throw AclException::noSuchRole($name);
            }

            $this->refuseEscalation($role, $changedBy);
            $wanted[] = $role;
        }

        // Invalidate against the membership as it stands now, before the roles change: the
        // people affected are the current members either way.
        foreach ($group->roles() as $existing) {
            $group->revokeRole($existing);
        }

        foreach ($wanted as $role) {
            $group->assignRole($role);
        }

        $this->em->flush();
        $this->invalidate->forGroup($group);

        $this->audit->record(SecurityEventType::RoleAssigned, $changedBy, [
            'action' => 'set_group_roles',
            'group' => $group->name(),
            'roles' => array_values($roleNames),
        ]);

        return $group;
    }

    /**
     * A role may only be attached by someone who already holds everything it carries.
     */
    private function refuseEscalation(Role $role, Uuid $changedBy): void
    {
        if (Role::SUPER_ADMIN === $role->name() && !$this->acl->isGranted($changedBy, 'permission.grant')) {
            throw AclException::cannotGrantWhatYouDoNotHold($role->name());
        }

        foreach ($role->permissions() as $permission) {
            if (!$this->acl->isGranted($changedBy, $permission->name())) {
                throw AclException::cannotGrantWhatYouDoNotHold($permission->name());
            }
        }
    }
}
